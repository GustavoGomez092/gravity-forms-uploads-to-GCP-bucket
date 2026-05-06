// @vitest-environment jsdom
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { GFGCSUploader, preflight } from '../../assets/js/gfgcs-uploader.js';
import { parseRangeHeader, backoffMs } from '../../assets/js/gfgcs-uploader.js';

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

describe('parseRangeHeader', () => {
    it('parses bytes=0-N as next byte = N+1', () => {
        expect(parseRangeHeader('bytes=0-8388607')).toBe(8388608);
    });
    it('returns 0 when header missing', () => {
        expect(parseRangeHeader(null)).toBe(0);
        expect(parseRangeHeader('')).toBe(0);
    });
});

describe('backoffMs', () => {
    it('follows 1s 2s 4s 8s 16s schedule', () => {
        expect(backoffMs(1)).toBe(1000);
        expect(backoffMs(2)).toBe(2000);
        expect(backoffMs(3)).toBe(4000);
        expect(backoffMs(4)).toBe(8000);
        expect(backoffMs(5)).toBe(16000);
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

describe('initGFGCS idempotency', () => {
    it('does not attach a second uploader to the same host on re-init', async () => {
        const { initGFGCS } = await import('../../assets/js/gfgcs-uploader.js');
        const host = document.createElement('div');
        host.innerHTML = '<div class="gfgcs-dropzone">drop</div><ul class="gfgcs-files"></ul><input class="gfgcs-hidden" />';
        host.className = 'ginput_container_gcs_upload';
        host.dataset.gfgcsConfig = JSON.stringify(cfg);
        document.body.appendChild(host);

        new GFGCSUploader(host);
        host.dataset.gfgcsInited = '1';

        // Record how many file inputs exist after first construction.
        const inputCountBefore = host.querySelectorAll('input[type=file]').length;

        // Calling initGFGCS again on the same host must skip it (guard: gfgcsInited=1).
        initGFGCS(document.body);

        // Give microtasks a moment to flush.
        await new Promise(r => setTimeout(r, 0));

        const inputCountAfter = host.querySelectorAll('input[type=file]').length;
        expect(inputCountAfter).toBe(inputCountBefore);
    });
});
