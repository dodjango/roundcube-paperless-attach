# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to semantic versioning.

## [1.2.1](https://github.com/dodjango/roundcube-paperless-attach/compare/v1.2.0...v1.2.1) (2026-06-06)


### Documentation

* capture lint-via-stdin and docblock `*/` gotchas in CLAUDE.md ([75306a3](https://github.com/dodjango/roundcube-paperless-attach/commit/75306a34ec56ed61fdfcf93b2e5e1b954cddf41f))
* document save-to-Paperless for received attachments ([8ff889a](https://github.com/dodjango/roundcube-paperless-attach/commit/8ff889a404dc4d503d5c1d5f0592f590eca2587a))
* make "docs are part of done" a release checklist item ([631b0d9](https://github.com/dodjango/roundcube-paperless-attach/commit/631b0d9c56afc0a4c0f63e49d6269b66f76f6035))

## [1.2.0](https://github.com/dodjango/roundcube-paperless-attach/compare/v1.1.0...v1.2.0) (2026-06-04)


### Features

* **save:** upload received-mail attachments to Paperless ([b262480](https://github.com/dodjango/roundcube-paperless-attach/commit/b2624802a4bb4eed794676259b2e1dfc512cdc4b))

## [1.1.0](https://github.com/dodjango/roundcube-paperless-attach/compare/v1.0.0...v1.1.0) (2026-06-02)


### Features

* **picker:** show a loading indicator while filter lists load ([929f85b](https://github.com/dodjango/roundcube-paperless-attach/commit/929f85bf082f650030a8aa2039abab18720a2944))
* **picker:** show selected-tag count in the Tags filter ([60519b4](https://github.com/dodjango/roundcube-paperless-attach/commit/60519b436024d0e3e948b35fcad68bd16325851f))


### Bug Fixes

* **picker:** keep Tags list scroll position stable on click and drag ([c73c310](https://github.com/dodjango/roundcube-paperless-attach/commit/c73c31046fcbb699ee2fa6bfbf952a6efee85d29))


### Documentation

* add CLAUDE.md (architecture, conventions, gotchas) ([91aace5](https://github.com/dodjango/roundcube-paperless-attach/commit/91aace596fd7567c17aee3c82ed465786d2bdb1c))

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
