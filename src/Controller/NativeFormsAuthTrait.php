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

        return null;
    }
}
