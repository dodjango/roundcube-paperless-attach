# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Roundcube 1.6.x (Elastic skin **only**) plugin that attaches documents from a Paperless-ngx
instance directly in the mail compose window. The per-user Paperless API token is stored encrypted
and **all** Paperless traffic is server-side — the browser never sees the token or the base URL.
PHP 7.4+.

## Commands

- **Lint PHP:** `php -l paperless_attach.php` (and `lib/PaperlessClient.php`). If PHP isn't on the
  host, run it inside the Roundcube container:
  `docker compose exec -T <service> php -l /var/www/html/plugins/paperless_attach/paperless_attach.php`.
- **Dev loop:** bind-mount the plugin into a Roundcube 1.6 container at
  `/var/www/html/plugins/paperless_attach`, enable it via `ROUNDCUBEMAIL_PLUGINS`, then
  edit → sync files → recreate the container (opcache `validate_timestamps` picks changes up;
  recreating is the reliable way). See `README.md` for the compose/env setup.
- **No automated test suite.** Verify functionally: *Settings → Paperless → Test connection* (green ✓),
  then compose → *Attach from Paperless* → pick → *Send* → confirm the recipient actually receives the PDF.
- **Releases:** never `git tag` by hand. Commits are Conventional Commits; release-please opens a
  release PR on `main` — merging it tags `vX.Y.Z` and publishes to Packagist. See `CONTRIBUTING.md`.

## Architecture (the parts that span files)

**Single server-side egress.** `lib/PaperlessClient.php` is the only code that talks to Paperless
(DRF Token auth; bundled Guzzle if present, else a cURL fallback). The browser reaches it solely
through `plugin.paperless.*` actions registered in `paperless_attach.php` (`action_search`,
`action_thumb`, `action_tags`, `action_correspondents`, `action_doctypes`, `action_attach`). The
token and base URL never leave the server; document ids are integer-validated and the base URL is
server-fixed (SSRF guards).

**⚠️ Attach-at-send is load-bearing — do not remove or "simplify" it.** Attaching a Paperless doc
downloads its PDF server-side, so the compose request runs >0.5s. That trips
`rcube_session::reload()`, which rebuilds `$_SESSION` and **orphans** `send.php`'s
`$COMPOSE =& $_SESSION['compose_data_<id>']` reference — so Roundcube's own `add_attachments()` never
attaches the file (it shows in compose but is missing from the sent mail). The fix:
`inject_attachment()` marks each descriptor `paperless => true`, and the **`message_ready` hook
(`attach_at_send()`)** re-attaches every marked document to the outgoing `Mail_mime` at send time,
reading from the session entry that is reliably present then.

**Attachment storage mirrors core exactly.** `inject_attachment()` runs
`exec_hook('attachment_save', …)` (filesystem_attachments) to store the temp file and get an id, then
`$rcmail->session->append('compose_data_<id>.attachments', $id, $att)` — the same path native uploads
use. Note `rcmail_action_mail_compose::save_attachment(null, $path, …)` is a **no-op in RC 1.6.6**;
don't reach for it.

**Token storage.** `$rcmail->encrypt()` / `decrypt()` (Roundcube `des_key`, 24 chars). The
`preferences_*` hooks render the *Settings → Paperless* section and only ever expose a boolean
"configured" flag — never the token value, and never via `rcmail.env` or an AJAX body.

**UI is Elastic-only, injected from JS.** `js/paperless.js` injects the compose button into
`#compose-attachments > div` and builds the picker dialog. `skins/elastic/paperless.css` is layout
glue — colours/spacing come from Elastic `var(--color-*)` tokens (no hex literals).

## Gotchas (hard-won)

- **Icon rendering:** the Paperless leaf is a CSS `mask` painted in `currentColor`. It needs
  `display: inline-block !important` (an Elastic rule otherwise forces `display:inline`, collapsing
  the box to zero size so the icon vanishes), a high-specificity selector to beat Elastic's
  `.btn.attach:before` glyph, and the SVG **inlined as a `data:` URI** in the `--paperless-leaf` CSS
  var — the Roundcube image's web server 404s standalone `.svg` files in the plugin dir.
- **Paperless download:** request `Accept: */*` — Paperless returns HTTP 406 for `application/pdf`.
  The official Roundcube image bundles **no** Guzzle, so the cURL path is what actually runs.
- **Effective upload limit** = `min(Roundcube upload limit, PHP upload_max_filesize / post_max_size /
  memory_limit)`, parsed with a byte parser — a plain `(int) "15M"` yields 15 *bytes*.
- **`:latest` drift:** Elastic toolbar/markup and the Paperless API can shift between versions —
  verify against the running Roundcube/Paperless before relying on internals.

## Conventions

- Conventional Commits + SemVer; meaningful scopes (`picker`, `settings`, `attach`, `client`, `skin`).
  release-please owns versioning.
- `composer.json` carries **no** `version` field (Packagist derives it from git tags).
- Maintainer: **@dodjango**.
