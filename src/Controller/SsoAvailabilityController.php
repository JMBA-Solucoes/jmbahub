<?php
namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Jmbahub\PluginConfig;
use GlpiPlugin\Jmbahub\SsoAvailability;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Public challenge response exposes policy only, never credentials or identity data. */
final class SsoAvailabilityController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/SsoAvailability', name: 'jmbahub_sso_availability', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];
        $challenge = $request->query->get('challenge');
        $id = $request->query->get('installationId');
        if (!is_string($challenge) || !preg_match('/^[a-f0-9]{64}$/D', $challenge)
            || !is_string($id) || !preg_match('/^[a-fA-F0-9-]{36}$/D', $id))
            return new JsonResponse(['error' => 'Consulta inválida.'], 400, $headers);
        try {
            $storedId = PluginConfig::getExistingInstallationId();
            if ($storedId === null || !hash_equals($storedId, $id))
                return new JsonResponse(['error' => 'Instalação indisponível.'], 403, $headers);
            $transport = PluginConfig::getNotificationTransport();
            if ($transport === null) throw new \RuntimeException();
            $allowed = PluginConfig::getSsoAllowedAppIds();
            $apps = SsoAvailability::availableApplications();
            $enabled = $apps !== [];
            if ($request->query->get('protocolVersion') === '2') {
                return new JsonResponse(['protocolVersion' => 2, 'enabled' => $enabled,
                    'allowedAppIds' => $allowed, 'applications' => $apps,
                    'proof' => SsoAvailability::proofV2($transport['secret'], $id, $challenge, $enabled, $allowed, $apps)], 200, $headers);
            }
            $enabled = count($allowed) === 1 && count($apps) === 1;
            $provider = $enabled ? $apps[0]['id'] : 0;
            return new JsonResponse(['enabled' => $enabled, 'providerAppId' => $provider,
                'proof' => SsoAvailability::proof($transport['secret'], $id, $challenge, $enabled, $provider)], 200, $headers);
        } catch (\Throwable $unavailable) {
            return new JsonResponse(['error' => 'Disponibilidade SSO não confirmada.'], 503, $headers);
        }
    }
}
