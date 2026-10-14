<?php
/**
 * 设置页模板，由 Cos_Uploader::render_page() 引入。
 * 可用变量：$o $configured $stats $update $last_error $is_local $field
 */

if (!defined('ABSPATH')) {
    exit;
}
$pct = $stats['total'] ? (int) floor($stats['synced'] * 100 / $stats['total']) : 0;
?>
<div class="wrap cosu">
    <h1 class="screen-reader-text">COS 图片上传</h1>

    <header class="cosu-hero">
        <div class="cosu-hero-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26"><path fill="currentColor" d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
        </div>
        <div class="cosu-hero-text">
            <div class="cosu-title">COS 图片上传 <span class="cosu-ver">v<?php echo esc_html(COSU_VERSION); ?></span></div>
            <div class="cosu-sub">把媒体库自动同步到腾讯云对象存储，随时可以切回本站</div>
        </div>
        <div class="cosu-hero-status">
            <?php if (!$configured) : ?>
                <span class="cosu-pill is-gray">未配置</span>
            <?php elseif ($is_local) : ?>
                <span class="cosu-pill is-amber">本站模式</span>
            <?php else : ?>
                <span class="cosu-pill is-green">COS 已启用</span>
            <?php endif; ?>
            <a class="cosu-pill is-blue" id="cosu-new-pill" href="#update" <?php echo empty($update['has_update']) ? 'hidden' : ''; ?>><span>有新版本 v<?php echo esc_html($update['latest']); ?></span></a>
        </div>
    </header>

    <?php if ($this->secret_broken()) : ?>
        <div class="cosu-alert is-red">保存的 SecretKey 无法解密（可能是站点的安全密钥被更换过），请在下方重新填写 SecretKey 并保存。</div>
    <?php endif; ?>
    <?php if (is_array($last_error) && !empty($last_error['time']) && time() - $last_error['time'] < 3 * DAY_IN_SECONDS && $configured && !$is_local) : ?>
        <div class="cosu-alert is-amber">
            <strong>最近一次自动上传失败</strong>（<?php echo esc_html(human_time_diff($last_error['time'])); ?>前）：<?php echo esc_html($last_error['msg']); ?>
            <br>没传上去的图片会继续使用本站地址，不影响访问；到「同步」页点一下同步即可补传。
        </div>
    <?php endif; ?>

    <section class="cosu-stats">
        <div class="cosu-stat">
            <div class="cosu-stat-label">媒体文件</div>
            <div class="cosu-stat-num" id="cosu-total"><?php echo esc_html(number_format_i18n($stats['total'])); ?></div>
        </div>
        <div class="cosu-stat">
            <div class="cosu-stat-label">已在 COS</div>
            <div class="cosu-stat-num is-green" id="cosu-synced" data-n="<?php echo (int) $stats['synced']; ?>"><?php echo esc_html(number_format_i18n($stats['synced'])); ?></div>
        </div>
        <div class="cosu-stat">
            <div class="cosu-stat-label">待同步</div>
            <div class="cosu-stat-num <?php echo $stats['pending'] ? 'is-amber' : ''; ?>" id="cosu-pending" data-n="<?php echo (int) $stats['pending']; ?>"><?php echo esc_html(number_format_i18n($stats['pending'])); ?></div>
        </div>
        <div class="cosu-stat">
            <div class="cosu-stat-label">同步进度</div>
            <div class="cosu-stat-num" id="cosu-pct" data-total="<?php echo (int) $stats['total']; ?>"><?php echo $pct; ?>%</div>
            <div class="cosu-bar is-thin"><span id="cosu-pct-bar" style="width:<?php echo $pct; ?>%"></span></div>
        </div>
    </section>

    <nav class="cosu-tabs" role="tablist">
        <button type="button" class="cosu-tab" data-tab="settings" role="tab">基本设置</button>
        <button type="button" class="cosu-tab" data-tab="sync" role="tab">同步</button>
        <button type="button" class="cosu-tab" data-tab="update" role="tab">更新 <i class="cosu-dot" id="cosu-new-dot" <?php echo empty($update['has_update']) ? 'hidden' : ''; ?>></i></button>
    </nav>

    <!-- ============ 基本设置 ============ -->
    <div class="cosu-panel" data-panel="settings">
        <form method="post" action="options.php" autocomplete="off">
            <?php settings_fields('cos_uploader'); ?>

            <div class="cosu-card">
                <div class="cosu-card-head">
                    <h2>图片使用</h2>
                    <p>随时可以切换，切换不会删除任何文件。</p>
                </div>
                <div class="cosu-choice">
                    <label class="cosu-choice-item">
                        <input type="radio" name="<?php echo $field('mode'); ?>" value="cos" <?php checked(!$is_local); ?>>
                        <span>
                            <strong>使用 COS</strong>
                            <em>新上传的图片自动传到 COS，前台用 COS 地址访问</em>
                        </span>
                    </label>
                    <label class="cosu-choice-item">
                        <input type="radio" name="<?php echo $field('mode'); ?>" value="local" <?php checked($is_local); ?>>
                        <span>
                            <strong>使用本站</strong>
                            <em>不再上传，所有图片（包括文章里的 COS 地址）都显示为本站地址</em>
                        </span>
                    </label>
                </div>
                <?php if ($is_local) : ?>
                    <p class="cosu-hint">本站模式期间上传的图片，切回 COS 后到「同步」页点一下同步即可补传。</p>
                <?php endif; ?>
            </div>

            <div class="cosu-card">
                <div class="cosu-card-head">
                    <h2>腾讯云账号</h2>
                    <p>在 <a href="https://console.cloud.tencent.com/cam/capi" target="_blank" rel="noopener noreferrer">访问管理 → API 密钥</a> 获取。建议新建一个<strong>只授权这个存储桶</strong>的子账号，用它的密钥更安全。</p>
                </div>
                <?php if ($this->keys_in_config()) : ?>
                    <div class="cosu-row">
                        <label>SecretId / SecretKey</label>
                        <div><span class="cosu-pill is-green">已在 wp-config.php 中设置</span></div>
                    </div>
                <?php else : ?>
                    <div class="cosu-row">
                        <label for="cosu-sid">SecretId</label>
                        <div><input id="cosu-sid" class="cosu-input" name="<?php echo $field('secret_id'); ?>" value="<?php echo esc_attr($o['secret_id']); ?>" autocomplete="off" spellcheck="false"></div>
                    </div>
                    <div class="cosu-row">
                        <label for="cosu-skey">SecretKey</label>
                        <div>
                            <input id="cosu-skey" class="cosu-input" type="password" name="<?php echo $field('secret_key'); ?>" placeholder="<?php echo $o['secret_key'] ? '●●●●●●●●  已加密保存，留空则不修改' : ''; ?>" autocomplete="new-password" spellcheck="false">
                            <p class="cosu-hint">加密后保存在数据库里，页面上不会再显示出来。</p>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="cosu-row">
                    <label for="cosu-region">所属地域</label>
                    <div>
                        <select id="cosu-region" class="cosu-input" name="<?php echo $field('region'); ?>">
                            <?php foreach (self::regions() as $k => $v) : ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($o['region'], $k); ?>><?php echo esc_html($v . '（' . $k . '）'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="cosu-row">
                    <label for="cosu-bucket">存储桶名称</label>
                    <div>
                        <input id="cosu-bucket" class="cosu-input" name="<?php echo $field('bucket'); ?>" value="<?php echo esc_attr($o['bucket']); ?>" placeholder="例如 mysite-1250000000" spellcheck="false">
                        <p class="cosu-hint">要带上后面的数字（APPID），和 COS 控制台里显示的一模一样。</p>
                    </div>
                </div>
            </div>

            <div class="cosu-card">
                <div class="cosu-card-head">
                    <h2>访问设置</h2>
                </div>
                <div class="cosu-row">
                    <label for="cosu-domain">访问域名</label>
                    <div>
                        <input id="cosu-domain" class="cosu-input" name="<?php echo $field('domain'); ?>" value="<?php echo esc_attr($o['domain']); ?>" placeholder="可选，例如 https://img.example.com" spellcheck="false">
                        <p class="cosu-hint">绑定了 CDN 或自定义域名就填这里；留空使用 COS 默认域名。</p>
                    </div>
                </div>
                <div class="cosu-row">
                    <label for="cosu-prefix">存放目录</label>
                    <div>
                        <input id="cosu-prefix" class="cosu-input" name="<?php echo $field('prefix'); ?>" value="<?php echo esc_attr($o['prefix']); ?>" spellcheck="false">
                        <p class="cosu-hint">文件在存储桶里的目录，默认 <code>wp-content/uploads</code>，和网站路径一致，方便迁移。<br>改了存储桶或目录后，要到「同步」页重新同步一次（桶里已有的文件会自动识别，不会重复上传）。</p>
                    </div>
                </div>
                <div class="cosu-row">
                    <label>文章旧链接</label>
                    <div>
                        <label class="cosu-switch">
                            <input type="checkbox" name="<?php echo $field('replace_content'); ?>" value="1" <?php checked($o['replace_content']); ?>>
                            <i></i>
                            <span>显示文章时，把本站图片地址换成 COS 地址</span>
                        </label>
                        <p class="cosu-hint">只换已经传到 COS 的图片；还没同步的、或不在媒体库里的图片继续用本站地址，不会出现打不开的情况。</p>
                    </div>
                </div>
            </div>

            <div class="cosu-actions">
                <button type="submit" class="cosu-btn is-primary">保存设置</button>
            </div>
        </form>
    </div>

    <!-- ============ 同步 ============ -->
    <div class="cosu-panel" data-panel="sync" hidden>
        <div class="cosu-card">
            <div class="cosu-card-head">
                <h2>测试连接</h2>
                <p>上传一个很小的测试文件，依次检查上传、查询、公开访问、删除，测完自动删掉。</p>
            </div>
            <button type="button" class="cosu-btn" id="cosu-test" <?php disabled(!$configured); ?>>开始测试</button>
            <ul class="cosu-steps" id="cosu-test-result"></ul>
        </div>

        <div class="cosu-card">
            <div class="cosu-card-head">
                <h2>同步已有文件</h2>
                <p>把媒体库里还不在 COS 上的文件补传上去。存储桶里已经有的会直接识别，不会重复上传；随时可以暂停或关掉页面，下次接着传。</p>
            </div>
            <div class="cosu-sync-row">
                <button type="button" class="cosu-btn is-primary" id="cosu-sync" <?php disabled(!$configured || $is_local); ?>>开始同步</button>
                <button type="button" class="cosu-btn" id="cosu-stop" hidden>暂停</button>
                <span class="cosu-muted" id="cosu-sync-msg"><?php
                    if (!$configured) {
                        echo '请先在「基本设置」里填好密钥和存储桶';
                    } elseif ($is_local) {
                        echo '本站模式下不能同步，请先切换到「使用 COS」';
                    }
                ?></span>
            </div>
            <div class="cosu-bar" id="cosu-sync-bar" hidden><span></span></div>
            <div class="cosu-log is-red" id="cosu-sync-errors" hidden></div>
            <details class="cosu-details" id="cosu-sync-skipped-box" hidden>
                <summary>跳过的缺失缩略图（<span id="cosu-sync-skipped-n">0</span> 个，本地和存储桶里都没有，不影响原图）</summary>
                <div class="cosu-log" id="cosu-sync-skipped"></div>
            </details>
        </div>
    </div>

    <!-- ============ 更新 ============ -->
    <div class="cosu-panel" data-panel="update" hidden>
        <div class="cosu-card">
            <div class="cosu-card-head">
                <h2>插件更新</h2>
                <p>新版本发布在 GitHub。下载后会核对文件指纹，对不上就拒绝安装。更新不影响设置和上传记录。</p>
            </div>
            <div class="cosu-update" id="cosu-update" data-status="<?php echo esc_attr(wp_json_encode($update)); ?>"></div>
            <div class="cosu-sync-row">
                <a class="cosu-btn is-primary" id="cosu-do-update" href="#" hidden>立即更新</a>
                <button type="button" class="cosu-btn" id="cosu-check">检查更新</button>
                <span class="cosu-muted" id="cosu-check-msg"></span>
            </div>
        </div>
        <div class="cosu-card">
            <div class="cosu-card-head"><h2>更新日志</h2></div>
            <div class="cosu-changelog" id="cosu-changelog"><p class="cosu-muted">加载中…</p></div>
        </div>
    </div>
</div>
