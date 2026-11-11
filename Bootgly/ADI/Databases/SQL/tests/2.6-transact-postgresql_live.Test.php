<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


// ! Opt-in live E2E — BOOTGLY_PGSQL_E2E=1 + DB_* environment
$optin = getenv('BOOTGLY_PGSQL_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 5432;
$reachable = false;

if ($optin) {
   $Probe = @fsockopen($host, $port, $errno, $error, 0.5);
   $reachable = is_resource($Probe);

   if ($reachable) {
      fclose($Probe);
   }
}

$scenario = require __DIR__ . '/0.live.php';


return new Test(
   description: 'PostgreSQL(live): SQL::transact() commits, rolls back, nests and always returns its connection (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port, $scenario) {
      $Open = static fn (int $max): SQL => new SQL([
         'driver' => 'pgsql',
         'host' => $host,
         'port' => $port,
         'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres',
         'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres',
         'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
         'timeout' => 5.0,
         'secure' => [
            'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
         ],
         'pool' => ['min' => 0, 'max' => $max],
      ]);

      yield from $scenario($Open, 'pgsql');
   }
);
