<?php
/**
 * Plugin Name:       COS 图片上传
 * Plugin URI:        https://github.com/suyin101/CosUploader
 * Description:       把媒体库里的图片（以及其他附件）自动上传到腾讯云对象存储 COS，前台直接用 COS 地址访问，可随时切回本站。
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            suyin101
 * License:           GPLv2 or later
 * Update URI:        https://github.com/suyin101/CosUploader
 */

if (!defined('ABSPATH')) {
    exit;
}

define('COSU_VERSION', '1.3.0');
define('COSU_FILE', __FILE__);

require_once __DIR__ . '/includes/class-cos-client.php';
require_once __DIR__ . '/includes/class-updater.php';

class Cos_Uploader
{
    const OPTION     = 'cos_uploader';
    const META       = '_cos_uploader_keys';   // 已上传到 COS 的对象 key 列表
    const META_DEST  = '_cos_uploader_target'; // 上传到的是哪个存储桶 + 目录
    const LAST_ERROR = 'cos_uploader_last_error';
    const DB_VERSION = 'cos_uploader_db';
    const PAGE       = 'cos-uploader';

    /** @var Cos_Uploader_Client|null */
    private $client;
    private $opts;
    private $upload_dir;

    public static function init()
    {
        $self = new self();
        Cos_Uploader_Updater::boot(COSU_FILE, COSU_VERSION);

        add_action('admin_menu', [$self, 'add_menu']);
        add_action('admin_init', [$self, 'register_settings']);
        add_action('admin_init', [$self, 'maybe_upgrade_data']);
        add_action('admin_enqueue_scripts', [$self, 'enqueue']);
        add_filter('plugin_action_links_' . plugin_basename(COSU_FILE), [$self, 'action_links']);

        add_action('wp_ajax_cos_uploader_test', [$self, 'ajax_test']);
        add_action('wp_ajax_cos_uploader_pending', [$self, 'ajax_pending']);
        add_action('wp_ajax_cos_uploader_sync', [$self, 'ajax_sync']);
        add_action('wp_ajax_cos_uploader_check_update', [$self, 'ajax_check_update']);

        if (!$self->configured()) {
            return;
        }

        // 删除附件时同时删除 COS 上的文件（两种模式都生效，避免 COS 里留下垃圾）
        add_action('delete_attachment', [$self, 'on_delete_attachment']);

        if ($self->opt('mode') === 'local') {
            // 本站模式：不上传，文章里写死的 COS 地址也换回本站地址
            add_filter('the_content', [$self, 'filter_content_to_local'], 999);
            return;
        }

        // 新上传：缩略图全部生成完之后再统一上传
        add_filter('wp_generate_attachment_metadata', [$self, 'on_generate_metadata'], 999, 2);
        // 已同步的图片被编辑（裁剪/旋转）或重新生成缩略图后，补传新文件
        add_filter('wp_update_attachment_metadata', [$self, 'on_update_metadata'], 999, 2);

        // 前台地址改写成 COS 地址
        add_filter('wp_get_attachment_url', [$self, 'filter_attachment_url'], 999, 2);
        add_filter('wp_calculate_image_srcset', [$self, 'filter_srcset'], 999, 5);
        if ($self->opt('replace_content')) {
            add_filter('the_content', [$self, 'filter_content'], 999);
        }
    }

    /* ================= 配置 ================= */

    private function opts()
    {
        if ($this->opts === null) {
            $defaults = [
                'mode'            => 'cos',
                'secret_id'       => '',
                'secret_key'      => '',
                'region'          => 'ap-guangzhou',
                'bucket'          => '',
                'domain'          => '',
                'prefix'          => 'wp-content/uploads',
                'replace_content' => 0,
            ];
            $this->opts = wp_parse_args((array) get_option(self::OPTION, []), $defaults);
        }
        return $this->opts;
    }

    private function opt($key)
    {
        $o = $this->opts();
        return isset($o[$key]) ? $o[$key] : '';
    }

    /** 密钥写在 wp-config.php 里时优先用它 */
    private function keys_in_config()
    {
        return defined('COS_UPLOADER_SECRET_ID') && defined('COS_UPLOADER_SECRET_KEY');
    }

    private function secret_id()
    {
        return $this->keys_in_config() ? (string) COS_UPLOADER_SECRET_ID : $this->opt('secret_id');
    }

    private function secret_key()
    {
        return $this->keys_in_config() ? (string) COS_UPLOADER_SECRET_KEY : self::decrypt($this->opt('secret_key'));
    }

    /** 保存过密钥，但用当前站点的安全密钥解不开（例如 wp-config 里的盐被换过） */
    private function secret_broken()
    {
        return !$this->keys_in_config() && $this->opt('secret_key') !== '' && $this->secret_key() === '';
    }

    private function configured()
    {
        return $this->secret_id() !== '' && $this->secret_key() !== '' && $this->opt('region') && $this->opt('bucket');
    }

    private function client()
    {
        if ($this->client === null) {
            $this->client = new Cos_Uploader_Client($this->secret_id(), $this->secret_key(), $this->opt('region'), $this->opt('bucket'));
        }
        return $this->client;
    }

    /** 当前上传目标：地域 + 存储桶 + 目录。换了任何一项，之前的上传记录都不再算数 */
    private function target()
    {
        return $this->opt('region') . ':' . $this->opt('bucket') . '/' . trim($this->opt('prefix'), '/');
    }

    private function upload_dir()
    {
        if ($this->upload_dir === null) {
            $this->upload_dir = wp_get_upload_dir();
        }
        return $this->upload_dir;
    }

    /** 对外访问的根地址，例如 https://xxx.cos.ap-guangzhou.myqcloud.com/wp-content/uploads */
    private function public_base()
    {
        $domain = trim($this->opt('domain'));
        $base = $domain === '' ? 'https://' . $this->client()->host() : rtrim($domain, '/');
        $prefix = trim($this->opt('prefix'), '/');
        return $prefix === '' ? $base : $base . '/' . $prefix;
    }

    private function key_for($relative)
    {
        $prefix = trim($this->opt('prefix'), '/');
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        return $prefix === '' ? $relative : $prefix . '/' . $relative;
    }

    /* ---------- 密钥加密保存（用站点自己的安全密钥，数据库泄露也看不到明文） ---------- */

    private static function crypt_key()
    {
        return hash('sha256', wp_salt('auth') . '|cos-uploader', true);
    }

    public static function encrypt($plain)
    {
        if ($plain === '' || strpos($plain, 'enc1:') === 0 || !function_exists('openssl_encrypt')) {
            return $plain;
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv);
        return $cipher === false ? $plain : 'enc1:' . base64_encode($iv . $cipher);
    }

    public static function decrypt($stored)
    {
        if (strpos((string) $stored, 'enc1:') !== 0) {
            return (string) $stored;
        }
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) <= 16 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain === false ? '' : $plain;
    }

    /* ---------- 旧版本数据升级 ---------- */

    public function maybe_upgrade_data()
    {
        if ((int) get_option(self::DB_VERSION, 1) >= 2) {
            return;
        }
        global $wpdb;

        // 1.x 的上传记录没有标明存储桶，统一标记为当前存储桶
        if ($this->configured()) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                 SELECT m.post_id, %s, %s FROM {$wpdb->postmeta} m
                 WHERE m.meta_key = %s AND NOT EXISTS (
                     SELECT 1 FROM {$wpdb->postmeta} t WHERE t.post_id = m.post_id AND t.meta_key = %s
                 )",
                self::META_DEST,
                $this->target(),
                self::META,
                self::META_DEST
            ));
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group('post_meta');
            } else {
                wp_cache_flush();
            }
        } elseif ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::META))) {
            return; // 有旧记录但还没配置好，等配置好再升级
        }

        // 明文保存的 SecretKey 改为加密保存
        $raw = (array) get_option(self::OPTION, []);
        if (!empty($raw['secret_key']) && strpos($raw['secret_key'], 'enc1:') !== 0) {
            $raw['secret_key'] = self::encrypt($raw['secret_key']);
            update_option(self::OPTION, $raw);
            $this->opts = null;
        }

        update_option(self::DB_VERSION, 2);
    }

    /* ================= 附件处理 ================= */

    /** 一个附件对应的所有本地文件：相对 uploads 的路径 => 绝对路径。第一个是原图 */
    private function files_of($post_id, $meta = null)
    {
        $relative = get_post_meta($post_id, '_wp_attached_file', true);
        if (!$relative || strpos($relative, '..') !== false) {
            return [];
        }
        if ($meta === null) {
            $meta = wp_get_attachment_metadata($post_id);
        }
        $basedir = $this->upload_dir()['basedir'];
        $dir = dirname($relative);
        $dir = ($dir === '.' ? '' : $dir . '/');

        $names = [basename($relative)];
        if (is_array($meta)) {
            if (!empty($meta['original_image'])) {
                $names[] = $meta['original_image'];
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $names[] = $size['file'];
                    }
                }
            }
        }

        $files = [];
        foreach (array_unique($names) as $name) {
            // 防止数据库里被写入 ../ 之类的路径，越出上传目录
            if (!is_string($name) || $name === '' || $name === '..' || strpbrk($name, '/\\') !== false) {
                continue;
            }
            $files[$dir . $name] = $basedir . '/' . $dir . $name;
        }
        return $files;
    }

    private function is_synced($post_id)
    {
        return get_post_meta($post_id, self::META_DEST, true) === $this->target();
    }

    /**
     * 上传附件的全部文件。
     * - 存储桶里已经有同一个文件（本地没有，或大小一致）就直接用，不重复上传
     * - 本地和存储桶都没有的缩略图会跳过（以后重新生成缩略图时会自动补传）
     * - 原图哪里都找不到则整组失败
     * @return array|WP_Error 成功返回被跳过的缺失文件列表
     */
    private function sync_attachment($post_id, $meta = null)
    {
        $done = $this->is_synced($post_id) ? array_filter((array) get_post_meta($post_id, self::META, true)) : [];
        $files = $this->files_of($post_id, $meta);
        if (!$files) {
            return new WP_Error('cos_missing', '没有文件记录');
        }
        $main = key($files);
        $missing = [];
        $client = $this->client();

        foreach ($files as $relative => $local) {
            $key = $this->key_for($relative);
            if (in_array($key, $done, true)) {
                continue;
            }

            $remote = $client->size($key);
            if (is_wp_error($remote)) {
                return $remote;
            }
            $has_local = is_readable($local);
            if (is_int($remote) && (!$has_local || filesize($local) === $remote)) {
                $done[] = $key;
                continue;
            }

            if (!$has_local) {
                if ($relative === $main) {
                    return new WP_Error('cos_missing', '原图在服务器和存储桶里都不存在：' . $key);
                }
                $missing[] = $local;
                continue;
            }

            $r = $client->put_file($local, $key);
            if (is_wp_error($r)) {
                return $r; // 不记录，下次重新处理（已传上去的文件会被识别出来，不会重传）
            }
            $done[] = $key;
        }

        update_post_meta($post_id, self::META, array_values(array_unique($done)));
        update_post_meta($post_id, self::META_DEST, $this->target());
        return $missing;
    }

    private function remember_error($post_id, WP_Error $e)
    {
        $msg = '「' . get_the_title($post_id) . '」上传失败：' . $e->get_error_message();
        error_log('[COS 图片上传] ' . $msg);
        update_option(self::LAST_ERROR, ['time' => time(), 'msg' => $msg], false);
    }

    public function on_generate_metadata($meta, $post_id)
    {
        $r = $this->sync_attachment($post_id, $meta);
        if (is_wp_error($r)) {
            $this->remember_error($post_id, $r);
        }
        return $meta;
    }

    public function on_update_metadata($meta, $post_id)
    {
        // 只处理已经同步过的附件（编辑图片、重新生成缩略图）；新上传由 on_generate_metadata 负责
        if ($this->is_synced($post_id)) {
            $r = $this->sync_attachment($post_id, $meta);
            if (is_wp_error($r)) {
                $this->remember_error($post_id, $r);
            }
        }
        return $meta;
    }

    public function on_delete_attachment($post_id)
    {
        // 只删除确定是传到当前存储桶的文件
        if (!$this->is_synced($post_id)) {
            return;
        }
        foreach (array_filter((array) get_post_meta($post_id, self::META, true)) as $key) {
            $this->client()->delete($key);
        }
    }

    /* ================= 地址改写 ================= */

    private function local_bases()
    {
        $b = $this->upload_dir()['baseurl'];
        return array_unique([$b, set_url_scheme($b, 'http'), set_url_scheme($b, 'https')]);
    }

    private function to_cos_url($url)
    {
        foreach ($this->local_bases() as $b) {
            if (strpos($url, $b . '/') === 0) {
                return $this->public_base() . substr($url, strlen($b));
            }
        }
        return $url;
    }

    public function filter_attachment_url($url, $post_id)
    {
        return $this->is_synced($post_id) ? $this->to_cos_url($url) : $url;
    }

    public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (!is_array($sources) || !$this->is_synced($attachment_id)) {
            return $sources;
        }
        foreach ($sources as $w => $source) {
            $sources[$w]['url'] = $this->to_cos_url($source['url']);
        }
        return $sources;
    }

    public function filter_content($content)
    {
        $search = array_map(function ($b) {
            return $b . '/';
        }, $this->local_bases());
        return str_replace($search, $this->public_base() . '/', $content);
    }

    public function filter_content_to_local($content)
    {
        $prefix = trim($this->opt('prefix'), '/');
        $suffix = $prefix === '' ? '' : '/' . $prefix;
        $host = $this->client()->host();
        // 自定义域名 + COS 默认域名（http/https）都换回本站
        $search = array_unique([
            $this->public_base() . '/',
            'https://' . $host . $suffix . '/',
            'http://' . $host . $suffix . '/',
        ]);
        return str_replace($search, $this->upload_dir()['baseurl'] . '/', $content);
    }

    /* ================= 统计 ================= */

    private function stats()
    {
        global $wpdb;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
        $synced = 0;
        if ($this->configured()) {
            $synced = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
                 WHERE p.post_type = 'attachment'",
                self::META_DEST,
                $this->target()
            ));
        }
        return ['total' => $total, 'synced' => $synced, 'pending' => max(0, $total - $synced)];
    }

    /* ================= AJAX ================= */

    private function ajax_guard()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer('cos_uploader', 'nonce', false)) {
            wp_send_json_error('没有权限，请刷新页面后重试', 403);
        }
    }

    private function ajax_need_config()
    {
        if (!$this->configured()) {
            wp_send_json_error('请先在「基本设置」里填好密钥和存储桶并保存');
        }
    }

    public function ajax_test()
    {
        $this->ajax_guard();
        $this->ajax_need_config();
        @set_time_limit(120);

        $client = $this->client();
        $name = 'cos-uploader-test-' . strtolower(wp_generate_password(8, false)) . '.txt';
        $key = $this->key_for($name);
        $steps = [];

        $tmp = wp_tempnam('cos-test');
        file_put_contents($tmp, 'COS 图片上传 连接测试 ' . gmdate('c'));
        $r = $client->put_file($tmp, $key);
        @unlink($tmp);
        if (is_wp_error($r)) {
            $steps[] = ['ok' => false, 'name' => '上传文件', 'msg' => $r->get_error_message()];
            wp_send_json_success(['ok' => false, 'steps' => $steps]);
        }
        $steps[] = ['ok' => true, 'name' => '上传文件', 'msg' => '密钥、地域、存储桶都正确'];

        $size = $client->size($key);
        if (is_int($size)) {
            $steps[] = ['ok' => true, 'name' => '查询文件', 'msg' => '可以识别存储桶里已有的文件，不会重复上传'];
        } else {
            $steps[] = ['ok' => false, 'warn' => true, 'name' => '查询文件', 'msg' => '密钥没有查询权限（HeadObject）：同步时识别不了存储桶里已有的文件，会直接重新上传'];
        }

        $public_url = $this->public_base() . '/' . $name;
        $res = wp_remote_get($public_url, ['timeout' => 15, 'redirection' => 2]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code === 200) {
            $steps[] = ['ok' => true, 'name' => '公开访问', 'msg' => '访客能正常打开 COS 上的图片'];
        } elseif ($code === 403) {
            $steps[] = ['ok' => false, 'name' => '公开访问', 'msg' => '访客打不开图片（403）。请在 COS 控制台把存储桶权限改为「公有读私有写」，用了 CDN 的话检查 CDN 回源设置'];
        } else {
            $why = is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . $code;
            $steps[] = ['ok' => false, 'warn' => true, 'name' => '公开访问', 'msg' => '没法确认访客能否打开（' . $why . '）。请检查「访问域名」是否填对：' . $public_url];
        }

        if ($client->delete($key) === true) {
            $steps[] = ['ok' => true, 'name' => '删除文件', 'msg' => '在媒体库删图时，COS 上的文件会一起删除'];
        } else {
            $steps[] = ['ok' => false, 'warn' => true, 'name' => '删除文件', 'msg' => '密钥没有删除权限：在媒体库删图时，COS 上的文件不会跟着删（测试文件 ' . $name . ' 需要手动删除）'];
        }

        $ok = true;
        foreach ($steps as $s) {
            if (!$s['ok'] && empty($s['warn'])) {
                $ok = false;
            }
        }
        wp_send_json_success(['ok' => $ok, 'steps' => $steps]);
    }

    /** 列出所有还没同步到当前存储桶的附件 ID */
    public function ajax_pending()
    {
        $this->ajax_guard();
        $this->ajax_need_config();
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
             WHERE p.post_type = 'attachment' AND m.meta_id IS NULL
             ORDER BY p.ID ASC",
            self::META_DEST,
            $this->target()
        ));
        wp_send_json_success(array_map('intval', $ids));
    }

    /** 同步指定的几个附件 */
    public function ajax_sync()
    {
        $this->ajax_guard();
        $this->ajax_need_config();
        if ($this->opt('mode') === 'local') {
            wp_send_json_error('本站模式下不能同步，请先切换到「使用 COS」');
        }
        @set_time_limit(300);
        $ids = array_slice(array_map('intval', (array) ($_POST['ids'] ?? [])), 0, 5);

        $done = 0;
        $failed = [];
        $skipped = [];
        foreach ($ids as $id) {
            if (get_post_type($id) !== 'attachment' || $this->is_synced($id)) {
                $done++;
                continue;
            }
            $r = $this->sync_attachment($id);
            if (is_wp_error($r)) {
                $failed[] = ['id' => $id, 'msg' => get_the_title($id) . '：' . $r->get_error_message()];
            } else {
                $done++;
                foreach ($r as $local) {
                    $skipped[] = $local;
                }
            }
        }

        wp_send_json_success(['done' => $done, 'failed' => $failed, 'skipped' => $skipped]);
    }

    public function ajax_check_update()
    {
        $this->ajax_guard();
        if (!current_user_can('update_plugins')) {
            wp_send_json_error('当前账号没有更新插件的权限');
        }
        wp_send_json_success(Cos_Uploader_Updater::instance()->status(!empty($_POST['force'])));
    }

    /* ================= 后台页面 ================= */

    public function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE)) . '">设置</a>');
        return $links;
    }

    public function add_menu()
    {
        add_options_page('COS 图片上传', 'COS 图片上传', 'manage_options', self::PAGE, [$this, 'render_page']);
    }

    public function register_settings()
    {
        register_setting('cos_uploader', self::OPTION, ['sanitize_callback' => [$this, 'sanitize']]);
    }

    public function enqueue($hook)
    {
        if ($hook !== 'settings_page_' . self::PAGE) {
            return;
        }
        $url = plugin_dir_url(COSU_FILE) . 'assets/';
        wp_enqueue_style('cos-uploader', $url . 'admin.css', [], COSU_VERSION);
        wp_enqueue_script('cos-uploader', $url . 'admin.js', [], COSU_VERSION, true);
        wp_localize_script('cos-uploader', 'COSU', [
            'ajax'     => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cos_uploader'),
            'editBase' => admin_url('post.php?action=edit&post='),
        ]);
    }

    private static function regions()
    {
        return [
            'ap-beijing' => '北京', 'ap-nanjing' => '南京', 'ap-shanghai' => '上海', 'ap-guangzhou' => '广州',
            'ap-chengdu' => '成都', 'ap-chongqing' => '重庆', 'ap-hongkong' => '中国香港',
            'ap-shenzhen-fsi' => '深圳金融', 'ap-shanghai-fsi' => '上海金融', 'ap-beijing-fsi' => '北京金融',
            'ap-singapore' => '新加坡', 'ap-tokyo' => '东京', 'ap-seoul' => '首尔', 'ap-bangkok' => '曼谷',
            'ap-jakarta' => '雅加达', 'ap-mumbai' => '孟买', 'na-siliconvalley' => '硅谷', 'na-ashburn' => '弗吉尼亚',
            'na-toronto' => '多伦多', 'sa-saopaulo' => '圣保罗', 'eu-frankfurt' => '法兰克福',
        ];
    }

    public function sanitize($input)
    {
        $old = (array) get_option(self::OPTION, []);
        $old += ['secret_key' => '', 'region' => 'ap-guangzhou', 'bucket' => '', 'prefix' => 'wp-content/uploads'];
        $input = (array) $input;

        $out = [
            'mode'            => ($input['mode'] ?? '') === 'local' ? 'local' : 'cos',
            'secret_id'       => preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['secret_id'] ?? '')),
            'secret_key'      => $old['secret_key'],
            'region'          => $old['region'],
            'bucket'          => $old['bucket'],
            'domain'          => '',
            'prefix'          => $old['prefix'],
            'replace_content' => empty($input['replace_content']) ? 0 : 1,
        ];

        // SecretKey：留空表示不修改；已经是加密格式（数据升级时）原样保留
        $key = trim((string) ($input['secret_key'] ?? ''));
        if ($key !== '') {
            $out['secret_key'] = strpos($key, 'enc1:') === 0 ? $key : self::encrypt(preg_replace('/\s+/', '', $key));
        }

        $region = (string) ($input['region'] ?? '');
        if (preg_match('/^[a-z]+(-[a-z0-9]+)+$/', $region)) {
            $out['region'] = $region;
        } else {
            add_settings_error(self::OPTION, 'region', '地域格式不对，已保留原来的设置');
        }

        $bucket = strtolower(trim((string) ($input['bucket'] ?? '')));
        if ($bucket === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?-\d+$/', $bucket)) {
            $out['bucket'] = $bucket;
        } else {
            add_settings_error(self::OPTION, 'bucket', '存储桶名称格式不对，应该像 mysite-1250000000（名称-APPID），已保留原来的设置');
        }

        $domain = trim((string) ($input['domain'] ?? ''));
        if ($domain !== '') {
            if (!preg_match('#^https?://#i', $domain)) {
                $domain = 'https://' . $domain;
            }
            $domain = esc_url_raw(rtrim($domain, '/'), ['http', 'https']);
            if ($domain === '' || !wp_parse_url($domain, PHP_URL_HOST)) {
                add_settings_error(self::OPTION, 'domain', '访问域名格式不对，已清空（将使用 COS 默认域名）');
                $domain = '';
            }
        }
        $out['domain'] = $domain;

        // 目录：去掉 ./、../ 和奇怪字符
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', (string) ($input['prefix'] ?? ''))) as $seg) {
            $seg = preg_replace('/[^\p{L}\p{N}_\-.]/u', '', $seg);
            if ($seg !== '' && trim($seg, '.') !== '') {
                $parts[] = $seg;
            }
        }
        $out['prefix'] = implode('/', $parts);

        $this->opts = null;
        $this->client = null;
        return $out;
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o = $this->opts();
        $configured = $this->configured();
        $stats = $this->stats();
        $update = Cos_Uploader_Updater::instance()->status(false, false);
        $last_error = get_option(self::LAST_ERROR);
        $is_local = $o['mode'] === 'local';
        $field = function ($k) {
            return esc_attr(self::OPTION . '[' . $k . ']');
        };
        require __DIR__ . '/includes/admin-page.php';
    }
}

// 等 WordPress 核心函数（如 wp_salt）都加载完再初始化，否则解密密钥时会报错
add_action('plugins_loaded', ['Cos_Uploader', 'init']);
