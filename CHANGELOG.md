# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to semantic versioning.

## [1.0.0] - 2026-06-01

Initial public release.

### Added
- Settings → Paperless section: per-user Paperless API token, stored **encrypted**, with a
  "Test connection" check. The token and Paperless URL never reach the browser.
- "Attach from Paperless" button in the compose attachments widget (new mail, reply, forward).
- Picker dialog (Elastic skin): full-text search plus filters for tags, correspondent, document
  type, and date range; multi-select across paginated results; thumbnails.
- Server-side proxy for all Paperless traffic (`lib/PaperlessClient.php`): document listing/search,
  metadata, thumbnails, and archive-PDF download — single egress point, redirects disabled, timeouts
  enforced, document ids integer-validated (SSRF guard).
- Attaches the searchable **archive PDF** of selected documents to the outgoing email.
- Robust edge-case handling: oversize rejection before download, born-digital (no-archive) skip,
  per-item batch result reporting, and duplicate-attach prevention.
- German and English localization.

### Notes
- Paperless documents are attached to the outgoing message via the `message_ready` hook. This works
  around a Roundcube session-handling detail: a compose request that streams a PDF runs long enough to
  trigger `rcube_session::reload()`, which leaves the injected attachment out of send.php's compose
  reference; re-attaching at send time delivers it reliably.
