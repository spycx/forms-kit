<?php

declare(strict_types=1);

namespace Spycx\FormsKit\Tests;

use PHPUnit\Framework\TestCase;
use Spycx\FormsKit\FormEndpoint;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class FormEndpointTest extends TestCase
{
    private const SUCCESS = '¡Mensaje enviado con éxito! Nos pondremos en contacto muy pronto.';

    private string $dir;
    private int $now = 1_800_000_000;
    private object $mailer;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/forms-kit-test-' . bin2hex(random_bytes(6));
        $this->mailer = new class implements MailerInterface {
            /** @var list<Email> */
            public array $sent = [];
            public bool $fail = false;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->fail) {
                    throw new TransportException('SMTP caído: usuario:clave@smtp');
                }
                $this->sent[] = $message;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function endpoint(string $dsn = 'null://null'): FormEndpoint
    {
        return (new FormEndpoint($dsn, 'ventas@example.com', 'web@example.com', 'secreto', '/sitio'))
            ->field('name', 'Nombre', required: true, max: 120, error: 'Por favor indica tu nombre completo.')
            ->field('email', 'Correo', required: true, email: true, error: 'El correo electrónico no es válido.')
            ->field('phone', 'Teléfono', max: 40, error: 'El teléfono es demasiado largo.')
            ->field('message', 'Mensaje', required: true, max: 5000, multiline: true, error: 'Escribe tu mensaje o requerimiento.')
            ->replyTo('email', 'name')
            ->subject('Contacto web: {name}')
            ->intro('Nuevo mensaje desde el formulario de contacto de MexCoffee')
            ->fromName('MexCoffee Web')
            ->template(__DIR__ . '/fixtures/email.php')
            ->logo(__DIR__ . '/fixtures/logo.png', 'logo@mexcoffee')
            ->rateLimit(dir: $this->dir)
            ->mailer($this->mailer)
            ->clock(fn(): int => $this->now);
    }

    /** Token pedido hace $age segundos. */
    private function token(int $age = 5): string
    {
        $issuedAt = $this->now;
        $this->now -= $age;
        $token = $this->endpoint()->process('GET', ['token' => ''], [], [])->body['token'];
        $this->now = $issuedAt;
        return $token;
    }

    /** @param array<string, string> $overrides */
    private function post(array $overrides = [], string $ip = '203.0.113.9', ?FormEndpoint $endpoint = null): \Spycx\FormsKit\Response
    {
        $data = [
            'name'       => 'Ana López',
            'email'      => 'ana@example.com',
            'phone'      => '',
            'message'    => "Hola\nQuiero 10 sacos",
            'website'    => '',
            'form_token' => $this->token(),
            ...$overrides,
        ];
        return ($endpoint ?? $this->endpoint())->process('POST', [], $data, ['REMOTE_ADDR' => $ip]);
    }

    // --- Token y método ---

    public function testGetTokenIsNotCached(): void
    {
        $res = $this->endpoint()->process('GET', ['token' => ''], [], []);
        $this->assertSame(200, $res->status);
        $this->assertTrue($res->body['ok']);
        $this->assertMatchesRegularExpression('/^\d{10}\.[0-9a-f]{64}$/', $res->body['token']);
        $this->assertSame('no-store', $res->headers['Cache-Control']);
    }

    public function testOtherMethodsAreRejected(): void
    {
        $this->assertSame(405, $this->endpoint()->process('GET', [], [], [])->status);
        $this->assertSame(405, $this->endpoint()->process('PUT', [], [], [])->status);
    }

    public function testMissingMailConfigIs500(): void
    {
        $endpoint = new FormEndpoint('', 'a@example.com', 'b@example.com', 'k', '/sitio');
        $res = @$endpoint->process('POST', [], [], []);
        $this->assertSame(500, $res->status);
        $this->assertSame('El servidor no está configurado para enviar correos.', $res->body['message']);
    }

    // --- Anti-spam ---

    public function testHoneypotGetsFakeSuccessAndSendsNothing(): void
    {
        $res = $this->post(['website' => 'http://spam.example']);
        $this->assertSame([200, true, self::SUCCESS], [$res->status, $res->body['ok'], $res->body['message']]);
        $this->assertCount(0, $this->mailer->sent);
    }

    public function testTooFastGetsFakeSuccessAndSendsNothing(): void
    {
        $res = $this->post(['form_token' => $this->token(age: 1)]);
        $this->assertSame(200, $res->status);
        $this->assertCount(0, $this->mailer->sent);
    }

    public function testMissingOrForgedTokenIs422(): void
    {
        foreach (['', 'basura', time() . '.' . str_repeat('a', 64)] as $token) {
            $res = $this->post(['form_token' => $token]);
            $this->assertSame(422, $res->status);
            $this->assertSame('El formulario caducó. Recarga la página e intenta de nuevo.', $res->body['message']);
        }
        $this->assertCount(0, $this->mailer->sent);
    }

    // --- Validación ---

    public function testFieldErrorsUseTheirMessage(): void
    {
        $this->assertSame('Por favor indica tu nombre completo.', $this->post(['name' => '   '])->body['message']);
        $this->assertSame('Por favor indica tu nombre completo.', $this->post(['name' => str_repeat('a', 121)])->body['message']);
        $this->assertSame('El correo electrónico no es válido.', $this->post(['email' => 'no-es-correo'])->body['message']);
        $this->assertSame('El teléfono es demasiado largo.', $this->post(['phone' => str_repeat('1', 41)])->body['message']);
        $this->assertSame('Escribe tu mensaje o requerimiento.', $this->post(['message' => ''])->body['message']);
        $this->assertSame(422, $this->post(['message' => ['array']])->status);
        $this->assertCount(0, $this->mailer->sent);
    }

    public function testDefaultFieldErrorUsesLabel(): void
    {
        $endpoint = $this->endpoint()->field('company', 'Empresa', required: true);
        $res = $this->post([], endpoint: $endpoint);
        $this->assertSame('Revisa el campo «Empresa».', $res->body['message']);
    }

    // --- Envío ---

    public function testValidSubmissionSendsTheEmail(): void
    {
        $res = $this->post(['phone' => '+52 271 105 7277']);
        $this->assertSame([200, true, self::SUCCESS], [$res->status, $res->body['ok'], $res->body['message']]);
        $this->assertCount(1, $this->mailer->sent);

        $email = $this->mailer->sent[0];
        $this->assertSame('"MexCoffee Web" <web@example.com>', $email->getFrom()[0]->toString());
        $this->assertSame('ventas@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('"Ana López" <ana@example.com>', $email->getReplyTo()[0]->toString());
        $this->assertSame('Contacto web: Ana López', $email->getSubject());
    }

    public function testTextBodyFormat(): void
    {
        $this->post();
        $this->assertSame(
            "Nuevo mensaje desde el formulario de contacto de MexCoffee\n\n"
            . "Nombre:   Ana López\n"
            . "Correo:   ana@example.com\n"
            . "Teléfono: (no indicado)\n\n"
            . "Mensaje:\nHola\nQuiero 10 sacos\n",
            $this->mailer->sent[0]->getTextBody()
        );
    }

    public function testHtmlTemplateGetsEscapedValues(): void
    {
        $this->post(['name' => '<script>x</script>', 'message' => "<b>uno</b>\ndos"]);
        $html = $this->mailer->sent[0]->getHtmlBody();

        $this->assertStringContainsString('<h1>&lt;script&gt;x&lt;/script&gt;</h1>', $html);
        $this->assertStringContainsString("&lt;b&gt;uno&lt;/b&gt;<br />\ndos", $html);
        $this->assertStringContainsString('<dt>Teléfono</dt><dd></dd>', $html);
        $this->assertStringContainsString('src="cid:logo@mexcoffee"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testDateUsesConfiguredTimezone(): void
    {
        // 1_800_000_000 = 2027-01-15 08:00 UTC
        $this->post();
        $this->assertStringContainsString('<p class="date">15/01/2027 a las 02:00</p>', $this->mailer->sent[0]->getHtmlBody());

        $this->mailer->sent = [];
        $this->post(endpoint: $this->endpoint()->date('Europe/Madrid', 'Y-m-d H:i'));
        $this->assertStringContainsString('<p class="date">2027-01-15 09:00</p>', $this->mailer->sent[0]->getHtmlBody());
    }

    public function testLogoIsEmbeddedInline(): void
    {
        $this->post();
        $parts = $this->mailer->sent[0]->getAttachments();
        $this->assertCount(1, $parts);
        $this->assertSame('image/png', $parts[0]->getMediaType() . '/' . $parts[0]->getMediaSubtype());
        $this->assertSame('logo@mexcoffee', $parts[0]->getFilename());
        $this->assertStringContainsString('inline', $parts[0]->getPreparedHeaders()->get('Content-Disposition')->getBodyAsString());
    }

    public function testSubjectCannotInjectHeaders(): void
    {
        $email = $this->endpoint()->buildEmail(['name' => "Ana\r\nBcc: x@example.com", 'email' => 'ana@example.com']);
        $this->assertStringNotContainsString("\n", $email->getSubject());
    }

    public function testTransportErrorIs502WithoutLeakingDetails(): void
    {
        $this->mailer->fail = true;
        $res = @$this->post();
        $this->assertSame(502, $res->status);
        $this->assertSame('No pudimos enviar tu mensaje. Intenta de nuevo más tarde.', $res->body['message']);
        $this->assertStringNotContainsString('smtp', strtolower(json_encode($res->body)));
    }

    public function testMessagesCanBeOverridden(): void
    {
        $this->mailer->fail = true;
        $endpoint = $this->endpoint()->messages(['send_failed' => 'Escríbenos por WhatsApp.']);
        $this->assertSame('Escríbenos por WhatsApp.', @$this->post(endpoint: $endpoint)->body['message']);
    }

    // --- Límite por IP ---

    public function testRateLimitPerIpAfterValidation(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->post()->status);
        }
        // Un error de validación no gasta intentos ni cambia la respuesta
        $this->assertSame(422, $this->post(['email' => 'mal'])->status);

        $res = $this->post();
        $this->assertSame(429, $res->status);
        $this->assertSame('Recibimos varios mensajes seguidos desde tu conexión. Intenta más tarde.', $res->body['message']);

        $this->assertSame(200, $this->post(ip: '198.51.100.7')->status, 'otra IP no está limitada');
        $this->assertCount(6, $this->mailer->sent);

        $this->now += 3601;
        $this->assertSame(200, $this->post()->status, 'la ventana se libera');
    }

    public function testCustomClientIpResolver(): void
    {
        $endpoint = $this->endpoint()
            ->rateLimit(max: 1, dir: $this->dir)
            ->clientIp(static fn(array $server): string => $server['HTTP_CF_CONNECTING_IP'] ?? '');

        $first  = $endpoint->process('POST', [], $this->validData(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1']);
        $second = $endpoint->process('POST', [], $this->validData(), ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '2.2.2.2']);
        $third  = $endpoint->process('POST', [], $this->validData(), ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1']);

        $this->assertSame([200, 200, 429], [$first->status, $second->status, $third->status]);
    }

    /** @return array<string, string> */
    private function validData(): array
    {
        return ['name' => 'Ana', 'email' => 'ana@example.com', 'message' => 'hola', 'form_token' => $this->token()];
    }

    // --- fromEnv ---

    public function testFromEnvReadsDotEnvAndDerivesSecret(): void
    {
        $site = sys_get_temp_dir() . '/forms-kit-site-' . bin2hex(random_bytes(6));
        mkdir($site);
        file_put_contents($site . '/.env', "MAILER_DSN=null://null\nMAIL_TO=a@example.com\nMAIL_FROM=b@example.com\n");
        try {
            $endpoint = FormEndpoint::fromEnv($site)->field('message', 'Mensaje', required: true)->rateLimit(dir: $this->dir)->mailer($this->mailer);
            $token = $endpoint->process('GET', ['token' => ''], [], [])->body['token'];

            // La misma clave derivada verifica el token después de 3 s
            $ts = (int) explode('.', $token)[0] - 5;
            $old = $ts . '.' . hash_hmac('sha256', (string) $ts, hash('sha256', 'forms-kit|null://null'));
            $res = $endpoint->process('POST', [], ['message' => 'hola', 'form_token' => $old], ['REMOTE_ADDR' => '1.1.1.1']);
            $this->assertSame(200, $res->status);
            $this->assertCount(1, $this->mailer->sent);
        } finally {
            unlink($site . '/.env');
            rmdir($site);
            foreach (['MAILER_DSN', 'MAIL_TO', 'MAIL_FROM', 'SYMFONY_DOTENV_VARS'] as $var) {
                unset($_ENV[$var], $_SERVER[$var]);
                putenv($var);
            }
        }
    }
}
