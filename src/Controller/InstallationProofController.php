<?php
namespace GlpiPlugin\Jmbahub\Controller;

use Config;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Jmbahub\PluginConfig;
use GlpiPlugin\Jmbahub\RecoveryTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Proves possession without accepting or disclosing any integration secret. */
final class InstallationProofController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/InstallationProof', name: 'jmbahub_installation_proof', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];
        $challenge = $request->query->get('challenge', '');
        $id = $request->query->get('installationId', '');
        $stored = Config::getConfigurationValues('plugin:jmbahub', ['installation_id']);
        if (!RecoveryTransport::hasHttpsPublicUrl()) {
            return new JsonResponse(['code' => 'GLPI_PUBLIC_HTTPS_REQUIRED',
                'error' => 'A URL pública do GLPI deve ser HTTPS para configurar a conexão automática.'], 403, $headers);
        }
        if (!is_string($challenge) || !preg_match('/^[a-f0-9]{64}$/D', $challenge)) {
            return new JsonResponse(['code' => 'INSTALLATION_CHALLENGE_INVALID',
                'error' => 'O desafio de confirmação da instalação é inválido.'], 400, $headers);
        }
        if (!is_string($id) || empty($stored['installation_id']) || !hash_equals($stored['installation_id'], $id)) {
            return new JsonResponse(['code' => 'INSTALLATION_IDENTITY_MISMATCH',
                'error' => 'A identidade recebida não corresponde à instalação GLPI.'], 403, $headers);
        }
        try {
            $credentials = PluginConfig::getOrCreateInstallationCredentials();
            return new JsonResponse(['proof' => hash_hmac('sha256', $id . '|' . $challenge,
                $credentials['installation_token'])], 200, $headers);
        } catch (\Throwable $error) {
            return new JsonResponse(['code' => 'INSTALLATION_CREDENTIAL_UNAVAILABLE', 'error' => 'Não foi possível ler a credencial da instalação.'], 503, $headers);
        }
    }
}
