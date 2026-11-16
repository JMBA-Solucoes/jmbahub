<?php
// Execute apenas no GLPI descartável, com o mesmo usuário do servidor web.
if (getenv('JMBA_SSO_DISPOSABLE_TEST') !== '1') throw new RuntimeException('Teste exige laboratório descartável explícito.');
$glpiRoot = getenv('GLPI_SSO_TEST_ROOT') ?: '/usr/share/glpi';
require $glpiRoot . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();
if ($DB->dbdefault !== 'glpi_sso_lab') throw new RuntimeException('Banco de teste incorreto.');
require dirname(__DIR__, 2) . '/src/PluginConfig.php';
require dirname(__DIR__, 2) . '/src/SsoWebIdentity.php';
require dirname(__DIR__, 2) . '/src/SsoRestSession.php';
Config::setConfigurationValues('plugin:jmbahub', ['access_control_enabled' => '0']);
Config::setConfigurationValues('core', ['enable_api' => '1', 'enable_api_login_credentials' => '1', 'enable_api_login_external_token' => '0']);
$user = new User(); $user->getFromDB(2);
$auth = new Auth(); $auth->user = $user; $auth->auth_succeded = true;
Session::setPath(); Session::start(); Session::init($auth);
$webId = session_id();
$identity = \GlpiPlugin\Jmbahub\SsoWebIdentity::fromCurrentSession();
$token = \GlpiPlugin\Jmbahub\SsoRestSession::create();
if (session_id() !== $webId || (int) Session::getLoginUserID() !== 2 || ($_SESSION['valid_id'] ?? null) !== $webId) throw new RuntimeException('Web session was changed');
$decoded = (new GLPIKey())->decrypt(base64_decode($token, true));
if ($decoded === $webId) throw new RuntimeException('Web cookie was exported');
function apiCall(string $path, string $token): array {
    $curl = curl_init('http://127.0.0.1/apirest.php/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Session-Token: ' . $token, 'Accept: application/json'], CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return [$status, json_decode((string) $body, true)];
}
[$status, $data] = apiCall('getFullSession', $token);
if ($status !== 200 || (int) ($data['session']['glpiID'] ?? 0) !== $identity['userId']) throw new RuntimeException('REST session refused; HTTP ' . $status . '; code=' . (is_string($data[0] ?? null) ? $data[0] : 'unknown'));
\GlpiPlugin\Jmbahub\SsoRestSession::discard($token);
[$revoked] = apiCall('getFullSession', $token);
if ($revoked === 200 || session_id() !== $webId || ($_SESSION['valid_id'] ?? null) !== $webId) throw new RuntimeException('Revoke did not preserve web session');
$_SESSION['mfa_pre_auth'] = ['pending' => true];
$denied = false;
try { \GlpiPlugin\Jmbahub\SsoRestSession::create(); } catch (RuntimeException $expected) { $denied = true; }
unset($_SESSION['mfa_pre_auth']);
if (!$denied) throw new RuntimeException('MFA pending was accepted');
echo json_encode(['web_session_preserved' => true, 'different_rest_session' => true,
    'rest_identity_validated' => true, 'revoked_session_refused' => true, 'pending_mfa_refused' => true]) . PHP_EOL;
