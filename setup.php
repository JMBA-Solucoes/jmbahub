<?php

/**
 * Plugin jmbahub - JMBA Hub Mobile Access
 */

define('PLUGIN_JMBAHUB_VERSION', '1.4.10');
define('PLUGIN_JMBAHUB_MIN_GLPI', '10.0.0');
define('PLUGIN_JMBAHUB_MAX_GLPI', '11.9.99');

// O console de instalacao/upgrade nao carrega hook.php automaticamente.
// Sem este include, plugin_jmbahub_install() fica indisponivel no --force.
require_once __DIR__ . '/hook.php';

/**
 * Metadados do plugin.
 */
function plugin_version_jmbahub(): array {
    return [
        'name'         => 'JMBA Hub',
        'version'      => PLUGIN_JMBAHUB_VERSION,
        'author'       => 'JMBA Soluções',
        'license'      => 'GPL-2.0-or-later',
        'homepage'     => 'https://github.com/JMBA-Solucoes/jmbahub',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_JMBAHUB_MIN_GLPI,
                'max' => PLUGIN_JMBAHUB_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Verifica pré-requisitos antes da instalação.
 */
function plugin_jmbahub_check_prerequisites(): bool {
    if (version_compare(GLPI_VERSION, PLUGIN_JMBAHUB_MIN_GLPI, 'lt')) {
        echo "Este plugin requer GLPI >= " . PLUGIN_JMBAHUB_MIN_GLPI;
        return false;
    }
    return true;
}

/**
 * Verifica configurações após instalação.
 */
function plugin_jmbahub_check_config(): bool {
    return true;
}

/**
 * Inicializa o plugin: registra hooks e menus.
 */
function plugin_init_jmbahub(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['jmbahub'] = true;
    $PLUGIN_HOOKS['add_css']['jmbahub'][] = 'css/branding.css';

    // Engrenagem "Configurar" na lista Setup > Plugins (front/config.form.php,
    // tela do hub de módulos) — sempre registrada, incondicional: é o único
    // jeito de o admin religar um módulo depois de desligá-lo por lá.
    $PLUGIN_HOOKS['config_page']['jmbahub'] = 'front/config.form.php';

    // Ação em massa "Habilitar/Desabilitar acesso ao app" + filtro na
    // listagem nativa de Usuários (plugin_jmbahub_MassiveActions/
    // plugin_jmbahub_getAddSearchOptionsNew em hook.php) — hook sempre
    // registrado; a checagem do módulo "Controle de acesso" ligado acontece
    // dentro das próprias funções, mesmo padrão de checkuser.php.
    $PLUGIN_HOOKS['use_massive_action']['jmbahub'] = true;

    // Notificacoes push sem dependencia dos webhooks nativos do GLPI.
    // O plugin observa os eventos internos e publica o payload assinado
    // diretamente na API do JMBA Hub.
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['jmbahub']['Ticket_User'] = [
        \GlpiPlugin\Jmbahub\DirectNotificationSender::class,
        'onItemAdd',
    ];
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['jmbahub']['Ticket'] = [
        \GlpiPlugin\Jmbahub\DirectNotificationSender::class,
        'onItemAdd',
    ];
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['jmbahub']['ITILFollowup'] = [
        \GlpiPlugin\Jmbahub\DirectNotificationSender::class,
        'onItemAdd',
    ];
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['jmbahub']['TicketTask'] = [
        \GlpiPlugin\Jmbahub\DirectNotificationSender::class,
        'onItemAdd',
    ];
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['jmbahub']['ITILSolution'] = [
        \GlpiPlugin\Jmbahub\DirectNotificationSender::class,
        'onItemAdd',
    ];
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_UPDATE]['jmbahub'] = [
        \GlpiPlugin\Jmbahub\DirectNotificationSender::class,
        'onItemUpdate',
    ];

    if (Session::getLoginUserID() && \GlpiPlugin\Jmbahub\PluginConfig::isAccessControlEnabled()) {
        Plugin::registerClass('PluginJmbahubAppuser', [
            'addtabon' => ['User'],
        ]);

        $PLUGIN_HOOKS['menu_toadd']['jmbahub'] = [
            'admin' => 'PluginJmbahubAppuser',
        ];
    }
}

/**
 * Chamado no boot do kernel Symfony, antes da checagem de CSRF
 * (CheckCsrfListener) e do início da sessão baseada em cookie
 * (SessionManager). 'csrf_compliant' acima cobre só o mecanismo legado
 * (public/plugins/*.php, ex.: checkuser.php); os Controllers registrados
 * via PluginRoutesLoader (Entrega A da renderização nativa dos formulários)
 * usam o kernel novo e precisam desse registro pra valer como
 * "stateless" — chamadas máquina-a-máquina do backend Java autenticadas
 * pelo GLPI_APP_SHARED_SECRET, sem sessão de navegador nem token CSRF.
 */
function plugin_jmbahub_boot(): void {
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/InstallationProof$#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/SessionAuthorization$#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/SessionCredential$#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/FormStructure/#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/FormSubmit/#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/FormActors/#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/FormItems/#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/FormFileUpload/#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/TemplatePreview/#');
    \Glpi\Http\SessionManager::registerPluginStatelessPath('jmbahub', '#^/ServiceCatalog#');

    // checkuser.php faz sua própria autenticação (header Session-Token +
    // X-GLPI-App-Secret, ver public/checkuser.php) — sem este registro, o
    // Firewall do GLPI 11 (Firewall::computeFallbackStrategyForPlugin) cai
    // no fallback padrão de qualquer script legado em public/plugins/*.php
    // sem estratégia própria (STRATEGY_AUTHENTICATED), exigindo uma sessão
    // de navegador real e redirecionando pro login ANTES do script sequer
    // rodar — o header Session-Token nunca chega a ser lido. Bug real
    // encontrado em 2026-08-28 testando plugin_check_enabled pela primeira
    // vez de ponta a ponta: o endpoint nunca funcionou de fato no GLPI 11.
    // addPluginStrategyForLegacyScripts só existe a partir do GLPI 11 (não
    // no Firewall do GLPI 10.0.23) — inofensivo lá, já que plugin_boot()
    // (Plugin::bootPlugins) também só existe no core do GLPI 11.
    if (method_exists(\Glpi\Http\Firewall::class, 'addPluginStrategyForLegacyScripts')) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'jmbahub',
            '#^/checkuser\.php$#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }
}
