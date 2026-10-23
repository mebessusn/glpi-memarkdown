/**
 * Minimal Effort Markdown - one-click "Copy" button on rendered code blocks
 * (KB articles, ticket descriptions, followups, tasks, solutions ...).
 *
 * Works on plain-HTTP intranet servers too: navigator.clipboard only exists in
 * secure contexts (HTTPS/localhost), so it falls back to execCommand('copy').
 *
 * @license MIT
 */
(function () {
    'use strict';

    if (window.MEMarkdownCodeCopy) return;

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallbackCopy(text); });
        }
        return Promise.resolve(fallbackCopy(text));
    }

    function flash(btn, ok) {
        const label = btn.querySelector('span');
        btn.classList.toggle('copied', ok);
        label.textContent = ok ? 'Copied' : 'Copy failed';
        clearTimeout(btn._t);
        btn._t = setTimeout(function () {
            btn.classList.remove('copied');
            label.textContent = 'Copy';
        }, 1500);
    }

    function decorate(pre) {
        if (pre.dataset.memdCopy) return;
        // not inside editors / our markdown textarea / editable areas
        if (pre.closest('.tox, .tox-tinymce, [contenteditable="true"], textarea')) return;
        pre.dataset.memdCopy = '1';

        const wrap = document.createElement('div');
        wrap.className = 'memd-code-wrap';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'memd-copy-btn';
        btn.title = 'Copy to clipboard';
        btn.innerHTML = '<i class="ti ti-copy"></i><span>Copy</span>';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const code = pre.querySelector('code') || pre;
            copyText(code.innerText.replace(/\n$/, '')).then(function (ok) { flash(btn, ok); });
        });
        wrap.appendChild(btn);
    }

    function scan(root) {
        (root || document).querySelectorAll('pre').forEach(decorate);
    }

    function boot() {
        scan(document);
        // timeline items, tabs and modals are loaded dynamically
        let pending = null;
        new MutationObserver(function () {
            if (pending) return;
            pending = setTimeout(function () { pending = null; scan(document); }, 200);
        }).observe(document.body, { childList: true, subtree: true });
    }

    window.MEMarkdownCodeCopy = { scan: scan };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
