<?php
namespace Glpi\Controller { class AbstractController {} }
namespace GlpiPlugin\Jmbahub {
    class SsoAvailability { public static bool $allowed=true; public static function isApplicationAllowed(int $id): bool { return self::$allowed && in_array($id,[2,7],true); } }
    class SsoWebIdentity { public static ?int $provider=null; public static function providerFromCurrentSession(): int { return self::$provider ?? throw new \RuntimeException('No SSO origin'); } }
}
namespace Symfony\Component\HttpFoundation {
    class Request {
        public object $query;
        public function __construct(array $values) { $this->query=new class($values) { public function __construct(private array $values) {} public function get(string $key): mixed { return $this->values[$key]??null; } }; }
    }
    class Response { public function __construct(public string $content='',public int $status=200,public array $headers=[]) {} }
    class RedirectResponse extends Response { public function __construct(public string $target,int $status=302,array $headers=[]) {parent::__construct('',$status,$headers);} }
}
namespace {
    class Session { public static int $id=42; public static function getLoginUserID(): int { return self::$id; } }
    require dirname(__DIR__).'/src/Controller/SsoStartController.php';
    $CFG_GLPI=['root_doc'=>'/glpi'];
    $attempt=str_repeat('A',43);
    $request=fn(string $id)=>new \Symfony\Component\HttpFoundation\Request(['attempt'=>$attempt,'app'=>$id]);
    $controller=new \GlpiPlugin\Jmbahub\Controller\SsoStartController();
    Session::$id=0;
    $response=$controller($request('2'));
    Session::$id=42;
    if (!str_starts_with($response->target,'/glpi/plugins/nextool/sso/ssologin/start?app=2&redirect=')) throw new \RuntimeException('Local session must authenticate with selected application');
    \GlpiPlugin\Jmbahub\SsoWebIdentity::$provider=7;
    if (!str_contains($controller($request('2'))->target,'nextool/sso/ssologin/start')) throw new \RuntimeException('Another provider reused');
    \GlpiPlugin\Jmbahub\SsoWebIdentity::$provider=2;
    if ($controller($request('2'))->target!=='/glpi/plugins/jmbahub/SsoCallback?attempt='.$attempt.'&automatic=1') throw new \RuntimeException('Exact provider session not reused');
    \GlpiPlugin\Jmbahub\SsoAvailability::$allowed=false;
    if ($controller($request('2'))->status!==403) throw new \RuntimeException('Revoked provider accepted');
    if ($controller($request('2&redirect=https://evil.example'))->status!==403) throw new \RuntimeException('Invalid provider accepted');
    echo "SSO browser start checks passed: exact application reuse, other/local session, revoked provider\n";
}
