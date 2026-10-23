/**
 * ---------------------------------------------------------------------
 *
 * Minimal Effort Markdown - editor toggle for every GLPI rich-text editor
 *
 * - Adds a "Markdown" switch above every TinyMCE editor in a form
 *   (KB articles, ticket descriptions, followups, tasks, solutions, ...).
 * - Markdown mode is ON by default.
 * - Markdown -> HTML: marked (GitHub-flavored: tables, fenced code, ...).
 *   HTML -> Markdown: turndown + turndown-plugin-gfm.
 *   Libraries are bundled in public/js/vendor (no CDN, works air-gapped).
 * - The original markdown is posted as `_memarkdown_source[<field>]` and stored
 *   server-side, so editing an item later reopens the exact markdown written.
 *
 * @copyright 2024 HellrazorX64
 * @license   MIT
 * ---------------------------------------------------------------------
 */

(function () {
    'use strict';

    if (window.MEMarkdown) {
        return;
    }

    const DEFAULT_ON = true;
    const attached = new WeakSet();
    let counter = 0;

    /* ---------------- conversion ---------------- */

    function markdownToHtml(markdown) {
        if (typeof window.marked === 'undefined') {
            console.error('MEMarkdown: marked library not loaded');
            return markdown;
        }
        let html = window.marked.parse(markdown || '', { gfm: true, breaks: false, async: false });
        html = html.replace(/<table>/g, '<table class="table table-bordered table-striped">');
        html = html.replace(/<blockquote>/g, '<blockquote class="blockquote">');
        html = html.replace(/<a href="(https?:\/\/[^"]*)"/g, '<a href="$1" target="_blank" rel="noopener noreferrer"');
        return renderCallouts(html);
    }

    /* ---------------- callouts (Obsidian / GitHub alert syntax) ----------------
     * > [!summary] Optional title          > [!NOTE]
     * > Body text                           > Body text
     * "[!type]-" renders collapsed, "[!type]+" expanded (foldable <details>).
     * Inline styles are added so callouts still look right if the plugin is disabled.
     * Icons come from GLPI's own bundled Tabler icon font (CSS ::before), no emoji/system fonts.
     */
    const CALLOUT_TYPES = {};
    [
        [['note'], 'Note', '#448aff'],
        [['abstract', 'summary', 'tldr'], 'Summary', '#00b0ff'],
        [['info'], 'Info', '#00b8d4'],
        [['todo'], 'Todo', '#00b8d4'],
        [['tip', 'hint'], 'Tip', '#00bfa5'],
        [['important'], 'Important', '#a371f7'],
        [['success', 'check', 'done'], 'Success', '#00c853'],
        [['question', 'help', 'faq'], 'Question', '#64dd17'],
        [['warning', 'attention'], 'Warning', '#ff9100'],
        [['caution'], 'Caution', '#ff5252'],
        [['failure', 'fail', 'missing'], 'Failure', '#ff5252'],
        [['danger', 'error'], 'Danger', '#ff1744'],
        [['bug'], 'Bug', '#f50057'],
        [['example'], 'Example', '#7c4dff'],
        [['quote', 'cite'], 'Quote', '#9e9e9e'],
    ].forEach(function (def) {
        def[0].forEach(function (name) {
            CALLOUT_TYPES[name] = { type: def[0][0], label: def[1], color: def[2] };
        });
    });

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
    }

    function renderCallouts(html) {
        if (html.indexOf('[!') === -1) return html;
        const tpl = document.createElement('template');
        tpl.innerHTML = html;
        // Build new nodes in the template's inert document: innerHTML on nodes owned by
        // the live document would load <img>/<svg> and fire onerror/onload handlers (XSS).
        const doc = tpl.content.ownerDocument;

        // innermost first, so nested callouts work
        const quotes = Array.from(tpl.content.querySelectorAll('blockquote')).reverse();
        quotes.forEach(function (bq) {
            const first = bq.firstElementChild;
            if (!first || first.nodeName !== 'P') return;
            const m = first.innerHTML.match(/^\s*\[!([A-Za-z][\w-]*)\]([+-]?)[ \t]*/);
            if (!m) return;

            const key = m[1].toLowerCase();
            const def = CALLOUT_TYPES[key] || { type: key, label: m[1].charAt(0).toUpperCase() + m[1].slice(1).toLowerCase(), color: '#448aff' };
            const fold = m[2];

            // title = rest of the first line, body = everything after it
            const rest = first.innerHTML.slice(m[0].length);
            const br = rest.search(/\n|<br\s*\/?>/i);
            const titleHtml = (br === -1 ? rest : rest.slice(0, br)).trim() || def.label;
            const bodyFirst = br === -1 ? '' : rest.slice(br).replace(/^(\n|<br\s*\/?>)/i, '').trim();

            const box = doc.createElement(fold ? 'details' : 'div');
            box.className = 'memd-callout memd-callout-' + def.type;
            box.setAttribute('style',
                'border-left: 4px solid ' + def.color + '; background: ' + hexToRgba(def.color, 0.1) + ';' +
                ' border-radius: 4px; padding: .6rem .9rem; margin: 0 0 1rem;');
            if (fold === '+') box.setAttribute('open', 'open');

            const title = doc.createElement(fold ? 'summary' : 'p');
            title.className = 'memd-callout-title';
            title.setAttribute('style', 'font-weight: 600; margin: 0 0 .35rem; color: ' + def.color + ';' + (fold ? ' cursor: pointer;' : ''));
            title.innerHTML = titleHtml;
            box.appendChild(title);

            if (bodyFirst) {
                const p = doc.createElement('p');
                p.innerHTML = bodyFirst;
                box.appendChild(p);
            }
            first.remove();
            while (bq.firstChild) box.appendChild(bq.firstChild);
            // no trailing margin on the last paragraph
            const last = box.lastElementChild;
            if (last && last !== title && last.nodeName === 'P') last.style.marginBottom = '0';

            bq.replaceWith(box);
        });
        return tpl.innerHTML;
    }

    let turndown = null;
    function htmlToMarkdown(html) {
        if (typeof window.TurndownService === 'undefined') {
            console.error('MEMarkdown: turndown library not loaded');
            return html;
        }
        if (!turndown) {
            turndown = new window.TurndownService({
                headingStyle: 'atx',
                codeBlockStyle: 'fenced',
                fence: '```',
                bulletListMarker: '-',
                emDelimiter: '*',
                strongDelimiter: '**',
                hr: '---',
            });
            if (window.turndownPluginGfm) {
                turndown.use(window.turndownPluginGfm.gfm);
            }
            addCalloutRule(turndown);
            // keep the language of fenced blocks (<pre><code class="language-bash">)
            turndown.addRule('fencedWithLang', {
                filter: function (node) {
                    return node.nodeName === 'PRE' && node.firstChild && node.firstChild.nodeName === 'CODE';
                },
                replacement: function (content, node) {
                    const code = node.firstChild;
                    const m = (code.getAttribute('class') || '').match(/language-(\S+)/);
                    const text = code.textContent.replace(/\n$/, '');
                    return '\n\n```' + (m ? m[1] : '') + '\n' + text + '\n```\n\n';
                },
            });
        }
        // TinyMCE hands back absolute URLs; keep links to this GLPI server relative
        const origin = window.location.origin;
        return turndown.turndown(html || '').split('](' + origin + '/').join('](/');
    }

    function addCalloutRule(td) {
        // callouts back to "> [!type] title" syntax
        td.addRule('callout', {
            filter: function (node) {
                return (node.nodeName === 'DIV' || node.nodeName === 'DETAILS')
                    && /(^|\s)memd-callout(\s|$)/.test(node.getAttribute('class') || '');
            },
            replacement: function (content, node) {
                const m = (node.getAttribute('class') || '').match(/memd-callout-([\w-]+)/);
                const type = m ? m[1] : 'note';
                const fold = node.nodeName === 'DETAILS' ? (node.hasAttribute('open') ? '+' : '-') : '';
                const clone = node.cloneNode(true);
                const titleEl = clone.querySelector('.memd-callout-title');
                let title = titleEl ? titleEl.textContent.trim() : '';
                if (titleEl) titleEl.remove();
                const def = CALLOUT_TYPES[type];
                if (def && title === def.label) title = '';
                const body = td.turndown(clone.innerHTML).trim();
                const lines = ['[!' + type + ']' + fold + (title ? ' ' + title : '')].concat(body ? body.split('\n') : []);
                return '\n\n' + lines.map(function (l) { return l ? '> ' + l : '>'; }).join('\n') + '\n\n';
            },
        });
    }

    /* ---------------- helpers ---------------- */

    function getEditors() {
        if (typeof tinymce === 'undefined') return [];
        // TinyMCE 6/7 removed tinymce.editors; get() with no args returns all editors
        const all = typeof tinymce.get === 'function' ? tinymce.get() : null;
        if (Array.isArray(all)) return all;
        return tinymce.editors || [];
    }

    function librariesReady() {
        return typeof window.marked !== 'undefined' && typeof window.TurndownService !== 'undefined';
    }

    /** Markdown stored server-side for this form/field, or null. */
    function storedSource(form, field) {
        if (!form) return null;
        const nodes = form.querySelectorAll('script.memarkdown-source');
        for (const el of nodes) {
            try {
                const data = JSON.parse(el.textContent);
                if (data && data.sources && typeof data.sources[field] === 'string') {
                    return data.sources[field];
                }
            } catch (e) { /* ignore */ }
        }
        return null;
    }

    function eligible(editor) {
        if (!editor || !editor.initialized || attached.has(editor)) return false;
        const target = editor.targetElm;
        if (!target || target.nodeName !== 'TEXTAREA' || !target.name) return false;
        if (!target.closest('form')) return false;
        if (editor.mode && typeof editor.mode.isReadOnly === 'function' && editor.mode.isReadOnly()) return false;
        // Skip GLPI 11 Forms builder, it manages its own editors dynamically
        if (target.closest('[data-glpi-form-editor-container], [data-glpi-form-editor]')) return false;
        const container = editor.getContainer();
        return !!(container && container.parentNode);
    }

    /* ---------------- per-editor controller ---------------- */

    function attach(editor) {
        if (!eligible(editor) || !librariesReady()) return;
        attached.add(editor);

        const target = editor.targetElm;
        const field = target.name;
        const form = target.closest('form');
        const container = editor.getContainer();
        const uid = 'memarkdown-' + (++counter);

        const st = { enabled: false, textarea: null, timer: null };

        // toggle bar
        const bar = document.createElement('div');
        bar.className = 'markdown-toggle-container';
        bar.innerHTML =
            '<div class="form-check form-switch markdown-switch mb-0">' +
            '  <input class="form-check-input" type="checkbox" id="' + uid + '">' +
            '  <label class="form-check-label" for="' + uid + '"><i class="ti ti-markdown"></i> Markdown</label>' +
            '</div>' +
            '<small class="markdown-help text-muted" style="display:none">' +
            '**bold** · *italic* · # heading · [link](url) · `code` · ```lang fenced``` · - list · 1. list · &gt; quote · | tables | · ---' +
            '</small>';
        container.parentNode.insertBefore(bar, container);
        const checkbox = bar.querySelector('input');
        const help = bar.querySelector('.markdown-help');

        // hidden input carrying the markdown source ('' = forget stored source)
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = '_memarkdown_source[' + field + ']';
        hidden.value = '';
        form.appendChild(hidden);

        function sync() {
            if (!st.textarea) return;
            editor.setContent(markdownToHtml(st.textarea.value));
            editor.save(); // write to the underlying <textarea> (required-field checks, submit)
            hidden.value = st.textarea.value;
        }

        function enable(initialMarkdown) {
            if (st.enabled) return;
            st.enabled = true;
            const md = typeof initialMarkdown === 'string' ? initialMarkdown : htmlToMarkdown(editor.getContent());

            const ta = document.createElement('textarea');
            ta.className = 'form-control markdown-textarea';
            ta.spellcheck = false;
            ta.rows = Math.max(8, Math.min(30, md.split('\n').length + 3));
            ta.value = md;
            ta.setAttribute('aria-label', 'Markdown');
            ta.addEventListener('input', function () {
                clearTimeout(st.timer);
                st.timer = setTimeout(sync, 300);
            });
            container.style.display = 'none';
            container.parentNode.insertBefore(ta, container.nextSibling);
            st.textarea = ta;

            hidden.value = md;
            help.style.display = '';
            checkbox.checked = true;
        }

        function disable() {
            if (!st.enabled) return;
            clearTimeout(st.timer);
            sync();
            st.enabled = false;
            st.textarea.remove();
            st.textarea = null;
            hidden.value = '';
            container.style.display = '';
            help.style.display = 'none';
            checkbox.checked = false;
        }

        function beforeSubmit() {
            if (!st.enabled) return;
            clearTimeout(st.timer);
            sync();
        }

        checkbox.addEventListener('change', function () {
            if (this.checked) { enable(); } else { disable(); }
        });

        // Capture phase so the editor is up to date before GLPI/TinyMCE read it.
        form.addEventListener('submit', beforeSubmit, true);
        form.addEventListener('click', function (e) {
            if (e.target.closest('button[type="submit"], input[type="submit"], button:not([type])')) {
                beforeSubmit();
            }
        }, true);

        const source = storedSource(form, field);
        if (source !== null) {
            enable(source);
        } else if (DEFAULT_ON) {
            enable();
        }

        editor.on('remove', function () {
            bar.remove();
            hidden.remove();
            if (st.textarea) st.textarea.remove();
        });
    }

    function scan() {
        getEditors().forEach(attach);
    }

    function boot() {
        if (typeof tinymce !== 'undefined' && typeof tinymce.on === 'function' && !boot.hooked) {
            boot.hooked = true;
            tinymce.on('AddEditor', function (e) {
                e.editor.on('init', function () { setTimeout(function () { attach(e.editor); }, 0); });
            });
        }
        scan();
    }

    window.MEMarkdown = { markdownToHtml: markdownToHtml, htmlToMarkdown: htmlToMarkdown, scan: scan };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    // Timeline forms (followups, tasks, solutions) are injected later; keep looking.
    setInterval(boot, 1000);
})();
