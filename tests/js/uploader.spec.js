// @vitest-environment jsdom
//
// These tests exercise the public DOM-level behavior of the gfgcs-uploader IIFE
// at assets/js/gfgcs-uploader.js. The module is not an ES module — it's a
// self-executing script that scans the document for
// `.ginput_container_fileupload_gcs[data-gfgcs-config]` elements on
// DOMContentLoaded / `gform_post_render` / DOM mutation and wires up each one.
// There are no named exports to import; we test what the IIFE observably does
// to the DOM after it loads.
//
// Each test arranges the DOM BEFORE loading the module (so the auto-attach
// finds something to attach to), then asserts on the resulting DOM state or on
// the side effects of dispatched events.
import { describe, it, expect, beforeEach, vi } from 'vitest';

const baseConfig = {
    formId: 1,
    fieldId: 4,
    multiple: true,
    maxFiles: 3,
    maxSize: 10 * 1024 * 1024, // 10 MB
    allowedExtensions: ['jpg', 'png'],
    ajaxUrl: '/x',
    nonce: 'n',
};

function makeHost(overrides = {}) {
    const cfg = { ...baseConfig, ...overrides };
    const host = document.createElement('div');
    host.className = 'ginput_container_fileupload_gcs';
    host.setAttribute('data-gfgcs-config', JSON.stringify(cfg));
    host.innerHTML = [
        '<div class="gform_drop_area">',
        '  <button type="button" class="gform_button_select_files">Select</button>',
        '</div>',
        '<input type="file" class="gfgcs-file-input" multiple style="display:none">',
        '<div class="gfgcs-validation"></div>',
        '<ul class="ginput_preview_list"></ul>',
        '<input type="hidden" class="gfgcs-hidden" value="[]">',
    ].join('');
    document.body.appendChild(host);
    return host;
}

function makeFile(name, sizeBytes, mime) {
    return new File([new Uint8Array(sizeBytes)], name, { type: mime });
}

async function loadModule() {
    vi.resetModules();
    await import('../../assets/js/gfgcs-uploader.js');
    // The IIFE runs attachAll() synchronously on import (jsdom sets
    // document.readyState to 'complete', so the DOMContentLoaded branch is
    // skipped and attachAll() is called immediately).
    // setTimeout(reenableSelectButtons, 0) is queued — flush microtasks.
    await new Promise((r) => setTimeout(r, 0));
}

function setFilesAndChange(input, files) {
    // jsdom doesn't expose DataTransfer. The IIFE reads e.target.files via
    // `Array.from(e.target.files || [])`, which works on any iterable —
    // including a plain Array. Override the `files` getter so the change
    // handler sees our test files.
    Object.defineProperty(input, 'files', {
        value: files,
        configurable: true,
    });
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

beforeEach(() => {
    // Wipe the body — the module's MutationObserver (attached on previous
    // imports) will fire on this change but the idempotency guard on each new
    // host prevents double-wiring, so the noise is harmless.
    document.body.innerHTML = '';
});

describe('GCS uploader auto-attach', () => {
    it('marks the host as initialized after the IIFE loads', async () => {
        const host = makeHost();
        await loadModule();
        expect(host.dataset.gfgcsInitialized).toBe('1');
    });

    it('does not crash when no matching host exists in the DOM', async () => {
        await loadModule();
        // No assertion needed — the test passes if loadModule() doesn't throw.
    });

    it('is idempotent: re-running attachAll via gform_post_render does not re-init', async () => {
        const host = makeHost();
        await loadModule();
        expect(host.dataset.gfgcsInitialized).toBe('1');

        // Stash a marker on the file input — if init re-runs, the IIFE replaces
        // the input's event handlers but does NOT replace the input element,
        // so the marker survives. The check is really "did dataset stay '1'?".
        host.querySelector('.gfgcs-file-input').dataset.testMarker = 'a';

        // Simulate a GF AJAX re-render — the IIFE wires
        // jQuery(document).on('gform_post_render', attachAll), but no jQuery in
        // jsdom. Instead, manually invoke the DOMContentLoaded path: dispatch
        // the event the IIFE's listener was bound to. Since the IIFE already
        // ran attachAll once on import, we re-trigger by mutating the body and
        // relying on the MutationObserver.
        const dummy = document.createElement('div');
        document.body.appendChild(dummy);
        await new Promise((r) => setTimeout(r, 0));
        document.body.removeChild(dummy);
        await new Promise((r) => setTimeout(r, 0));

        // Marker still present (input not replaced), dataset still '1'.
        expect(host.querySelector('.gfgcs-file-input').dataset.testMarker).toBe('a');
        expect(host.dataset.gfgcsInitialized).toBe('1');
    });
});

describe('GCS uploader file validation', () => {
    it('shows "Maximum number of files" when selection exceeds maxFiles', async () => {
        const host = makeHost({ maxFiles: 3 });
        await loadModule();
        const input = host.querySelector('.gfgcs-file-input');
        const files = Array.from({ length: 4 }, (_, i) =>
            makeFile(`f${i}.jpg`, 10, 'image/jpeg')
        );
        setFilesAndChange(input, files);
        expect(host.querySelector('.gfgcs-validation').textContent).toMatch(
            /Maximum number of files/i
        );
    });

    it('shows "exceeds the maximum upload size" for an oversize file', async () => {
        // maxSize = 10MB. Submit one 11MB file.
        const host = makeHost({ maxSize: 10 * 1024 * 1024 });
        await loadModule();
        const input = host.querySelector('.gfgcs-file-input');
        const oversize = makeFile('big.jpg', 11 * 1024 * 1024, 'image/jpeg');
        setFilesAndChange(input, [oversize]);
        expect(host.querySelector('.gfgcs-validation').textContent).toMatch(
            /exceeds the maximum upload size/i
        );
    });

    it('shows "This type of file is not allowed" for a disallowed extension', async () => {
        const host = makeHost({ allowedExtensions: ['jpg', 'png'] });
        await loadModule();
        const input = host.querySelector('.gfgcs-file-input');
        const pdf = makeFile('doc.pdf', 10, 'application/pdf');
        setFilesAndChange(input, [pdf]);
        expect(host.querySelector('.gfgcs-validation').textContent).toMatch(
            /This type of file is not allowed/i
        );
    });

    it('accepts files when allowedExtensions is empty (no validation error)', async () => {
        const host = makeHost({ allowedExtensions: [] });
        await loadModule();
        const input = host.querySelector('.gfgcs-file-input');
        // Stub fetch so the upload attempt after validation doesn't blow up
        // with "fetch is not defined" or an unhandled rejection.
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'stub' } }) })
        );
        const f = makeFile('x.bin', 10, 'application/octet-stream');
        setFilesAndChange(input, [f]);
        // Validation should be empty (no extension restriction).
        expect(host.querySelector('.gfgcs-validation').textContent).toBe('');
    });
});

describe('GCS uploader dropzone interactions', () => {
    it('adds drag-over class on dragenter and removes it on dragleave', async () => {
        const host = makeHost();
        await loadModule();
        const drop = host.querySelector('.gform_drop_area');

        drop.dispatchEvent(new Event('dragenter', { bubbles: true }));
        expect(drop.classList.contains('drag-over')).toBe(true);

        drop.dispatchEvent(new Event('dragleave', { bubbles: true }));
        expect(drop.classList.contains('drag-over')).toBe(false);
    });

    it('removes drag-over class on drop (even if no files transferred)', async () => {
        const host = makeHost();
        await loadModule();
        const drop = host.querySelector('.gform_drop_area');

        drop.dispatchEvent(new Event('dragenter', { bubbles: true }));
        expect(drop.classList.contains('drag-over')).toBe(true);

        drop.dispatchEvent(new Event('drop', { bubbles: true }));
        expect(drop.classList.contains('drag-over')).toBe(false);
    });
});
