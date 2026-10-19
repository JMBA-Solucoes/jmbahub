<?php

/**
 * Bootstrap de sessão compartilhado por FormStructureController e
 * FormSubmitController (Entrega A da renderização nativa dos formulários).
 *
 * Diferente do FormBridgeConsumeController (Entregas 2/3, sessão criada por
 * navegação de WebView + nonce de uso único), aqui é o backend Java que
 * chama o plugin diretamente, request-a-request, sem precisar de nonce: a
 * sessão GLPI só precisa viver durante essa única requisição HTTP, então
 * a identidade do usuário (glpiUserId/activeEntityId/activeProfileId, já
 * extraídos do JWT pelo backend) e o segredo compartilhado
 * (X-GLPI-App-Secret, mesmo de checkuser.php/Consume) bastam.
 */

namespace GlpiPlugin\Jmbahub\Controller;

use Auth;
use GlpiPlugin\Jmbahub\PluginConfig;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use User;

trait NativeFormsAuthTrait
{
    /**
     * Valida o header X-GLPI-App-Secret e inicia a sessão GLPI autenticada.
     * Retorna null em caso de sucesso, ou a Response de erro já pronta pra
     * devolver ao chamador.
     */
    private function authenticateFromRequest(
        Request $request,
        int $glpiUserId,
        ?int $activeEntityId,
        ?bool $activeEntityRecursive,
        ?int $activeProfileId
    ): ?Response {
        $expectedSecret = PluginConfig::getSharedSecret();
        $providedSecret = $request->headers->get('X-GLPI-App-Secret') ?? '';
        if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return new JsonResponse(['error' => 'Credencial do JMBA Hub inválida.'], 401);
        }

        $user = new User();
        if ($glpiUserId <= 0 || !$user->getFromDB($glpiUserId)) {
            return new JsonResponse(['error' => 'Usuário não encontrado.'], 400);
        }

        $auth = new Auth();
        $auth->auth_succeded = true;
        $auth->user = $user;
        Session::init($auth);

        // JMBA Hub: validate the fresh identity and requested profile.
        if (PluginConfig::isAccessControlEnabled()) {
            if (!class_exists(\PluginJmbahubAppuser::class)) require_once dirname(__DIR__, 2) . '/inc/appuser.class.php';
            if (!\PluginJmbahubAppuser::isUserEnabled($glpiUserId)) {
                return new JsonResponse(['code' => 'APP_ACCESS_DISABLED', 'error' => 'Seu acesso ao JMBA Hub está desabilitado.'], 403);
            }
        }
        if ((int) Session::getLoginUserID() !== $glpiUserId) {
            return new JsonResponse(['code' => 'APP_ACCESS_DISABLED', 'error' => 'Seu acesso ao JMBA Hub está desabilitado.'], 403);
        }
        if ($activeProfileId !== null && !array_key_exists($activeProfileId, $_SESSION['glpiprofiles'] ?? [])) {
            return new JsonResponse(['code' => 'GLPI_CONTEXT_CHANGED', 'error' => 'Seu acesso ao perfil selecionado mudou. Selecione um contexto disponível.'], 409);
        }
        // JMBA Hub: end identity validation.

        // Ordem importa: changeActiveEntities() valida um pedido recursivo
        // contra $_SESSION['glpiactiveprofile']['entities'], que só existe
        // depois de changeProfile() rodar — ver mesmo bug/comentário em
        // FormBridgeConsumeController.php (achado em teste 21/08/2026).
        //
        // !empty() é o bug errado aqui: a Entidade raiz do GLPI é sempre o ID
        // 0, e empty(0) === true em PHP — "entidade raiz ativa" nunca
        // disparava changeActiveEntities() (2º bug real do mesmo teste).
        if ($activeProfileId !== null) {
            Session::changeProfile($activeProfileId);
        }
        if ($activeEntityId !== null) {
            Session::changeActiveEntities($activeEntityId, (bool) $activeEntityRecursive);
        }

        // JMBA Hub: confirm the requested context, including entity zero.
        if (($activeProfileId !== null && (int) ($_SESSION['glpiactiveprofile']['id'] ?? -1) !== $activeProfileId)
            || ($activeEntityId !== null && ((int) ($_SESSION['glpiactive_entity'] ?? -1) !== $activeEntityId
                || (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false) !== (bool) $activeEntityRecursive))) {
            return new JsonResponse(['code' => 'GLPI_CONTEXT_CHANGED', 'error' => 'Seu acesso ao perfil ou à entidade selecionada mudou. Selecione um contexto disponível.'], 409);
        }
        // JMBA Hub: end context confirmation.
        return null;
    }
}
