# spycx/forms-kit

Formularios web para sitios PHP sin framework: anti-spam (honeypot + token firmado +
límite por IP), validación y envío por correo con `symfony/mailer`, más un cliente JS
con modales de SweetAlert2 opcionales.

Pensado para landing pages estáticas (`index.html` + un `contact.php`) que antes
necesitaban Laravel solo para el formulario.

## Instalación

Mientras el paquete sea privado, se declara su repositorio en el `composer.json` del sitio:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:spycx/forms-kit.git" }
    ],
    "require": {
        "spycx/forms-kit": "^0.1"
    },
    "scripts": {
        "post-install-cmd": ["forms-kit publish"],
        "post-update-cmd": ["forms-kit publish"]
    }
}
```

Para desarrollar el paquete junto a un sitio, usa un repositorio `path` **sin symlink**,
porque `vendor/` se sube al servidor y un symlink no funcionaría allá:

```json
{ "type": "path", "url": "../forms-kit", "options": { "symlink": false } }
```

con `"spycx/forms-kit": "dev-main"`.

`forms-kit publish` copia `forms-kit.js` a la raíz del sitio. `vendor/` no debe servirse
públicamente, por eso el JS se copia. La copia se sobrescribe en cada `install`/`update`:
no la edites.

## Servidor: `contact.php`

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

`fromEnv()` lee de `.env` (o del entorno):

| Variable | Uso |
|---|---|
| `MAILER_DSN` | Transporte de symfony/mailer (`smtp://usuario:clave@host:587`, `null://null` en desarrollo) |
| `MAIL_TO` | Buzón que recibe los mensajes |
| `MAIL_FROM` | Remitente; debe ser un dominio que el SMTP permita |
| `FORM_SECRET` | Clave para firmar el token. Se genera con `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Si falta, se deriva de `MAILER_DSN`. |

### Qué hace con cada petición

1. `GET contact.php?token` → `{ok: true, token}` con `Cache-Control: no-store`.
2. Cualquier otro método distinto de `POST` → 405.
3. Sin `MAILER_DSN`/`MAIL_TO`/`MAIL_FROM` → 500 (y `error_log`).
4. **Honeypot** (`website` lleno) o token con menos de 3 s → 200 con **éxito falso**: el bot
   no se entera y no se envía nada.
5. Token ausente, falsificado o con más de 24 h → 422 «El formulario caducó…».
6. Validación de campos → 422 con el mensaje del campo.
7. **Límite por IP:** más de 5 envíos válidos en una hora → 429. Solo cuentan los que pasaron
   la validación.
8. Envío. Si el transporte falla → 502 con mensaje genérico; el detalle va a `error_log`.

Las respuestas son siempre JSON `{ok, message}`. Los errores de PHP nunca se muestran en pantalla.

### Opciones

| Método | Por defecto |
|---|---|
| `field(name, label, required, max, email, multiline, error)` | — |
| `template(path)` | sin HTML (solo texto) |
| `subject('… {campo} …')` | `Nuevo mensaje del formulario web` |
| `intro(texto)` | primera línea del correo de texto |
| `fromName(nombre)` | `Formulario web` |
| `replyTo(campoCorreo, campoNombre)` | sin Reply-To |
| `logo(path, cid)` | sin logo |
| `date(zonaHoraria, formato)` | `America/Mexico_City`, `d/m/Y \a \l\a\s H:i` |
| `messages([...])` | ver `FormEndpoint::DEFAULT_MESSAGES` |
| `guard(minSeconds, maxAge)` | 3 s, 24 h |
| `rateLimit(max, window, dir)` | 5 por 3600 s, en `sys_get_temp_dir()/forms-kit-<hash del sitio>` |
| `clientIp(fn(array $server): string)` | `REMOTE_ADDR` |
| `mailer(MailerInterface)` | se crea desde `MAILER_DSN` |

**Detrás de un proxy o CDN** (p. ej. Cloudflare) todos llegan con la IP del proxy. Usa
`clientIp()` para leer la cabecera del proxy, validando que la petición viene de él.

### Plantilla del correo

Plantilla PHP normal. Todas las variables llegan **ya escapadas**, así que la plantilla no
debe volver a escapar:

- una variable por campo (`$name`, `$email`…);
- `$<campo>Html` con `<br>` para los campos `multiline` (p. ej. `$messageHtml`);
- `$fields`: lista de `['name', 'label', 'value', 'html', 'multiline']`, para plantillas genéricas;
- `$date` y `$logoCid` (úsalo como `src="cid:<?= $logoCid ?>"`).

Usa estilos en línea: los clientes de correo ignoran `<style>`. Conviene que empiece con
`if (!isset($logoCid)) { http_response_code(404); exit; }` por si alguien la pide directo
por URL.

## Navegador: `forms-kit.js`

```html
<form class="contacto-form" action="contact.php" method="POST" novalidate>
  <div class="form-hp" aria-hidden="true">
    <label for="website">Sitio web</label>
    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
  </div>
  <input type="hidden" name="form_token" value="">
  <!-- campos… -->
  <button type="submit" class="btn-submit">Enviar</button>
  <p class="form-status" role="status" aria-live="polite"></p>
</form>

<!-- Opcional: SweetAlert2 (con integrity) -->
<script src="forms-kit.js"></script>
<script src="index.js"></script>
```

```js
FormsKit.attach(document.querySelector('.contacto-form'), {
  swal: { background: '#131512', color: '#F4F1EA', confirmButtonColor: '#2E5A44', customClass: { popup: 'swal-misitio' } },
  iconColors: { question: '#D2AE6D', success: '#4A8F6A', error: '#E06C6C' },
  texts: {
    confirmHtml: (safeName) => `Hola <strong>${safeName}</strong>, te responderemos pronto.`
  }
});
```

Opciones: `swal`, `iconColors`, `texts` (ver `DEFAULT_TEXTS` en el archivo), `confirm`,
`nameField`, `tokenField`, `statusSelector`, `submitSelector`, `sendingClass`, `minWaitMs`,
`maxTokenAgeMs`.

El cliente pide el token al cargar, espera lo necesario para no enviar antes de los 3 s y
renueva el token si es viejo. Si SweetAlert2 no cargó, usa `window.confirm` y escribe el
resultado en `.form-status` (con `is-success` / `is-error`).

**CSS que debe poner el sitio:** ocultar el honeypot **sin** `display: none`, porque algunos
bots lo detectan:

```css
.form-hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
```

## CSRF

El endpoint no usa sesiones ni cookies, así que no hay CSRF clásico. Un formulario de otro
sitio no tiene token válido (422), y su JS no puede leer `?token` porque no se envían
cabeceras CORS. Si un sitio agrega sesiones o login, esos formularios sí necesitan su propio
token CSRF.

## Desarrollo

```bash
composer install
composer test
```
