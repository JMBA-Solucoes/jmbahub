<?php

/**
 * Endpoint consultado pela API Java para verificar se o usuário está habilitado.
 *
 * GET /plugins/jmbahub/api/checkuser.php?user_id={userId}
 * Header: Session-Token: {glpi_session_token}
 *
 * Response:
 *   200 { "enabled": true }   → usuário habilitado
 *   200 { "enabled": false }  → usuário não habilitado
 *   401 { "error": "..." }    → session-token ausente
 *   400 { "error": "..." }    → parâmetro ausente
 *   500 { "error": "..." }    → erro interno
 *
 * Estratégia: usa apenas o DB layer do GLPI (sem o framework web) para evitar
 * redirecionamentos/403 do front-controller e evitar o cache da Search API.
 */

header('Content-Type: application/json');

// Valida parâmetros antes de qualquer bootstrap pesado
$sessionToken = $_SERVER['HTTP_SESSION_TOKEN'] ?? null;
if (!$sessionToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Session-Token header obrigatório.']);
    exit;
}

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro user_id obrigatório e numérico.']);
    exit;
}

$userId   = (int) $_GET['user_id'];
$glpiRoot = realpath(__DIR__ . '/../../../');

// ── Bootstrap mínimo: apenas Composer autoload + config do DB ────────────────

require_once($glpiRoot . '/vendor/autoload.php');

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', $glpiRoot);
}

// local_define.php define GLPI_CONFIG_DIR e outras constantes de caminho
$localDefine = $glpiRoot . '/config/local_define.php';
if (file_exists($localDefine)) {
    include_once($localDefine);
}

if (!defined('GLPI_CONFIG_DIR')) {
    define('GLPI_CONFIG_DIR', $glpiRoot . '/config');
}

$configDb = GLPI_CONFIG_DIR . '/config_db.php';
if (!file_exists($configDb)) {
    http_response_code(500);
    echo json_encode(['error' => 'Arquivo config_db.php não encontrado.']);
    exit;
}

require_once($configDb);

// ── Conexão e consulta direta (sem cache da Search API) ──────────────────────

global $DB;
$DB = new DB();

if (!$DB->connected) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha na conexão com o banco de dados.']);
    exit;
}

// ── Valida o secret — prioriza glpi_configs (banco), cai pra variável de
// ambiente se não houver linha configurada (ver GlpiPlugin\Jmbahub\SharedSecret;
// não usada aqui pra não depender do autoloader de plugins do GLPI, que este
// bootstrap parcial não garante ter registrado). PHP-FPM com clear_env ligado
// esconde variável de ambiente do worker que atende HTTP mesmo ela existindo
// no container — achado em produção 28/08/2026, guardar no banco elimina
// essa classe de bug.

$expectedSecret = '';
$configRow = Config::getConfigurationValues('plugin:jmbahub', ['shared_secret']);
if (!empty($configRow['shared_secret'])) {
    $expectedSecret = $configRow['shared_secret'];
} else {
    $expectedSecret = getenv('GLPI_APP_SHARED_SECRET') ?: '';
}

$providedSecret = $_SERVER['HTTP_X_GLPI_APP_SECRET'] ?? '';
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Credencial do JMBA Hub inválida.']);
    exit;
}

$result = $DB->request([
    'SELECT' => ['is_active'],
    'FROM'   => 'glpi_plugin_jmbahub_appusers',
    'WHERE'  => ['users_id' => $userId],
    'LIMIT'  => 1,
]);

$row     = $result->count() > 0 ? $result->current() : null;
$enabled = $row !== null && (int) $row['is_active'] === 1;

echo json_encode(['enabled' => $enabled]);
