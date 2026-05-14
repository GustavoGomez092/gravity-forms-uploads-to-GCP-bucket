(function () {
    'use strict';

    function init(root) {
        if (root.dataset.gfgcsInitialized === '1') return;
        root.dataset.gfgcsInitialized = '1';

        var cfg;
        try {
            cfg = JSON.parse(root.getAttribute('data-gfgcs-config') || '{}');
        } catch (e) {
            console.error('[gfgcs] bad config', e);
            return;
        }

        var state = {
            entries: new Map(),
            nextId: 1,
            submissionUuid: generateUuid(),
            root: root,
            cfg: cfg,
            hidden: root.querySelector('.gfgcs-hidden'),
            fileInput: root.querySelector('.gfgcs-file-input'),
            previewList: root.querySelector('.ginput_preview_list'),
            validation: root.querySelector('.gfgcs-validation'),
            dropArea: root.querySelector('.gform_drop_area'),
            selectButton: root.querySelector('.gform_button_select_files'),
        };

        wireFileSelection(state);
        wireDropZone(state);
        render(state);
    }

    function wireFileSelection(state) {
        if (state.selectButton) {
            state.selectButton.addEventListener('click', function () {
                state.fileInput && state.fileInput.click();
            });
        }
        if (state.fileInput) {
            state.fileInput.addEventListener('change', function (e) {
                handleFiles(state, Array.from(e.target.files || []));
                state.fileInput.value = '';
            });
        }
    }

    function wireDropZone(state) {
        if (!state.dropArea) return;
        ['dragenter', 'dragover'].forEach(function (ev) {
            state.dropArea.addEventListener(ev, function (e) {
                e.preventDefault();
                state.dropArea.classList.add('drag-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            state.dropArea.addEventListener(ev, function (e) {
                e.preventDefault();
                state.dropArea.classList.remove('drag-over');
            });
        });
        state.dropArea.addEventListener('drop', function (e) {
            var files = e.dataTransfer ? Array.from(e.dataTransfer.files || []) : [];
            handleFiles(state, files);
        });
    }

    function handleFiles(state, files) {
        state.validation.textContent = '';
        if (!files.length) return;
        var accepted = [];
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            var err = validateFile(state, f, accepted.length);
            if (err) {
                state.validation.textContent = err;
                return; // stop on first error, matches native behavior
            }
            accepted.push(f);
        }
        accepted.forEach(function (f) {
            var id = state.nextId++;
            state.entries.set(id, {
                id: id,
                file: f,
                status: 'queued',
                progress: 0,
                error: null,
                objectKey: null,
                descriptor: null,
                xhr: null,
            });
        });
        render(state);
        startNextUpload(state);
    }

    function validateFile(state, file, alreadyAccepted) {
        var cfg = state.cfg;
        if (cfg.multiple) {
            var totalSoFar = state.entries.size + alreadyAccepted;
            if (totalSoFar >= cfg.maxFiles) {
                return 'Maximum number of files reached';
            }
        } else {
            if (state.entries.size + alreadyAccepted >= 1) {
                return 'Maximum number of files reached';
            }
        }
        if (file.size > cfg.maxSize) {
            var mb = Math.round(cfg.maxSize / (1024 * 1024));
            return 'File exceeds the maximum upload size (' + mb + ' MB).';
        }
        if (cfg.allowedExtensions && cfg.allowedExtensions.length) {
            var name = file.name.toLowerCase();
            var dot = name.lastIndexOf('.');
            var ext = dot < 0 ? '' : name.slice(dot + 1);
            if (cfg.allowedExtensions.indexOf(ext) < 0) {
                return 'This type of file is not allowed.';
            }
        }
        return null;
    }

    function startNextUpload(state) {
        var next = null;
        state.entries.forEach(function (e) { if (!next && e.status === 'queued') next = e; });
        if (!next) return;

        next.status = 'uploading';
        render(state);

        initUpload(state, next).then(function (initData) {
            next.objectKey = initData.object_path;
            next.fileUuid = initData.file_uuid;
            return putToGcs(state, next, initData.signed_init_url);
        }).then(function () {
            next.status = 'done';
            next.progress = 100;
            next.descriptor = {
                object_path:   next.objectKey,
                original_name: next.file.name,
                size:          next.file.size,
                mime:          next.file.type || 'application/octet-stream',
                file_uuid:     next.fileUuid,
            };
            render(state);
            startNextUpload(state);
        }).catch(function (err) {
            if (err && err.aborted) {
                state.entries.delete(next.id);
                render(state);
                startNextUpload(state);
                return;
            }
            next.status = 'error';
            next.error = (err && err.message) ? err.message : 'Upload failed.';
            render(state);
            startNextUpload(state);
        });
    }

    function initUpload(state, entry) {
        var body = new URLSearchParams();
        body.set('action', 'gfgcs_init');
        body.set('form_id', state.cfg.formId);
        body.set('field_id', state.cfg.fieldId);
        body.set('nonce', state.cfg.nonce);
        body.set('submission_uuid', state.submissionUuid);
        body.set('filename', entry.file.name);
        body.set('size', entry.file.size);
        body.set('type', entry.file.type || 'application/octet-stream');

        return fetch(state.cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) {
                    var msg = (j && j.data && j.data.message) ? j.data.message : 'Upload init failed.';
                    throw new Error(msg);
                }
                return j.data;
            });
    }

    function putToGcs(state, entry, signedUrl) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            entry.xhr = xhr;
            xhr.open('PUT', signedUrl, true);
            xhr.setRequestHeader('Content-Type', entry.file.type || 'application/octet-stream');
            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    entry.progress = Math.round((e.loaded / e.total) * 100);
                    render(state);
                }
            });
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    entry.xhr = null;
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve();
                    } else if (xhr.status === 0) {
                        reject({ aborted: true });
                    } else {
                        reject(new Error('Upload failed (HTTP ' + xhr.status + ')'));
                    }
                }
            };
            xhr.send(entry.file);
        });
    }

    function render(state) {
        var ul = state.previewList;
        if (!ul) {
            syncHidden(state);
            return;
        }
        ul.innerHTML = '';
        state.entries.forEach(function (entry) {
            var li = document.createElement('li');
            li.className = 'ginput_preview' + (entry.status === 'error' ? ' is-error' : '');
            li.dataset.entryId = String(entry.id);

            var fname = document.createElement('span');
            fname.className = 'gform_fileupload_filename';
            fname.textContent = entry.file.name;

            var fsize = document.createElement('span');
            fsize.className = 'gform_fileupload_filesize';
            fsize.textContent = formatBytes(entry.file.size);

            li.appendChild(fname);
            li.appendChild(fsize);

            if (entry.status === 'uploading' || entry.status === 'queued') {
                var bar = document.createElement('progress');
                bar.className = 'gfgcs-progress';
                bar.max = 100;
                bar.value = entry.progress;
                li.appendChild(bar);
            }

            if (entry.status === 'error' && entry.error) {
                var errSpan = document.createElement('span');
                errSpan.className = 'gfgcs-row-error';
                errSpan.textContent = entry.error;
                li.appendChild(errSpan);
            }

            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'gform_delete_file';
            del.setAttribute('aria-label', 'Delete this file');
            del.innerHTML = '<span class="screen-reader-text">Delete</span>';
            del.addEventListener('click', function () { removeEntry(state, entry.id); });
            li.appendChild(del);

            ul.appendChild(li);
        });
        syncHidden(state);
    }

    function syncHidden(state) {
        var descriptors = [];
        state.entries.forEach(function (e) {
            if (e.descriptor) descriptors.push(e.descriptor);
        });
        state.hidden.value = JSON.stringify(descriptors);
    }

    function formatBytes(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function generateUuid() {
        return ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, function (c) {
            return (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16);
        });
    }

    function attachAll() {
        document.querySelectorAll('.ginput_container_fileupload_gcs[data-gfgcs-config]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachAll);
    } else {
        attachAll();
    }

    if (window.jQuery) {
        window.jQuery(document).on('gform_post_render', attachAll);
    }

    var observer = new MutationObserver(attachAll);
    observer.observe(document.body, { childList: true, subtree: true });
})();
