// @vitest-environment jsdom
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { GFGCSUploader, preflight } from '../../assets/js/gfgcs-uploader.js';

const cfg = {
    formId: 1, fieldId: 4, multiple: true, maxFiles: 5,
    maxSize: 10 * 1024 * 1024,
    mimes: ['image/*', 'video/mp4'],
    ajaxUrl: '/x', nonce: 'n',
};

describe('preflight', () => {
    it('rejects oversize files', () => {
        const f = new File([new Uint8Array(11 * 1024 * 1024)], 'big.bin', { type: 'image/jpeg' });
        expect(preflight(f, cfg).code).toBe('size_exceeded');
    });

    it('rejects disallowed MIME types', () => {
        const f = new File([new Uint8Array(10)], 'doc.pdf', { type: 'application/pdf' });
        expect(preflight(f, cfg).code).toBe('mime_not_allowed');
    });

    it('accepts conforming files (image/* glob)', () => {
        const f = new File([new Uint8Array(10)], 'a.jpg', { type: 'image/jpeg' });
        expect(preflight(f, cfg)).toBeNull();
    });

    it('accepts when MIME list is empty', () => {
        const f = new File([new Uint8Array(10)], 'x.bin', { type: 'application/octet-stream' });
        expect(preflight(f, { ...cfg, mimes: [] })).toBeNull();
    });
});

describe('GFGCSUploader file list', () => {
    let host;
    beforeEach(() => {
        host = document.createElement('div');
        host.innerHTML = '<div class="gfgcs-dropzone">drop</div><ul class="gfgcs-files"></ul><input class="gfgcs-hidden" />';
        host.dataset.gfgcsConfig = JSON.stringify(cfg);
        document.body.appendChild(host);
    });

    it('rejects more than maxFiles', () => {
        const u = new GFGCSUploader(host);
        const files = Array.from({ length: 6 }, (_, i) =>
            new File([new Uint8Array(10)], `f${i}.jpg`, { type: 'image/jpeg' })
        );
        const errs = u.acceptFiles(files);
        expect(errs.some(e => e.code === 'too_many_files')).toBe(true);
    });
});
