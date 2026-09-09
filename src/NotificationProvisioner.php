<?php

namespace GlpiPlugin\Jmbahub;

use Config;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Toolbox;

/** Provisiona o transporte direto de eventos entre o plugin e a API. */
final class NotificationProvisioner
{
    private const MARKER_PREFIX = '[managed-by:jmbahub:';

    /** @return array{enabled: bool, event_count: int, checked_at: string} */
    public static function provision(): array
    {
        $credentials = PluginConfig::getOrCreateInstallationCredentials();
        $glpiUrl = PluginConfig::getGlpiBaseUrl();
        if ($glpiUrl === '') {
            throw new RuntimeException('Configure a URL base do GLPI antes de ativar as notificações.');
        }

        try {
            $response = Toolbox::getGuzzleClient(['timeout' => 15])->post(
                rtrim(PluginConfig::getApiBaseUrl(), '/') . '/integrations/glpi/provision',
                [
                    'headers' => ['Accept' => 'application/json'],
                    'json' => [
                        'installationId' => $credentials['installation_id'],
                        'installationToken' => $credentials['installation_token'],
                        'glpiUrl' => $glpiUrl,
                        'pluginVersion' => PLUGIN_JMBAHUB_VERSION,
                    ],
                ]
            );
        } catch (GuzzleException $error) {
            throw new RuntimeException('Não foi possível conectar ao serviço de notificações.', 0, $error);
        }

        $provisioned = json_decode((string) $response->getBody(), true);
        if (!is_array($provisioned) || empty($provisioned['webhookUrl']) || empty($provisioned['webhookSecret'])) {
            throw new RuntimeException('O serviço retornou uma configuração inválida.');
        }

        PluginConfig::setNotificationTransport(
            (string) $provisioned['webhookUrl'],
            (string) $provisioned['webhookSecret']
        );

        // Versoes anteriores criavam webhooks nativos. Eles precisam ser
        // desligados para nao duplicar eventos enviados agora pelos hooks PHP.
        self::deactivateManagedWebhooks();

        $status = [
            'enabled' => true,
            'event_count' => 3,
            'checked_at' => date(DATE_ATOM),
        ];
        Config::setConfigurationValues('plugin:jmbahub', [
            'notifications_enabled' => '1',
            'notifications_status' => json_encode($status),
            'notifications_last_error' => '',
        ]);
        return $status;
    }

    public static function recordFailure(\Throwable $error): void
    {
        Config::setConfigurationValues('plugin:jmbahub', [
            'notifications_enabled' => '0',
            'notifications_last_error' => mb_substr($error->getMessage(), 0, 500),
        ]);
        Toolbox::logInFile('php-errors', 'jmbahub: provisionamento de notificações falhou: ' . $error->getMessage());
    }

    /** Desativa somente objetos gerenciados; preserva a identidade para reinstalação sem suporte. */
    public static function deactivateManagedWebhooks(): void
    {
        if (!class_exists(\Webhook::class)) {
            return;
        }
        foreach (['new-ticket', 'ticket-update'] as $key) {
            $marker = self::MARKER_PREFIX . $key . ']';
            $comment = $marker . ' Configuração mantida automaticamente pelo plugin JMBA Hub.';
            $webhook = new \Webhook();
            if ($webhook->getFromDBByCrit(['comment' => $comment])) {
                $webhook->update(['id' => $webhook->getID(), 'is_active' => 0]);
            }
        }
        Config::setConfigurationValues('plugin:jmbahub', ['notifications_enabled' => '0']);
    }

}
