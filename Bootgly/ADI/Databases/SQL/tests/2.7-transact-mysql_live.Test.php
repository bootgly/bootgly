<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


// ! Opt-in live E2E — BOOTGLY_MYSQL_E2E=1 + DB_* environment
$optin = getenv('BOOTGLY_MYSQL_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 3306;
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
   description: 'MySQL(live): SQL::transact() commits, rolls back, nests and always returns its connection (requires BOOTGLY_MYSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port, $scenario) {
      $Open = static fn (int $max): SQL => new SQL([
         'driver' => 'mysql',
         'host' => $host,
         'port' => $port,
         'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'bootgly',
         'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'root',
         'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
         'timeout' => 5.0,
         // ! Plaintext caching_sha2 full auth needs the pinned server public
         //   key in DB_SERVER_PUBLIC_KEY; TLS is opt-in through DB_SSLMODE.
         'secure' => [
            'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
            'key' => getenv('DB_SERVER_PUBLIC_KEY') !== false ? (string) getenv('DB_SERVER_PUBLIC_KEY') : '',
         ],
         'pool' => ['min' => 0, 'max' => $max],
      ]);

      yield from $scenario($Open, 'mysql');
   }
);
