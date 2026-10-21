# spycx/forms-kit

Web forms for framework-free PHP sites. It provides:
- anti-spam: a honeypot, a signed token and a per-IP rate limit;
- validation, and email delivery through `symfony/mailer`;
- a JS client with optional SweetAlert2 modals.

Built for static landing pages (`index.html` + one `contact.php`) that used to need Laravel just
for the contact form. User-facing messages default to Spanish and can all be overridden.

## Installation

The code lives at <https://github.com/spycx/forms-kit>. It isn't on Packagist yet, so declare the
repository in the site's `composer.json`. Use `dev-main` until there are tags, then `^0.1`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/spycx/forms-kit" }
    ],
    "require": {
        "spycx/forms-kit": "dev-main"
    },
    "scripts": {
        "post-install-cmd": ["forms-kit publish"],
        "post-update-cmd": ["forms-kit publish"]
    }
}
```

To **develop** the package while testing it in a site, temporarily add a `path` repository
**without symlink** *before* the `vcs` one, and don't commit it to the site's repo. It has to be
without symlink because `vendor/` is uploaded to the server, where a symlink wouldn't resolve:

```json
{ "type": "path", "url": "../forms-kit", "options": { "symlink": false } }
```

`forms-kit publish` copies `forms-kit.js` to the site root. `vendor/` must not be publicly served,
so the JS is copied out of it. The copy is overwritten on every `install`/`update`, so don't edit it.

## Server: `contact.php`

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Spycx\FormsKit\FormEndpoint;

FormEndpoint::fromEnv(__DIR__)
    ->field('name',    'Nombre',   required: true, max: 120, error: 'Por favor indica tu nombre completo.')
    ->field('email',   'Correo',   required: true, email: true, error: 'El correo electrónico no es válido.')
    ->field('phone',   'Teléfono', max: 40)
    ->field('message', 'Mensaje',  required: true, max: 5000, multiline: true)
    ->replyTo('email', 'name')
    ->subject('Contacto web: {name}')
    ->intro('Nuevo mensaje desde el formulario de contacto de Mi Sitio')
    ->fromName('Mi Sitio Web')
    ->template(__DIR__ . '/templates/contact-email.php')
    ->logo(__DIR__ . '/images/logo.png', 'logo@misitio')
    ->handle();
```

`fromEnv()` reads from `.env` (or the environment):

| Variable | Purpose |
|---|---|
| `MAILER_DSN` | symfony/mailer transport (`smtp://user:pass@host:587`; `null://null` for development) |
| `MAIL_TO` | Inbox that receives the messages |
| `MAIL_FROM` | Sender; must be a domain the SMTP server allows |
| `FORM_SECRET` | Key that signs the token. Generate one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. If missing, it's derived from `MAILER_DSN`. |

### What happens on each request

1. `GET contact.php?token` → `{ok: true, token}` with `Cache-Control: no-store`.
2. Any other method except `POST` → 405.
3. Missing `MAILER_DSN`/`MAIL_TO`/`MAIL_FROM` → 500, plus an `error_log` entry.
4. **Honeypot** filled (`website`), or a token younger than 3 s → 200 with a **fake success**. The bot isn't told and nothing is sent.
5. Missing, forged or more than 24 h old token → 422 «El formulario caducó…».
6. Field validation → 422 with the field's message.
7. **Per-IP limit:** more than 5 valid submissions in an hour → 429. Only submissions that passed validation count.
8. Send. If the transport fails → 502 with a generic message; the details go to `error_log`.

Responses are always JSON `{ok, message}`. PHP errors are never displayed.

### Options

| Method | Default |
|---|---|
| `field(name, label, required, max, email, multiline, error)` | — |
| `template(path)` | no HTML (text only) |
| `subject('… {field} …')` | `Nuevo mensaje del formulario web` |
| `intro(text)` | first line of the plain-text email |
| `fromName(name)` | `Formulario web` |
| `replyTo(emailField, nameField)` | no Reply-To |
| `logo(path, cid)` | no logo |
| `date(timezone, format)` | `America/Mexico_City`, `d/m/Y \a \l\a\s H:i` |
| `messages([...])` | see `FormEndpoint::DEFAULT_MESSAGES` |
| `guard(minSeconds, maxAge)` | 3 s, 24 h |
| `rateLimit(max, window, dir)` | 5 per 3600 s, in `sys_get_temp_dir()/forms-kit-<site hash>` |
| `clientIp(fn(array $server): string)` | `REMOTE_ADDR` |
| `mailer(MailerInterface)` | built from `MAILER_DSN` |

**Behind a proxy or CDN** (e.g. Cloudflare), every visitor arrives with the proxy's IP. Use
`clientIp()` to read the proxy's header, after checking that the request really comes from the proxy.

### Email template

A plain PHP template. All variables arrive **already escaped**, so the template must not escape
them again:

- one variable per field (`$name`, `$email`…);
- `$<field>Html` with `<br>` line breaks for `multiline` fields (e.g. `$messageHtml`);
- `$fields`: a list of `['name', 'label', 'value', 'html', 'multiline']`, for generic templates;
- `$date` and `$logoCid` (use it as `src="cid:<?= $logoCid ?>"`).

Use inline styles: email clients ignore `<style>`. Start the template with
`if (!isset($logoCid)) { http_response_code(404); exit; }`, so it shows nothing if someone requests
it directly by URL.

## Browser: `forms-kit.js`

```html
<form class="contact-form" action="contact.php" method="POST" novalidate>
  <div class="form-hp" aria-hidden="true">
    <label for="website">Sitio web</label>
    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
  </div>
  <input type="hidden" name="form_token" value="">
  <!-- fields… -->
  <button type="submit" class="btn-submit">Enviar</button>
  <p class="form-status" role="status" aria-live="polite"></p>
</form>

<!-- Optional: SweetAlert2 (with integrity) -->
<script src="forms-kit.js"></script>
<script src="index.js"></script>
```

```js
FormsKit.attach(document.querySelector('.contact-form'), {
  swal: { background: '#131512', color: '#F4F1EA', confirmButtonColor: '#2E5A44', customClass: { popup: 'swal-mysite' } },
  iconColors: { question: '#D2AE6D', success: '#4A8F6A', error: '#E06C6C' },
  texts: {
    confirmHtml: (safeName) => `Hola <strong>${safeName}</strong>, te responderemos pronto.`
  }
});
```

Options: `swal`, `iconColors`, `texts` (see `DEFAULT_TEXTS` in the file), `confirm`, `nameField`,
`tokenField`, `statusSelector`, `submitSelector`, `sendingClass`, `minWaitMs`, `maxTokenAgeMs`.

The client fetches the token on page load and waits so it never submits before the 3 s minimum.
It also renews the token when it gets old. If SweetAlert2 didn't load, it falls back to
`window.confirm` and writes the result into `.form-status` (with `is-success` / `is-error`).

**CSS the site must provide:** hide the honeypot **without** `display: none`, since some bots
detect that:

```css
.form-hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
```

## CSRF

The endpoint uses no sessions or cookies, so classic CSRF doesn't apply:
- a form on another site has no valid token, so it gets a 422;
- the other site's JS can't read `?token`, because no CORS headers are sent.

If a site adds sessions or logins, those forms do need their own CSRF token.

## Development

```bash
composer install
composer test
```
