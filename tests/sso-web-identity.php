<?php

namespace {
    final class PluginNextoolSsologinLogin { public const SESSION_KEY = 'nextool_ssologin'; }
    final class Session
    {
        public static bool $loggedIn = true;
        public static bool $passwordChange = false;
        public static function checkLoginUser(): void
        {
            if (!self::$loggedIn) throw new \RuntimeException('no session');
        }
        public static function mustChangePassword(): bool { return self::$passwordChange; }
        public static function getLoginUserID(): int { return 42; }
    }
    final class User
    {
        public static array $testFields = ['is_active' => 1, 'is_deleted' => 0];
        public array $fields = [];
        public function getFromDB(int $id): bool
        {
            $this->fields = self::$testFields;
            return $id === 42;
        }
    }
    final class PluginJmbahubAppuser
    {
        public static bool $enabled = true;
        public static function isUserEnabled(int $userId): bool { return self::$enabled; }
    }
}

namespace GlpiPlugin\Jmbahub {
    final class SsoAvailability { public static bool $allowed = true; public static function isApplicationAllowed(int $id): bool { return self::$allowed && $id === 2; } }
    final class PluginConfig
    {
        public static function isAccessControlEnabled(): bool { return true; }
    }
}

namespace {
    require dirname(__DIR__) . '/src/SsoWebIdentity.php';
    use GlpiPlugin\Jmbahub\SsoWebIdentity;

    function reject(callable $action): void
    {
        try { $action(); } catch (\RuntimeException $expected) { return; }
        throw new \RuntimeException('Expected SSO identity validation to fail');
    }

    $_SESSION = [
        'glpiactiveprofile' => ['id' => 4],
        'glpiprofiles' => [4 => []],
        'glpiactive_entity' => 0,
        'glpiactive_entity_recursive' => true,
    ];
    $identity = SsoWebIdentity::fromCurrentSession();
    if ($identity !== ['userId' => 42, 'profileId' => 4, 'entityId' => 0, 'recursive' => true]) {
        throw new \RuntimeException('Unexpected identity or context');
    }

    reject(fn () => SsoWebIdentity::providerFromCurrentSession());
    $_SESSION['nextool_ssologin'] = ['app'=>2,'issuer'=>'https://issuer.example','ts'=>time()];
    if (SsoWebIdentity::providerFromCurrentSession()!==2) throw new \RuntimeException('Valid provider session refused');
    $_SESSION['nextool_ssologin']['app']=1;
    reject(fn () => SsoWebIdentity::providerFromCurrentSession());
    $_SESSION['nextool_ssologin']['app']=2;
    \GlpiPlugin\Jmbahub\SsoAvailability::$allowed=false;
    reject(fn () => SsoWebIdentity::providerFromCurrentSession());
    \GlpiPlugin\Jmbahub\SsoAvailability::$allowed=true;
    $_SESSION['mfa_pre_auth']=['pending'=>true];
    reject(fn () => SsoWebIdentity::providerFromCurrentSession());
    unset($_SESSION['mfa_pre_auth']);
    $_SESSION['nextool_ssologin']['issuer']='';
    reject(fn () => SsoWebIdentity::providerFromCurrentSession());
    unset($_SESSION['nextool_ssologin']);
    Session::$loggedIn = false;
    reject(fn () => SsoWebIdentity::fromCurrentSession());
    Session::$loggedIn = true;
    Session::$passwordChange = true;
    reject(fn () => SsoWebIdentity::fromCurrentSession());
    Session::$passwordChange = false;
    $_SESSION['mfa_pre_auth'] = ['pending' => true];
    reject(fn () => SsoWebIdentity::fromCurrentSession());
    unset($_SESSION['mfa_pre_auth']);
    User::$testFields['is_active'] = 0;
    reject(fn () => SsoWebIdentity::fromCurrentSession());
    User::$testFields['is_active'] = 1;
    PluginJmbahubAppuser::$enabled = false;
    reject(fn () => SsoWebIdentity::fromCurrentSession());
    PluginJmbahubAppuser::$enabled = true;
    $_SESSION['glpiprofiles'] = [];
    reject(fn () => SsoWebIdentity::fromCurrentSession());
    echo "SSO web identity checks passed\n";
}
