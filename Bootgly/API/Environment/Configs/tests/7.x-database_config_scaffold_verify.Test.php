<?php

namespace Bootgly\API\Environment\Configs\Tests\ScaffoldVerify;


use const BOOTGLY_ROOT_DIR;
use function assert;
use function getenv;
use function json_encode;
use function putenv;
use function str_starts_with;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config as ADIConfig;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;


/**
 * The Demo project's database scope is what a new project starts from. Its
 * `DB_SSLVERIFY` binding used to default to `true`, which re-imposed
 * certificate verification on the default `prefer` mode that ADI Config no
 * longer implies — so a scaffolded project still refused a stock MySQL 8.
 * Left unbound, the flag is derived by ADI from the mode.
 */
$configure = static function (string $driver): ADIConfig {
   // ! bind() reads the environment when the scope file runs: the shipped
   //   defaults are the subject, so every DB_* key — any of them could override
   //   or break a binding — is cleared around the require and restored afterwards
   $saved = [];
   foreach (getenv() as $key => $value) {
      if (str_starts_with($key, 'DB_')) {
         $saved[$key] = $value;
         putenv($key);
      }
   }
   putenv("DB_CONNECTION={$driver}");
   try {
      $Scope = require BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/configs/database/database.Config.php';
   }
   finally {
      putenv('DB_CONNECTION');
      foreach ($saved as $key => $value) {
         putenv("{$key}={$value}");
      }
   }
   if ($Scope instanceof Config === false) {
      throw new RuntimeException('The Demo database scope did not return a Config.');
   }
   // @ The file evaluates to its last node — climb to the scope root as the loader does
   while ($Scope->parent !== null) {
      $Scope = $Scope->parent;
   }

   return (new DatabaseConfig($Scope))->configure();
};

return new Test(
   description: 'DatabaseConfig: the Demo scaffold leaves DB_SSLVERIFY unbound, so its default prefer mode reaches a self-signed server unverified',
   test: function () use ($configure) {
      foreach (['pgsql', 'mysql'] as $driver) {
         $Config = $configure($driver);
         $secure = $Config->secure;

         yield assert(
            assertion: $Config->driver === $driver
               && $secure['mode'] === ADIConfig::SECURE_PREFER
               && $secure['verify'] === false
               && $secure['name'] === false,
            description: "{$driver}: the scaffold's default TLS mode is prefer with verification off — " . json_encode([$Config->driver, $secure])
         );
      }
   }
);
