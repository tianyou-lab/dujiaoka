{{-- 全局居中模态弹窗组件，提供 window.showAlert / window.showConfirm 替代浏览器原生 alert/confirm --}}
<style>
    .qh-dialog-mask {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity .18s ease;
    }
    .qh-dialog-mask.qh-show { opacity: 1; }

    .qh-dialog-box {
        width: min(420px, calc(100vw - 32px));
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        overflow: hidden;
        transform: translateY(8px) scale(.98);
        transition: transform .18s ease;
        font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
    }
    .qh-dialog-mask.qh-show .qh-dialog-box { transform: translateY(0) scale(1); }

    .qh-dialog-header {
        padding: 18px 22px 6px;
        font-size: 16px;
        font-weight: 600;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .qh-dialog-header .qh-dialog-icon {
        width: 22px; height: 22px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 700;
        flex-shrink: 0;
    }
    .qh-dialog-header.qh-info .qh-dialog-icon { background: #dbeafe; color: #2563eb; }
    .qh-dialog-header.qh-warn .qh-dialog-icon { background: #fef3c7; color: #d97706; }
    .qh-dialog-header.qh-error .qh-dialog-icon { background: #fee2e2; color: #dc2626; }
    .qh-dialog-header.qh-confirm .qh-dialog-icon { background: #ede9fe; color: #7c3aed; }

    .qh-dialog-body {
        padding: 8px 22px 20px;
        font-size: 14px;
        line-height: 1.65;
        color: #334155;
        word-break: break-word;
        max-height: 60vh;
        overflow-y: auto;
    }

    .qh-dialog-footer {
        padding: 12px 18px 16px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        background: #f8fafc;
        border-top: 1px solid #f1f5f9;
    }
    .qh-dialog-btn {
        min-width: 76px;
        padding: 7px 16px;
        border-radius: 8px;
        border: 0;
        font-size: 13.5px;
        font-weight: 500;
        cursor: pointer;
        transition: all .15s ease;
        line-height: 1.4;
    }
    .qh-dialog-btn-cancel {
        background: #fff;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    .qh-dialog-btn-cancel:hover { background: #f1f5f9; }
    .qh-dialog-btn-ok {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
    }
    .qh-dialog-btn-ok:hover { filter: brightness(1.05); transform: translateY(-1px); }
    .qh-dialog-btn-ok:active { transform: translateY(0); }

    @media (max-width: 480px) {
        .qh-dialog-box { width: calc(100vw - 24px); }
        .qh-dialog-header { padding: 16px 18px 4px; font-size: 15px; }
        .qh-dialog-body { padding: 6px 18px 16px; font-size: 13.5px; }
    }
</style>

<script>
(function () {
    if (window.showAlert && window.showConfirm) return;

    function buildDialog(opts) {
        var type = opts.type || 'info';
        var iconMap = { info: 'i', warn: '!', error: '×', confirm: '?' };
        var titleMap = { info: '提示', warn: '警告', error: '错误', confirm: '确认' };
        var title = opts.title || titleMap[type] || '提示';
        var message = String(opts.message == null ? '' : opts.message);
        var okText = opts.okText || '确定';
        var cancelText = opts.cancelText || '取消';
        var showCancel = !!opts.showCancel;

        var mask = document.createElement('div');
        mask.className = 'qh-dialog-mask';
        mask.innerHTML = ''
            + '<div class="qh-dialog-box" role="dialog" aria-modal="true">'
            +   '<div class="qh-dialog-header qh-' + type + '">'
            +     '<span class="qh-dialog-icon">' + iconMap[type] + '</span>'
            +     '<span class="qh-dialog-title"></span>'
            +   '</div>'
            +   '<div class="qh-dialog-body"></div>'
            +   '<div class="qh-dialog-footer">'
            +     (showCancel ? '<button type="button" class="qh-dialog-btn qh-dialog-btn-cancel"></button>' : '')
            +     '<button type="button" class="qh-dialog-btn qh-dialog-btn-ok"></button>'
            +   '</div>'
            + '</div>';

        mask.querySelector('.qh-dialog-title').textContent = title;
        mask.querySelector('.qh-dialog-body').textContent = message;
        mask.querySelector('.qh-dialog-btn-ok').textContent = okText;
        if (showCancel) mask.querySelector('.qh-dialog-btn-cancel').textContent = cancelText;

        document.body.appendChild(mask);
        requestAnimationFrame(function () { mask.classList.add('qh-show'); });
        return mask;
    }

    function close(mask) {
        mask.classList.remove('qh-show');
        setTimeout(function () { if (mask.parentNode) mask.parentNode.removeChild(mask); }, 200);
    }

    window.showAlert = function (message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var mask = buildDialog({
                type: opts.type || 'info',
                title: opts.title,
                message: message,
                okText: opts.okText || '确定',
                showCancel: false,
            });
            var done = function () { close(mask); resolve(true); };
            mask.querySelector('.qh-dialog-btn-ok').addEventListener('click', done);
            mask.addEventListener('keydown', function (e) { if (e.key === 'Escape') done(); });
            setTimeout(function () { mask.querySelector('.qh-dialog-btn-ok').focus(); }, 50);
        });
    };

    window.showConfirm = function (message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var mask = buildDialog({
                type: opts.type || 'confirm',
                title: opts.title,
                message: message,
                okText: opts.okText || '确定',
                cancelText: opts.cancelText || '取消',
                showCancel: true,
            });
            var ok = function () { close(mask); resolve(true); };
            var cancel = function () { close(mask); resolve(false); };
            mask.querySelector('.qh-dialog-btn-ok').addEventListener('click', ok);
            mask.querySelector('.qh-dialog-btn-cancel').addEventListener('click', cancel);
            mask.addEventListener('click', function (e) { if (e.target === mask) cancel(); });
            document.addEventListener('keydown', function escHandler(e) {
                if (!mask.parentNode) { document.removeEventListener('keydown', escHandler); return; }
                if (e.key === 'Escape') { document.removeEventListener('keydown', escHandler); cancel(); }
            });
            setTimeout(function () { mask.querySelector('.qh-dialog-btn-ok').focus(); }, 50);
        });
    };
})();
</script>
