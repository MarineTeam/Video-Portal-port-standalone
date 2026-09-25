<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * SMTP, spoken directly over a socket: STARTTLS or implicit TLS, AUTH PLAIN
 * or LOGIN. If the host blocks outbound HTTPS, this is usually the provider
 * that works; if it blocks ports 25/465/587 instead, the HTTPS APIs are.
 *
 * Presets fill the host, port and encryption for the common relays and say
 * which credential goes where.
 */
final class SmtpProvider extends BaseProvider implements EmailProvider
{
    public const PRESETS = [
        'host' => ['label' => 'This host’s own mail server', 'host' => 'localhost', 'port' => 25, 'encryption' => 'none', 'help' => 'On cPanel this is usually localhost, port 25, with no username — or the mailbox’s own address and password on port 587.'],
        'google' => ['label' => 'Google Workspace / Gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'starttls', 'help' => 'Username is the full address; password is an app password (Google Account → Security → App passwords), not the account password.'],
        'microsoft365' => ['label' => 'Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls', 'help' => 'SMTP AUTH must be enabled for the mailbox in the Microsoft 365 admin centre. If your tenant forbids it, use the Microsoft 365 (Graph) provider.'],
        'zoho' => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.com', 'port' => 465, 'encryption' => 'tls', 'help' => 'Use the mailbox address and an application-specific password.'],
        'fastmail' => ['label' => 'Fastmail', 'host' => 'smtp.fastmail.com', 'port' => 465, 'encryption' => 'tls', 'help' => 'Use an app password from Settings → Privacy & Security.'],
        'mailgun' => ['label' => 'Mailgun relay', 'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'starttls', 'help' => 'The SMTP login and password from your Mailgun domain’s settings (use smtp.eu.mailgun.org for an EU domain).'],
        'sendgrid' => ['label' => 'SendGrid relay', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'starttls', 'help' => 'Username is literally "apikey"; the password is an API key with Mail Send permission.'],
        'postmark' => ['label' => 'Postmark relay', 'host' => 'smtp.postmarkapp.com', 'port' => 587, 'encryption' => 'starttls', 'help' => 'Username and password are both the Server API token.'],
        'ses' => ['label' => 'Amazon SES relay', 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'starttls', 'help' => 'Use SES SMTP credentials (not your AWS access key); change the region in the host name to your SES region.'],
        'brevo' => ['label' => 'Brevo relay', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'starttls', 'help' => 'The SMTP login and key from Brevo → SMTP & API.'],
        'smtp2go' => ['label' => 'SMTP2GO', 'host' => 'mail.smtp2go.com', 'port' => 587, 'encryption' => 'starttls', 'help' => 'An SMTP user created in the SMTP2GO dashboard.'],
    ];

    private const TIMEOUT = 15;

    /** @var resource|null */
    private $socket = null;

    /** @var list<string> */
    private array $transcript = [];

    public static function slot(): string
    {
        return 'email';
    }

    public static function id(): string
    {
        return 'smtp';
    }

    public static function label(): string
    {
        return 'SMTP';
    }

    public static function limits(): string
    {
        return 'Many shared hosts block outbound ports 25, 465 and 587 to other servers; if the test cannot connect, use the host’s own mail server or an HTTPS API provider.';
    }

    public static function configSchema(): array
    {
        $presets = ['' => 'Custom'];
        foreach (self::PRESETS as $key => $preset) {
            $presets[$key] = $preset['label'];
        }
        return [
            ['key' => 'preset', 'label' => 'Preset', 'type' => 'select', 'options' => $presets, 'help' => 'Fills in the server details for a common provider.'],
            ['key' => 'host', 'label' => 'Server', 'type' => 'text', 'required' => true],
            ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'default' => 587],
            ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'options' => ['starttls' => 'STARTTLS (usually port 587)', 'tls' => 'TLS (usually port 465)', 'none' => 'None (only for the host’s own server)'], 'default' => 'starttls'],
            ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'secret' => true],
            ['key' => 'from', 'label' => 'Send as', 'type' => 'text', 'required' => true, 'help' => 'e.g. Grace Church <office@gracechurch.org>. Must be an address the server allows you to send from.'],
        ];
    }

    /** @return array{host: string, port: int, encryption: string} */
    private function server(): array
    {
        $preset = self::PRESETS[$this->str('preset')] ?? null;
        return [
            'host' => $this->str('host', $preset['host'] ?? 'localhost'),
            'port' => (int) $this->cfg('port', $preset['port'] ?? 587),
            'encryption' => $this->str('encryption', $preset['encryption'] ?? 'starttls'),
        ];
    }

    public function from(): string
    {
        return $this->str('from');
    }

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        try {
            $this->connect($steps);
            $this->disconnect();
        } catch (\Throwable $e) {
            $this->disconnect();
            return TestResult::fail($e->getMessage(), $steps);
        }
        $result = $this->send(Message::plain($context->adminEmail, 'Marine Team email test', "This is the test message from your site's SMTP settings. If you can read it, email works."), $this->from() ?: $context->adminEmail);
        if (!$result->ok()) {
            return TestResult::fail('The server refused the test message: ' . $result->error, $steps);
        }
        $steps[] = "Test message sent to {$context->adminEmail}";
        return TestResult::ok("Connected, signed in and sent a test message to {$context->adminEmail}.", $steps);
    }

    public function send(Message $message, string $from): SendResult
    {
        $from = $this->from() !== '' ? $this->from() : $from;
        $envelope = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : trim($from);
        if (preg_match('/[\r\n]/', $from) || !filter_var($envelope, FILTER_VALIDATE_EMAIL)) {
            return SendResult::failed('The "send as" address is not valid.');
        }
        $steps = [];
        try {
            $this->connect($steps);
            $this->command('MAIL FROM:<' . $envelope . '>', [250]);
            $this->command('RCPT TO:<' . $message->to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $id = bin2hex(random_bytes(12)) . '@' . (explode('@', $envelope)[1] ?? 'localhost');
            $this->write($this->mime($message, $from, $id) . "\r\n.");
            $this->expect([250]);
            $this->command('QUIT', [221]);
            $this->disconnect();
            return SendResult::sent("<$id>");
        } catch (\Throwable $e) {
            $this->disconnect();
            return SendResult::failed($e->getMessage());
        }
    }

    /** @param list<string> $steps */
    private function connect(array &$steps): void
    {
        $server = $this->server();
        $scheme = $server['encryption'] === 'tls' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("$scheme://{$server['host']}:{$server['port']}", $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("Couldn't connect to {$server['host']} on port {$server['port']} ($errstr). The host may block this port — ask them, or try port 587 or 465.");
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;
        $steps[] = "Connected to {$server['host']}:{$server['port']}";
        $this->expect([220]);
        $hostname = preg_replace('/[^a-z0-9.-]/i', '', (string) (gethostname() ?: 'localhost'));
        $ehlo = $this->command("EHLO $hostname", [250]);
        if ($server['encryption'] === 'starttls') {
            if (!str_contains(strtoupper($ehlo), 'STARTTLS')) {
                throw new \RuntimeException('The server does not offer STARTTLS on this port. Try encryption "TLS" on port 465.');
            }
            $this->command('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed: the server’s certificate could not be verified.');
            }
            $steps[] = 'Encrypted with STARTTLS';
            $ehlo = $this->command("EHLO $hostname", [250]);
        } elseif ($server['encryption'] === 'tls') {
            $steps[] = 'Encrypted with TLS';
        }
        $user = $this->str('username');
        if ($user !== '') {
            $password = $this->str('password');
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
                $this->command('AUTH PLAIN ' . base64_encode("\0$user\0$password"), [235], 'Signing in failed: check the username and password.');
            } else {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($user), [334]);
                $this->command(base64_encode($password), [235], 'Signing in failed: check the username and password.');
            }
            $steps[] = 'Signed in';
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** @param list<int> $codes */
    private function command(string $line, array $codes, ?string $failure = null): string
    {
        $this->write($line);
        return $this->expect($codes, $failure);
    }

    private function write(string $data): void
    {
        if ($this->socket === null || @fwrite($this->socket, $data . "\r\n") === false) {
            throw new \RuntimeException('Lost the connection to the mail server.');
        }
    }

    /** @param list<int> $codes */
    private function expect(array $codes, ?string $failure = null): string
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $codes, true)) {
            $said = trim(preg_replace('/\s+/', ' ', $reply) ?? '');
            throw new \RuntimeException(($failure ?? 'The mail server said no') . ($said !== '' ? " — \"$said\"" : ' (no answer)'));
        }
        return $reply;
    }

    private function mime(Message $message, string $from, string $id): string
    {
        $boundary = 'mt-' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $from,
            'To: <' . $message->to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($message->subject) . '?=',
            'Message-ID: <' . $id . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo;
        }
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message->text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message->html ?? nl2br(htmlspecialchars($message->text))))
            . "--$boundary--";
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}
