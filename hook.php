<?php

/**
 * Instalação do plugin: cria as tabelas necessárias.
 */
function plugin_jmbahub_install(): bool {
    global $DB;

    // Preserve access grants and installation credentials when upgrading glpiapp.
    if ($DB->tableExists('glpi_plugin_glpiapp_appusers') && !$DB->tableExists('glpi_plugin_jmbahub_appusers')) {
        $DB->doQuery('CREATE TABLE `glpi_plugin_jmbahub_appusers` LIKE `glpi_plugin_glpiapp_appusers`');
        $DB->doQuery('INSERT INTO `glpi_plugin_jmbahub_appusers` SELECT * FROM `glpi_plugin_glpiapp_appusers`');
    }
    $legacy = Config::getConfigurationValues('plugin:glpiapp');
    $current = Config::getConfigurationValues('plugin:jmbahub');
    if ($legacy) {
        Config::setConfigurationValues('plugin:jmbahub', array_replace($legacy, $current));
    }

    \GlpiPlugin\Jmbahub\PluginConfig::migrateSsoApplications();

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_jmbahub_appusers')) {
        $query = "CREATE TABLE `glpi_plugin_jmbahub_appusers` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `users_id`      int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_active`     tinyint(1)              NOT NULL DEFAULT 0,
            `date_creation` timestamp               NULL DEFAULT NULL,
            `date_mod`      timestamp               NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        $DB->doQuery($query);
    }

    // Instalação nunca falha por indisponibilidade externa. O autorreparo
    // também fica disponível na tela de configuração do plugin.
    try {
        if (class_exists(\GlpiPlugin\Jmbahub\NotificationProvisioner::class)) {
            \GlpiPlugin\Jmbahub\NotificationProvisioner::provision();
        }
    } catch (\Throwable $error) {
        \GlpiPlugin\Jmbahub\NotificationProvisioner::recordFailure($error);
    }

    return true;
}

/**
 * Desinstalação do plugin: remove as tabelas.
 */
function plugin_jmbahub_uninstall(): bool {
    global $DB;

    try {
        \GlpiPlugin\Jmbahub\NotificationProvisioner::deactivateManagedWebhooks();
    } catch (\Throwable $error) {
        Toolbox::logInFile('php-errors', 'jmbahub: falha ao desativar webhooks gerenciados: ' . $error->getMessage());
    }

    $tables = ['glpi_plugin_jmbahub_appusers'];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    return true;
}

/**
 * Expõe "Habilitado para o app (JMBA Hub)" como coluna/filtro pesquisável na
 * listagem nativa de Usuários (Administração > Usuários) — não numa tela de
 * busca própria do plugin. `massiveaction: false`: a escrita não é pelo
 * "Atualizar" genérico (o campo vive em `glpi_plugin_jmbahub_appusers`, tabela
 * própria do plugin, não em `glpi_users`) — é feita pela ação dedicada em
 * {@see plugin_jmbahub_MassiveActions()}. Só exposto com o módulo "Controle
 * de acesso ao app" ligado (senão o campo não tem efeito nenhum — ver
 * PluginConfig::isAccessControlEnabled()).
 */
function plugin_jmbahub_getAddSearchOptionsNew(string $itemtype): array {
    if ($itemtype !== User::class || !\GlpiPlugin\Jmbahub\PluginConfig::isAccessControlEnabled()) {
        return [];
    }

    return [
        [
            // Id na faixa reservada a plugins (bem acima da faixa usada pelos
            // search options nativos do GLPI para User), evita colisão.
            'id'            => 9000,
            'table'         => 'glpi_plugin_jmbahub_appusers',
            'field'         => 'is_active',
            'name'          => __('Habilitado para o app (JMBA Hub)', 'jmbahub'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ],
    ];
}

/**
 * Ação em massa "Habilitar/Desabilitar acesso ao app" na listagem nativa de
 * Usuários — evita ter que abrir a ficha de cada usuário um por um. Só
 * exposta com o módulo "Controle de acesso ao app" ligado e pra quem tem
 * direito de configuração (mesmo `$rightname` de PluginJmbahubAppuser).
 * Requer `$PLUGIN_HOOKS['use_massive_action']['jmbahub'] = true` em setup.php.
 */
function plugin_jmbahub_MassiveActions(string $itemtype): array {
    $actions = [];
    if (
        $itemtype === User::class
        && \GlpiPlugin\Jmbahub\PluginConfig::isAccessControlEnabled()
        && Session::haveRight('config', UPDATE)
    ) {
        PluginJmbahubAppuser::getMassiveActionsForItemtype($actions, $itemtype);
    }
    return $actions;
}
