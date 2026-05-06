'use strict';

export const CHUNK_SIZE = 8 * 1024 * 1024;
export const MAX_RETRIES = 5;

export function backoffMs(attempt) {
    return Math.pow(2, Math.max(0, attempt - 1)) * 1000;
}

export function parseRangeHeader(header) {
    if (!header) return 0;
    const m = String(header).match(/bytes=\d+-(\d+)/);
    if (!m) return 0;
    return parseInt(m[1], 10) + 1;
}

export function preflight(file, cfg) {
    if (file.size > cfg.maxSize) {
        return { code: 'size_exceeded', max: cfg.maxSize };
    }
    if (cfg.mimes && cfg.mimes.length > 0) {
        const t = (file.type || '').toLowerCase();
        const ok = cfg.mimes.some(p => {
            const pat = String(p).toLowerCase().trim();
            if (!pat) return false;
            if (pat.endsWith('/*')) return t.startsWith(pat.slice(0, -1));
            return t === pat;
        });
        if (!ok) return { code: 'mime_not_allowed', allowed: cfg.mimes };
    }
    return null;
}

export class GFGCSUploader {
    constructor(host) {
        this.host = host;
        this.cfg = JSON.parse(host.dataset.gfgcsConfig || '{}');
        this.cfg.mimes = Array.isArray(this.cfg.mimes) ? this.cfg.mimes : [];
        this.dropzone = host.querySelector('.gfgcs-dropzone');
        this.list = host.querySelector('.gfgcs-files');
        this.hidden = host.querySelector('.gfgcs-hidden');
        this.files = [];
        this.submissionUuid = null;
        this.attachListeners();
    }

    attachListeners() {
        if (!this.dropzone) return;
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = !!this.cfg.multiple;
        if (Array.isArray(this.cfg.mimes) && this.cfg.mimes.length) {
            input.accept = this.cfg.mimes.join(',');
        }
        input.style.display = 'none';
        this.host.appendChild(input);
        this.input = input;

        this.dropzone.addEventListener('click', () => input.click());
        this.dropzone.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        input.addEventListener('change', () => {
            const fs = Array.from(input.files || []);
            this.acceptFiles(fs);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(ev => {
            this.dropzone.addEventListener(ev, e => { e.preventDefault(); this.dropzone.classList.add('is-drag'); });
        });
        ['dragleave', 'drop'].forEach(ev => {
            this.dropzone.addEventListener(ev, e => { e.preventDefault(); this.dropzone.classList.remove('is-drag'); });
        });
        this.dropzone.addEventListener('drop', e => {
            const fs = Array.from(e.dataTransfer?.files || []);
            this.acceptFiles(fs);
        });

        const form = this.host.closest('form');
        if (form) {
            this._watchForm(form);
        }
    }

    acceptFiles(fileList) {
        const errors = [];
        const room = (this.cfg.maxFiles || 1) - this.files.filter(f => f.state !== 'removed' && f.state !== 'error').length;
        if (fileList.length > room) {
            errors.push({ code: 'too_many_files', max: this.cfg.maxFiles });
            fileList = fileList.slice(0, Math.max(0, room));
        }
        fileList.forEach(file => {
            const err = preflight(file, this.cfg);
            const row = this._addRow(file, err);
            this.files.push({ file, state: err ? 'error' : 'queued', error: err, row, progressBar: row.querySelector('.gfgcs-progress-bar') });
            if (!err) this._startUpload(this.files[this.files.length - 1]);
        });
        this._refreshHidden();
        return errors;
    }

    _addRow(file, err) {
        const li = document.createElement('li');
        li.className = 'gfgcs-file' + (err ? ' is-error' : '');
        li.innerHTML =
            '<div class="gfgcs-name"></div>' +
            '<div class="gfgcs-progress"><div class="gfgcs-progress-bar"></div></div>' +
            '<div class="gfgcs-status"></div>' +
            '<button type="button" class="gfgcs-remove">Remove</button>';
        li.querySelector('.gfgcs-name').textContent = file.name + ' — ' + this._fmtSize(file.size);
        if (err) {
            li.querySelector('.gfgcs-status').textContent = this._errMsg(err);
        }
        li.querySelector('.gfgcs-remove').addEventListener('click', () => this._removeRow(li));
        this.list.appendChild(li);
        return li;
    }


    _watchForm(form) {
        const submit = form.querySelector('input[type=submit], button[type=submit]');
        if (!submit) return;
        const update = () => {
            const busy = this.files.some(f => f.state !== 'done' && f.state !== 'error' && f.state !== 'removed');
            submit.disabled = busy;
        };
        const obs = new MutationObserver(update);
        obs.observe(this.list, { childList: true, subtree: true });
        this._updateSubmitGate = update;
        window.addEventListener('beforeunload', e => {
            if (this.files.some(f => f.state === 'uploading' || f.state === 'retrying')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    _refreshHidden() {
        const done = this.files.filter(f => f.state === 'done' && f.descriptor).map(f => f.descriptor);
        this.hidden.value = done.length ? JSON.stringify(done) : '';
        if (this._updateSubmitGate) this._updateSubmitGate();
    }

    _fmtSize(n) {
        if (n < 1024) return n + ' B';
        const u = ['KB', 'MB', 'GB']; let i = -1;
        do { n = n / 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toFixed(1) + ' ' + u[i];
    }

    _errMsg(err) {
        switch (err && err.code) {
            case 'size_exceeded':    return 'File exceeds maximum size.';
            case 'mime_not_allowed': return 'File type not allowed.';
            case 'too_many_files':   return 'Too many files.';
            default:                 return 'Upload error.';
        }
    }
}

GFGCSUploader.prototype._initOnServer = async function (file) {
    const fd = new FormData();
    fd.append('action', 'gfgcs_init');
    fd.append('form_id', this.cfg.formId);
    fd.append('field_id', this.cfg.fieldId);
    fd.append('nonce', this.cfg.nonce);
    fd.append('filename', file.name);
    fd.append('size', String(file.size));
    fd.append('type', file.type || 'application/octet-stream');
    if (this.submissionUuid) fd.append('submission_uuid', this.submissionUuid);
    const res = await fetch(this.cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json();
    if (!res.ok || !j.success) {
        const code = j && j.data && j.data.code ? j.data.code : 'init_failed';
        throw Object.assign(new Error('init failed'), { code, data: j && j.data });
    }
    this.submissionUuid = j.data.submission_uuid;
    return j.data;
};

GFGCSUploader.prototype._openSession = async function (signedInitUrl) {
    const res = await fetch(signedInitUrl, {
        method: 'POST',
        headers: { 'x-goog-resumable': 'start', 'Content-Length': '0' },
    });
    if (res.status !== 201) {
        throw new Error('Failed to open resumable session: HTTP ' + res.status);
    }
    const sessionUri = res.headers.get('Location');
    if (!sessionUri) throw new Error('No Location header on session start response');
    return sessionUri;
};

GFGCSUploader.prototype._startUpload = async function (entry) {
    entry.state = 'initializing';
    entry.row.querySelector('.gfgcs-status').textContent = 'Preparing…';
    let init;
    try {
        init = await this._initOnServer(entry.file);
    } catch (e) {
        return this._fail(entry, e);
    }
    entry.objectPath = init.object_path;
    entry.fileUuid = init.file_uuid;

    let sessionUri;
    try {
        sessionUri = await this._openSession(init.signed_init_url);
    } catch (e) {
        return this._fail(entry, e);
    }
    entry.sessionUri = sessionUri;

    entry.state = 'uploading';
    entry.controller = new AbortController();
    entry.startedAt = Date.now();
    try {
        await this._uploadChunks(entry);
        entry.state = 'done';
        entry.descriptor = {
            object_path: entry.objectPath,
            size: entry.file.size,
            mime: entry.file.type || 'application/octet-stream',
            original_name: entry.file.name,
            file_uuid: entry.fileUuid,
            uploaded_at: new Date().toISOString(),
        };
        entry.row.classList.add('is-done');
        entry.row.querySelector('.gfgcs-status').textContent = 'Uploaded';
        entry.row.querySelector('.gfgcs-progress-bar').style.width = '100%';
    } catch (e) {
        this._fail(entry, e);
    } finally {
        this._refreshHidden();
    }
};

GFGCSUploader.prototype._uploadChunks = async function (entry) {
    let offset = 0;
    const total = entry.file.size;
    let attempt = 0;

    while (offset < total) {
        const end = Math.min(offset + CHUNK_SIZE, total);
        const chunk = entry.file.slice(offset, end);
        try {
            const result = await this._putChunk(entry, chunk, offset, end - 1, total);
            if (result.done) { offset = total; break; }
            offset = result.nextByte;
            attempt = 0;
        } catch (e) {
            if (entry.state === 'removed') return;
            attempt++;
            if (attempt > MAX_RETRIES) throw e;
            entry.state = 'retrying';
            entry.row.querySelector('.gfgcs-status').textContent = 'Retrying… (attempt ' + attempt + ')';
            await new Promise(r => setTimeout(r, backoffMs(attempt)));
            try {
                offset = await this._queryResumePosition(entry, total);
                entry.state = 'uploading';
            } catch (qe) {
                throw qe;
            }
        }
    }
};

GFGCSUploader.prototype._putChunk = async function (entry, chunk, startByte, endByte, total) {
    const res = await fetch(entry.sessionUri, {
        method: 'PUT',
        headers: { 'Content-Range': 'bytes ' + startByte + '-' + endByte + '/' + total },
        body: chunk,
        signal: entry.controller.signal,
    });
    if (res.status === 200 || res.status === 201) {
        return { done: true };
    }
    if (res.status === 308) {
        const range = res.headers.get('Range');
        const next = parseRangeHeader(range);
        entry.row.querySelector('.gfgcs-progress-bar').style.width = ((next / total) * 100).toFixed(1) + '%';
        return { done: false, nextByte: next };
    }
    if (res.status === 410) {
        const err = new Error('Session expired'); err.code = 'session_expired'; throw err;
    }
    if (res.status >= 500 || res.status === 0) {
        throw new Error('Transient HTTP ' + res.status);
    }
    throw new Error('Upload failed: HTTP ' + res.status);
};

GFGCSUploader.prototype._queryResumePosition = async function (entry, total) {
    const res = await fetch(entry.sessionUri, {
        method: 'PUT',
        headers: { 'Content-Range': 'bytes */' + total, 'Content-Length': '0' },
    });
    if (res.status === 200 || res.status === 201) {
        return total;
    }
    if (res.status === 308) {
        return parseRangeHeader(res.headers.get('Range'));
    }
    throw new Error('Resume query failed: HTTP ' + res.status);
};

GFGCSUploader.prototype._fail = function (entry, err) {
    entry.state = 'error';
    entry.error = err;
    entry.row.classList.add('is-error');
    entry.row.querySelector('.gfgcs-status').textContent = (err && err.message) || 'Upload failed';
    this._refreshHidden();
};

GFGCSUploader.prototype._removeRow = function (li) {
    const idx = Array.from(this.list.children).indexOf(li);
    if (idx < 0) return;
    const entry = this.files[idx];
    if (entry) {
        if (entry.controller) entry.controller.abort();
        if (entry.sessionUri && entry.state !== 'done') {
            // Fire-and-forget DELETE to release the partial session.
            fetch(entry.sessionUri, { method: 'DELETE' }).catch(() => { /* best-effort */ });
        }
        entry.state = 'removed';
    }
    li.remove();
    this.files.splice(idx, 1);
    this._refreshHidden();
};

export function initGFGCS(root) {
    var scope = root || document;
    if (!scope.querySelectorAll) return;
    scope.querySelectorAll('.ginput_container_gcs_upload').forEach(function (host) {
        if (host.dataset.gfgcsInited === '1') return;
        host.dataset.gfgcsInited = '1';
        new GFGCSUploader(host);
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function () { initGFGCS(); });

    // Gravity Forms fires gform_post_render after every form re-render (multi-step,
    // AJAX-submit confirmation reload, dynamic embeds). Hook into it via jQuery if
    // available — that's GF's own dispatch transport.
    if (typeof window.jQuery !== 'undefined') {
        window.jQuery(document).on('gform_post_render', function (_event, formId) {
            var form = document.getElementById('gform_' + formId) || document.getElementById('gform_wrapper_' + formId);
            initGFGCS(form || document);
        });
    }

    // Fallback: a MutationObserver picks up dynamically-inserted GCS upload fields
    // even when GF's jQuery event isn't available.
    if (typeof MutationObserver !== 'undefined' && document.body) {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node && node.nodeType === 1) {
                        if (node.classList && node.classList.contains('ginput_container_gcs_upload')) {
                            initGFGCS(node.parentNode || document);
                        } else if (node.querySelectorAll) {
                            initGFGCS(node);
                        }
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
}
