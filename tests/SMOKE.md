# Pre-Release Smoke Tests

Run every item before tagging a release. Mark each ✅ or ❌ with notes.

## 1. Settings + connection
- [ ] Pasting valid SA JSON and clicking **Test Connection** → "✔ Connection OK."
- [ ] Pasting `{"foo":"bar"}` → save error "missing required key".
- [ ] **Rotate** secret → confirm dialog → success alert.

## 2. Upload happy path
- [ ] Submit form with one 4 KB image → uploads, hidden input populated, entry saves, webhook payload contains valid permanent URL, URL opens the image in a new tab.
- [ ] Same with one 250 MB video.
- [ ] Same with three files of mixed sizes (20 MB, 200 MB, 500 MB).

## 3. Reliability
- [ ] During a 1 GB upload, kill WiFi at ~50%, restore → upload **resumes** without restarting.
- [ ] Cancel mid-upload at ~30% → no partial object lingers in the bucket prefix.
- [ ] Refresh page mid-upload → form data lost (expected); orphan cleanup cron deletes the partial after 24h.

## 4. Validation gate
- [ ] Tamper with hidden input via devtools (change `object_path` to a non-existent path) → submit → field error "One or more files did not finish uploading"; webhook does **not** fire; log file contains an entry.
- [ ] Tamper with `object_path` to a different prefix → submit → field error "Submission integrity check failed"; webhook does **not** fire.

## 5. Permanent URL
- [ ] Click permanent URL → image loads in browser.
- [ ] Tamper with one char of token → 404.
- [ ] Click **Rotate** in settings → previously-issued URLs return 404.
- [ ] Hammer one URL 70 times in 60 s with curl → 60 succeed, then 429 with `Retry-After: 60`.

## 6. WP admin entry detail
- [ ] **View** opens file in new tab. **Download** works. **Copy permanent URL** copies the right URL.

## 7. Mobile
- [ ] Open form on iOS Safari → drop zone falls back to picker → upload completes.
- [ ] Same on Chrome Android.

## 8. Per-form override
- [ ] Override bucket on one form → file lands in the override bucket, not the global default.
