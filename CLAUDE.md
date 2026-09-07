# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Roundcube 1.6.x / 1.7.x (Elastic skin **only**) plugin that attaches documents from a Paperless-ngx
instance directly in the mail compose window. It also goes the **other way**: attachments on a
**received** message can be uploaded straight into Paperless from the message view. The per-user
Paperless API token is stored encrypted and **all** Paperless traffic is server-side — the browser
never sees the token or the base URL. PHP 7.4+.

## Commands

- **Lint PHP:** `php -l paperless_attach.php` (and `lib/PaperlessClient.php`, `lib/PaperlessHelpers.php`).
  No host PHP? Pipe the WORKING-TREE file via stdin:
  `docker compose exec -T <service> php -l < paperless_attach.php`. Do NOT `php -l` the *in-container
  path* — that's the separate rsync'd deploy copy, so it lints STALE code (false pass after an edit).
- **Lint JS:** `node --check js/paperless.js` — syntax check without PHP/Roundcube.
- **Dev loop:** bind-mount the plugin into a Roundcube 1.6 container at
  `/var/www/html/plugins/paperless_attach`, enable it via `ROUNDCUBEMAIL_PLUGINS`, then
  edit → sync files → recreate the container (opcache `validate_timestamps` picks changes up;
  recreating is the reliable way). See `README.md` for the compose/env setup.
- **Tests:** `composer install && composer test` (PHPUnit). Without a local PHP, run pinned:
  `docker run --rm -v "$PWD":/app -w /app composer:2 install` then
  `docker run --rm -v "$PWD":/app -w /app php:8.0-cli php vendor/bin/phpunit`. Covers
  **`lib/PaperlessClient.php`** logic (id validation, status→reason + duplicate mapping, task-UUID
  parsing, `download`/`upload` cleanup, `listAll` pagination + `toLocalPath` SSRF reduction) — the
  three wire transports (`request` / `uploadTransport` / `downloadTransport`) are **protected seams**
  a test subclass overrides with canned responses (no network) — and **`lib/PaperlessHelpers.php`**
  (byte-shorthand parsing, human sizes, filename/title sanitisation, the consume-task
  status/duplicate mapping, and the `usesUploadsTable` / `legacyAttachmentRow` storage-path split
  between RC 1.6.x and 1.7+). `composer.json` pins `config.platform.php=7.4` so deps resolve to
  PHPUnit 9.x; **never deploy `vendor/`/`tests/` to the live plugin** (it would add Guzzle and flip
  the transport path — exclude them from the rsync).
- **`paperless_attach.php` is not unit-tested** (it extends `rcube_plugin`, needs the Roundcube
  runtime) — its pure logic was extracted to `lib/PaperlessHelpers.php` (tested) and the plugin
  delegates to it. Verify the remaining glue functionally (see `CONTRIBUTING.md`'s smoke test):
  *Settings → Test connection* (green ✓); compose → *Attach from Paperless* → *Send* → recipient gets
  the PDF; received mail → *Save to Paperless* → document lands in Paperless (repeat → already exists).
- **Headless UI check (no login):** build a throwaway repro HTML that loads the real
  `skins/elastic/paperless.css` + Elastic `--color-*` var stand-ins and a copy of the relevant markup,
  serve with `python3 -m http.server`, then render / measure / screenshot via the Playwright MCP
  (`file://` is blocked). Drive real input with `page.mouse`/keyboard via `browser_run_code_unsafe`
  when a native quirk only reproduces under real events.
- **Releases:** never `git tag` by hand. Commits are Conventional Commits; release-please opens a
  release PR on `main` — merging it tags `vX.Y.Z` and publishes to Packagist. See `CONTRIBUTING.md`.
- **⚠️ Docs are part of done.** Every feature/behavior change updates **`README.md`** (user-facing)
  AND **this file** (architecture + gotchas) in the **same commit** — never ship code and leave the
  docs as a follow-up.

## Architecture (the parts that span files)

**Single server-side egress.** `lib/PaperlessClient.php` is the only code that talks to Paperless
(DRF Token auth; bundled Guzzle if present, else a cURL fallback). The browser reaches it solely
through `plugin.paperless.*` actions registered in `paperless_attach.php` (`action_search`,
`action_thumb`, `action_tags`, `action_correspondents`, `action_doctypes`, `action_attach`, plus the
upload side `action_save_attachment` / `action_task_status`). The token and base URL never leave the
server; document ids are integer-validated and the base URL is server-fixed (SSRF guards).

**Save-to-Paperless (received attachment → Paperless).** `action_save_attachment` streams the chosen
MIME part to a temp file via `rcube_message::get_part_body($id, false, 0, $fp)` (filename/mimetype come
from the message structure, never the browser), then `PaperlessClient::uploadDocument()` POSTs it to
`/api/documents/post_document/`. Paperless consumes ASYNCHRONOUSLY and returns a task UUID; the client
polls `action_task_status` → `getTaskStatus()` (`/api/tasks/?task_id=`) and maps the Celery state to
uploaded ✓ / duplicate (FAILURE whose `result` contains "It is a duplicate of …") / failure. Two entry
points: a per-attachment inline button (injected by the `template_object_messageattachments` hook,
`attachment_save_links()`) and a button in the attachment-preview toolbar (`template_container` hook on
`messagepart.html`'s `toolbar`, `preview_toolbar_button()`).

**⚠️ Attachment storage is version-split — `inject_attachment()` has TWO persistence paths.**
Both drive the `attachment_save` hook with a temp-file `path` (`data => null`), then diverge on
`PaperlessHelpers::usesUploadsTable($rcmail)` (probes `method_exists($rcmail,
'insert_uploaded_file')`):

- **RC 1.7+** — core moved compose attachments out of the session into a `uploads` DB table
  (`program/lib/Roundcube/rcube_uploads.php`). `$rcmail->insert_uploaded_file($att,
  'attachment_save')` runs the hook **and** writes the row, so it replaces the whole step — do NOT
  also call `exec_hook('attachment_save')` (that stores the file twice). Everything core does now
  reads `list_uploaded_files($group)`: `send.php` (what actually gets sent), the compose attachment
  list, the `max_message_size` accounting, remove-attachment, and
  `filesystem_attachments::cleanup()` (temp-file unlink). A session-only row is invisible to all
  five — that was the 1.7 regression this split fixes.
- **RC 1.6.x** — no uploads table; `PaperlessHelpers::legacyAttachmentRow()` strips the transient
  keys, marks `paperless => true`, and `$rcmail->session->append('compose_data_<id>.attachments',
  $id, $att)` persists it — the same path native uploads use there.

**⚠️ Attach-at-send is load-bearing on 1.6.x — do not remove or "simplify" it.** Attaching a
Paperless doc downloads its PDF server-side, so the compose request runs >0.5s. That trips
`rcube_session::reload()`, which rebuilds `$_SESSION` and **orphans** `send.php`'s
`$COMPOSE =& $_SESSION['compose_data_<id>']` reference — so Roundcube's own `add_attachments()` never
attaches the file (it shows in compose but is missing from the sent mail). The fix: the
**`message_ready` hook (`attach_at_send()`)** re-attaches every `paperless`-marked document to the
outgoing `Mail_mime` at send time, reading from the session entry that is reliably present then.
On **1.7+** no row is marked, so the hook is a no-op (plus a filename dedup as a second safety net) —
marking there would attach each document twice.

Note `rcmail_action_mail_compose::save_attachment(null, $path, …)` is a **no-op in RC 1.6.6**;
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
  verify against the running Roundcube/Paperless before relying on internals. This has bitten once
  already: the live stack rode `roundcube/roundcubemail:latest` onto **1.7.4**, whose new `uploads`
  table silently broke attachment removal, draft reload, size accounting and temp-file cleanup while
  *sending* kept working via the `message_ready` fallback — i.e. no visible error. When core's
  version moves, diff the subsystems this plugin hooks (`rcube_uploads`, `rcube_session`,
  compose/send actions), don't just smoke-test the happy path.
- **Tags `<select multiple>` scroll:** mutating `option.selected` makes the browser *async*-scroll to
  the first selected option (and a button-held drag over options auto-scrolls). `js/paperless.js` pins
  `scrollTop` on option-mousedown until mouseup — keep that guard; a plain sync restore is not enough.
- **`*/` inside a docblock ends it early.** `*/*` (e.g. an `Accept: */*` note) in a `/** … */` block
  closes the comment → "unexpected '*'" parse error. Reword in docblocks (e.g. "a wildcard Accept");
  it's fine in `//` comments and string literals.
- **⚠️ Message-view UI must be BODY-injected, not asset-included.** The Elastic preview pane loads the
  message as `_action=preview&_framed=1`, and Roundcube then **strips all plugin scripts + the plugin
  `<head>`** (`rcmail_output_html`: `scripts=[]; header=''`). So the Save-to-Paperless UI is injected as
  rendered BODY content via `template_object_*` / `template_container` hooks (survives), **not** via an
  included button/CSS (vanishes). Its **icon is styled inline on a real child `<span>`** — an injected
  `<style>` block is dropped because Elastic's toolbar JS rebuilds `#messagetoolbar` and keeps only
  `<a>` nodes, and the plugin stylesheet isn't applied there either. The toolbar icon also rides a
  **negative top margin** to cover Elastic's empty `.menu.toolbar a:before` slot (glyph-less for our
  class). Clicks resolve `paperlessSaveAttachment()` from `window` → `opener` → `parent` (the upload +
  toast run wherever the plugin JS is alive: the main mail window, which loads it for `action ∈ {'',
  show, preview, get}`).
- **Paperless upload contract:** `POST /api/documents/post_document/` returns HTTP 200 with the consume
  task UUID as a JSON string; `GET /api/tasks/?task_id=` returns Celery `status` (PENDING/STARTED/
  SUCCESS/FAILURE) + `result`. Send `Accept: */*` (406 otherwise); cURL uses `CURLFile` multipart.

## Conventions

- Conventional Commits + SemVer; meaningful scopes (`picker`, `settings`, `attach`, `save`, `client`,
  `skin`). release-please owns versioning.
- **PR merges:** repo auto-merge is ON. `main` requires the 4 status checks (`PHPUnit (PHP 7.4/
  8.0/8.1)` + `JS syntax check`) — no required review (so the solo maintainer isn't blocked by the
  can't-self-approve rule), `enforce_admins` off (direct admin pushes to `main` still allowed),
  `strict` off (PR need not be up to date). Queue a PR with `gh pr merge N --squash --auto` and it
  lands when CI goes green. Stacked Dependabot PRs on the same file still conflict after the first
  merge — auto-merge just waits: comment `@dependabot rebase`, let CI go green, the next one merges.
- `composer.json` carries **no** `version` field (Packagist derives it from git tags).
- Packagist package is **`dodjango/paperless_attach`** (from composer.json `name`), **not** the repo
  name `roundcube-paperless-attach`. The name's second segment **must** equal the plugin dir
  `paperless_attach` (roundcube-plugin installer path) — don't rename it to match the repo.
- Maintainer: **@dodjango**.
