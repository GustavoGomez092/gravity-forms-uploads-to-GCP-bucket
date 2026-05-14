(function () {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    function isGcsField(field) {
        return field && field.type === 'gcs_upload';
    }

    function applyMaxFilesVisibility(isMulti) {
        var row = document.querySelector('.gform_max_files');
        if (row) row.style.display = isMulti ? '' : 'none';
    }

    $(document).on('gform_load_field_settings', function (event, field) {
        if (!isGcsField(field)) return;
        applyMaxFilesVisibility(!!field.multipleFiles);

        // GF's "Enable Multi-File Upload" checkbox is wired to ToggleMultiFile(),
        // which calls StartChangeInputType("fileupload", field) and overwrites
        // field.inputType. Bind a capture-phase listener so we run before GF's
        // bubbling handler, then stop propagation.
        var cb = document.getElementById('field_multiple_files');
        if (cb && !cb.dataset.gfgcsBound) {
            cb.dataset.gfgcsBound = '1';
            cb.addEventListener('change', function (e) {
                var f = (typeof window.GetSelectedField === 'function') ? window.GetSelectedField() : null;
                if (!isGcsField(f)) return;
                f.multipleFiles = cb.checked;
                if (f.inputType === 'fileupload') {
                    delete f.inputType;
                }
                applyMaxFilesVisibility(cb.checked);
                e.stopImmediatePropagation();
            }, true);
        }
    });
})();
