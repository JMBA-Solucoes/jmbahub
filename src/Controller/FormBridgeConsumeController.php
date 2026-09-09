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
    private const ERROR_MESSAGE = 'Sessão expirada. Feche esta tela e tente novamente.';

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

        $user = new User();
        if (!isset($context['glpiUserId']) || !$user->getFromDB((int) $context['glpiUserId'])) {
            return $this->errorResponse();
        }

        $auth = new Auth();
        $auth->auth_succeded = true;
        $auth->user = $user;
        Session::init($auth);

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

        try {
            $response = Toolbox::getGuzzleClient(['timeout' => 5])->post(
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
            Toolbox::logInFile('php-errors', 'jmbahub: falha ao consumir nonce da ponte de formulários: ' . $e->getMessage());
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        return is_array($body) ? $body : null;
    }

    private function errorResponse(): Response
    {
        return new Response(self::ERROR_MESSAGE, 400);
    }
}
