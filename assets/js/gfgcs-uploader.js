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
        // Implemented in Task 15.
        console.log('[gfgcs] files selected', files);
    }

    function render(state) {
        // Implemented in Task 16.
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
