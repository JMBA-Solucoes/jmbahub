<?php

/**
 * Consome o nonce de uso único gerado por POST /forms/web-session (backend
 * Java, Entrega 2 da ponte de formulários nativos) e estabelece uma sessão
 * web GLPI autenticada, sem repetir login/senha — Entrega 3 do plano.
 *
 * URL de acesso: {glpiUrl}/plugins/jmbahub/Consume?nonce={nonce}
 *
 * Config resolvida via GlpiPlugin\Jmbahub\PluginConfig (banco `glpi_configs`,
 * com fallback pra variável de ambiente):
 *   api_base_url / GLPI_APP_API_BASE_URL   base URL do backend Java (ex.: http://192.168.15.14:8022)
 *   shared_secret / GLPI_APP_SHARED_SECRET  secret compartilhado, enviado como header
 *                            X-GLPI-App-Secret (mesmo secret de checkuser.php,
 *                            agora usado no sentido plugin -> backend)
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Auth;
use Config;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Jmbahub\PluginConfig;
use GuzzleHttp\Exception\GuzzleException;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Toolbox;
use User;

final class FormBridgeConsumeController extends AbstractController
{
    private const ERROR_MESSAGE = 'Este acesso ao formulário não está mais válido. Feche esta tela e abra o formulário novamente pelo aplicativo.';
    private string $errorMessage = self::ERROR_MESSAGE;
    private int $errorStatus = 400;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // ainda não há sessão — este controller cria uma
    #[Route("/Consume", name: "jmbahub_form_bridge_consume", methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $nonce = $request->query->get('nonce');
        if (!is_string($nonce) || $nonce === '') {
            return $this->errorResponse();
        }

        $context = $this->consumeNonce($nonce);
        if ($context === null) {
            return $this->errorResponse();
        }

        // JMBA Hub: bind the browser bootstrap to a trusted, configured GLPI instance.
        $configured = Config::getConfigurationValues('core', ['url_base']);
        $localUrl = $this->canonicalUrl((string) ($configured['url_base'] ?? ''));
        $issuedUrl = $this->canonicalUrl((string) ($context['glpiUrl'] ?? ''));
        if ($localUrl === null) {
            $this->errorMessage = 'O endereço desta instalação GLPI precisa ser configurado pelo administrador para abrir formulários pelo aplicativo.';
            return $this->errorResponse();
        }
        if ($issuedUrl === null || $localUrl !== $issuedUrl) {
            $this->errorMessage = 'Este link de formulário não é válido para esta instalação GLPI. Abra o formulário pelo aplicativo.';
            return $this->errorResponse();
        }
        // JMBA Hub: end instance binding.

        $user = new User();
        if (!isset($context['glpiUserId']) || !$user->getFromDB((int) $context['glpiUserId'])) {
            return $this->errorResponse();
        }

        $auth = new Auth();
        $auth->auth_succeded = true;
        $auth->user = $user;
        Session::init($auth);

        // JMBA Hub: reject revoked identities and unavailable profiles before selecting context.
        if (PluginConfig::isAccessControlEnabled()) {
            if (!class_exists(\PluginJmbahubAppuser::class)) require_once dirname(__DIR__, 2) . '/inc/appuser.class.php';
            if (!\PluginJmbahubAppuser::isUserEnabled((int) $context['glpiUserId'])) {
                $this->errorMessage = 'Seu acesso ao JMBA Hub está desabilitado. Contate o administrador.';
                $this->errorStatus = 403;
                return $this->errorResponse();
            }
        }
        if ((int) Session::getLoginUserID() !== (int) $context['glpiUserId']) {
            $this->errorMessage = 'Seu acesso ao GLPI está desabilitado. Contate o administrador.';
            $this->errorStatus = 403;
            return $this->errorResponse();
        }
        if (isset($context['activeProfileId']) && !array_key_exists((int) $context['activeProfileId'], $_SESSION['glpiprofiles'] ?? [])) {
            $this->errorMessage = 'Seu acesso ao perfil selecionado mudou. Feche esta tela e selecione um contexto disponível no aplicativo.';
            $this->errorStatus = 409;
            return $this->errorResponse();
        }
        // JMBA Hub: end browser identity validation.

        // Ordem importa: changeActiveEntities() valida um pedido recursivo
        // contra $_SESSION['glpiactiveprofile']['entities'], que só existe
        // depois de changeProfile() rodar. Trocada (entidade antes do
        // perfil), a checagem de recursivo rodava contra o perfil padrão do
        // Session::init() — não o solicitado — rejeitava silenciosamente e a
        // entidade ficava presa nesse padrão mesmo depois do changeProfile()
        // (bug real, achado em teste 21/08/2026: Super-Admin com
        // recursividade ativa continuava vendo só a entidade anterior).
        //
        // !empty() é o bug errado aqui: a Entidade raiz do GLPI é sempre o ID
        // 0, e empty(0) === true em PHP — "entidade raiz ativa" nunca
        // disparava changeActiveEntities(), deixando a sessão presa no que
        // Session::init()/changeProfile() escolheram por padrão (2º bug real
        // do mesmo teste). isset()+!== null trata 0 como valor válido.
        if (isset($context['activeProfileId']) && $context['activeProfileId'] !== null) {
            Session::changeProfile((int) $context['activeProfileId']);
        }
        if (isset($context['activeEntityId']) && $context['activeEntityId'] !== null) {
            Session::changeActiveEntities((int) $context['activeEntityId'], (bool) ($context['activeEntityRecursive'] ?? false));
        }

        // JMBA Hub: never open a form in a silently substituted context.
        if ((isset($context['activeProfileId']) && (int) ($_SESSION['glpiactiveprofile']['id'] ?? -1) !== (int) $context['activeProfileId'])
            || (isset($context['activeEntityId']) && ((int) ($_SESSION['glpiactive_entity'] ?? -1) !== (int) $context['activeEntityId']
                || (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false) !== (bool) ($context['activeEntityRecursive'] ?? false)))) {
            $this->errorMessage = 'Seu acesso ao perfil ou à entidade selecionada mudou. Feche esta tela e selecione um contexto disponível no aplicativo.';
            $this->errorStatus = 409;
            return $this->errorResponse();
        }
        // JMBA Hub: end browser context confirmation.

        $target = !empty($context['formId'])
            ? '/Form/Render/' . (int) $context['formId']
            : '/ServiceCatalog';

        return new RedirectResponse($target);
    }

    /**
     * @return array{glpiUserId?: int, activeEntityId?: int, activeEntityRecursive?: bool, activeProfileId?: int, formId?: int}|null
     *         null cobre indistintamente nonce inexistente, expirado, já
     *         consumido ou erro de comunicação com o backend.
     */
    private function consumeNonce(string $nonce): ?array
    {
        $baseUrl = PluginConfig::getApiBaseUrl();
        $secret  = PluginConfig::getSharedSecret();
        if ($baseUrl === '' || $secret === '') {
            Toolbox::logInFile('php-errors', 'jmbahub: GLPI_APP_API_BASE_URL/GLPI_APP_SHARED_SECRET não configurados.');
            return null;
        }

        if (strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            $this->errorMessage = 'A conexão segura entre o GLPI e o servidor do aplicativo precisa ser configurada pelo administrador.';
            $this->errorStatus = 503;
            return null;
        }
        try {
            $response = Toolbox::getGuzzleClient(['timeout' => 5, 'allow_redirects' => false, 'http_errors' => false])->post(
                rtrim($baseUrl, '/') . '/forms/internal/consume-nonce',
                [
                    'headers' => [
                        'X-GLPI-App-Secret' => $secret,
                        'Content-Type'      => 'application/json',
                        'Accept'            => 'application/json',
                    ],
                    'json' => ['nonce' => $nonce],
                ]
            );
        } catch (GuzzleException $e) {
            Toolbox::logInFile('php-errors', 'jmbahub: falha ao consumir nonce da ponte de formulários: ' . get_class($e));
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200) {
            $code = is_array($body) ? ($body['code'] ?? '') : '';
            $messages = [
                'HUB_SESSION_REVOKED' => 'Sua sessão no JMBA Hub foi encerrada. Feche esta tela e entre novamente no aplicativo.',
                'HUB_SESSION_EXPIRED' => 'Seu período de acesso terminou. Feche esta tela e entre novamente no aplicativo.',
                'GLPI_CONTEXT_CHANGED' => 'Seu acesso ao perfil ou à entidade selecionada mudou. Feche esta tela e selecione um contexto disponível no aplicativo.',
                'SESSION_BUSY' => 'Há outra operação em andamento nesta sessão. Aguarde alguns instantes e abra o formulário novamente.',
            ];
            $this->errorMessage = $messages[$code] ?? ($response->getStatusCode() >= 500
                ? 'O servidor do JMBA Hub não conseguiu confirmar este acesso. Tente novamente em instantes.'
                : self::ERROR_MESSAGE);
            $this->errorStatus = isset($messages[$code]) ? $response->getStatusCode() : 400;
            return null;
        }

        return is_array($body) ? $body : null;
    }

    private function canonicalUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) return null;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $suffix = $port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port;
        return $scheme . '://' . strtolower($parts['host']) . $suffix . rtrim($parts['path'] ?? '', '/');
    }

    private function errorResponse(): Response
    {
        return new Response($this->errorMessage, $this->errorStatus, ['Cache-Control' => 'no-store, private', 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
