<?php

namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\SessionExpiredException;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Jmbahub\PluginConfig;
use GlpiPlugin\Jmbahub\RecoveryTransport;
use GlpiPlugin\Jmbahub\RecoverySession;
use GlpiPlugin\Jmbahub\RecoveryCredential;
use Session;
use User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Backend-only bootstrap credential, scoped to the owner of a verified GLPI session. */
final class SessionCredentialController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/SessionCredential', name: 'jmbahub_session_credential', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request, true);
    }

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/SessionAuthorization', name: 'jmbahub_session_authorization', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        return $this->handle($request, false);
    }

    private function handle(Request $request, bool $credential): Response
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];
        $unavailable = static fn () => new JsonResponse(['code' => 'GLPI_RECOVERY_UNAVAILABLE'], 503, $headers);
        $expected = PluginConfig::getRecoverySecret();
        $provided = $request->headers->get('X-GLPI-App-Secret', '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return new JsonResponse(['error' => 'Credencial do JMBA Hub inválida.'], 401, $headers);
        }
        if (!RecoveryTransport::hasHttpsPublicUrl()) {
            return $unavailable();
        }
        $token = $request->headers->get('Session-Token', '');
        if ($token === '' || strlen($token) > 1024) {
            return new JsonResponse(['ERROR_SESSION_TOKEN_INVALID', 'Sessão GLPI inválida.'], 401, $headers);
        }
        $input = ['allowCreate' => false];
        try {
            if ($credential) {
                $input = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($input) || !array_key_exists('allowCreate', $input) || !is_bool($input['allowCreate'])) {
                    return new JsonResponse(['error' => 'allowCreate deve ser booleano.'], 400, $headers);
                }
            }
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'Corpo JSON inválido.'], 400, $headers);
        }
        global $DB, $CFG_GLPI;
        $transaction = false;
        try {
            RecoverySession::open($token);
            $userId = (int) Session::getLoginUserID();
            $owner = new User();
            if (!$owner->getFromDB($userId)) {
                throw new SessionExpiredException();
            }
            $now = date('Y-m-d H:i:s');
            if (empty($owner->fields['is_active']) || !empty($owner->fields['is_deleted'])
                || (!empty($owner->fields['begin_date']) && $owner->fields['begin_date'] > $now)
                || (!empty($owner->fields['end_date']) && $owner->fields['end_date'] < $now)) {
                return new JsonResponse(['code' => 'APP_ACCESS_DISABLED'], 403, $headers);
            }
            // REST sessions deliberately lack the browser-only valid_id marker in recent GLPI.
            if (!RecoverySession::hasCurrentContext()) {
                return new JsonResponse(['code' => 'GLPI_CONTEXT_CHANGED'], 409, $headers);
            }
            if (PluginConfig::isAccessControlEnabled() && !class_exists(\PluginJmbahubAppuser::class)) {
                require_once dirname(__DIR__, 2) . '/inc/appuser.class.php';
            }
            if (PluginConfig::isAccessControlEnabled() && !\PluginJmbahubAppuser::isUserEnabled($userId)) {
                return new JsonResponse(['code' => 'APP_ACCESS_DISABLED'], 403, $headers);
            }
            if (!$credential) {
                $expectedHash = $request->headers->get('X-GLPI-API-Token-Hash', '');
                $verified = false;
                if ($expectedHash !== '') {
                    $apiToken = RecoveryCredential::read($owner);
                    if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash) || $apiToken === null
                        || !hash_equals(hash('sha256', $apiToken), $expectedHash)) {
                        return new JsonResponse(['code' => 'GLPI_REAUTH_REQUIRED'], 401, $headers);
                    }
                    $verified = true;
                }
                return new JsonResponse(['userId' => $userId, 'credentialVerified' => $verified], 200, $headers);
            }
            if (empty($CFG_GLPI['enable_api_login_external_token'])) {
                return $unavailable();
            }
            // Serializes check/create also against other backend instances. No unconditional reset.
            $DB->beginTransaction();
            $transaction = true;
            // request() accepts structured criteria, not raw SQL, on current GLPI.
            $result = $DB->doQuery('SELECT `id` FROM `glpi_users` WHERE `id` = ' . $userId . ' FOR UPDATE');
            $row = $DB->fetchAssoc($result);
            $result->free();
            if (!$row) {
                $DB->rollBack();
                $transaction = false;
                return $unavailable();
            }
            $user = new User();
            if (!$user->getFromDB($userId)) {
                throw new SessionExpiredException();
            }
            $apiToken = RecoveryCredential::read($user);
            if ($apiToken === null && $input['allowCreate']) {
                if (!$user->can($userId, UPDATE)) {
                    $DB->rollBack();
                    $transaction = false;
                    return new JsonResponse(['code' => 'GLPI_PERMISSION_DENIED'], 403, $headers);
                }
                $generated = RecoveryCredential::getOrCreate($user);
                // GLPI getAuthToken may return a generated value even if the update was denied.
                if (!$user->getFromDB($userId)) {
                    throw new \RuntimeException('Unable to confirm credential persistence.');
                }
                $apiToken = RecoveryCredential::read($user);
                if (!is_string($generated) || $apiToken === null || !hash_equals($apiToken, $generated)) {
                    $DB->rollBack();
                    $transaction = false;
                    return $unavailable();
                }
            }
            $DB->commit();
            $transaction = false;
            return new JsonResponse(['userId' => $userId, 'apiToken' => $apiToken], 200, $headers);
        } catch (SessionExpiredException $exception) {
            return new JsonResponse(['ERROR_SESSION_TOKEN_INVALID', 'Sessão GLPI inválida.'], 401, $headers);
        } catch (\Throwable $exception) {
            // Never return/log SQL, session data, token values, or internal exceptions.
            return $unavailable();
        } finally {
            if ($transaction) {
                try { $DB->rollBack(); } catch (\Throwable $cleanupFailure) { /* No internal details in responses. */ }
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }
}
