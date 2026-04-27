/* Divi JSON Converter v2 — Admin JS */
(function () {
    'use strict';

    /* ── DOM refs ──────────────────────────────────────────────────── */
    const fileInput     = document.getElementById('eth-djc-file-input');
    const dropzone      = document.getElementById('eth-djc-dropzone');
    const fileInfo      = document.getElementById('eth-djc-file-info');
    const fileName      = document.getElementById('eth-djc-file-name');
    const clearBtn      = document.getElementById('eth-djc-clear-file');
    const convertBtn    = document.getElementById('eth-djc-convert-btn');
    const progressWrap  = document.getElementById('eth-djc-progress');
    const progressBar   = document.getElementById('eth-djc-progress-bar');
    const progressLabel = document.getElementById('eth-djc-progress-label');
    const errorBox      = document.getElementById('eth-djc-error');
    const uploadCard    = document.getElementById('eth-djc-upload-card');
    const resultCard    = document.getElementById('eth-djc-result-card');
    const statsEl       = document.getElementById('eth-djc-stats');
    const previewEl     = document.getElementById('eth-djc-preview');
    const copyBtn       = document.getElementById('eth-djc-copy-btn');
    const downloadBtn   = document.getElementById('eth-djc-download-btn');
    const resetBtn      = document.getElementById('eth-djc-reset-btn');
    const expandAllBtn  = document.getElementById('eth-djc-expand-all');
    const collapseAllBtn= document.getElementById('eth-djc-collapse-all');
    const searchInput   = document.getElementById('eth-djc-search');

    let convertedJSON = '';

    /* ── File selection ────────────────────────────────────────────── */
    function setFile(file) {
        if (!file) return;
        fileName.textContent = file.name + '  (' + (file.size / 1024).toFixed(1) + ' KB)';
        fileInfo.classList.add('visible');
        dropzone.classList.add('has-file');
        convertBtn.disabled = false;
        hideError();
    }

    function clearFile() {
        fileInput.value = '';
        fileInfo.classList.remove('visible');
        dropzone.classList.remove('has-file');
        convertBtn.disabled = true;
    }

    fileInput.addEventListener('change', () => setFile(fileInput.files[0]));
    clearBtn.addEventListener('click', e => { e.stopPropagation(); clearFile(); });

    dropzone.addEventListener('dragover',  e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            fileInput.files = dt.files;
            setFile(e.dataTransfer.files[0]);
        }
    });

    /* ── Error helpers ─────────────────────────────────────────────── */
    function showError(msg) {
        errorBox.innerHTML = '<span class="dashicons dashicons-warning"></span>' + escHtml(msg);
        errorBox.classList.add('visible');
    }
    function hideError() { errorBox.classList.remove('visible'); }

    /* ── Convert (AJAX) ────────────────────────────────────────────── */
    convertBtn.addEventListener('click', () => {
        if (!fileInput.files[0]) return;
        hideError();
        startProgress();

        const fd = new FormData();
        fd.append('action', window.ETH_DJC.action);
        fd.append('nonce', window.ETH_DJC.nonce);
        fd.append('file',          fileInput.files[0]);
        fd.append('remove_images', document.getElementById('eth-djc-remove-images').checked ? '1' : '0');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.ETH_DJC.ajax_url);

        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 50); // 0–50% for upload
                setProgress(pct, 'Uploading…');
            }
        });

        xhr.addEventListener('load', () => {
            try {
                const res = JSON.parse(xhr.responseText);
                if (!res.success) {
                    stopProgress();
                    showError(res.data || 'An unknown error occurred.');
                    return;
                }
                setProgress(90, 'Rendering preview…');
                setTimeout(() => {
                    convertedJSON = res.data.json;
                    renderResult(convertedJSON);
                    renderCSSPanels(JSON.parse(convertedJSON));
                    stopProgress();
                    resultCard.classList.add('visible');
                    resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 50);
            } catch (e) {
                stopProgress();
                showError('Server error. Raw response: ' + xhr.responseText.substring(0, 200));
            }
        });

        xhr.addEventListener('error', () => { stopProgress(); showError('Network error. Please try again.'); });
        xhr.send(fd);
    });

    /* ── Progress bar ──────────────────────────────────────────────── */
    function startProgress() {
        convertBtn.disabled = true;
        convertBtn.innerHTML = '<span class="dashicons dashicons-update djc-spin"></span> Converting…';
        progressWrap.classList.add('visible');
        setProgress(10, 'Uploading…');
    }
    function stopProgress() {
        progressWrap.classList.remove('visible');
        convertBtn.innerHTML = '<span class="dashicons dashicons-controls-repeat"></span> Convert';
        convertBtn.disabled = false;
    }
    function setProgress(pct, label) {
        progressBar.style.width = pct + '%';
        if (label) progressLabel.textContent = label;
    }

    /* ── Render result ─────────────────────────────────────────────── */
    function renderResult(jsonStr) {
        const parsed = JSON.parse(jsonStr);

        // Stats
        const stats = countStats(parsed);
        statsEl.innerHTML =
            stat('Sections',  stats.sections)  +
            stat('Rows',      stats.rows)       +
            stat('Columns',   stats.columns)    +
            stat('Modules',   stats.modules)    +
            stat('Total blocks', stats.total)   +
            stat('File size', formatBytes(new Blob([jsonStr]).size));

        // Syntax-highlighted, collapsible preview
        previewEl.innerHTML = highlightJSON(jsonStr);
        attachToggleListeners();
    }

    function stat(label, val) {
        return `<span class="eth-djc-stat"><strong>${val}</strong>${label}</span>`;
    }

    function countStats(obj) {
        const s = { sections:0, rows:0, columns:0, modules:0, total:0 };
        function walk(node) {
            if (Array.isArray(node)) { node.forEach(walk); return; }
            if (typeof node !== 'object' || !node) return;
            if (node.type === 'section') s.sections++;
            else if (node.type === 'row') s.rows++;
            else if (node.type === 'column') s.columns++;
            else if (node.type && !['section','row','column'].includes(node.type)) s.modules++;
            if (node.type) s.total++;
            if (node.children) walk(node.children);
            if (node.data) Object.values(node.data).forEach(walk);
        }
        walk(obj);
        return s;
    }

    function formatBytes(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
        return (b/1048576).toFixed(1) + ' MB';
    }

    /* ── Syntax highlighter ────────────────────────────────────────── */
    function highlightJSON(jsonStr) {
        const lines = jsonStr.split('\n');
        let out = '';
        let nodeId = 0;

        // Track brace/bracket depth to detect collapsible blocks
        const openStack = []; // stack of {id, indent}

        for (let i = 0; i < lines.length; i++) {
            const raw = lines[i];
            const trimmed = raw.trimStart();
            const indent  = raw.length - trimmed.length;

            // Detect opening of collapsible block
            const opensBrace   = trimmed.endsWith('{')  || trimmed.endsWith('{,');
            const opensBracket = trimmed.endsWith('[')  || trimmed.endsWith('[,');
            const closesBlock  = trimmed.startsWith('}') || trimmed.startsWith(']');

            let lineHtml = '';

            if (opensBrace || opensBracket) {
                const id = 'djn' + (++nodeId);
                const bracket = trimmed.endsWith('[') || trimmed.endsWith('[,') ? '[' : '{';
                const trailing = (trimmed.endsWith(',')) ? ',' : '';
                const prefix = raw.substring(0, raw.lastIndexOf(bracket));

                lineHtml =
                    '<span class="eth-djc-indent">' + escHtml(' '.repeat(indent)) + '</span>' +
                    colourLine(prefix.trimStart()) +
                    '<span class="eth-djc-toggle" data-target="' + id + '">' +
                        '<span class="eth-djc-json-punct">' + bracket + '</span>' +
                    '</span>' +
                    '<span class="eth-djc-ellipsis" id="ell-' + id + '">…</span>' +
                    '<div class="eth-djc-collapsible" id="' + id + '">';

                openStack.push({ id, indent, trailing });
            } else if (closesBlock) {
                const top = openStack.pop();
                const bracket = trimmed.startsWith('}') ? '}' : ']';
                const trailing = trimmed.endsWith(',') ? ',' : '';

                lineHtml =
                    '</div>' + // close djc-collapsible
                    '<span class="eth-djc-json-punct">' +
                        ' '.repeat(indent) + bracket + trailing +
                    '</span>';
            } else {
                lineHtml = '<span class="eth-djc-indent">' + escHtml(' '.repeat(indent)) + '</span>' + colourLine(trimmed);
            }

            out += lineHtml + '\n';
        }

        return out;
    }

    /* Colour a single trimmed JSON line */
    function colourLine(line) {
        // Key: "someKey":
        line = line.replace(
            /^("((?:[^"\\]|\\.)*)"\s*:)/,
            (match, full, key) => {
                const cls = specialKeyClass(key);
                return '<span class="eth-djc-json-key ' + cls + '">' + escHtml('"' + key + '"') + '</span><span class="eth-djc-json-punct">:</span>';
            }
        );
        // String value
        line = line.replace(
            /:\s*("(?:[^"\\]|\\.)*")(,?)$/,
            (_, v, comma) =>
                ': <span class="eth-djc-json-string">' + escHtml(v) + '</span>' +
                (comma ? '<span class="eth-djc-json-punct">,</span>' : '')
        );
        // Number value
        line = line.replace(
            /:\s*(-?\d+\.?\d*)(,?)$/,
            (_, v, comma) =>
                ': <span class="eth-djc-json-number">' + escHtml(v) + '</span>' +
                (comma ? '<span class="eth-djc-json-punct">,</span>' : '')
        );
        // Boolean / null
        line = line.replace(
            /:\s*(true|false|null)(,?)$/,
            (_, v, comma) =>
                ': <span class="eth-djc-json-' + (v==='null'?'null':'bool') + '">' + v + '</span>' +
                (comma ? '<span class="eth-djc-json-punct">,</span>' : '')
        );
        // Bare string (array item)
        line = line.replace(
            /^("(?:[^"\\]|\\.)*")(,?)$/,
            (_, v, comma) =>
                '<span class="eth-djc-json-string">' + escHtml(v) + '</span>' +
                (comma ? '<span class="eth-djc-json-punct">,</span>' : '')
        );
        // Bare number (array item)
        line = line.replace(
            /^(-?\d+\.?\d*)(,?)$/,
            (_, v, comma) =>
                '<span class="eth-djc-json-number">' + escHtml(v) + '</span>' +
                (comma ? '<span class="eth-djc-json-punct">,</span>' : '')
        );
        return line;
    }

    function specialKeyClass(key) {
        if (key === '_start')      return 'eth-djc-k-start';
        if (key === '_end')        return 'eth-djc-k-end';
        if (key === '_breadcrumb') return 'eth-djc-k-breadcrumb';
        if (key === '_index')      return 'eth-djc-k-index';
        if (key === 'type')        return 'eth-djc-k-type';
        return '';
    }

    /* ── Collapse / expand ─────────────────────────────────────────── */
    function attachToggleListeners() {
        previewEl.querySelectorAll('.eth-djc-toggle').forEach(toggle => {
            toggle.addEventListener('click', () => {
                const targetId = toggle.dataset.target;
                const content  = document.getElementById(targetId);
                const ellipsis = document.getElementById('ell-' + targetId);
                const collapsed = toggle.classList.toggle('collapsed');
                content.classList.toggle('hidden', collapsed);
                ellipsis.classList.toggle('visible', collapsed);
            });
        });
    }

    expandAllBtn.addEventListener('click', () => {
        previewEl.querySelectorAll('.eth-djc-toggle').forEach(t => { t.classList.remove('collapsed'); });
        previewEl.querySelectorAll('.eth-djc-collapsible').forEach(c => { c.classList.remove('hidden'); });
        previewEl.querySelectorAll('.eth-djc-ellipsis').forEach(e => { e.classList.remove('visible'); });
    });

    collapseAllBtn.addEventListener('click', () => {
        previewEl.querySelectorAll('.eth-djc-toggle').forEach(t => { t.classList.add('collapsed'); });
        previewEl.querySelectorAll('.eth-djc-collapsible').forEach(c => { c.classList.add('hidden'); });
        previewEl.querySelectorAll('.eth-djc-ellipsis').forEach(e => { e.classList.add('visible'); });
    });

    /* ── Search / highlight ────────────────────────────────────────── */
    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            // Remove old highlights
            previewEl.querySelectorAll('.eth-djc-hl').forEach(el => {
                el.replaceWith(document.createTextNode(el.textContent));
            });
            previewEl.normalize();

            const q = searchInput.value.trim();
            if (!q) return;

            highlightText(previewEl, q);
            // Auto-expand parents of first match
            const first = previewEl.querySelector('.eth-djc-hl');
            if (first) {
                let el = first;
                while (el && el !== previewEl) {
                    if (el.classList && el.classList.contains('eth-djc-collapsible')) {
                        el.classList.remove('hidden');
                        const toggle = previewEl.querySelector('[data-target="' + el.id + '"]');
                        if (toggle) {
                            toggle.classList.remove('collapsed');
                            const ell = document.getElementById('ell-' + el.id);
                            if (ell) ell.classList.remove('visible');
                        }
                    }
                    el = el.parentElement;
                }
                // Scroll the overflow container to the match
                const previewOuter = document.getElementById('eth-djc-preview-outer') || previewEl.parentElement;
                const firstRect = first.getBoundingClientRect();
                const outerRect = previewOuter.getBoundingClientRect();
                const relativeTop = first.offsetTop - previewOuter.offsetTop;
                previewOuter.scrollTo({ top: relativeTop - (previewOuter.clientHeight / 3), behavior: 'smooth' });
            }
        }, 250);
    });

    function highlightText(root, query) {
        const lq = query.toLowerCase();
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let n;
        while ((n = walker.nextNode())) nodes.push(n);
        nodes.forEach(node => {
            const idx = node.textContent.toLowerCase().indexOf(lq);
            if (idx === -1) return;
            const range = document.createRange();
            range.setStart(node, idx);
            range.setEnd(node, idx + query.length);
            const span = document.createElement('span');
            span.className = 'eth-djc-hl';
            range.surroundContents(span);
        });
    }

    /* ── Copy ──────────────────────────────────────────────────────── */
    copyBtn.addEventListener('click', () => {
        copyToClipboard(convertedJSON);
    });

    function copyToClipboard(text) {
        // Modern API (HTTPS / secure context)
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text)
                .then(() => onCopied())
                .catch(() => fallbackCopy(text));
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        // Works over plain HTTP — create a hidden textarea, select, execCommand
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        let success = false;
        try { success = document.execCommand('copy'); } catch (e) { success = false; }
        document.body.removeChild(ta);
        if (success) { onCopied(); } else { onCopyFailed(); }
    }

    function onCopied() {
        copyBtn.classList.add('eth-djc-copied');
        copyBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> Copied!';
        setTimeout(() => {
            copyBtn.classList.remove('eth-djc-copied');
            copyBtn.innerHTML = '<span class="dashicons dashicons-clipboard"></span> Copy JSON';
        }, 2000);
    }

    function onCopyFailed() {
        copyBtn.innerHTML = '<span class="dashicons dashicons-warning"></span> Copy failed';
        setTimeout(() => {
            copyBtn.innerHTML = '<span class="dashicons dashicons-clipboard"></span> Copy JSON';
        }, 2500);
    }

    /* ── Download ──────────────────────────────────────────────────── */
    downloadBtn.addEventListener('click', () => {
        const blob = new Blob([convertedJSON], { type: 'application/json' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'divi-layout-converted-' + timestamp() + '.json';
        a.click();
        URL.revokeObjectURL(url);
    });

    /* ── Reset ─────────────────────────────────────────────────────── */
    resetBtn.addEventListener('click', () => {
        resultCard.classList.remove('visible');
        clearFile();
        convertedJSON = '';
        previewEl.innerHTML = '';
        searchInput.value = '';
        hideError();
        document.getElementById('eth-djc-freeform-list').innerHTML = '';
        document.getElementById('eth-djc-element-list').innerHTML  = '';
        document.getElementById('eth-djc-invalid-css-list').innerHTML  = '';
        document.getElementById('eth-djc-invalid-html-list').innerHTML = '';
        document.getElementById('eth-djc-badge-freeform').textContent    = '0';
        document.getElementById('eth-djc-badge-element').textContent     = '0';
        document.getElementById('eth-djc-badge-invalid-css').textContent     = '0';
        document.getElementById('eth-djc-badge-invalid-html').textContent = '0';
        // Reset to full tab
        document.querySelectorAll('.eth-djc-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.eth-djc-tab-panel').forEach(p => p.style.display = 'none');
        document.querySelector('[data-tab="full"]').classList.add('active');
        document.getElementById('eth-djc-panel-full').style.display = 'block';
        uploadCard.scrollIntoView({ behavior:'smooth', block:'start' });
    });

    /* ── Helpers ───────────────────────────────────────────────────── */
    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    function timestamp() {
        const d = new Date();
        return [d.getFullYear(), pad(d.getMonth()+1), pad(d.getDate()),
                pad(d.getHours()), pad(d.getMinutes()), pad(d.getSeconds())].join('');
    }
    function pad(n) { return String(n).padStart(2,'0'); }

    /* ── Tab switching ─────────────────────────────────────────────── */
    document.querySelectorAll('.eth-djc-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.eth-djc-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.eth-djc-tab-panel').forEach(p => p.style.display = 'none');
            tab.classList.add('active');
            document.getElementById('eth-djc-panel-' + tab.dataset.tab).style.display = 'block';
        });
    });

    /* ── CSS panel rendering ───────────────────────────────────────── */
    function renderCSSPanels(parsed) {
        const freeFormList   = document.getElementById('eth-djc-freeform-list');
        const elementList    = document.getElementById('eth-djc-element-list');
        const invalidList    = document.getElementById('eth-djc-invalid-css-list');
        const invalidHtmlList= document.getElementById('eth-djc-invalid-html-list');
        const badgeFree      = document.getElementById('eth-djc-badge-freeform');
        const badgeElem      = document.getElementById('eth-djc-badge-element');
        const badgeInvalid   = document.getElementById('eth-djc-badge-invalid-css');
        const badgeInvHtml   = document.getElementById('eth-djc-badge-invalid-html');

        const freeFormData   = parsed.free_form_css  || [];
        const elementData    = parsed.element_css    || [];
        const invalidData    = parsed.invalid_css    || [];
        const invalidHtmlData= parsed.invalid_html   || [];

        badgeFree.textContent     = freeFormData.length;
        badgeElem.textContent     = elementData.length;
        badgeInvalid.textContent  = invalidData.length;
        badgeInvHtml.textContent  = invalidHtmlData.length;

        freeFormList.innerHTML    = freeFormData.map(e => buildCSSCard(e, true)).join('');
        elementList.innerHTML     = elementData.map(e => buildCSSCard(e, false)).join('');
        invalidList.innerHTML     = invalidData.length
            ? invalidData.map(e => buildInvalidCard(e)).join('')
            : '<div class="eth-djc-no-issues"><span class="dashicons dashicons-yes-alt"></span> No invalid CSS detected.</div>';
        invalidHtmlList.innerHTML = invalidHtmlData.length
            ? invalidHtmlData.map(e => buildInvalidHtmlCard(e)).join('')
            : '<div class="eth-djc-no-issues"><span class="dashicons dashicons-yes-alt"></span> No invalid HTML detected.</div>';
    }

    function buildInvalidHtmlCard(entry) {
        const idx   = entry._index || {};
        const pills = Object.entries(idx).map(([k,v]) => {
            if (typeof v === 'object') {
                return Object.entries(v).map(([sk,sv]) =>
                    `<span class="eth-djc-css-index-pill"><strong>${escH(sk)}:</strong> ${escH(String(sv))}</span>`
                ).join('');
            }
            return `<span class="eth-djc-css-index-pill"><strong>${escH(k)}:</strong> ${escH(String(v))}</span>`;
        }).join('');

        const issuesHtml = (entry.issues || []).map(iss => {
            const sev  = iss.severity === 'error' ? 'eth-djc-issue--error' : 'eth-djc-issue--warning';
            const icon = iss.severity === 'error' ? 'warning' : 'info-outline';
            const ln   = iss.line ? `<span class="eth-djc-issue-line">Line ${iss.line}</span>` : '';
            const snip = iss.snippet ? `<code class="eth-djc-issue-snippet">${escH(iss.snippet)}</code>` : '';
            return `<div class="eth-djc-issue ${sev}">
                <span class="dashicons dashicons-${icon}"></span>
                <div class="eth-djc-issue-body">
                    <div class="eth-djc-issue-msg">${ln}${escH(iss.issue)}</div>
                    ${snip}
                </div>
            </div>`;
        }).join('');

        const snippet = entry.html_snippet
            ? `<div class="eth-djc-invalid-snippet-wrap">
                <div class="eth-djc-css-bp-header" style="background:#1e1e2e;border-top:1px solid #2d2d3f;">
                    <span class="eth-djc-css-bp-label desktop">HTML source</span>
                    <span class="eth-djc-css-sub-key">${escH(entry.field || '')}</span>
                </div>
                <pre class="eth-djc-css-code djc-css-code--compact">${escH(entry.html_snippet)}</pre>
              </div>`
            : '';

        return `<div class="eth-djc-css-entry djc-invalid-entry">
            <div class="eth-djc-css-entry-header">
                <div class="eth-djc-css-entry-meta">
                    <div class="eth-djc-css-entry-title">
                        <span class="eth-djc-css-type-badge djc-src-badge djc-src--html">HTML</span>
                        <span class="eth-djc-css-type-badge">${escH(entry.component_type || '')}</span>
                        <span class="eth-djc-css-label">${escH(entry.label || '')}</span>
                    </div>
                    <div class="eth-djc-css-breadcrumb">${escH(entry._breadcrumb || '')}</div>
                    <div class="eth-djc-css-index" style="margin-top:5px">
                        <span class="eth-djc-css-index-pill"><strong>field:</strong> ${escH(entry.field || '')}</span>
                    </div>
                </div>
                <div class="eth-djc-css-index">${pills}</div>
            </div>
            <div class="eth-djc-issues-list">${issuesHtml}</div>
            ${snippet}
        </div>`;
    }

    function buildInvalidCard(entry) {
        const idx   = entry._index || {};
        const pills = Object.entries(idx).map(([k,v]) => {
            if (typeof v === 'object') {
                return Object.entries(v).map(([sk,sv]) =>
                    `<span class="eth-djc-css-index-pill"><strong>${escH(sk)}:</strong> ${escH(String(sv))}</span>`
                ).join('');
            }
            return `<span class="eth-djc-css-index-pill"><strong>${escH(k)}:</strong> ${escH(String(v))}</span>`;
        }).join('');

        const sourceLabels = {
            code_module:    { label:'Code Module',    cls:'eth-djc-src--code'     },
            free_form_css:  { label:'Free Form CSS',  cls:'eth-djc-src--freeform' },
            element_css:    { label:'Element CSS',    cls:'eth-djc-src--element'  },
        };
        const src = sourceLabels[entry.source] || { label: entry.source, cls:'' };

        const meta = [
            entry.breakpoint ? `<span class="eth-djc-css-index-pill"><strong>breakpoint:</strong> ${escH(entry.breakpoint)}</span>` : '',
            entry.css_field  ? `<span class="eth-djc-css-index-pill"><strong>field:</strong> ${escH(entry.css_field)}</span>`  : '',
        ].filter(Boolean).join('');

        const issuesHtml = (entry.issues || []).map(iss => {
            const sev = iss.severity === 'error' ? 'eth-djc-issue--error' : 'eth-djc-issue--warning';
            const icon = iss.severity === 'error' ? 'warning' : 'info-outline';
            const ln   = iss.line ? `<span class="eth-djc-issue-line">Line ${iss.line}</span>` : '';
            const snip = iss.snippet ? `<code class="eth-djc-issue-snippet">${escH(iss.snippet)}</code>` : '';
            return `<div class="eth-djc-issue ${sev}">
                <span class="dashicons dashicons-${icon}"></span>
                <div class="eth-djc-issue-body">
                    <div class="eth-djc-issue-msg">${ln}${escH(iss.issue)}</div>
                    ${snip}
                </div>
            </div>`;
        }).join('');

        const snippet = entry.css_snippet
            ? `<div class="eth-djc-invalid-snippet-wrap"><pre class="eth-djc-css-code djc-css-code--compact">${escH(entry.css_snippet)}</pre></div>`
            : '';

        return `<div class="eth-djc-css-entry djc-invalid-entry">
            <div class="eth-djc-css-entry-header">
                <div class="eth-djc-css-entry-meta">
                    <div class="eth-djc-css-entry-title">
                        <span class="eth-djc-css-type-badge djc-src-badge ${src.cls}">${src.label}</span>
                        <span class="eth-djc-css-type-badge">${escH(entry.component_type || '')}</span>
                        <span class="eth-djc-css-label">${escH(entry.label || '')}</span>
                    </div>
                    <div class="eth-djc-css-breadcrumb">${escH(entry._breadcrumb || '')}</div>
                    ${meta ? `<div class="eth-djc-css-index" style="margin-top:5px">${meta}</div>` : ''}
                </div>
                <div class="eth-djc-css-index">${pills}</div>
            </div>
            <div class="eth-djc-issues-list">${issuesHtml}</div>
            ${snippet}
        </div>`;
    }

    function buildCSSCard(entry, isFreeForm) {
        const idx   = entry._index || {};
        const pills = Object.entries(idx).map(([k,v]) => {
            if (typeof v === 'object') {
                return Object.entries(v).map(([sk,sv]) =>
                    `<span class="eth-djc-css-index-pill"><strong>${escH(sk)}:</strong> ${escH(String(sv))}</span>`
                ).join('');
            }
            return `<span class="eth-djc-css-index-pill"><strong>${escH(k)}:</strong> ${escH(String(v))}</span>`;
        }).join('');

        const cssBlocks = (entry.css || []).map(bp => {
            const bpClass = escH(bp.breakpoint || 'desktop');
            if (isFreeForm) {
                return `<div class="eth-djc-css-bp">
                    <div class="eth-djc-css-bp-header"><span class="eth-djc-css-bp-label ${bpClass}">${bpClass}</span></div>
                    <pre class="eth-djc-css-code">${escH(bp.freeForm || '')}</pre>
                </div>`;
            } else {
                const subHtml = Object.entries(bp).filter(([k]) => k !== 'breakpoint').map(([k,v]) =>
                    `<div class="eth-djc-css-subfield">
                        <div class="eth-djc-css-subfield-label">${escH(k)}</div>
                        <pre class="eth-djc-css-code">${escH(v)}</pre>
                    </div>`
                ).join('');
                return `<div class="eth-djc-css-bp">
                    <div class="eth-djc-css-bp-header"><span class="eth-djc-css-bp-label ${bpClass}">${bpClass}</span></div>
                    ${subHtml}
                </div>`;
            }
        }).join('');

        return `<div class="eth-djc-css-entry">
            <div class="eth-djc-css-entry-header">
                <div class="eth-djc-css-entry-meta">
                    <div class="eth-djc-css-entry-title">
                        <span class="eth-djc-css-type-badge">${escH(entry.component_type || '')}</span>
                        <span class="eth-djc-css-label">${escH(entry.label || '')}</span>
                    </div>
                    <div class="eth-djc-css-breadcrumb">${escH(entry._breadcrumb || '')}</div>
                </div>
                <div class="eth-djc-css-index">${pills}</div>
            </div>
            <div class="eth-djc-css-breakpoints">${cssBlocks}</div>
        </div>`;
    }

    function escH(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})();
