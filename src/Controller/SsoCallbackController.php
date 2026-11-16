<?php

namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Jmbahub\PluginConfig;
use GlpiPlugin\Jmbahub\SsoInstallationProof;
use GlpiPlugin\Jmbahub\SsoWebIdentity;
use GlpiPlugin\Jmbahub\SsoRestSession;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Toolbox;

/** Retorno do navegador: exige sessão GLPI e POST protegido pelo CSRF nativo. */
final class SsoCallbackController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/SsoCallback', name: 'jmbahub_sso_callback', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if (!PluginConfig::isSsoEnabled()) return $this->page('SSO indisponível.', 404);
        $attempt = $request->query->get('attempt');
        if (!is_string($attempt) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $attempt)) {
            return $this->page('Este acesso não é válido. Abra novamente o login pelo aplicativo.', 400);
        }
        try {
            $identity = SsoWebIdentity::fromCurrentSession();
            $providerAppId = SsoWebIdentity::providerFromCurrentSession();
        } catch (\Throwable $error) {
            return $this->page('Não foi possível confirmar seu acesso ao JMBA Hub.', 403);
        }

        $automatic = $request->query->get('automatic') === '1';
        $returnUri = $automatic ? PluginConfig::getSsoReturnUri() : null;
        if ($automatic && $returnUri === null) return $this->page('Retorno ao aplicativo não configurado.', 503);
        if ($request->isMethod('GET')) {
            $_SESSION['jmbahub_sso_pending'] = [
                'providerAppId' => $providerAppId, 'attempt' => $attempt, 'userId' => $identity['userId'], 'expires' => time() + 300, 'automatic' => $automatic,
            ];
            $csrf = htmlspecialchars(Session::getNewCSRFToken(true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $form = '<form id="sso-complete" method="post"><input type="hidden" name="_glpi_csrf_token" value="' . $csrf . '">'
                . ($automatic ? '<noscript><button type="submit">Continuar no aplicativo</button></noscript>'
                    : '<button type="submit">Confirmar entrada</button>') . '</form>';
            return $this->page(($automatic ? 'Concluindo sua entrada no aplicativo…' : 'Confirme que você deseja entrar no aplicativo JMBA Hub com sua conta GLPI.')
                . $form, 200, $automatic ? "document.getElementById('sso-complete').requestSubmit();" : null, $returnUri);
        }

        if (!$request->isMethod('POST') || $request->isXmlHttpRequest()) return $this->page('Método não permitido.', 405);
        // O CheckCsrfListener do GLPI valida e consome o token antes deste controller.
        $pending = $_SESSION['jmbahub_sso_pending'] ?? null;
        unset($_SESSION['jmbahub_sso_pending']);
        if (!is_array($pending) || ($pending['attempt'] ?? null) !== $attempt
            || ($pending['providerAppId'] ?? null) !== $providerAppId
            || ($pending['userId'] ?? null) !== $identity['userId'] || ($pending['automatic'] ?? false) !== $automatic || ($pending['expires'] ?? 0) < time()) {
            return $this->page('Esta confirmação expirou. Abra novamente o login pelo aplicativo.', 400);
        }

        $handoff = $automatic ? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=') : null;
        $handoffHash = $handoff === null ? null : hash('sha256', $handoff);
        $sessionToken = null;
        try {
            $installationId = PluginConfig::getExistingInstallationId();
            $transport = PluginConfig::getNotificationTransport();
            $baseUrl = PluginConfig::getApiBaseUrl();
            $parts = parse_url($baseUrl);
            if ($installationId === null || $transport === null || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                return $this->page('A integração JMBA Hub não está disponível.', 503);
            }
            $sessionToken = SsoRestSession::create();
            $proof = SsoInstallationProof::sign($installationId, $transport['secret'], $attempt, $identity['userId'], $sessionToken, $handoffHash, $providerAppId);
            $payload = ['attemptId' => $attempt, 'installationId' => $installationId,
                'providerAppId' => $providerAppId, 'userId' => $identity['userId'], 'signature' => $proof, 'sessionToken' => $sessionToken];
            if ($handoffHash !== null) $payload['handoffHash'] = $handoffHash;
            $result = Toolbox::getGuzzleClient(['timeout' => 5, 'allow_redirects' => false, 'http_errors' => false])
                ->post(rtrim($baseUrl, '/') . '/integrations/glpi/sso/complete', [
                    'headers' => ['Accept' => 'application/json'],
                    'json' => $payload,
                ]);
            if ($result->getStatusCode() !== 204) {
                SsoRestSession::discard($sessionToken);
                return $this->page('Esta tentativa não está mais disponível. Abra novamente o login pelo aplicativo.', 400);
            }
        } catch (\Throwable $error) {
            if ($sessionToken !== null) {
                try { SsoRestSession::discard($sessionToken); } catch (\Throwable $ignored) {}
            }
            return $this->page('Não foi possível conectar ao JMBA Hub. Tente novamente.', 503);
        }
        if ($automatic) {
            $target = $returnUri . '#attempt=' . $attempt . '&handoff=' . $handoff;
            $escaped = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $response = $this->page('Entrada concluída. Abrindo o aplicativo… <a href="' . $escaped . '">Abrir aplicativo</a>', 302);
            $response->headers->set('Location', $target);
            return $response;
        }
        return $this->page('Entrada confirmada. Volte ao aplicativo para continuar.');
    }

    private function page(string $content, int $status = 200, ?string $script = null, ?string $returnUri = null): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $formTarget = $returnUri === null ? "'self'" : "'self' " . parse_url($returnUri, PHP_URL_SCHEME) . ':';
        return new Response('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>JMBA Hub</title>'
            . '<style>body{font:16px sans-serif;max-width:32rem;margin:4rem auto;padding:0 1.5rem;line-height:1.5}'
            . 'button{background:#174c8c;color:white;border:0;padding:.8rem 1.2rem;border-radius:.4rem;margin-top:1rem}</style>'
            . '</head><body><h1>JMBA Hub</h1><p>' . $content . '</p>'
            . ($script === null ? '' : '<script nonce="' . $nonce . '">' . $script . '</script>') . '</body></html>', $status, [
                'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; form-action {$formTarget}; base-uri 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
