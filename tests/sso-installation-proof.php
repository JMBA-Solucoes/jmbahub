<?php
require dirname(__DIR__) . '/src/SsoInstallationProof.php';

use GlpiPlugin\Jmbahub\SsoInstallationProof;

$id = '12345678-1234-1234-1234-123456789abc';
$token = str_repeat('A', 43);
$attempt = str_repeat('B', 43);
$proof = SsoInstallationProof::sign($id, $token, $attempt, 42, $token);
if (!preg_match('/^[a-f0-9]{64}$/D', $proof)
    || hash_equals($proof, SsoInstallationProof::sign($id, $token, $attempt, 43, $token))
    || hash_equals($proof, SsoInstallationProof::sign($id, $token, str_repeat('C', 43), 42, $token))) {
    throw new RuntimeException('SSO installation proof is not bound to the request.');
}
if (hash_equals($proof, SsoInstallationProof::sign($id, $token, $attempt, 42, str_repeat('D', 43)))) throw new RuntimeException('Session substitution accepted');
try {
    SsoInstallationProof::sign($id, $token, 'invalid', 42, $token);
    throw new RuntimeException('Invalid SSO attempt was accepted.');
} catch (InvalidArgumentException $expected) {
    // Expected.
}
echo "SSO installation proof checks passed\n";

$bound = SsoInstallationProof::sign($id,$token,$attempt,42,$token,null,2);
if ($bound !== '62d17b34e8bbc86b08cdd745df4b9ece0370a12716577ce2371fde20f102e000' || hash_equals($bound,SsoInstallationProof::sign($id,$token,$attempt,42,$token,null,1))
    || hash_equals($bound,$proof)) throw new RuntimeException('Provider proof mismatch or downgrade');
