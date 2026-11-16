<?php
namespace GlpiPlugin\Jmbahub;

/** Adapter to the installed Nextools public readiness APIs; no user session is opened. */
final class SsoAvailability
{
    public static function providerIsReady(int $providerId): bool
    {
        try {
            if ($providerId <= 0 || !\Plugin::isPluginActive('nextool')
                || !class_exists(\PluginNextoolModuleManager::class) || !defined('NEXTOOL_MODULES_BASE')) return false;
            $modules = \PluginNextoolModuleManager::getInstance()->getActiveModules();
            if (!is_array($modules) || !isset($modules['ssologin'])) return false;
            $base = NEXTOOL_MODULES_BASE . '/ssologin/inc/';
            foreach (['login.class.php', 'application.class.php'] as $file) {
                if (!is_file($base . $file)) return false;
                require_once $base . $file;
            }
            if (!is_callable([\PluginNextoolSsologinLogin::class, 'isLoginAvailable'])
                || !\PluginNextoolSsologinLogin::isLoginAvailable()) return false;
            $application = new \PluginNextoolSsologinApplication();
            return $application->getFromDB($providerId) && is_callable([$application, 'isReadyForLogin'])
                && $application->isReadyForLogin();
        } catch (\Throwable $unavailable) {
            // Missing/incompatible/disabled Nextools must never enable the app button by accident.
            return false;
        }
    }

    /** Metadata only; never export client IDs, secrets or identity claims. */
    public static function applicationsForAdmin(): array
    {
        $out = [];
        try {
            global $DB;
            if (!\Plugin::isPluginActive('nextool') || !defined('NEXTOOL_MODULES_BASE')) return [];
            $file = NEXTOOL_MODULES_BASE . '/ssologin/inc/application.class.php';
            if (!is_file($file)) return [];
            require_once $file;
            $table = \PluginNextoolSsologinApplication::getTable();
            if (!$DB->tableExists($table)) return [];
            foreach ($DB->request(['SELECT' => ['id', 'name', 'provider'], 'FROM' => $table, 'ORDER' => 'id ASC']) as $row) {
                $id = (int) $row['id'];
                $out[] = ['id' => $id, 'name' => mb_substr((string) $row['name'], 0, 160),
                    'provider' => (string) $row['provider'], 'ready' => self::providerIsReady($id)];
            }
        } catch (\Throwable $unavailable) { return []; }
        return $out;
    }

    public static function availableApplications(): array
    {
        if (!PluginConfig::isSsoAllowedInApp()) return [];
        $allowed = PluginConfig::getSsoAllowedAppIds();
        $out = [];
        foreach (self::applicationsForAdmin() as $app) {
            if (in_array($app['id'], $allowed, true) && $app['ready']
                && in_array($app['provider'], ['azure', 'google', 'openid', 'keycloak', 'okta'], true)) {
                unset($app['ready']); $out[] = $app;
            }
        }
        return $out;
    }

    public static function isApplicationAllowed(int $id): bool
    {
        return PluginConfig::isSsoAllowedInApp()
            && in_array($id, PluginConfig::getSsoAllowedAppIds(), true) && self::providerIsReady($id);
    }

    public static function proofV2(string $secret, string $id, string $challenge, bool $enabled, array $allowed, array $apps): string
    {
        // Reuse input validation; the new proof binds every allowlisted ID and public option.
        self::proof($secret, $id, $challenge, false, 0);
        $rows = array_map(static fn(array $a): string => $a['id'] . ':' . hash('sha256', $a['name']) . ':' . $a['provider'], $apps);
        return hash_hmac('sha256', "jmbahub-sso-availability-v2\n" . $id . "\n" . $challenge . "\n"
            . ($enabled ? '1' : '0') . "\n" . implode(',', $allowed) . "\n" . implode("\n", $rows), $secret);
    }

    public static function proof(string $secret, string $installationId, string $challenge, bool $enabled, int $provider): string
    {
        if (strlen($secret) < 32 || !preg_match('/^[a-fA-F0-9-]{36}$/D', $installationId)
            || !preg_match('/^[a-f0-9]{64}$/D', $challenge) || ($enabled ? $provider <= 0 : $provider !== 0)) {
            throw new \InvalidArgumentException('Disponibilidade SSO inválida.');
        }
        return hash_hmac('sha256', "jmbahub-sso-availability-v1\n" . $installationId . "\n" . $challenge
            . "\n" . ($enabled ? '1' : '0') . "\n" . $provider, $secret);
    }
}
