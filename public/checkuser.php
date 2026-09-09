<?php

/**
 * Endpoint consultado pela API Java para verificar se o usuário está habilitado.
 *
 * Localização no container: /var/www/glpi/public/plugins/jmbahub/checkuser.php
 * URL de acesso:            {glpiUrl}/plugins/jmbahub/checkuser.php?user_id={userId}
 * Header:                   Session-Token: {glpi_session_token}
 *
 * Este arquivo fica em public/ para ser servido diretamente pelo Apache.
 * Usa mysqli diretamente (sem classes do GLPI) para evitar dependências
 * de classes legacy não mapeadas no autoloader do Composer.
 *
 * Response:
 *   200 { "enabled": true/false }
 *   401 { "error": "..." }
 *   400 { "error": "..." }
 *   500 { "error": "..." }
 */

header('Content-Type: application/json');

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
$glpiRoot = realpath(__DIR__ . '/../../..');

// ── Localiza config_db.php (GLPI Docker separa código de dados) ──────────────

$configDbCandidates = [
    '/var/glpi/config/config_db.php',
    $glpiRoot . '/config/config_db.php',
    '/etc/glpi/config_db.php',
];

$configDb = null;
foreach ($configDbCandidates as $path) {
    if (file_exists($path)) {
        $configDb = $path;
        break;
    }
}

if ($configDb === null) {
    http_response_code(500);
    echo json_encode(['error' => 'config_db.php não encontrado.']);
    exit;
}

// ── Extrai credenciais via regex (evita instanciar classes do GLPI) ──────────

$configContent = file_get_contents($configDb);

$dbHost = '';
$dbUser = '';
$dbPass = '';
$dbName = '';

if (preg_match("/\\\$dbhost\s*=\s*'([^']+)'/", $configContent, $m))     $dbHost = $m[1];
if (preg_match("/\\\$dbuser\s*=\s*'([^']+)'/", $configContent, $m))     $dbUser = $m[1];
if (preg_match("/\\\$dbpassword\s*=\s*'([^']+)'/", $configContent, $m)) $dbPass = urldecode($m[1]);
if (preg_match("/\\\$dbdefault\s*=\s*'([^']+)'/", $configContent, $m))  $dbName = $m[1];

if (empty($dbHost) || empty($dbUser) || empty($dbName)) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível parsear config_db.php.']);
    exit;
}

// ── Conexão direta com mysqli (sem classes do GLPI, sem cache) ───────────────

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de conexão com o banco de dados.']);
    exit;
}

// ── Valida o secret — prioriza glpi_configs (banco), cai pra variável de
// ambiente se não houver linha configurada. Ver GlpiPlugin\Jmbahub\SharedSecret
// (não usada aqui de propósito — este arquivo evita depender do autoloader
// de classes do GLPI): PHP-FPM com clear_env ligado esconde variável de
// ambiente do worker que atende HTTP mesmo ela existindo no container —
// achado em produção 28/08/2026, guardar no banco elimina essa classe de bug.

$expectedSecret = '';
$configResult = $conn->query(
    "SELECT `value` FROM `glpi_configs` WHERE `context` = 'plugin:jmbahub' AND `name` = 'shared_secret' LIMIT 1"
);
if ($configResult !== false && ($configRow = $configResult->fetch_assoc()) && !empty($configRow['value'])) {
    $expectedSecret = $configRow['value'];
} else {
    $expectedSecret = getenv('GLPI_APP_SHARED_SECRET') ?: '';
}

$providedSecret = $_SERVER['HTTP_X_GLPI_APP_SECRET'] ?? '';
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    $conn->close();
    http_response_code(401);
    echo json_encode(['error' => 'Credencial do JMBA Hub inválida.']);
    exit;
}

// ── Módulo "Controle de acesso ao app" (hub de módulos, Fase 1) — se
// desligado pelo admin na tela do plugin, acesso liberado geral, sem
// checar a tabela por usuário. Ausente (nenhuma linha ainda) = habilitado,
// mesmo default seguro de PluginConfig::isAccessControlEnabled() (não
// replicado via classe aqui de propósito, mesmo motivo do shared_secret
// acima: este script evita depender do autoloader de plugins do GLPI).

$accessControlResult = $conn->query(
    "SELECT `value` FROM `glpi_configs` WHERE `context` = 'plugin:jmbahub' AND `name` = 'access_control_enabled' LIMIT 1"
);
$accessControlRow = $accessControlResult !== false ? $accessControlResult->fetch_assoc() : null;
if ($accessControlRow !== null && $accessControlRow['value'] === '0') {
    $conn->close();
    echo json_encode(['enabled' => true]);
    exit;
}

$stmt = $conn->prepare(
    'SELECT is_active FROM glpi_plugin_jmbahub_appusers WHERE users_id = ? LIMIT 1'
);

if (!$stmt) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar query.']);
    exit;
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($isActive);

$found   = $stmt->fetch();
$enabled = $found && (int) $isActive === 1;

$stmt->close();
$conn->close();

echo json_encode(['enabled' => $enabled]);
