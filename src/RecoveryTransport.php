<?php
namespace GlpiPlugin\Jmbahub;

/**
 * Public TLS is verified by the Java API before sending installation/session credentials.
 * PHP may receive HTTP from the TLS terminator. Never infer authorization from proxy headers.
 * This check is configuration validation, NOT evidence of a secure incoming transport.
 */
final class RecoveryTransport
{
    public static function hasHttpsPublicUrl(): bool
    {
        $url = parse_url(PluginConfig::getGlpiBaseUrl());
        return is_array($url) && ($url['scheme'] ?? '') === 'https'
            && !empty($url['host']) && !isset($url['user']) && !isset($url['pass'])
            && !isset($url['query']) && !isset($url['fragment']);
    }
}
