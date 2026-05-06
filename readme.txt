=== Gravity Forms GCS Uploads ===
Contributors: sscw
Tags: gravity forms, google cloud storage, gcs, file upload, signed url
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.1
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

= 0.1.1 =
* Fix: invalidate OAuth access-token cache when service-account JSON is replaced (previously a stale token survived rotation until expiry).
* Fix: validator no longer runs HEAD checks against GCS on admin-side entry edits — only on fresh form submissions.
* Fix: tighten `submission_uuid` regex to canonical UUID-v4 shape; reject malformed values.
* Fix: rate-limit IP source is now opt-in. By default `REMOTE_ADDR` is used; trusted-proxy headers (`X-Forwarded-For`, `CF-Connecting-IP`) are honored only when explicitly selected in **Forms → Settings → GCS Storage → Access**.
* Fix: orphan cleanup cron now scans per-form override buckets, not just the global default. Cron run summary in `gfgcs_cleanup_last_run` includes target count and per-target errors.

= 0.1.0 =
Initial release.
