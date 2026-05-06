'use strict';

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

    _removeRow(li) {
        const idx = Array.from(this.list.children).indexOf(li);
        if (idx < 0) return;
        const entry = this.files[idx];
        if (entry && entry.controller) entry.controller.abort();
        if (entry) entry.state = 'removed';
        li.remove();
        this.files.splice(idx, 1);
        this._refreshHidden();
    }

    _startUpload(/* entry */) {
        // Stub. Filled in by Task 5.2.
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

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.ginput_container_gcs_upload').forEach(host => {
            new GFGCSUploader(host);
        });
    });
}
