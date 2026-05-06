(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var test = document.getElementById('gfgcs-test-connection');
        var result = document.getElementById('gfgcs-test-result');
        if (test) {
            test.addEventListener('click', function () {
                result.textContent = GFGCSAdmin.i18n.testing;
                var fd = new FormData();
                fd.append('action', 'gfgcs_test_connection');
                fd.append('nonce', GFGCSAdmin.nonce);
                fetch(GFGCSAdmin.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                    .then(function (x) {
                        result.textContent = (x.ok && x.json.success ? '✔ ' : '✖ ') + (x.json.data && x.json.data.message ? x.json.data.message : 'Error');
                    })
                    .catch(function (e) { result.textContent = '✖ ' + e.message; });
            });
        }

        var rotate = document.getElementById('gfgcs-rotate-secret');
        if (rotate) {
            rotate.addEventListener('click', function () {
                if (!window.confirm(GFGCSAdmin.i18n.confirm)) { return; }
                rotate.disabled = true;
                rotate.textContent = GFGCSAdmin.i18n.rotating;
                var fd = new FormData();
                fd.append('action', 'gfgcs_rotate_secret');
                fd.append('nonce', GFGCSAdmin.nonce);
                fetch(GFGCSAdmin.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        rotate.disabled = false;
                        rotate.textContent = 'Rotate';
                        window.alert(GFGCSAdmin.i18n.rotated);
                    });
            });
        }
    });
})();
