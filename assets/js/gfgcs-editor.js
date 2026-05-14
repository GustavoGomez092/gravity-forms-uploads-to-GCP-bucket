(function () {
    if (typeof jQuery === 'undefined') return;
    jQuery(document).on('gform_load_field_settings', function (event, field) {
        if (!field || field.type !== 'gcs_upload') return;
        var isMulti = !!field.multipleFiles;
        var maxFilesRow = document.querySelector('.gform_max_files');
        if (maxFilesRow) {
            maxFilesRow.style.display = isMulti ? '' : 'none';
        }
    });
})();
