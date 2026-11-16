<?php

namespace GlpiPlugin\Jmbahub;

/** Assina a confirmação sem transmitir a credencial da instalação. */
final class SsoInstallationProof
{
    public static function sign(string $installationId, string $secret, string $attemptId, int $userId, string $sessionToken, ?string $handoffHash = null, ?int $providerAppId = null): string
    {
        if (!preg_match('/^[a-fA-F0-9-]{36}$/D', $installationId)
            || strlen($secret) < 32 || strlen($secret) > 255
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $attemptId) || $userId <= 0
            || !preg_match('~^[A-Za-z0-9+/=_-]{16,4096}$~D', $sessionToken)) {
            throw new \InvalidArgumentException('Dados de confirmação SSO inválidos.');
        }
        if ($handoffHash !== null && !preg_match('/^[a-f0-9]{64}$/D', $handoffHash)) {
            throw new \InvalidArgumentException('Retorno SSO inválido.');
        }
        $message = ($handoffHash === null ? "jmbahub-sso-session-v1\n" : "jmbahub-sso-session-v2\n") . $installationId . "\n" . $attemptId . "\n" . $userId
            . "\n" . hash('sha256', $sessionToken) . ($handoffHash === null ? '' : "\n" . $handoffHash);
        if ($providerAppId !== null) {
            if ($providerAppId <= 0) throw new \InvalidArgumentException('Aplicação SSO inválida.');
            $message = "jmbahub-sso-session-v3\n" . $installationId . "\n" . $attemptId . "\n" . $userId
                . "\n" . hash('sha256', $sessionToken) . "\n" . ($handoffHash ?? '-') . "\n" . $providerAppId;
        }
        return hash_hmac('sha256', $message, $secret);
    }
}
