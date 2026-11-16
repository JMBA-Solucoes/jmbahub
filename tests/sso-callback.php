<?php

namespace Glpi\Controller { class AbstractController {} }
namespace GlpiPlugin\Jmbahub {
    final class PluginConfig
    {
        public static bool $enabled = false;
        public static function isSsoEnabled(): bool { return self::$enabled; }
        public static function getExistingInstallationId(): ?string { return '12345678-1234-1234-1234-123456789abc'; }
        public static function getNotificationTransport(): ?array
        { return ['endpoint' => 'https://api.example/webhook/glpi', 'secret' => str_repeat('A', 43)]; }
        public static function getSsoReturnUri(): ?string { return 'jmbahub-jmbasolucoes://sso/complete'; }
        public static function getApiBaseUrl(): string { return 'https://api.example'; }
    }
    final class SsoRestSession
    {
        public static function create(): string { return str_repeat('A', 43); }
        public static function discard(string $token): void {}
    }
    final class SsoWebIdentity
    {
        public static int $userId = 42;
        public static int $provider = 1;
        public static function providerFromCurrentSession(): int { return self::$provider; }
        public static function fromCurrentSession(): array { return ['userId' => self::$userId]; }
    }
}
namespace Symfony\Component\HttpFoundation {
    final class ParameterBag
    {
        public function __construct(private array $data) {}
        public function get(string $key): mixed { return $this->data[$key] ?? null; }
    }
    final class Request
    {
        public ParameterBag $query;
        public function __construct(private string $method, string $attempt, bool $automatic = false)
        {
            $this->query = new ParameterBag(['attempt' => $attempt, 'automatic' => $automatic ? '1' : null]);
        }
        public function isMethod(string $method): bool { return $this->method === $method; }
        public function isXmlHttpRequest(): bool { return false; }
    }
    final class Response
    {
        public object $headers;
        public function __construct(public string $content, public int $status = 200, array $headers = []) {
            $this->headers = new class($headers) {
                public function __construct(public array $values) {}
                public function set(string $key, string $value): void { $this->values[$key] = $value; }
            };
        }
    }
}
namespace {
    final class Session { public static function getNewCSRFToken(bool $standalone): string { return 'test-csrf'; } }
    final class Toolbox
    {
        public static array $sent = [];
        public static function getGuzzleClient(array $options): object
        {
            return new class {
                public function post(string $url, array $options): object
                {
                    Toolbox::$sent[] = [$url, $options['json']];
                    return new class { public function getStatusCode(): int { return 204; } };
                }
            };
        }
    }
    require dirname(__DIR__) . '/src/SsoInstallationProof.php';
    require dirname(__DIR__) . '/src/Controller/SsoCallbackController.php';
    $controller = new \GlpiPlugin\Jmbahub\Controller\SsoCallbackController();
    $attempt = str_repeat('B', 43);
    $request = fn (string $method) => new \Symfony\Component\HttpFoundation\Request($method, $attempt);
    $_SESSION = [];
    if ($controller($request('GET'))->status !== 404) throw new RuntimeException('SSO must default to disabled');
    \GlpiPlugin\Jmbahub\PluginConfig::$enabled = true;
    $page = $controller($request('GET'));
    if ($page->status !== 200 || !str_contains($page->content, '_glpi_csrf_token')) throw new RuntimeException('Consent form missing');
    $response = $controller($request('POST'));
    if (str_contains($response->content, str_repeat('A', 43))) throw new RuntimeException('Session exposed in browser');
    if ($response->status !== 200 || count(Toolbox::$sent) !== 1) throw new RuntimeException('Consent was not confirmed');
    $sent = Toolbox::$sent[0][1];
    if (array_keys($sent) !== ['attemptId', 'installationId', 'providerAppId', 'userId', 'signature', 'sessionToken'] || $sent['userId'] !== 42
        || $sent['signature'] !== \GlpiPlugin\Jmbahub\SsoInstallationProof::sign($sent['installationId'],str_repeat('A',43),$attempt,42,$sent['sessionToken'],null,1)) {
        throw new RuntimeException('Unexpected confirmation payload');
    }
    if ($controller($request('POST'))->status !== 400 || count(Toolbox::$sent) !== 1) throw new RuntimeException('Repeated consent accepted');
    $controller($request('GET'));
    \GlpiPlugin\Jmbahub\SsoWebIdentity::$userId = 43;
    if ($controller($request('POST'))->status !== 400 || count(Toolbox::$sent) !== 1) throw new RuntimeException('Changed identity accepted');
    $controller($request('GET'));
    \GlpiPlugin\Jmbahub\SsoWebIdentity::$provider = 2;
    if ($controller($request('POST'))->status !== 400) throw new RuntimeException('Changed provider accepted');
    \GlpiPlugin\Jmbahub\SsoWebIdentity::$provider = 1;
    $auto = fn (string $method) => new \Symfony\Component\HttpFoundation\Request($method, $attempt, true);
    $page = $controller($auto('GET'));
    if (!str_contains($page->content, 'requestSubmit()') || !str_contains($page->content, '_glpi_csrf_token')) throw new RuntimeException('Automatic POST missing CSRF');
    if (!str_contains($page->headers->values['Content-Security-Policy'], "script-src 'nonce-")) throw new RuntimeException('Script CSP nonce missing');
    $response = $controller($auto('POST'));
    $target = $response->headers->values['Location'] ?? '';
    if ($response->status !== 302 || !str_starts_with($target, 'jmbahub-jmbasolucoes://sso/complete#attempt=')) throw new RuntimeException('Automatic return missing');
    parse_str(parse_url($target, PHP_URL_FRAGMENT), $fields);
    $sent = Toolbox::$sent[1][1];
    if (($sent['handoffHash'] ?? '') !== hash('sha256', $fields['handoff'])) throw new RuntimeException('Handoff mismatch');
    if (str_contains($target, $sent['sessionToken']) || str_contains($response->content, $sent['sessionToken'])) throw new RuntimeException('Session exposed in callback');
    if ($controller($auto('POST'))->status !== 400) throw new RuntimeException('Automatic callback replay accepted');
    echo "SSO callback checks passed (CSRF listener requires GLPI integration test)\n";
}
