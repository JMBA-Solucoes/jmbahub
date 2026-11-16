<?php

/**
 * ---------------------------------------------------------------------
 * Resolve as configurações compartilhadas entre o backend Java e o plugin
 * (segredo de autenticação máquina-a-máquina + URL do backend usada pela
 * ponte de WebView).
 *
 * Prioriza os valores guardados no próprio banco do GLPI (`glpi_configs`,
 * contexto `plugin:jmbahub`) — configuráveis com um simples SQL, sem
 * depender de variável de ambiente do servidor PHP. Sem valor no banco,
 * cai no `getenv()` de sempre (compatibilidade com instalações já
 * configuradas via variável de ambiente).
 *
 * Motivo da troca (achado em produção, 28/08/2026): PHP-FPM com
 * `clear_env` ligado (padrão comum) esconde variáveis de ambiente do
 * container do processo que atende requisição HTTP, mesmo elas existindo
 * de verdade no container (confirmado via `docker exec`/CLI) — só
 * aparecem pro PHP-CLI, nunca pro worker que serve a request. Whitelistar
 * uma variável de cada vez em `env[]` no pool do PHP-FPM é frágil (é
 * exatamente o que aconteceu: só `GLPI_APP_SHARED_SECRET` foi whitelistado
 * na primeira rodada, `GLPI_APP_API_BASE_URL` continuou quebrado). Guardar
 * no banco elimina essa classe inteira de bug de uma vez, pra qualquer
 * config futura — basta usar {@link resolve()}.
 *
 * Pra configurar num cliente novo (ou migrar um que já usa variável de
 * ambiente):
 *   INSERT INTO glpi_configs (context, name, value) VALUES
 *     ('plugin:jmbahub', 'shared_secret', '<secret>'),
 *     ('plugin:jmbahub', 'api_base_url', '<url-do-backend-java>')
 *   ON DUPLICATE KEY UPDATE value = VALUES(value);
 *
 * Usada só por quem já roda com bootstrap completo do GLPI (Controllers
 * via NativeFormsAuthTrait/FormBridgeConsumeController).
 * `public/checkuser.php` e `api/checkuser.php` replicam a mesma lógica só
 * pro `shared_secret` (único que usam) sem depender desta classe — o
 * primeiro por design (evita autoload/framework), o segundo por não ter
 * garantia de que o autoloader de plugins do GLPI esteja registrado nesse
 * bootstrap parcial (só carrega `vendor/autoload.php`, não o kernel
 * completo).
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Jmbahub;

use Config;
use GLPIKey;
use Toolbox;

class PluginConfig
{
    /** Retorno fixado pelo administrador; nunca aceito pela query do navegador. */
    public static function getSsoReturnUri(): ?string
    {
        $values = Config::getConfigurationValues('plugin:jmbahub', ['sso_return_scheme']);
        $scheme = $values['sso_return_scheme'] ?? '';
        return is_string($scheme) && preg_match('/^[a-z][a-z0-9-]{7,63}$/D', $scheme)
            ? $scheme . '://sso/complete' : null;
    }

    public const DEFAULT_API_BASE_URL = 'https://api-glpi-app.jmbasolucoes.com.br';

    /** @return array<string, array{icon: string, name: string, description: string, defaults: array<string, bool>}> */
    public static function getNotificationEventDefinitions(): array
    {
        return [
            'ticket_created' => ['icon' => 'ti-ticket', 'name' => __('Chamado criado', 'jmbahub'), 'description' => __('Enviado assim que um novo chamado é registrado.', 'jmbahub'), 'defaults' => ['requester' => true, 'observer' => false, 'technician' => false]],
            'technician_assigned' => ['icon' => 'ti-user-check', 'name' => __('Técnico atribuído', 'jmbahub'), 'description' => __('Enviado quando um técnico é atribuído ao chamado.', 'jmbahub'), 'defaults' => ['requester' => true, 'observer' => false, 'technician' => true]],
            'followup_added' => ['icon' => 'ti-message-circle-plus', 'name' => __('Acompanhamento adicionado', 'jmbahub'), 'description' => __('Enviado quando uma nova mensagem é adicionada ao chamado.', 'jmbahub'), 'defaults' => ['requester' => true, 'observer' => true, 'technician' => true]],
            'task_changed' => ['icon' => 'ti-list-check', 'name' => __('Tarefa criada ou atualizada', 'jmbahub'), 'description' => __('Enviado quando uma tarefa do chamado é criada ou alterada.', 'jmbahub'), 'defaults' => ['requester' => false, 'observer' => false, 'technician' => true]],
            'status_changed' => ['icon' => 'ti-arrows-exchange', 'name' => __('Status alterado', 'jmbahub'), 'description' => __('Enviado quando o chamado muda de status.', 'jmbahub'), 'defaults' => ['requester' => true, 'observer' => true, 'technician' => true]],
            'solution_added' => ['icon' => 'ti-bulb', 'name' => __('Solução adicionada', 'jmbahub'), 'description' => __('Enviado quando uma solução é registrada no chamado.', 'jmbahub'), 'defaults' => ['requester' => true, 'observer' => true, 'technician' => true]],
            'ticket_closed' => ['icon' => 'ti-circle-check', 'name' => __('Chamado solucionado ou fechado', 'jmbahub'), 'description' => __('Enviado quando o chamado chega ao estado solucionado ou fechado.', 'jmbahub'), 'defaults' => ['requester' => true, 'observer' => true, 'technician' => true]],
        ];
    }

    /** @return array<string, string> */
    public static function getNotificationRecipientDefinitions(): array
    {
        return ['requester' => __('Requerente', 'jmbahub'), 'observer' => __('Observador', 'jmbahub'), 'technician' => __('Técnico', 'jmbahub')];
    }

    /** @return array<string, array<string, bool>> */
    public static function getNotificationMatrix(): array
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['notification_matrix']);
        $decoded = json_decode((string) ($stored['notification_matrix'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $matrix = [];
        foreach (self::getNotificationEventDefinitions() as $event => $definition) {
            foreach (self::getNotificationRecipientDefinitions() as $recipient => $_label) {
                $matrix[$event][$recipient] = array_key_exists($recipient, $decoded[$event] ?? [])
                    ? (bool) $decoded[$event][$recipient]
                    : $definition['defaults'][$recipient];
            }
        }
        return $matrix;
    }

    /** @param array<string, array<string, bool>> $matrix */
    public static function setNotificationMatrix(array $matrix): void
    {
        $normalized = [];
        foreach (self::getNotificationEventDefinitions() as $event => $_definition) {
            foreach (self::getNotificationRecipientDefinitions() as $recipient => $_label) {
                $normalized[$event][$recipient] = !empty($matrix[$event][$recipient]);
            }
        }
        Config::setConfigurationValues('plugin:jmbahub', [
            'notification_matrix' => json_encode($normalized, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Separate from the legacy global shared secret and notification transport. */
    public static function getRecoverySecret(): string
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['recovery_secret']);
        $value = (string) ($stored['recovery_secret'] ?? '');
        return str_starts_with($value, 'enc:') ? (string) (new GLPIKey())->decrypt(substr($value, 4)) : '';
    }

    public static function setRecoverySecret(string $secret): void
    {
        Config::setConfigurationValues('plugin:jmbahub', [
            'recovery_secret' => 'enc:' . (new GLPIKey())->encrypt($secret),
        ]);
    }

    public static function getSharedSecret(): string
    {
        return self::resolve('shared_secret', 'GLPI_APP_SHARED_SECRET');
    }

    public static function getApiBaseUrl(): string
    {
        $configured = self::resolve('api_base_url', 'GLPI_APP_API_BASE_URL');
        return $configured !== '' ? $configured : self::DEFAULT_API_BASE_URL;
    }

    /** SSO fica desabilitado até que toda a integração esteja disponível. */
    public static function isSsoEnabled(): bool
    {
        return self::isSsoAllowedInApp() && SsoAvailability::availableApplications() !== [];
    }

    /** Explicit allowlist: absence never authorizes an application. */
    public static function getSsoAllowedAppIds(): array
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['sso_allowed_app_ids']);
        $value = json_decode((string) ($stored['sso_allowed_app_ids'] ?? '[]'), true);
        return self::normalizeSsoAppIds($value);
    }

    public static function normalizeSsoAppIds($value): array
    {
        if (!is_array($value) || count($value) > 100) throw new \InvalidArgumentException('Seleção SSO inválida.');
        $ids = [];
        foreach ($value as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $id)) {
                throw new \InvalidArgumentException('Aplicação SSO inválida.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Called only by installation/upgrade, not on public requests. */
    public static function migrateSsoApplications(): void
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub');
        if (array_key_exists('sso_allowed_app_ids', $stored)) return;
        $ids = array_key_exists('sso_enabled', $stored) && self::getSsoProviderAppId() > 0
            ? [self::getSsoProviderAppId()] : [];
        Config::setConfigurationValues('plugin:jmbahub', ['sso_allowed_app_ids' => json_encode($ids)]);
    }

    public static function getSsoProviderAppId(): int
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['sso_provider_app_id']);
        $value = $stored['sso_provider_app_id'] ?? '1';
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value) ? (int) $value : 0;
    }

    public static function isSsoAllowedInApp(): bool
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['sso_enabled']);
        return ($stored['sso_enabled'] ?? '') === '1';
    }

    public static function getExistingInstallationId(): ?string
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['installation_id']);
        $id = (string) ($stored['installation_id'] ?? '');
        return $id !== '' ? $id : null;
    }

    /** @return array{endpoint: string, secret: string}|null */
    public static function getNotificationTransport(): ?array
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', [
            'notification_endpoint', 'notification_secret',
        ]);
        $endpoint = (string) ($stored['notification_endpoint'] ?? '');
        $encryptedSecret = (string) ($stored['notification_secret'] ?? '');
        if ($endpoint === '' || !str_starts_with($encryptedSecret, 'enc:')) {
            return null;
        }
        $secret = (string) (new GLPIKey())->decrypt(substr($encryptedSecret, 4));
        return $secret === '' ? null : ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public static function setNotificationTransport(string $endpoint, string $secret): void
    {
        Config::setConfigurationValues('plugin:jmbahub', [
            'notification_endpoint' => rtrim($endpoint, '/'),
            'notification_secret' => 'enc:' . (new GLPIKey())->encrypt($secret),
        ]);
    }

    /** @return array{installation_id: string, installation_token: string} */
    public static function getOrCreateInstallationCredentials(): array
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', [
            'installation_id', 'installation_token',
        ]);
        $id = (string) ($stored['installation_id'] ?? '');
        $storedToken = (string) ($stored['installation_token'] ?? '');
        $token = str_starts_with($storedToken, 'enc:')
            ? (string) (new GLPIKey())->decrypt(substr($storedToken, 4))
            : $storedToken;
        if ($id !== '' && $token !== '') {
            if (!str_starts_with($storedToken, 'enc:')) {
                Config::setConfigurationValues('plugin:jmbahub', [
                    'installation_token' => 'enc:' . (new GLPIKey())->encrypt($token),
                ]);
            }
            return ['installation_id' => $id, 'installation_token' => $token];
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $id = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4),
            substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Config::setConfigurationValues('plugin:jmbahub', [
            'installation_id' => $id,
            'installation_token' => 'enc:' . (new GLPIKey())->encrypt($token),
        ]);
        return ['installation_id' => $id, 'installation_token' => $token];
    }

    public static function getGlpiBaseUrl(): string
    {
        $core = Config::getConfigurationValues('core', ['url_base']);
        $url = (string) ($core['url_base'] ?? '');
        if ($url === '') {
            $url = Toolbox::getBaseUrl(true);
        }
        return rtrim($url, '/');
    }

    /**
     * Módulo "Controle de acesso ao app" (hub de módulos, Fase 1): liga/desliga
     * a checagem por usuário (aba "JMBA Hub" na ficha do usuário + gate em
     * checkuser.php). Sem linha em `glpi_configs` (instalação nova ou já
     * existente antes deste módulo) o default é `true` — preserva 100% o
     * comportamento atual em produção, nunca abre acesso geral por padrão.
     */
    public static function isAccessControlEnabled(): bool
    {
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['access_control_enabled']);
        if (!array_key_exists('access_control_enabled', $stored) || $stored['access_control_enabled'] === '') {
            return true;
        }
        return $stored['access_control_enabled'] === '1';
    }

    private static function resolve(string $configName, string $envVarName): string
    {
        $fromDb = Config::getConfigurationValues('plugin:jmbahub', [$configName]);
        if (!empty($fromDb[$configName])) {
            return $fromDb[$configName];
        }

        return getenv($envVarName) ?: '';
    }
}
