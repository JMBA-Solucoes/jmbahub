<?php
namespace GlpiPlugin\Jmbahub\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Jmbahub\SsoAvailability;
use GlpiPlugin\Jmbahub\SsoWebIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Reuses only a session authenticated by the exact selected Nextools application. */
final class SsoStartController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/SsoStart', name: 'jmbahub_sso_start', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $attempt = $request->query->get('attempt');
        $rawId = $request->query->get('app');
        if (!is_string($attempt) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $attempt)
            || !is_string($rawId) || !preg_match('/^[1-9][0-9]{0,8}$/D', $rawId)
            || !SsoAvailability::isApplicationAllowed((int) $rawId)) {
            return new Response('SSO indisponível. Inicie novamente pelo aplicativo.', 403, ['Cache-Control' => 'no-store']);
        }
        $callback = '/plugins/jmbahub/SsoCallback?attempt=' . $attempt . '&automatic=1';
        global $CFG_GLPI;
        $base = rtrim($CFG_GLPI['root_doc'] ?? '', '/');
        try {
            if (\Session::getLoginUserID() > 0 && SsoWebIdentity::providerFromCurrentSession() === (int) $rawId) {
                return new RedirectResponse($base . $callback, 302, ['Cache-Control' => 'no-store']);
            }
        } catch (\Throwable $notReusable) { /* Nextools will authenticate this session. */ }
        return new RedirectResponse($base . '/plugins/nextool/sso/ssologin/start?app=' . $rawId
            . '&redirect=' . rawurlencode($callback), 302, ['Cache-Control' => 'no-store']);
    }
}
