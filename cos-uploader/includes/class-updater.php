<?php
/**
 * 在线更新：从 GitHub Release 获取新版本，接入 WordPress 自带的插件更新流程。
 * 下载后用 Release 里附带的 SHA-256 指纹校验，不一致就拒绝安装。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cos_Uploader_Updater
{
    const REPO  = 'suyin101/CosUploader';
    const SLUG  = 'cos-uploader';
    const ASSET = 'cos-uploader.zip';
    const CACHE = 'cos_uploader_releases';

    private static $instance;
    private $file;
    private $basename;
    private $version;

    public static function boot($file, $version)
    {
        if (!self::$instance) {
            self::$instance = new self($file, $version);
        }
        return self::$instance;
    }

    public static function instance()
    {
        return self::$instance;
    }

    private function __construct($file, $version)
    {
        $this->file = $file;
        $this->basename = plugin_basename($file);
        $this->version = $version;

        add_filter('update_plugins_github.com', [$this, 'check'], 10, 4);
        add_filter('plugins_api', [$this, 'info'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'fix_folder'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);
    }

    /* ---------------- 读取 GitHub Release ---------------- */

    /** @return array ['list' => [...], 'error' => string, 'checked' => int] */
    public function releases($force = false)
    {
        $cached = get_site_transient(self::CACHE);
        if (!$force && is_array($cached) && isset($cached['list'])) {
            return $cached;
        }

        $data = ['list' => [], 'error' => '', 'checked' => time()];
        $res = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases?per_page=20', [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'cos-uploader/' . $this->version],
        ]);

        if (is_wp_error($res)) {
            $data['error'] = '连不上 GitHub：' . $res->get_error_message();
        } elseif (wp_remote_retrieve_response_code($res) !== 200) {
            $data['error'] = 'GitHub 返回 HTTP ' . wp_remote_retrieve_response_code($res) . '，请稍后再试';
        } else {
            $json = json_decode(wp_remote_retrieve_body($res), true);
            foreach ((array) $json as $r) {
                if (!is_array($r) || !empty($r['draft']) || !empty($r['prerelease'])) {
                    continue;
                }
                $ver = ltrim((string) ($r['tag_name'] ?? ''), 'vV');
                if (!preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
                    continue;
                }
                $package = '';
                $sha_url = '';
                $prefix = 'https://github.com/' . self::REPO . '/releases/download/';
                foreach ((array) ($r['assets'] ?? []) as $a) {
                    $url = (string) ($a['browser_download_url'] ?? '');
                    if (strpos($url, $prefix) !== 0) {
                        continue; // 只接受本仓库的下载地址
                    }
                    if (($a['name'] ?? '') === self::ASSET) {
                        $package = $url;
                    } elseif (($a['name'] ?? '') === self::ASSET . '.sha256') {
                        $sha_url = $url;
                    }
                }
                $data['list'][] = [
                    'version' => $ver,
                    'notes'   => (string) ($r['body'] ?? ''),
                    'date'    => substr((string) ($r['published_at'] ?? ''), 0, 10),
                    'package' => $package,
                    'sha_url' => $sha_url,
                ];
            }
            usort($data['list'], function ($a, $b) {
                return version_compare($b['version'], $a['version']);
            });
        }

        // 成功缓存 6 小时；失败 30 分钟后再试，不让后台页面反复等 GitHub
        set_site_transient(self::CACHE, $data, $data['error'] ? 30 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        return $data;
    }

    private function latest()
    {
        foreach ($this->releases()['list'] as $r) {
            if ($r['package'] && $r['sha_url']) {
                return $r;
            }
        }
        return null;
    }

    /**
     * 给设置页用的更新状态。
     * @param bool $force       立即重新检查
     * @param bool $allow_fetch 没有缓存时是否允许现在去 GitHub 取（页面加载时不取，避免卡住）
     */
    public function status($force, $allow_fetch = true)
    {
        if (!$force && !$allow_fetch && !is_array(get_site_transient(self::CACHE))) {
            return ['current' => $this->version, 'latest' => '', 'has_update' => false, 'update_url' => '',
                    'error' => '', 'checked' => '', 'changelog' => [], 'pending' => true];
        }
        if ($force) {
            $this->releases(true);
            // 让 WordPress 立刻重新检查，「立即更新」按钮才能用
            delete_site_transient('update_plugins');
            wp_update_plugins();
        }
        $data = $this->releases();
        $latest = $this->latest();
        $has_update = $latest && version_compare($latest['version'], $this->version, '>');

        $transient = get_site_transient('update_plugins');
        $ready = $has_update && isset($transient->response[$this->basename]);

        $changelog = [];
        foreach (array_slice($data['list'], 0, 10) as $r) {
            if ($latest && version_compare($r['version'], $latest['version'], '>')) {
                continue; // 比可安装的最新版还新（缺少更新包），不显示，免得误会
            }
            $changelog[] = [
                'version' => $r['version'],
                'date'    => $r['date'],
                'html'    => self::markdown($r['notes']),
                'newer'   => version_compare($r['version'], $this->version, '>'),
                'current' => $r['version'] === $this->version,
            ];
        }

        return [
            'current'    => $this->version,
            'latest'     => $latest ? $latest['version'] : '',
            'has_update' => (bool) $has_update,
            'update_url' => $ready ? wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($this->basename)), 'upgrade-plugin_' . $this->basename) : '',
            'error'      => $data['error'],
            'checked'    => $data['checked'] ? human_time_diff($data['checked']) . '前' : '',
            'changelog'  => $changelog,
            'pending'    => false,
        ];
    }

    /* ---------------- 接入 WordPress 更新流程 ---------------- */

    public function check($update, $plugin_data, $plugin_file, $locales)
    {
        if ($plugin_file !== $this->basename) {
            return $update;
        }
        $latest = $this->latest();
        if (!$latest) {
            return $update;
        }
        return [
            'id'           => 'github.com/' . self::REPO,
            'slug'         => self::SLUG,
            'plugin'       => $this->basename,
            'version'      => $latest['version'],
            'url'          => 'https://github.com/' . self::REPO,
            'package'      => $latest['package'],
            'requires_php' => '7.2',
        ];
    }

    /** 插件列表里「查看详情」弹窗 */
    public function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }
        $latest = $this->latest();
        $html = '';
        foreach (array_slice($this->releases()['list'], 0, 10) as $r) {
            $html .= '<h4>v' . esc_html($r['version']) . ' <small>' . esc_html($r['date']) . '</small></h4>' . self::markdown($r['notes']);
        }
        return (object) [
            'name'          => 'COS 图片上传',
            'slug'          => self::SLUG,
            'version'       => $latest ? $latest['version'] : $this->version,
            'author'        => 'suyin101',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => '5.8',
            'requires_php'  => '7.2',
            'download_link' => $latest ? $latest['package'] : '',
            'sections'      => [
                'description' => '<p>把媒体库里的图片自动上传到腾讯云对象存储 COS，前台直接用 COS 地址访问，可随时切回本站。</p>',
                'changelog'   => $html ?: '<p>暂无更新日志</p>',
            ],
        ];
    }

    /** 自己下载更新包并校验 SHA-256，不一致就中止更新 */
    public function download($reply, $package, $upgrader, $hook_extra = [])
    {
        if ($reply !== false) {
            return $reply;
        }
        $release = null;
        foreach ($this->releases()['list'] as $r) {
            if ($r['package'] !== '' && $r['package'] === $package) {
                $release = $r;
                break;
            }
        }
        if (!$release) {
            return $reply; // 不是本插件的更新包
        }
        if (!$release['sha_url']) {
            return new WP_Error('cosu_no_sha', '更新包缺少校验文件，为安全起见已取消更新');
        }

        $sum = wp_remote_get($release['sha_url'], ['timeout' => 30]);
        if (is_wp_error($sum) || wp_remote_retrieve_response_code($sum) !== 200
            || !preg_match('/\b([a-f0-9]{64})\b/i', wp_remote_retrieve_body($sum), $m)) {
            return new WP_Error('cosu_sha_fetch', '获取校验文件失败，已取消更新，请稍后再试');
        }
        $expected = strtolower($m[1]);

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (isset($upgrader->skin)) {
            $upgrader->skin->feedback('downloading_package', $package);
        }
        $tmp = download_url($package, 300);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        if (!hash_equals($expected, hash_file('sha256', $tmp))) {
            @unlink($tmp);
            return new WP_Error('cosu_sha_mismatch', '更新包校验失败（文件不完整或被篡改），已取消更新');
        }
        return $tmp;
    }

    /** 保证解压出来的文件夹名是 cos-uploader，否则更新后插件会被停用 */
    public function fix_folder($source, $remote_source, $upgrader, $hook_extra = [])
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }
        $want = trailingslashit($remote_source) . self::SLUG . '/';
        if (untrailingslashit($source) === untrailingslashit($want)) {
            return $source;
        }
        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($source, $want, true)) {
            return $want;
        }
        return new WP_Error('cosu_folder', '更新包目录结构不对，已取消更新');
    }

    public function after_update($upgrader, $extra)
    {
        if (($extra['type'] ?? '') === 'plugin' && in_array($this->basename, (array) ($extra['plugins'] ?? []), true)) {
            delete_site_transient(self::CACHE);
        }
    }

    /* ---------------- 工具 ---------------- */

    /** 把 Release 说明（简单 Markdown）转成安全的 HTML */
    public static function markdown($text)
    {
        $html = '';
        $in_list = false;
        foreach (preg_split('/\r?\n/', trim((string) $text)) as $line) {
            $line = trim($line);
            $is_item = preg_match('/^[-*]\s+(.*)$/', $line, $m);
            if (!$is_item && $in_list) {
                $html .= '</ul>';
                $in_list = false;
            }
            if ($line === '') {
                continue;
            }
            $inline = esc_html($is_item ? $m[1] : $line);
            $inline = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $inline);
            $inline = preg_replace('/`(.+?)`/', '<code>$1</code>', $inline);
            if ($is_item) {
                if (!$in_list) {
                    $html .= '<ul>';
                    $in_list = true;
                }
                $html .= '<li>' . $inline . '</li>';
            } elseif (preg_match('/^#{1,6}\s+(.*)$/', $line, $h)) {
                $html .= '<h5>' . esc_html($h[1]) . '</h5>';
            } else {
                $html .= '<p>' . $inline . '</p>';
            }
        }
        if ($in_list) {
            $html .= '</ul>';
        }
        return $html;
    }
}
