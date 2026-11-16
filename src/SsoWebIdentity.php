<?php

namespace GlpiPlugin\Jmbahub;

use Session;
use User;

/** Confere a identidade da sessão web atual, sem criar ou exportar credenciais. */
final class SsoWebIdentity
{
    /** Nextools writes this marker only after authenticating, including the GLPI MFA path. */
    public static function providerFromCurrentSession(): int
    {
        self::fromCurrentSession();
        if (!class_exists(\PluginNextoolSsologinLogin::class)) throw new \RuntimeException('Origem SSO indisponível.');
        $key = \PluginNextoolSsologinLogin::SESSION_KEY;
        $origin = $_SESSION[$key] ?? null;
        if (!is_array($origin) || !is_int($origin['app'] ?? null) || $origin['app'] <= 0
            || !is_int($origin['ts'] ?? null) || $origin['ts'] <= 0 || $origin['ts'] > time() + 30
            || !is_string($origin['issuer'] ?? null) || trim($origin['issuer']) === ''
            || !SsoAvailability::isApplicationAllowed($origin['app'])) {
            throw new \RuntimeException('A sessão não foi autenticada por uma aplicação SSO permitida.');
        }
        // The metadata belongs to the current server-side session; never accept browser parameters.
        return $origin['app'];
    }

    /**
     * Apenas um futuro controlador autenticado poderá usar esta identidade para
     * concluir uma tentativa SSO vinculada à instalação e ao aplicativo.
     *
     * @return array{userId: int, profileId: int, entityId: int, recursive: bool}
     */
    public static function fromCurrentSession(): array
    {
        Session::checkLoginUser();
        if (Session::mustChangePassword() || !empty($_SESSION['mfa_pre_auth'])) {
            throw new \RuntimeException('Autenticação GLPI incompleta.');
        }

        $userId = (int) Session::getLoginUserID();
        $profile = $_SESSION['glpiactiveprofile']['id'] ?? null;
        $entity = $_SESSION['glpiactive_entity'] ?? null;
        if ($userId <= 0 || !is_numeric($profile) || !is_numeric($entity)
            || !array_key_exists((int) $profile, $_SESSION['glpiprofiles'] ?? [])) {
            throw new \RuntimeException('Contexto GLPI indisponível.');
        }

        $user = new User();
        $now = date('Y-m-d H:i:s');
        if (!$user->getFromDB($userId) || empty($user->fields['is_active'])
            || !empty($user->fields['is_deleted'])
            || (!empty($user->fields['begin_date']) && $user->fields['begin_date'] > $now)
            || (!empty($user->fields['end_date']) && $user->fields['end_date'] < $now)) {
            throw new \RuntimeException('Usuário GLPI indisponível.');
        }

        if (PluginConfig::isAccessControlEnabled()) {
            if (!class_exists(\PluginJmbahubAppuser::class)) {
                require_once dirname(__DIR__) . '/inc/appuser.class.php';
            }
            if (!\PluginJmbahubAppuser::isUserEnabled($userId)) {
                throw new \RuntimeException('Acesso ao aplicativo desabilitado.');
            }
        }

        return [
            'userId' => $userId,
            'profileId' => (int) $profile,
            'entityId' => (int) $entity,
            'recursive' => (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false),
        ];
    }
}
