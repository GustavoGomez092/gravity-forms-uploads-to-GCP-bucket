=== Gravity Forms GCS Uploads ===
Contributors: sscw
Tags: gravity forms, google cloud storage, gcs, file upload, signed url
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.2.7
License: GPLv2 or later

Offload Gravity Forms file uploads directly to Google Cloud Storage via signed-URL resumable uploads. Files bypass your web server entirely.

== Description ==

* Browser uploads files directly to GCS — bytes never touch your web server.
* Resumable uploads handle hundreds-of-megabyte files reliably over flaky networks.
* Server-side verification before entry save: webhooks never fire pointing at missing files.
* Permanent, single-click download URLs for downstream consumers (CRMs, ticket systems).
* Files stay private in the bucket; access via per-site HMAC-signed proxy.
* Rotate signing secret to invalidate every outstanding URL in one click.

== Installation ==

1. Upload `gf-gcs-uploads` to `/wp-content/plugins/` and activate.
2. Create a service account in GCP with `Storage Object Admin` on your bucket. Download its JSON key.
3. Configure CORS on the bucket to allow `PUT` from your site origin.
4. In WordPress: **Forms → Settings → GCS Storage**. Paste the JSON, set bucket, click **Test Connection**.
5. Add the **GCS Upload** field to any form from the **Advanced Fields** group.

== Frequently Asked Questions ==

= Why not the regular Gravity Forms File Upload field? =

It uploads through your PHP server. For hundreds-of-megabyte files this saturates the worker pool and fills your local disk.

= What happens to the permanent URLs if I rotate the signing secret? =

They all return 404. This is the kill switch — use it if a webhook recipient is compromised.

= How does it handle network dropouts mid-upload? =

GCS resumable upload sessions; the JS client resumes from the last acknowledged byte automatically.

== Changelog ==

= 0.2.7 =
* Fix: webhook payloads now contain HMAC-signed proxy URLs for `gcs_upload` field values instead of the raw GCS descriptor. The GF Webhooks add-on builds its payload in `GFWebhooks::get_request_data()` outside any field-value transformation hook — in `all_fields` mode it ships `$entry` verbatim, and in `select_fields` mode it pulls each mapped value through `get_field_value()` which returns the raw descriptor string. Neither path runs through `gform_replace_merge_tags`, so our field's stored JSON (`{"object_path":"gravityforms/…","original_name":"…",…}`) was reaching the webhook recipient as a useless raw bucket key. A new `gform_webhooks_request_data` handler in `GFGCS_Merge_Tags` now walks the payload, finds entries that correspond to a `gcs_upload` field (mapping `select_fields` custom keys back to field IDs via `$feed['meta']['fieldValues']`), and rewrites each descriptor so `object_path` carries the proxy URL while `original_name`, `size`, `mime`, and `file_uuid` are preserved — same JSON-encoded string shape, no schema break for existing consumers.

= 0.2.6 =
* Fix: enhance GCS merge tags functionality with new value merge tag and improved file decoding. This fixes the issue for emails with invalid file link format.

= 0.2.5 =
* Fix: validator now recognizes uppercase `{Y}` (year) in object-path prefix templates. The regex builder in `GFGCS_Validator::verify_field()` used `[a-z_]+` to detect template tokens, which silently treated `{Y}` as a literal `\{Y\}` in the compiled regex. Templates like `gravityforms/{Y}/{m}/{submission_uuid}/` matched in the init endpoint (`GFGCS_Settings::expand_prefix()` recognizes `Y`/`m`/`d`) but every legitimate upload was then rejected as `tampered_path` ("Submission integrity check failed."). Character class is now `[A-Za-z_]+` to cover all `PREFIX_TOKENS`.

= 0.2.4 =
* Fix: `gcs_upload` fields with persisted `inputType=fileupload` corruption (from the pre-0.2.1 editor bug) now render correctly. `GF_Fields::create()` reads `inputType` first and falls back to `type`, so the corrupted property caused GF to instantiate `GF_Field_FileUpload` instead of our class — our markup never rendered and GF's native plupload hijacked the dropzone, uploading to `?gf_page=…` instead of GCS. Submission then failed with "This field is required." because no descriptor reached our hidden input. Two-layer fix: (a) one-time migration on activation strips the bad `inputType` from every affected field in every form; (b) runtime `gform_form_post_get_meta` filter re-instantiates any field that still has the corruption.

= 0.2.3 =
* Fix: entry-detail screen rendered an empty cell for `gcs_upload` fields. Our `gform_entry_field_value` filter was trying to `json_decode()` the already-formatted output from `get_value_entry_detail()`, getting `null`, and returning an empty string. The filter now reads the raw descriptor JSON straight from `$entry[ $field->id ]`. Entry-detail now shows a list of HMAC-signed proxy links (one per uploaded file, opening in a new tab via 302 to a short-lived signed GCS URL).

= 0.2.2 =
* Fix: corrupted `inputType=fileupload` on a `gcs_upload` field no longer breaks submission. `GF_Field_GCSUpload::get_input_type()` now hard-pins to `gcs_upload`, so submission preprocessing routes the field value through `$_POST` instead of `$_FILES`.
* Fix: renamed the outer wrapper class from `gform_fileupload_multifile` to `gfgcs-multifile`. GF's frontend JS scans the native class and attempts to attach plupload to it, which crashed on our element (`Cannot read properties of undefined (reading 'form_id')`).
* Fix: JS uploader now does the real GCS resumable two-step handshake — POST to the signed init URL with `x-goog-resumable: start` to obtain the upload session URL, then PUT bytes to that session URL. Previous code PUT directly to the init URL, which is a protocol mismatch (HTTP 400).
* Fix: `object_metadata` HTTP timeout bumped from 5s to 20s. GCS metadata HEAD occasionally exceeded 5s and the submission would fail with "File verification temporarily unavailable" when only one file out of many timed out.
* Fix: frontend preview rows and delete button restyled because the wrapper-class rename broke the native styling chain. Inline `×` glyph is now rendered as a real child of the delete button instead of via `::before` so themes with aggressive `button` overrides can't hide it.
* Fix: re-enable `.gform_button_select_files` on every `gform_post_render`. GF disables that button whenever plupload isn't loaded for the form, which leaves our select-files button greyed out.
* Fix: hidden `<input type="file">` uses inline `style="display:none"` instead of the `hidden` HTML attribute — some theme rules forced `display: block` and the input was bleeding through onto the page.

= 0.2.1 =
* Fix: `gfgcs_abort` endpoint now uses the per-form effective bucket override (was always targeting the default bucket).
* Fix: form editor — toggling "Enable Multi-File Upload" on a GCS Upload field no longer corrupts its inputType to `fileupload`. Suppresses GF core's `ToggleMultiFile()` call for our field type.
* Fix: `size_exceeded` and `mime_not_allowed` AJAX responses now include a human-readable `message` field.
* Tests: replaced PHPUnit-9.6-deprecated `assertObjectHasAttribute`/`assertObjectNotHasAttribute` with `property_exists()` assertions.

= 0.2.0 =
* Feature: GCS Upload field now matches native Gravity Forms File Upload UI — settings live in the General section (Allowed file extensions, Enable Multi-File Upload, Maximum Number of Files, Maximum File Size), and the frontend renders the same dashed-box dropzone with cloud icon, "Select files" button, and per-file progress rows.
* Feature: per-file remove button aborts the in-flight upload and deletes the partial object from GCS via a new `gfgcs_abort` AJAX endpoint.
* Feature: inline validation messages match native GF copy ("File exceeds the maximum upload size", "This type of file is not allowed.", "Maximum number of files reached").
* Change: per-field file-type filter switches from MIME types to extensions to match native GF. The global "Default Allowed MIME Types" and per-form "Override allowed MIMEs" settings are unchanged and continue to apply server-side as defense-in-depth.
* Migration: one-time upgrade translates existing `allowedMimes` field values to `allowedExtensions` using a static map. Unmappable MIME types are surfaced in a dismissible admin notice; affected fields need manual reconfiguration.

= 0.1.4 =
* UX: when a service account is already configured, the settings page now shows a clear "✓ Service account configured" banner with the SA email and project ID above the textarea. The textarea placeholder clarifies that leaving it blank preserves the existing configuration.

= 0.1.3 =
* Fix: pasting service-account JSON into the settings page no longer triggers a fatal error. Gravity Forms' Settings v2 framework auto-decodes any JSON-shaped POST value via `maybe_decode_json()`, converting our SA JSON string into a PHP array — which then crashed `esc_textarea()` on re-render. The SA JSON field now renders as a custom-named textarea outside the Settings v2 input pipeline; save logic reads it directly from `$_POST['gfgcs_sa_json_raw']`.

= 0.1.2 =
* Fix: hard-error when `AUTH_KEY` and `SECURE_AUTH_KEY` are missing from `wp-config.php` instead of falling back to a degraded site-URL-based encryption key. Surfaces a clear actionable error to the admin.
* Fix: settings-page asset enqueue now scopes to the GCS Storage subview, not every Gravity Forms settings sub-tab.
* Fix: don't disclose the GCS bucket name in the `gfgcs_init` AJAX response (the JS client never read it).
* Fix: load the custom field class synchronously inside `init()` instead of via a deferred `init` action — eliminates a hook-timing edge case.
* Fix: orphan-cleanup cron paginates `objects.list` results, so buckets containing more than 1000 objects are fully scanned.
* Fix: JS uploader auto-attaches to forms loaded via AJAX (multi-step forms, AJAX confirmation reloads, dynamic embeds) via `gform_post_render` hook + a `MutationObserver` fallback. Idempotent — never attaches twice.
* Fix: proxy redirect's `Content-Disposition` header now follows RFC 5987 (separate ASCII-safe `filename=` and UTF-8 `filename*=` forms). Handles non-ASCII filenames correctly across browsers; strips control characters to prevent header injection.
* Fix: classify OAuth token-exchange failures as `transient` vs `permanent` via a typed `GFGCS_OAuth_Exception`. Permanent errors (e.g., `invalid_grant`) include actionable hints (clock-skew check, key rotation).

= 0.1.1 =
* Fix: invalidate OAuth access-token cache when service-account JSON is replaced (previously a stale token survived rotation until expiry).
* Fix: validator no longer runs HEAD checks against GCS on admin-side entry edits — only on fresh form submissions.
* Fix: tighten `submission_uuid` regex to canonical UUID-v4 shape; reject malformed values.
* Fix: rate-limit IP source is now opt-in. By default `REMOTE_ADDR` is used; trusted-proxy headers (`X-Forwarded-For`, `CF-Connecting-IP`) are honored only when explicitly selected in **Forms → Settings → GCS Storage → Access**.
* Fix: orphan cleanup cron now scans per-form override buckets, not just the global default. Cron run summary in `gfgcs_cleanup_last_run` includes target count and per-target errors.

= 0.1.0 =
Initial release.
