<?php

namespace GlpiPlugin\Jmbahub;

/** Sessão REST independente, aberta somente após validar a sessão web e o acesso ao app. */
final class SsoRestSession
{
    public static function create(): string
    {
        $identity = SsoWebIdentity::fromCurrentSession();
        $webId = session_id();
        if ($webId === '' || session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('Sessão web indisponível.');
        $cookies = ini_get('session.use_cookies');
        session_write_close();
        try {
            ini_set('session.use_cookies', '0');
            session_id('');
            \Session::start();
            $user = new \User();
            if (!$user->getFromDB($identity['userId'])) throw new \RuntimeException('Usuário indisponível.');
            $auth = new \Auth(); $auth->user = $user; $auth->auth_succeded = true;
            \Session::init($auth);
            if (!$auth->auth_succeded || (int) \Session::getLoginUserID() !== $identity['userId']) {
                throw new \RuntimeException('Não foi possível abrir a sessão GLPI.');
            }
            \Session::changeProfile($identity['profileId']);
            \Session::changeActiveEntities($identity['entityId'], $identity['recursive']);
            if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? -1) !== $identity['profileId']
                || (int) ($_SESSION['glpiactive_entity'] ?? -1) !== $identity['entityId']
                || (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false) !== $identity['recursive']) {
                throw new \RuntimeException('Contexto da sessão GLPI indisponível.');
            }
            // Mesma representação produzida por API::initSession no GLPI 11.
            $token = base64_encode((new \GLPIKey())->encrypt($_SESSION['valid_id']));
            unset($_SESSION['valid_id']);
            session_write_close();
            return $token;
        } catch (\Throwable $error) {
            if (session_status() === PHP_SESSION_ACTIVE && session_id() !== $webId) session_destroy();
            throw $error;
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            ini_set('session.use_cookies', $cookies);
            session_id($webId);
            \Session::start();
        }
    }

    public static function discard(string $token): void
    {
        $encrypted = base64_decode($token, true);
        if ($encrypted === false) return;
        $id = (new \GLPIKey())->decrypt($encrypted);
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{16,256}$/D', $id) || $id === session_id()) return;
        $webId = session_id(); $cookies = ini_get('session.use_cookies');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            ini_set('session.use_cookies', '0'); session_id($id); \Session::start(); session_destroy();
        } finally {
            ini_set('session.use_cookies', $cookies); session_id($webId); \Session::start();
        }
    }
}
