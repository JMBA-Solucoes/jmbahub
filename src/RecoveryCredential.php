<?php

namespace GlpiPlugin\Jmbahub;

use RuntimeException;
use Throwable;
use User;

/** Reads an existing token for an owner already authenticated by the caller. */
final class RecoveryCredential
{
    public static function read(User $user): ?string
    {
        $stored = $user->fields['api_token'] ?? null;
        // getAuthToken also creates absent tokens. A read must never trigger generation.
        if ($stored === null || $stored === '') {
            return null;
        }

        return self::getOrCreate($user);
    }

    /**
     * The caller MUST hold the owner's row lock and, when the token is absent,
     * must have explicitly confirmed permission to create it before calling.
     */
    public static function getOrCreate(User $user): string
    {
        // GLPI's error logger preserves warning arguments. Intercept decrypt/create
        // warnings before that logger can receive a credential or encryption key.
        set_error_handler(static function (): void {
            throw new RuntimeException('GLPI recovery credential is unavailable.');
        }, E_WARNING | E_USER_WARNING);
        try {
            // GLPI owns the storage format; recent versions encrypt this field at rest.
            $token = $user->getAuthToken('api_token', false);
        } catch (Throwable $exception) {
            // Do not retain the original exception: it could contain credential data.
            throw new RuntimeException('GLPI recovery credential is unavailable.');
        } finally {
            restore_error_handler();
        }

        // GLPI generates 40 ASCII alphanumeric characters. Its decryptor can return
        // ciphertext unchanged when the key is unavailable; never export that value.
        if (!is_string($token) || !preg_match('/\A[a-zA-Z0-9]{40}\z/D', $token)) {
            throw new RuntimeException('GLPI recovery credential is unavailable.');
        }

        return $token;
    }
}
