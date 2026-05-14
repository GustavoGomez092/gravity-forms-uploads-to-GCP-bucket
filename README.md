# Gravity Forms GCS Uploads

> Offload Gravity Forms file uploads directly to Google Cloud Storage via signed-URL resumable uploads. File bytes never touch your web server.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![Gravity Forms](https://img.shields.io/badge/Gravity%20Forms-required-orange)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)
![Version](https://img.shields.io/badge/version-0.2.2-informational)

A WordPress plugin that adds a **GCS Upload** field to Gravity Forms. The browser uploads files straight to Google Cloud Storage using a short-lived, V4-signed resumable URL — your PHP workers and local disk are completely bypassed.

---

## Table of contents

- [Why this exists](#why-this-exists)
- [Features](#features)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Using the field](#using-the-field)
- [Permanent download URLs](#permanent-download-urls)
- [Merge tags](#merge-tags)
- [Security model](#security-model)
- [Operations](#operations)
- [Development](#development)
- [FAQ](#faq)
- [Changelog](#changelog)
- [License](#license)

---

## Why this exists

The stock Gravity Forms File Upload field streams every byte through PHP. For 100 MB+ files this:

- saturates the PHP worker pool (one busy worker per in-flight upload),
- fills local disk (`wp-content/uploads/gravity_forms/...`),
- breaks under flaky networks (no resume — a dropped connection means restart from byte 0),
- and leaves uploaded files reachable at predictable URLs unless additional protection is bolted on.

This plugin replaces that path. The browser PUTs directly to GCS over a signed resumable session; the server only ever sees small JSON payloads (init, finalize, validate).

## Features

- **Direct-to-GCS uploads** — bytes never traverse your web server.
- **Resumable sessions** — the JS client resumes from the last acknowledged byte on network drops; uploads of hundreds of MB complete reliably.
- **Server-side verification before entry save** — a finalize step HEADs the object in GCS so webhooks never fire pointing at a missing file.
- **Permanent, single-click download URLs** — HMAC-signed proxy URLs you can paste into CRMs, ticket systems, or notification emails.
- **Private bucket by default** — files are never publicly readable; downloads flow through a per-site signed proxy.
- **Kill switch** — rotating the signing secret invalidates every outstanding permanent URL in one click.
- **Auto-attaches to AJAX-loaded forms** — multi-step forms, AJAX confirmation reloads, and dynamic embeds work without extra wiring (`gform_post_render` + `MutationObserver` fallback).
- **Orphan cleanup cron** — paginated `objects.list` scan that removes uploads which were initialized but never finalized into a form entry.
- **Per-form bucket overrides** — route different forms to different buckets if needed.
- **Trusted-proxy aware rate limiting** — opt-in `X-Forwarded-For` / `CF-Connecting-IP` support; default is `REMOTE_ADDR`.

## How it works

```
┌──────────┐    1. POST /init (form, file meta)        ┌──────────────┐
│ Browser  │ ─────────────────────────────────────────▶│  WordPress   │
│ (JS)     │                                            │  (this       │
│          │ ◀───── 2. signed resumable upload URL ─── │   plugin)    │
│          │                                            └──────┬───────┘
│          │    3. PUT bytes directly (resumable)              │
│          │ ──────────────────────────────────────────────────┼──────▶ ┌──────────┐
│          │                                                   │        │   GCS    │
│          │ ◀──────── 4. 200 OK (upload complete) ────────────┼─────── │  bucket  │
│          │                                                   │        └────┬─────┘
│          │    5. POST /finalize (upload id)                  │             │
│          │ ─────────────────────────────────────────────────▶│  6. HEAD object
│          │                                                   │ ─────────▶  │
│          │ ◀───── 7. entry saved, permanent URL returned ────│             │
└──────────┘                                                   └─────────────┘
```

1. **Init** — JS calls `admin-ajax.php?action=gfgcs_init` with form id, field id, and file metadata. The plugin mints a service-account-signed resumable upload URL scoped to a single object key under a UUID-keyed prefix.
2. **Upload** — the browser PUTs bytes directly to GCS. The JS client handles resume on disconnect.
3. **Finalize** — on success, JS calls `gfgcs_finalize`. The plugin HEADs the object server-side to confirm it actually landed and matches the declared size/type before the form entry is saved.
4. **Download** — the entry stores a permanent proxy URL of the form `…/?gfgcs_dl=<sig>&k=<object-key>`. Requests to that URL are HMAC-verified against the per-site signing secret and 302-redirected to a short-lived V4-signed GCS URL.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [Gravity Forms](https://www.gravityforms.com/) (active)
- A Google Cloud project with a Storage bucket
- A service account with `Storage Object Admin` on the bucket
- `AUTH_KEY` and `SECURE_AUTH_KEY` defined in `wp-config.php` (used to encrypt the service-account JSON at rest — required; the plugin hard-errors if they are missing)

## Installation

1. Clone or download this repo into `wp-content/plugins/gf-gcs-uploads/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Create a service account in GCP and grant it `Storage Object Admin` on the target bucket. Download the JSON key.
4. Configure CORS on the bucket to allow `PUT` from your site origin:
   ```json
   [
     {
       "origin": ["https://your-site.example"],
       "method": ["PUT", "POST", "OPTIONS"],
       "responseHeader": ["Content-Type", "Content-Range", "X-Upload-Content-Length", "X-Upload-Content-Type"],
       "maxAgeSeconds": 3600
     }
   ]
   ```
   Apply with: `gsutil cors set cors.json gs://your-bucket`
5. Go to **Forms → Settings → GCS Storage**, paste the JSON, set the default bucket name, and click **Test Connection**.

## Configuration

The settings screen lives at **Forms → Settings → GCS Storage** and is split into sub-tabs:

| Sub-tab | What lives here |
|---|---|
| **Storage** | Service-account JSON, default bucket, connection test, signing-secret rotation. |
| **Access** | Rate-limit policy, trusted-proxy header opt-in (`X-Forwarded-For`, `CF-Connecting-IP`). |
| **Cleanup** | Orphan-upload cron schedule, last-run summary, per-form override buckets. |

When a service account is already configured, the Storage tab shows a `✓ Service account configured` banner with the SA email and project ID above the textarea. Leaving the textarea blank on save preserves the existing credential.

## Using the field

1. Edit any form. Open **Add Fields → Advanced Fields**.
2. Drag in **GCS Upload**.
3. In the field's General tab, optionally set:
   - **Allowed file types** (comma-separated extensions),
   - **Max file size** (MB),
   - **Bucket override** (defaults to the global bucket).
4. Save the form. The frontend now uses the GCS upload widget for that field.

## Permanent download URLs

Every uploaded file gets a permanent URL of the form:

```
https://your-site.example/?gfgcs_dl=<sig>&k=<object-key>
```

These URLs:

- are **HMAC-signed** with the per-site signing secret,
- never expire on their own,
- 302-redirect to a short-lived V4-signed GCS URL (default TTL: 10 minutes) on each request,
- can be safely pasted into Gravity Forms notifications, Zapier webhooks, ticket systems, etc.

To revoke every outstanding URL in a single action, click **Rotate signing secret** on the Storage tab. All previously issued URLs immediately return 404.

## Merge tags

The GCS Upload field exposes the following merge tags for use in notifications, confirmations, and webhooks:

| Merge tag | Value |
|---|---|
| `{GCS Upload:N}` | Permanent download URL (the proxy URL described above). |
| `{GCS Upload:N:filename}` | Original filename as uploaded. |
| `{GCS Upload:N:size}` | File size in bytes. |
| `{GCS Upload:N:mime}` | Content-Type as declared by the browser. |
| `{GCS Upload:N:key}` | Raw GCS object key (use with care — exposing this leaks bucket layout). |

Where `N` is the field ID.

## Security model

- **Files are private.** The bucket should have uniform bucket-level access enabled and zero public IAM bindings. The plugin only ever generates time-limited V4-signed URLs.
- **Service-account JSON is encrypted at rest** using a key derived from `AUTH_KEY` + `SECURE_AUTH_KEY`. The plugin refuses to operate if those constants are missing rather than silently falling back to a weaker key.
- **The signing secret is generated on activation** (64 random characters) and stored in `wp_options` under `gfgcs_signing_secret`. Rotating it is the kill switch for every outstanding permanent URL.
- **Init requests are rate limited** per source IP. Trusted-proxy headers (`X-Forwarded-For`, `CF-Connecting-IP`) are honored **only** when explicitly opted in — by default the plugin uses `REMOTE_ADDR`.
- **The `submission_uuid` is validated** against the canonical UUID-v4 shape; malformed values are rejected.
- **OAuth token cache is invalidated** when the service-account JSON is rotated so a stale token can't survive credential replacement.
- **Content-Disposition headers** follow RFC 5987 (ASCII-safe `filename=` plus UTF-8 `filename*=`); control characters are stripped to prevent header injection.

## Operations

### Orphan cleanup

A WP-Cron task scans the bucket for objects whose corresponding init record was never finalized into a form entry, and deletes them. The scan paginates `objects.list` so buckets with more than 1000 objects are fully covered. Per-form override buckets are also scanned. The last run summary (target count, per-target errors) is stored in the `gfgcs_cleanup_last_run` option and surfaced on the Cleanup tab.

### Logs

OAuth token-exchange failures are typed (`GFGCS_OAuth_Exception`) and classified as `transient` (retry) or `permanent` (admin action required). Permanent errors include actionable hints — for example, an `invalid_grant` will suggest checking clock skew and rotating the service-account key.

### Health checks

- **Test Connection** button on the Storage tab — does a real OAuth exchange and a bucket `GET`.
- `gfgcs_cleanup_last_run` option — last orphan-cleanup summary.

## Development

```bash
composer install
npm install

# Run the PHP test suite
./vendor/bin/phpunit

# Build / watch frontend assets
npm run build
npm run watch
```

The plugin source lives under `includes/`:

| File | Responsibility |
|---|---|
| `class-gfgcs-addon.php` | Gravity Forms Add-On bootstrap, hooks. |
| `class-gfgcs-field.php` | The `GCS Upload` field type. |
| `class-gfgcs-ajax.php` | `gfgcs_init` and `gfgcs_finalize` AJAX endpoints. |
| `class-gfgcs-gcs-client.php` | GCS API client (`objects.list`, `objects.delete`, HEAD). |
| `class-gfgcs-oauth.php` | Service-account → access-token exchange, with typed errors. |
| `class-gfgcs-signer.php` | V4 signing for resumable upload URLs and download redirects. |
| `class-gfgcs-proxy.php` | HMAC-verified permanent-URL proxy → 302 to signed GCS URL. |
| `class-gfgcs-validator.php` | Finalize-time HEAD checks (size, type, existence). |
| `class-gfgcs-cleanup.php` | Orphan-upload cron. |
| `class-gfgcs-settings.php` | Settings screen (Storage / Access / Cleanup sub-tabs). |
| `class-gfgcs-merge-tags.php` | Merge tags. |

## FAQ

**Why not the regular Gravity Forms File Upload field?**
It streams every byte through your PHP server. For hundreds-of-megabyte files this saturates the worker pool and fills your local disk. This plugin uploads browser → GCS directly.

**What happens to the permanent URLs if I rotate the signing secret?**
They all return 404 — immediately. This is the kill switch; use it if a webhook recipient is compromised or if signed URLs have been leaked.

**How does it handle network dropouts mid-upload?**
GCS resumable upload sessions persist server-side; the JS client resumes from the last acknowledged byte automatically. No restart from byte 0.

**Can different forms use different buckets?**
Yes — each field has an optional **Bucket override** setting. The orphan-cleanup cron scans per-form override buckets in addition to the global default.

**Does the plugin work with multi-step / AJAX-loaded forms?**
Yes. The JS uploader auto-attaches on `gform_post_render` and via a `MutationObserver` fallback for dynamic embeds. Attachment is idempotent.

**Where is the service account JSON stored?**
In `wp_options`, encrypted with a key derived from `AUTH_KEY` + `SECURE_AUTH_KEY`. If those constants are missing from `wp-config.php`, the plugin hard-errors instead of falling back to a weaker key.

## Changelog

See [`readme.txt`](readme.txt) for the full changelog. Highlights:

### 0.2.2
- Fix: `inputType=fileupload` corruption no longer breaks submission — `get_input_type()` hard-pins to `gcs_upload`.
- Fix: outer wrapper class renamed (`gform_fileupload_multifile` → `gfgcs-multifile`) so GF's plupload init no longer attaches to our element.
- Fix: JS uploader does the real GCS resumable two-step (POST init → PUT to Location URL). Previous direct PUT was a protocol mismatch.
- Fix: `object_metadata` HTTP timeout bumped 5s → 20s; GCS HEAD occasionally exceeded the lower bound and submission failed verification.
- Fix: preview-row + delete-button styling restored after wrapper rename broke the native CSS chain. Inline `×` glyph rendered as a real child of the button.

### 0.2.1
- Fix: `gfgcs_abort` honors per-form bucket override.
- Fix: editor no longer corrupts `gcs_upload` field's inputType when toggling multi-file.
- Fix: 422 responses include `message` field for `size_exceeded` and `mime_not_allowed`.

### 0.2.0
- Feature: field UI matches native Gravity Forms File Upload — General-tab settings, dashed-box dropzone, per-file progress rows with remove button.
- Feature: `gfgcs_abort` AJAX endpoint allows mid-upload cancel + post-upload removal from GCS.
- Change: per-field file-type filter is now extension-based. One-time 0.2.0 migration translates existing MIME values.

### 0.1.4
- UX: settings page now shows a `✓ Service account configured` banner (SA email + project ID) when credentials are already saved.

### 0.1.3
- Fix: pasting service-account JSON no longer triggers a fatal error caused by Gravity Forms' Settings v2 `maybe_decode_json()` decoding the JSON-shaped POST value into a PHP array. The SA JSON field is now rendered outside the Settings v2 input pipeline.

### 0.1.2
- Fix: hard-error when `AUTH_KEY` / `SECURE_AUTH_KEY` are missing instead of silently falling back to a degraded site-URL-based encryption key.
- Fix: settings-page asset enqueue is now scoped to the GCS Storage subview.
- Fix: bucket name is no longer disclosed in the `gfgcs_init` AJAX response.
- Fix: orphan-cleanup cron paginates `objects.list` so buckets > 1000 objects are fully scanned.
- Fix: JS uploader auto-attaches to AJAX-loaded forms.
- Fix: `Content-Disposition` follows RFC 5987 for non-ASCII filenames; control characters stripped.
- Fix: OAuth errors classified as `transient` vs `permanent` via typed exceptions, with actionable hints.

### 0.1.1
- Fix: OAuth access-token cache invalidated when the service-account JSON is replaced.
- Fix: validator no longer runs HEAD checks on admin-side entry edits.
- Fix: tightened `submission_uuid` regex to canonical UUID-v4 shape.
- Fix: rate-limit IP source now opt-in for trusted-proxy headers.
- Fix: orphan cleanup scans per-form override buckets.

### 0.1.0
- Initial release.

## License

GPL-2.0-or-later. See the plugin header for details.
