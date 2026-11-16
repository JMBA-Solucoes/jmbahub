<?php
class Plugin { public static bool $active = true; public static function isPluginActive(string $name): bool { return self::$active; } }
class Config { public static array $values = ['sso_enabled'=>'1','sso_allowed_app_ids'=>'[1]']; public static function setConfigurationValues(string $context,array $values): void { self::$values=array_merge(self::$values,$values); } public static function getConfigurationValues(string $context, array $keys=[]): array { if (!$keys) return self::$values; return array_intersect_key(self::$values, array_flip($keys)); } }
class PluginNextoolModuleManager { public static array $active=['ssologin'=>true]; public static function getInstance(): self { return new self(); } public function getActiveModules(): array { return self::$active; } }
class PluginNextoolSsologinLogin { public static bool $available=true; public static function isLoginAvailable(): bool { return self::$available; } }
class PluginNextoolSsologinApplication { public static function getTable(): string { return 'fixture'; } public static bool $ready=true; public static array $applications=[1=>true]; private int $id=0; public function getFromDB(int $id): bool { $this->id=$id; return array_key_exists($id,self::$applications); } public function isReadyForLogin(): bool { return self::$ready && self::$applications[$this->id]; } }
class FixtureDb {
 public function tableExists(string $table): bool { return true; }
 public function request(array $query): array { return array_map(static fn(int $id): array => ['id'=>$id,'name'=>'Empresa '.$id,'provider'=>'azure'],array_keys(PluginNextoolSsologinApplication::$applications)); }
}
$DB=new FixtureDb();
$base=sys_get_temp_dir().'/jmba-nextools-fixture-'.bin2hex(random_bytes(8));
mkdir($base.'/ssologin/inc',0700,true);
foreach(['login.class.php','application.class.php'] as $file) file_put_contents($base.'/ssologin/inc/'.$file,'<?php // fixture');
define('NEXTOOL_MODULES_BASE',$base);
require dirname(__DIR__).'/src/SsoAvailability.php';
require dirname(__DIR__).'/src/PluginConfig.php';
use GlpiPlugin\Jmbahub\PluginConfig;
use GlpiPlugin\Jmbahub\SsoAvailability;
try {
 if (!PluginConfig::isSsoEnabled()) throw new RuntimeException('Enabled policy refused');
 Config::$values['sso_enabled']='0';
 if (PluginConfig::isSsoEnabled() || !SsoAvailability::providerIsReady(1)) throw new RuntimeException('App toggle must be independent of Nextools');
 Config::$values['sso_enabled']='1'; Plugin::$active=false;
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Disabled plugin accepted');
 Plugin::$active=true; PluginNextoolModuleManager::$active=[];
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Disabled module accepted');
 PluginNextoolModuleManager::$active=['ssologin'=>true];PluginNextoolSsologinLogin::$available=false;
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Unavailable Nextools accepted');
 PluginNextoolSsologinLogin::$available=true;PluginNextoolSsologinApplication::$ready=false;
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Inactive/unready provider accepted');
 PluginNextoolSsologinApplication::$ready=true;Config::$values['sso_allowed_app_ids']='[]';
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Invalid provider accepted');
 Config::$values['sso_allowed_app_ids']='[1]';
 if (!PluginConfig::isSsoEnabled()) throw new RuntimeException('Re-enabled policy refused');
 // Multiple Nextools applications: only the configured ID governs the app.
 PluginNextoolSsologinApplication::$applications=[1=>true,2=>true];
 Config::$values['sso_allowed_app_ids']='[2]';
 if (!PluginConfig::isSsoEnabled() || PluginConfig::getSsoAllowedAppIds()!==[2]) throw new RuntimeException('Explicit second provider refused');
 PluginNextoolSsologinApplication::$applications=[1=>true,2=>false];
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Inactive selected provider silently fell back');
 PluginNextoolSsologinApplication::$applications=[2=>true];
 unset(Config::$values['sso_allowed_app_ids']);
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Missing default provider silently selected another');
 Config::$values['sso_allowed_app_ids']='[2]';
 if (!PluginConfig::isSsoEnabled()) throw new RuntimeException('Non-default sole provider refused');
 Config::$values['sso_allowed_app_ids']='[99]';
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Deleted selected provider accepted');
 foreach (['0','-1','1.5','1000000000','',[]] as $invalid) {
  try { PluginConfig::normalizeSsoAppIds([$invalid]); throw new RuntimeException('Invalid selection accepted'); }
  catch (InvalidArgumentException $expected) {}
 }
 Config::$values=['sso_enabled'=>'1','sso_provider_app_id'=>'2'];
 PluginConfig::migrateSsoApplications();
 if (PluginConfig::getSsoAllowedAppIds()!==[2]) throw new RuntimeException('Upgrade lost selected provider');
 Config::$values['sso_allowed_app_ids']='[]';PluginConfig::migrateSsoApplications();
 if (PluginConfig::getSsoAllowedAppIds()!==[]) throw new RuntimeException('Empty selection restored at upgrade');
 Config::$values=[];PluginConfig::migrateSsoApplications();
 if (PluginConfig::getSsoAllowedAppIds()!==[]) throw new RuntimeException('Fresh install authorized provider');
 Config::$values=['sso_enabled'=>'1','sso_allowed_app_ids'=>'[1,2]'];
 PluginNextoolSsologinApplication::$applications=[1=>true,2=>true];
 if (count(SsoAvailability::availableApplications())!==2) throw new RuntimeException('Multiple permitted applications missing');
 PluginNextoolSsologinApplication::$applications=[];
 Config::$values['sso_allowed_app_ids']='[2]';
 if (PluginConfig::isSsoEnabled()) throw new RuntimeException('Empty provider list accepted');
 $proof=SsoAvailability::proof(str_repeat('k',43),'12345678-1234-1234-1234-123456789abc',str_repeat('a',64),true,1);
 if ($proof!=='bdd0cfcda14fc1e74e7ba0d4a240e4db5be75a08ac55c9928affdb996cb4a4b3') throw new RuntimeException('Signature vector mismatch');
 $choices=[['id'=>2,'name'=>'Empresa Á','provider'=>'azure'],['id'=>7,'name'=>'Google','provider'=>'google']];
 if (SsoAvailability::proofV2(str_repeat('k',43),'12345678-1234-1234-1234-123456789abc',str_repeat('a',64),true,[2,7],$choices)!=='ed80051421540e5432c4679371dfc3f0656562a3fef64af362ea218a8f38da31') throw new RuntimeException('List signature mismatch');
 echo "SSO availability checks passed: app toggle, multiple applications, no fallback, plugin/module/provider disable and signed policy\n";
} finally {
 foreach(['login.class.php','application.class.php'] as $file)unlink($base.'/ssologin/inc/'.$file);
 rmdir($base.'/ssologin/inc');rmdir($base.'/ssologin');rmdir($base);
}
