<?php

declare(strict_types=1);

namespace Spycx\FormsKit;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Form endpoint: serves the anti-spam token (GET ?token) and processes the POST
 * (anti-spam → validation → per-IP limit → email). Always responds with JSON {ok, message}.
 *
 * Typical use in contact.php:
 *
 *     FormEndpoint::fromEnv(__DIR__)
 *         ->field('name', 'Nombre', required: true, max: 120)
 *         ->field('email', 'Correo', required: true, email: true)
 *         ->field('message', 'Mensaje', required: true, max: 5000, multiline: true)
 *         ->replyTo('email', 'name')
 *         ->template(__DIR__ . '/templates/contact-email.php')
 *         ->handle();
 */
final class FormEndpoint
{
    public const DEFAULT_MESSAGES = [
        'success'        => '¡Mensaje enviado con éxito! Nos pondremos en contacto muy pronto.',
        'expired'        => 'El formulario caducó. Recarga la página e intenta de nuevo.',
        'rate_limited'   => 'Recibimos varios mensajes seguidos desde tu conexión. Intenta más tarde.',
        'send_failed'    => 'No pudimos enviar tu mensaje. Intenta de nuevo más tarde.',
        'not_configured' => 'El servidor no está configurado para enviar correos.',
        'method'         => 'Método no permitido.',
        'invalid_field'  => 'Revisa el campo «{label}».',
        'empty_value'    => '(no indicado)',
    ];

    /** @var array<string, Field> */
    private array $fields = [];
    /** @var array<string, string> */
    private array $messages = self::DEFAULT_MESSAGES;

    private ?string $template = null;
    private string $subject = 'Nuevo mensaje del formulario web';
    private string $intro = 'Nuevo mensaje desde el formulario web';
    private string $fromName = 'Formulario web';
    private ?string $replyToField = null;
    private ?string $replyToNameField = null;
    private ?string $logoPath = null;
    private string $logoCid = 'logo';
    private string $timezone = 'America/Mexico_City';
    private string $dateFormat = 'd/m/Y \a \l\a\s H:i';

    private int $minSeconds = 3;
    private int $maxAge = 86400;
    private int $rateMax = 5;
    private int $rateWindow = 3600;
    private string $rateDir;

    private Closure $clientIp;
    private Closure $clock;
    private ?MailerInterface $mailer = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $to,
        private readonly string $from,
        private readonly string $secret,
        string $baseDir,
    ) {
        // One directory per site, so two sites on the same server don't share counters
        $this->rateDir  = sys_get_temp_dir() . '/forms-kit-' . substr(hash('sha256', $baseDir), 0, 12);
        $this->clientIp = static fn(array $server): string => (string) ($server['REMOTE_ADDR'] ?? '');
        $this->clock    = time(...);
    }

    /**
     * Reads MAILER_DSN, MAIL_TO, MAIL_FROM and FORM_SECRET from $baseDir/.env (if present) or the environment.
     * Without FORM_SECRET the key is derived from MAILER_DSN, which is already secret.
     */
    public static function fromEnv(string $baseDir): self
    {
        if (is_file($baseDir . '/.env')) {
            (new Dotenv())->load($baseDir . '/.env');
        }
        $env = static fn(string $name): string => (string) ($_ENV[$name] ?? getenv($name) ?: '');

        $dsn = $env('MAILER_DSN');
        $secret = $env('FORM_SECRET') ?: hash('sha256', 'forms-kit|' . $dsn);

        return new self($dsn, $env('MAIL_TO'), $env('MAIL_FROM'), $secret, $baseDir);
    }

    // --- Configuration ---------------------------------------------------

    public function field(
        string $name,
        string $label,
        bool $required = false,
        ?int $max = null,
        bool $email = false,
        bool $multiline = false,
        ?string $error = null,
    ): self {
        $this->fields[$name] = new Field($name, $label, $required, $max, $email, $multiline, $error);
        return $this;
    }

    /** PHP template for the HTML email. It receives already-escaped variables (see templateVars()). */
    public function template(string $path): self
    {
        $this->template = $path;
        return $this;
    }

    /** Subject; accepts {field} placeholders, e.g. "Contacto web: {name}". */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /** First line of the plain-text email. */
    public function intro(string $intro): self
    {
        $this->intro = $intro;
        return $this;
    }

    public function fromName(string $name): self
    {
        $this->fromName = $name;
        return $this;
    }

    /** Field holding the visitor's email (and optionally their name) for Reply-To. */
    public function replyTo(string $emailField, ?string $nameField = null): self
    {
        $this->replyToField = $emailField;
        $this->replyToNameField = $nameField;
        return $this;
    }

    /** Image embedded in the email; use it in the template as src="cid:<?= $logoCid ?>". */
    public function logo(string $path, string $cid = 'logo'): self
    {
        $this->logoPath = $path;
        $this->logoCid = $cid;
        return $this;
    }

    /** Timezone and format of the template's $date variable. */
    public function date(string $timezone, string $format = 'd/m/Y \a \l\a\s H:i'): self
    {
        $this->timezone = $timezone;
        $this->dateFormat = $format;
        return $this;
    }

    /** @param array<string, string> $messages Overrides entries of DEFAULT_MESSAGES. */
    public function messages(array $messages): self
    {
        $this->messages = [...$this->messages, ...$messages];
        return $this;
    }

    /** Minimum and maximum seconds between requesting the token and submitting the form. */
    public function guard(int $minSeconds = 3, int $maxAge = 86400): self
    {
        $this->minSeconds = $minSeconds;
        $this->maxAge = $maxAge;
        return $this;
    }

    /** Valid submissions allowed per IP within the window (seconds). */
    public function rateLimit(int $max = 5, int $window = 3600, ?string $dir = null): self
    {
        $this->rateMax = $max;
        $this->rateWindow = $window;
        $this->rateDir = $dir ?? $this->rateDir;
        return $this;
    }

    /**
     * How to get the visitor's IP (REMOTE_ADDR by default). Behind a proxy or CDN, read the
     * proxy's header here, after checking that the request really comes from the proxy.
     *
     * @param Closure(array<string, mixed>): string $resolver Receives $_SERVER.
     */
    public function clientIp(Closure $resolver): self
    {
        $this->clientIp = $resolver;
        return $this;
    }

    public function mailer(MailerInterface $mailer): self
    {
        $this->mailer = $mailer;
        return $this;
    }

    /** @param Closure(): int $clock */
    public function clock(Closure $clock): self
    {
        $this->clock = $clock;
        return $this;
    }

    // --- Execution -------------------------------------------------------

    /** Processes the current request, sends the response and exits. */
    public function handle(): never
    {
        // Errors go only to the log: on screen they'd break the JSON and reveal server paths
        ini_set('display_errors', '0');

        $this->process((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, $_POST, $_SERVER)->send();
        exit;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function process(string $method, array $query, array $post, array $server): Response
    {
        $guard = new FormGuard($this->secret, $this->minSeconds, $this->maxAge, clock: $this->clock);

        if ($method === 'GET' && isset($query['token'])) {
            return new Response(200, ['ok' => true, 'token' => $guard->issueToken()], ['Cache-Control' => 'no-store']);
        }
        if ($method !== 'POST') {
            return $this->fail(405, 'method');
        }
        if ($this->dsn === '' || $this->to === '' || $this->from === '') {
            error_log('forms-kit: MAILER_DSN, MAIL_TO or MAIL_FROM is missing');
            return $this->fail(500, 'not_configured');
        }

        // Spam: fake success so the bot doesn't learn it was caught
        switch ($guard->check($post)) {
            case Verdict::Spam:
                return $this->ok();
            case Verdict::Expired:
                return $this->fail(422, 'expired');
            case Verdict::Ok:
                break;
        }

        $values = [];
        foreach ($this->fields as $name => $field) {
            $raw = $post[$name] ?? '';
            $values[$name] = is_string($raw) ? trim($raw) : '';
            if (!$field->isValid($values[$name])) {
                $message = $field->error ?? str_replace('{label}', $field->label, $this->messages['invalid_field']);
                return new Response(422, ['ok' => false, 'message' => $message]);
            }
        }

        // Only count what passed validation, so a typo doesn't use up attempts
        $limiter = new RateLimiter($this->rateDir, $this->rateMax, $this->rateWindow, $this->clock);
        if (!$limiter->attempt(($this->clientIp)($server))) {
            return $this->fail(429, 'rate_limited');
        }

        try {
            ($this->mailer ?? new Mailer(Transport::fromDsn($this->dsn)))->send($this->buildEmail($values));
        } catch (TransportExceptionInterface $e) {
            error_log('forms-kit: failed to send email: ' . $e->getMessage());
            return $this->fail(502, 'send_failed');
        }

        return $this->ok();
    }

    // --- Email -----------------------------------------------------------

    /** @param array<string, string> $values */
    public function buildEmail(array $values): Email
    {
        $subject = preg_replace_callback(
            '/\{(\w+)\}/',
            static fn(array $m): string => $values[$m[1]] ?? $m[0],
            $this->subject
        );

        $email = (new Email())
            ->from(new Address($this->from, $this->fromName))
            ->to($this->to)
            ->subject(str_replace(["\r", "\n"], ' ', (string) $subject))
            ->text($this->textBody($values));

        if ($this->replyToField !== null && ($values[$this->replyToField] ?? '') !== '') {
            $name = $this->replyToNameField !== null ? ($values[$this->replyToNameField] ?? '') : '';
            $email->replyTo(new Address($values[$this->replyToField], $name));
        }
        if ($this->template !== null) {
            $email->html(self::render($this->template, $this->templateVars($values)));
        }
        if ($this->logoPath !== null && is_file($this->logoPath)) {
            // Type from the extension: guessing it from the content requires ext-fileinfo
            $type = match (strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION))) {
                'png'         => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif'         => 'image/gif',
                'webp'        => 'image/webp',
                default       => null,
            };
            $email->addPart((new DataPart(new File($this->logoPath), $this->logoCid, $type))->asInline());
        }

        return $email;
    }

    /**
     * Plain text: short fields aligned, then the long-text fields.
     *
     * @param array<string, string> $values
     */
    public function textBody(array $values): string
    {
        $short = array_filter($this->fields, static fn(Field $f): bool => !$f->multiline);
        $long  = array_filter($this->fields, static fn(Field $f): bool => $f->multiline);
        $width = max([0, ...array_map(static fn(Field $f): int => mb_strlen($f->label) + 1, $short)]);

        $text = $this->intro . "\n\n";
        foreach ($short as $name => $field) {
            $label = $field->label . ':';
            $value = ($values[$name] ?? '') !== '' ? $values[$name] : $this->messages['empty_value'];
            $text .= $label . str_repeat(' ', $width - mb_strlen($label) + 1) . $value . "\n";
        }
        foreach ($long as $name => $field) {
            $text .= "\n" . $field->label . ":\n" . ($values[$name] ?? '') . "\n";
        }
        return $text;
    }

    /**
     * Template variables, already escaped (the template must not escape them again):
     * - one per field ($name, $email…), plus "{field}Html" with <br> for long-text fields;
     * - $fields: a list of ['name', 'label', 'value', 'html', 'multiline'] for generic templates;
     * - $date and $logoCid.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    public function templateVars(array $values): array
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $vars = ['fields' => []];
        foreach ($this->fields as $name => $field) {
            $value = $e($values[$name] ?? '');
            $vars[$name] = $value;
            if ($field->multiline) {
                $vars[$name . 'Html'] = nl2br($value);
            }
            $vars['fields'][] = [
                'name'      => $name,
                'label'     => $e($field->label),
                'value'     => $value,
                'html'      => nl2br($value),
                'multiline' => $field->multiline,
            ];
        }
        $vars['date']    = (new DateTimeImmutable('@' . ($this->clock)()))
            ->setTimezone(new DateTimeZone($this->timezone))
            ->format($this->dateFormat);
        $vars['logoCid'] = $this->logoCid;
        return $vars;
    }

    /** @param array<string, mixed> $vars */
    private static function render(string $path, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }

    private function ok(): Response
    {
        return new Response(200, ['ok' => true, 'message' => $this->messages['success']]);
    }

    private function fail(int $status, string $key): Response
    {
        return new Response($status, ['ok' => false, 'message' => $this->messages[$key]]);
    }
}
