# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`spycx/forms-kit`: a small, framework-free Composer library for the contact/quote forms on static landing pages (`index.html` + a `contact.php`). It replaces installing Laravel just to handle one form. It has two halves that must stay in sync:

- **PHP** (`src/`, namespace `Spycx\FormsKit\`) — `FormEndpoint` serves the anti-spam token and processes the POST: anti-spam → validation → per-IP limit → email via `symfony/mailer`. Responses are always JSON `{ok, message}`.
- **JS** (`resources/js/forms-kit.js`) — the browser client. Sites don't load it from `vendor/` (their `.htaccess` blocks it). `bin/forms-kit publish` copies it into the site's web root, usually from the site's `post-install-cmd`/`post-update-cmd`.

User-facing docs are in `README.md` (Spanish). Messages, comments and docs are in Spanish; identifiers are English.

## Commands

```bash
composer install
composer test                                   # PHPUnit 11 (vendor/bin/phpunit)
vendor/bin/phpunit --filter testRateLimit       # single test
node --check resources/js/forms-kit.js          # JS syntax (there is no JS test suite)
```

## Architecture

- `FormGuard` — stateless anti-spam. The token is `timestamp.hmac_sha256(timestamp, secret)`. `check()` returns a `Verdict`:
  - `Spam` — the honeypot is filled, or the token is younger than `minSeconds`. The caller answers with a **fake success**.
  - `Expired` — the token is missing, forged or older than `maxAge`. The caller answers 422.
  - `Ok`.
- `RateLimiter` — one JSON file of timestamps per hashed key, with `flock`. **Fails open** if the directory isn't writable: never block real users because of storage problems.
- `Field` — validation rules (`required`, `max`, `email`) plus how the field appears in the email (`label`, `multiline`).
- `FormEndpoint` — fluent configuration. `fromEnv()` reads `MAILER_DSN`, `MAIL_TO`, `MAIL_FROM` and `FORM_SECRET`, falling back to a key derived from the DSN. `process()` is a pure-ish function from `(method, query, post, server)` to `Response`, and tests call it directly. `handle()` wraps it for real requests and turns off `display_errors`.
- **Order inside `process()` is deliberate:**
  1. GET `?token`;
  2. 405 for other methods;
  3. 500 when mail settings are missing;
  4. the guard;
  5. validation;
  6. the rate limit — after validation, so typos don't spend attempts;
  7. send.
  
  Tests pin this order.
- Template variables are **escaped by the package** (`templateVars()`): one variable per field, `{field}Html` with `<br>` for multiline fields, `$fields`, `$date`, `$logoCid`. Templates must not escape again. Changing this contract breaks every site's email template.
- Time comes from an injectable `clock` closure everywhere (`FormGuard`, `RateLimiter`, `$date`). Use it in tests, never `sleep()`.

## Compatibility rules (sites depend on this)

Sites known to use it: `~/projects/mexcoffee` and `~/projects/landingpage-skeleton` (and every site created from the skeleton). Treat these as public API:
- `FormEndpoint` method names and named arguments, `DEFAULT_MESSAGES` keys, and the JSON response shape;
- template variables;
- form field names `website` and `form_token`, and the `?token` endpoint;
- `FormsKit.attach(form, options)`, its option names and `DEFAULT_TEXTS` keys.

While on 0.x, a breaking change means a minor version bump plus a note in the commit message. Prefer adding options with defaults that keep current behavior.

Security behavior is intentional; don't "simplify" it away:
- fake success for bots;
- generic 502 messages, with SMTP details only in `error_log`;
- `hash_equals` for the signature check;
- stripping CR/LF from the subject;
- the logo's MIME type taken from its extension (avoids needing ext-fileinfo);
- no CORS headers on `?token`. Their absence is what stops other sites' JS from reading a token.

## JS client constraints

Plain classic script exposing `window.FormsKit`. There's no build step, no modules and no dependencies, and it must run under a strict CSP (`script-src 'self'`): no `eval`, no inline handlers. SweetAlert2 is optional; every step needs the non-Swal fallback (`window.confirm`, the `.form-status` element). `minWaitMs` must stay above the server's `minSeconds`.

## Packaging

`.gitattributes` `export-ignore`s `tests/`, `vendor/`, dev files. Composer also honors it when mirroring a `path` repository with `symlink: false` (how sites currently install it). Without it the package's own `vendor/` would be copied into each site's `vendor/` and uploaded. Keep new dev-only files listed there.

## Verifying a change against a real site

After committing here:

```bash
cd ~/projects/mexcoffee && composer update spycx/forms-kit   # re-mirrors and republishes forms-kit.js
```

Then exercise the form with `MAILER_DSN=null://null`. **Check the site's `.env` first: MexCoffee's points at real SMTP, so test on a copy with a null DSN.**

## Releasing

Not yet on a Git host. Once pushed, tag versions (`v0.1.0`…) and switch consumers from the `path` repository to `{"type": "vcs", "url": "…"}` with `^0.x`.
