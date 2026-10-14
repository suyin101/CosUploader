/* COS 图片上传 - 设置页脚本 */
(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };
    if (!$('cosu-total') || !window.COSU) {
        return;
    }

    function esc(t) {
        return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    /** 调用后台接口；返回不是 JSON 时（超时、PHP 报错等）把原因带出来 */
    function post(action, data) {
        var body = new URLSearchParams({ action: action, nonce: COSU.nonce });
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) {
                v.forEach(function (x) { body.append(k + '[]', x); });
            } else {
                body.append(k, v);
            }
        });
        return fetch(COSU.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
            return r.text().then(function (text) {
                var json;
                try { json = JSON.parse(text); } catch (e) {
                    var snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
                    throw new Error('HTTP ' + r.status + (snippet ? '：' + snippet : '（服务器没有返回内容，可能是处理超时）'));
                }
                if (!json.success) {
                    throw new Error(typeof json.data === 'string' ? json.data : '操作失败');
                }
                return json.data;
            });
        });
    }

    function loading(btn, on) {
        btn.disabled = on;
        btn.classList.toggle('is-loading', on);
    }

    /* ---------------- 标签页 ---------------- */
    var tabs = document.querySelectorAll('.cosu-tab');
    function showTab(name) {
        var found = false;
        tabs.forEach(function (t) { if (t.dataset.tab === name) { found = true; } });
        if (!found) { name = 'settings'; }
        tabs.forEach(function (t) {
            var on = t.dataset.tab === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.cosu-panel').forEach(function (p) {
            p.hidden = p.dataset.panel !== name;
        });
        if (name === 'update') { initUpdate(); }
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            history.replaceState(null, '', '#' + t.dataset.tab);
            showTab(t.dataset.tab);
        });
    });
    window.addEventListener('hashchange', function () { showTab(location.hash.slice(1)); });
    showTab(location.hash.slice(1));

    /* ---------------- 顶部统计 ---------------- */
    var synced = +$('cosu-synced').dataset.n, pending = +$('cosu-pending').dataset.n, totalAll = +$('cosu-pct').dataset.total;
    function bumpStats(n) {
        synced += n;
        pending = Math.max(0, pending - n);
        $('cosu-synced').textContent = synced.toLocaleString();
        $('cosu-pending').textContent = pending.toLocaleString();
        $('cosu-pending').classList.toggle('is-amber', pending > 0);
        var pct = totalAll ? Math.floor(synced * 100 / totalAll) : 0;
        $('cosu-pct').textContent = pct + '%';
        $('cosu-pct-bar').style.width = pct + '%';
    }

    /* ---------------- 测试连接 ---------------- */
    $('cosu-test').addEventListener('click', function () {
        var btn = this, list = $('cosu-test-result');
        loading(btn, true);
        list.innerHTML = '<li class="cosu-muted">测试中，大约需要几秒…</li>';
        post('cos_uploader_test').then(function (d) {
            list.innerHTML = d.steps.map(function (s) {
                var cls = s.ok ? 'is-ok' : (s.warn ? 'is-warn' : 'is-bad');
                var icon = s.ok ? '✓' : (s.warn ? '!' : '✕');
                return '<li class="' + cls + '"><i class="i">' + icon + '</i><b>' + esc(s.name) + '</b><span>' + esc(s.msg) + '</span></li>';
            }).join('');
        }).catch(function (err) {
            list.innerHTML = '<li class="is-bad"><i class="i">✕</i><b>测试失败</b><span>' + esc(err.message) + '</span></li>';
        }).then(function () { loading(btn, false); });
    });

    /* ---------------- 同步 ---------------- */
    var stopFlag = false;
    $('cosu-stop').addEventListener('click', function () {
        stopFlag = true;
        this.disabled = true;
        this.textContent = '正在暂停…';
    });

    $('cosu-sync').addEventListener('click', function () {
        var btn = this, stop = $('cosu-stop'), msg = $('cosu-sync-msg'), bar = $('cosu-sync-bar');
        var errs = $('cosu-sync-errors'), skippedBox = $('cosu-sync-skipped-box'), skippedList = $('cosu-sync-skipped');
        var ok = 0, failed = 0, skipped = 0, queue = [], all = 0;

        stopFlag = false;
        loading(btn, true);
        stop.hidden = false; stop.disabled = false; stop.textContent = '暂停';
        errs.hidden = true; errs.innerHTML = '';
        skippedBox.hidden = true; skippedList.innerHTML = ''; $('cosu-sync-skipped-n').textContent = '0';
        msg.textContent = '正在统计…';

        function fail(id, text) {
            failed++;
            errs.hidden = false;
            errs.insertAdjacentHTML('beforeend', '<div><a href="' + esc(COSU.editBase + id) + '" target="_blank" rel="noopener">#' + id + '</a> ' + esc(text) + '</div>');
        }
        function handle(d) {
            ok += d.done;
            bumpStats(d.done);
            d.failed.forEach(function (f) { fail(f.id, f.msg); });
            (d.skipped || []).forEach(function (f) {
                skipped++;
                skippedBox.hidden = false;
                $('cosu-sync-skipped-n').textContent = skipped;
                skippedList.insertAdjacentHTML('beforeend', '<div>' + esc(f) + '</div>');
            });
        }
        function progress() {
            var doneN = all - queue.length;
            bar.querySelector('span').style.width = (all ? doneN * 100 / all : 100) + '%';
            msg.textContent = '已处理 ' + doneN + ' / ' + all + '（成功 ' + ok + '，失败 ' + failed + '）';
        }
        function finish(text) {
            loading(btn, false);
            stop.hidden = true;
            msg.textContent = text;
        }
        /** 出错先等 3 秒重试一次 */
        function syncIds(ids) {
            return post('cos_uploader_sync', { ids: ids }).catch(function () {
                return wait(3000).then(function () { return post('cos_uploader_sync', { ids: ids }); });
            });
        }
        function next() {
            progress();
            if (stopFlag) {
                return finish('已暂停：成功 ' + ok + ' 个，剩余 ' + queue.length + ' 个，点「开始同步」继续');
            }
            if (!queue.length) {
                return finish(all === 0 ? '所有文件都已经在 COS 上了 ✓'
                    : '完成！成功 ' + ok + ' 个' + (failed ? '，失败 ' + failed + ' 个（见下方，点编号可以打开那张图）' : ' ✓'));
            }
            var ids = queue.splice(0, 3);
            syncIds(ids).then(handle).then(next, function () {
                // 整批失败：拆成单个处理，找出是哪一个出的问题，其他的继续
                var chain = Promise.resolve();
                ids.forEach(function (id) {
                    chain = chain.then(function () {
                        return syncIds([id]).then(handle, function (err) { fail(id, '请求失败：' + err.message); });
                    });
                });
                chain.then(next);
            });
        }

        post('cos_uploader_pending').then(function (ids) {
            queue = ids; all = ids.length;
            bar.hidden = all === 0;
            next();
        }).catch(function (err) {
            finish('出错了：' + err.message);
        });
    });

    /* ---------------- 更新 ---------------- */
    var updateInited = false;
    function renderUpdate(s) {
        var box = $('cosu-update');
        box.innerHTML =
            '<div>当前版本<strong>v' + esc(s.current) + '</strong></div>' +
            '<div class="' + (s.has_update ? 'is-new' : '') + '">最新版本<strong>' + (s.latest ? 'v' + esc(s.latest) : '—') + '</strong></div>';

        var go = $('cosu-do-update');
        go.hidden = !s.update_url;
        if (s.update_url) {
            go.href = s.update_url;
            go.textContent = '立即更新到 v' + s.latest;
        }
        $('cosu-new-dot').hidden = !s.has_update;
        $('cosu-new-pill').hidden = !s.has_update;
        $('cosu-new-pill').querySelector('span').textContent = '有新版本 v' + s.latest;

        var m = $('cosu-check-msg');
        if (s.error) {
            m.textContent = s.error;
        } else if (s.has_update && !s.update_url) {
            m.textContent = '发现新版本，点「检查更新」准备安装';
        } else if (s.latest && !s.has_update) {
            m.textContent = '已是最新版本' + (s.checked ? '（' + s.checked + '检查）' : '');
        } else {
            m.textContent = s.checked ? s.checked + '检查' : '';
        }

        var log = $('cosu-changelog');
        if (!s.changelog || !s.changelog.length) {
            log.innerHTML = '<p class="cosu-muted">' + (s.error ? '暂时获取不到更新日志' : '暂无更新日志') + '</p>';
            return;
        }
        // html 由服务器端转义生成
        log.innerHTML = s.changelog.map(function (r) {
            var tag = r.current ? '<span class="cosu-pill is-green">当前</span>' : (r.newer ? '<span class="cosu-pill is-blue">新</span>' : '');
            return '<div class="cosu-rel"><div class="cosu-rel-head"><b>v' + esc(r.version) + '</b>' + tag + '<small>' + esc(r.date) + '</small></div>' + r.html + '</div>';
        }).join('');
    }
    function checkUpdate(force) {
        var btn = $('cosu-check');
        loading(btn, true);
        $('cosu-check-msg').textContent = '正在检查…';
        return post('cos_uploader_check_update', { force: force ? 1 : 0 }).then(renderUpdate).catch(function (err) {
            $('cosu-check-msg').textContent = '检查失败：' + err.message;
        }).then(function () { loading(btn, false); });
    }
    function initUpdate() {
        if (updateInited) { return; }
        updateInited = true;
        var s = {};
        try { s = JSON.parse($('cosu-update').dataset.status || '{}'); } catch (e) { /* 忽略 */ }
        if (s.pending) {
            checkUpdate(false);
        } else {
            renderUpdate(s);
        }
    }
    $('cosu-check').addEventListener('click', function () { checkUpdate(true); });
    $('cosu-do-update').addEventListener('click', function () {
        this.classList.add('is-loading');
        this.textContent = '正在跳转到更新页面…';
    });
})();
