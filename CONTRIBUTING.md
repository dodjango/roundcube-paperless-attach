# Contributing

Thanks for your interest in improving **Paperless Attach**! Issues and pull requests are welcome.

When reporting a bug, please include your **Roundcube** and **Paperless-ngx** versions, and — for UI
issues — a screenshot. The plugin targets **Roundcube 1.6.x**, the **Elastic skin**, and **PHP 7.4+**.

## Commit messages — Conventional Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/). The commit type
drives automated versioning and the changelog (see *Releases* below), so please format messages as:

```
<type>(<optional scope>): <short summary>
```

Common types:

| Type | Use for | Version effect |
|------|---------|----------------|
| `feat` | a new feature | **minor** bump (`1.1.0`) |
| `fix` | a bug fix | **patch** bump (`1.0.1`) |
| `perf` | a performance improvement | patch |
| `docs` | documentation only | none (no release) |
| `refactor` | code change that neither fixes a bug nor adds a feature | none |
| `test` / `ci` / `build` / `chore` | tooling, tests, housekeeping | none |

A **breaking change** is marked with a `!` after the type/scope **or** a `BREAKING CHANGE:` footer,
and triggers a **major** bump (`2.0.0`):

```
feat(api)!: drop support for legacy /fetch URLs
```

Good scopes are areas of the plugin, e.g. `picker`, `settings`, `attach`, `save`, `client`, `skin`.

Examples:

```
feat(picker): add similar-documents filter
fix(attach): re-attach Paperless docs at send via message_ready hook
docs: clarify the default paperless_url must be changed
```

## Versioning — Semantic Versioning

Versions follow [SemVer](https://semver.org/): `MAJOR.MINOR.PATCH`. You don't set the version by
hand — it is computed from the Conventional Commit history.

## Releases — release-please

Releases are automated with [release-please](https://github.com/googleapis/release-please):

1. Merging Conventional Commits into `main` makes release-please open/update a **"release PR"** that
   bumps the version and updates [`CHANGELOG.md`](CHANGELOG.md) (Keep a Changelog style).
2. **Merging that release PR** creates the git tag (`vX.Y.Z`) and the GitHub release.
3. Packagist picks up the new tag automatically (the package version is derived from tags;
   `composer.json` intentionally has no `version` field).

You therefore never run `git tag` by hand — just write good commits and merge the release PR.

## Local checks

Before opening a PR, run the automated suite and the lints:

```bash
composer install        # once; pulls PHPUnit (dev)
composer test           # PHPUnit — covers lib/PaperlessClient.php + lib/PaperlessHelpers.php

php -l paperless_attach.php
php -l lib/PaperlessClient.php
node --check js/paperless.js
```

No local PHP/Composer? Run them in pinned containers:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.0-cli php vendor/bin/phpunit
```

CI (`.github/workflows/tests.yml`) runs the same on PHP 7.4 / 8.0 / 8.1 for every push and PR.

## Manual smoke test (before merging a release PR)

The automated tests cover the pure/server-side logic; the parts wired into the Roundcube runtime
(`paperless_attach.php`) and the real end-to-end flow can only be checked by hand. Against a running
Roundcube + Paperless, confirm:

1. **Settings → Paperless → Test connection** → green ✓ (and the no-token / bad-token messages when
   the token is missing/wrong).
2. **Attach from Paperless:** compose → *Attach from Paperless* → search/filter → pick → *Send* →
   the recipient actually receives the PDF.
3. **Save to Paperless:** open a received mail with an attachment → the per-attachment button (and the
   button in the attachment **preview** toolbar after *Open*) → toast goes *uploading → processing →
   saved ✓*; the document appears in Paperless. Repeat the same attachment → **already exists**.
4. **No secrets leak:** the API token / Paperless URL never appear in `rcmail.env`, page source, or
   any AJAX response.

For skin/layout changes, also do the headless UI check described in `CLAUDE.md`.
