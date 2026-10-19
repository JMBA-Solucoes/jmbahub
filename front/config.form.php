<?php

/**
 * Tela de configuração do plugin JMBA Hub — hub de módulos ligáveis/
 * desligáveis (Fase 1: só "Controle de acesso ao app"). Reachable via a
 * engrenagem "Configurar" na lista Setup > Plugins ($PLUGIN_HOOKS['config_page'],
 * registrado sempre em setup.php — é o único jeito de religar um módulo
 * depois de desligá-lo por aqui).
 *
 * Lista de módulos como array simples: adicionar um módulo novo no futuro é
 * só mais uma entrada aqui + o `if` de registro condicional correspondente
 * em setup.php, sem reescrever esta tela.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Jmbahub\PluginConfig;
use GlpiPlugin\Jmbahub\NotificationProvisioner;

Session::checkRight('config', UPDATE);

$modules = [
    'access_control_enabled' => [
        'icon'        => 'ti-shield-lock',
        'name'        => __('Controle de acesso ao app', 'jmbahub'),
        'description' => __(
            'Restringe o login no app mobile a usuários explicitamente habilitados (aba "JMBA Hub" na ficha do usuário; filtro e ação em massa "Habilitar/Desabilitar acesso ao app" na listagem de Usuários). Desligado: qualquer usuário GLPI autenticado pode entrar no app.',
            'jmbahub'
        ),
        'enabled'     => PluginConfig::isAccessControlEnabled(),
    ],
];
$notificationEvents = PluginConfig::getNotificationEventDefinitions();
$notificationRecipients = PluginConfig::getNotificationRecipientDefinitions();
$notificationMatrix = PluginConfig::getNotificationMatrix();

if (isset($_POST['activate_notifications'])) {
    try {
        $status = NotificationProvisioner::provision();
        Session::addMessageAfterRedirect(
            sprintf(__('Notificações ativadas. %d tipos de evento integrados diretamente.', 'jmbahub'), $status['event_count']),
            true,
            INFO
        );
        if (PluginConfig::getRecoverySecret() === '') {
            Session::addMessageAfterRedirect(
                'As notificações foram configuradas, mas a conexão automática do aplicativo não foi preparada. Tente Verificar e reparar novamente; se persistir, contate o suporte.',
                true,
                WARNING
            );
        }
    } catch (Throwable $error) {
        NotificationProvisioner::recordFailure($error);
        Session::addMessageAfterRedirect($error->getMessage(), true, ERROR);
    }
    Html::back();
}

if (isset($_POST['update'])) {
    $values = [];
    foreach (array_keys($modules) as $configKey) {
        $values[$configKey] = isset($_POST[$configKey]) && $_POST[$configKey] == 1 ? '1' : '0';
    }
    Config::setConfigurationValues('plugin:jmbahub', $values);
    $submittedMatrix = is_array($_POST['notification_matrix'] ?? null)
        ? $_POST['notification_matrix']
        : [];
    PluginConfig::setNotificationMatrix($submittedMatrix);
    Session::addMessageAfterRedirect(__('Configurações salvas.', 'jmbahub'), true, INFO);
    Html::back();
}

Html::header(__('JMBA Hub', 'jmbahub'), $_SERVER['PHP_SELF'], 'config', 'plugins');

global $CFG_GLPI;

echo "<div class='spaced d-flex align-items-center gap-3 mb-4'><img src='" . $CFG_GLPI['root_doc'] . "/Plugin/jmbahub/Logo' alt='' width='64' height='64'><div><h2 class='mb-1'>JMBA Hub</h2><p class='text-muted mb-0'>JMBA Soluções</p></div></div>";

$notificationConfig = Config::getConfigurationValues('plugin:jmbahub', [
    'notifications_enabled', 'notifications_status', 'notifications_last_error',
]);
$notificationsEnabled = ($notificationConfig['notifications_enabled'] ?? '0') === '1';
$notificationStatus = json_decode((string) ($notificationConfig['notifications_status'] ?? ''), true);
$lastError = (string) ($notificationConfig['notifications_last_error'] ?? '');

echo "<div class='spaced'>";
echo "<div class='card mb-4'><div class='card-body'>";
echo "<div class='d-flex align-items-start justify-content-between gap-3'>";
echo "<div><h3 class='card-title mb-1'><i class='ti ti-bell me-2'></i>" . __('Notificações do aplicativo', 'jmbahub') . "</h3>";
if ($notificationsEnabled) {
    echo "<div class='text-success fw-bold'><i class='ti ti-circle-check me-1'></i>" . __('Ativas', 'jmbahub') . "</div>";
    echo "<div class='text-muted small mt-1'>" . sprintf(
        __('%d eventos configurados e mantidos automaticamente.', 'jmbahub'),
        (int) ($notificationStatus['event_count'] ?? 3)
    ) . "</div>";
} else {
    echo "<div class='text-muted'>" . __('Ative para receber no aplicativo avisos sobre chamados e atualizações. O plugin envia os eventos diretamente; nenhum webhook do GLPI é criado.', 'jmbahub') . "</div>";
    if ($lastError !== '') {
        echo "<div class='text-danger small mt-2'><i class='ti ti-alert-circle me-1'></i>"
            . htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') . "</div>";
    }
}
echo "</div>";
echo "<form method='post' action='" . Plugin::getWebDir('jmbahub') . "/front/config.form.php'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<button type='submit' name='activate_notifications' class='btn btn-primary'>";
echo "<i class='ti ti-" . ($notificationsEnabled ? 'refresh' : 'bell-plus') . " me-1'></i>";
echo $notificationsEnabled ? __('Verificar e reparar', 'jmbahub') : __('Ativar notificações', 'jmbahub');
echo "</button></form></div></div></div>";

// Cards que são o próprio toggle (form-selectgroup-boxes do Tabler) — mesmo
// componente nativo do GLPI 11 usado na tela Administração > Definições de
// Ativos > Capacidades (templates/pages/admin/assetdefinition/capacities.html.twig).
// Reaproveita 100% o CSS/JS já carregado pelo core: sem estilo próprio, sem
// clonar o grid de cards do Marketplace.
echo "<div class='spaced'>";
echo "<p class='text-muted mb-3'>" . __('Módulos do JMBA Hub disponíveis neste GLPI. Desligar um módulo desativa a funcionalidade correspondente para todos os usuários.', 'jmbahub') . "</p>";
echo "<form method='post' action='" . Plugin::getWebDir('jmbahub') . "/front/config.form.php'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='card mb-4'><div class='card-body'>";
echo "<h3 class='card-title mb-1'><i class='ti ti-adjustments-horizontal me-2'></i>" . __('Regras de notificação', 'jmbahub') . "</h3>";
echo "<p class='text-muted mb-3'>" . __('Escolha, em cada evento, quais participantes do chamado receberão a notificação no aplicativo.', 'jmbahub') . "</p>";
echo "<div class='row gx-3'>";
foreach ($notificationEvents as $eventKey => $event) {
    echo "<div class='col-12 col-xl-6 mb-3'>";
    echo "<div class='card h-100 shadow-none'><div class='card-body'>";
    echo "<div class='d-flex align-items-start mb-3'>";
    echo "<span class='me-3'><i class='ti " . $event['icon'] . " fs-1 text-primary'></i></span>";
    echo "<div><div class='fw-bold'>" . $event['name'] . "</div><div class='text-muted small'>" . $event['description'] . "</div></div>";
    echo "</div><div class='form-selectgroup'>";
    foreach ($notificationRecipients as $recipientKey => $recipientLabel) {
        $fieldName = "notification_matrix[{$eventKey}][{$recipientKey}]";
        echo "<label class='form-selectgroup-item'>";
        echo "<input type='hidden' name='{$fieldName}' value='0'>";
        echo "<input type='checkbox' name='{$fieldName}' value='1' class='form-selectgroup-input'"
            . (!empty($notificationMatrix[$eventKey][$recipientKey]) ? " checked" : "") . ">";
        echo "<span class='form-selectgroup-label'><i class='ti ti-user me-1'></i>" . $recipientLabel . "</span>";
        echo "</label>";
    }
    echo "</div></div></div></div>";
}
echo "</div></div></div>";

echo "<div class='row gx-2'>";
foreach ($modules as $configKey => $module) {
    echo "<div class='col-12 col-lg-6 col-xxl-4 mb-2'>";
    echo "<label class='form-selectgroup-boxes flex-fill w-100 h-100' style='min-height: 90px;'>";
    echo "<input type='hidden' name='{$configKey}' value='0'>";
    echo "<input type='checkbox' name='{$configKey}' value='1' class='form-selectgroup-input'"
        . ($module['enabled'] ? " checked" : "") . ">";
    echo "<div class='form-selectgroup-label d-flex align-items-center h-100 shadow-none p-3'>";
    echo "<span class='me-3'><i class='ti {$module['icon']} fs-1 text-secondary'></i></span>";
    echo "<div class='text-start flex-fill'>";
    echo "<div class='fw-bold'>" . $module['name'] . "</div>";
    echo "<div class='text-muted small'>" . $module['description'] . "</div>";
    echo "</div>";
    echo "<div class='ms-2'><span class='form-selectgroup-check'></span></div>";
    echo "</div>";
    echo "</label>";
    echo "</div>";
}
echo "</div>";

echo "<div class='mt-3'>";
echo "<button type='submit' name='update' class='btn btn-primary'>";
echo "<i class='ti ti-device-floppy'></i> <span>" . __('Salvar') . "</span>";
echo "</button>";
echo "</div>";

Html::closeForm();
echo "</div>";

Html::footer();
