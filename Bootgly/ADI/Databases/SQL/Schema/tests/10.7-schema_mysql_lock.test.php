<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\MySQLLock;


use const BOOTGLY_STORAGE_DIR;
use function assert;
use function fclose;
use function fsockopen;
use function getenv;
use function is_dir;
use function is_resource;
use function mkdir;
use function rmdir;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Guard;


return new Specification(
   description: 'Database: MySQL schema dialect compiles GET_LOCK advisory locks',
   test: function () {
      $Database = new SQL(['driver' => 'mysql']);
      $Dialect = $Database->structure()->Dialect;

      $Lock = $Dialect->lock(123);
      $Unlock = $Dialect->unlock(123);

      yield assert(
         assertion: $Lock?->SQL === 'SELECT GET_LOCK(?, 0) AS `locked`'
            && $Lock->parameters === ['bootgly_123'],
         description: 'lock() compiles a non-blocking GET_LOCK with a namespaced key'
      );

      yield assert(
         assertion: $Unlock?->SQL === 'SELECT RELEASE_LOCK(?) AS `unlocked`'
            && $Unlock->parameters === ['bootgly_123'],
         description: 'unlock() compiles the matching RELEASE_LOCK'
      );

      yield assert(
         assertion: $Dialect->transactions === false,
         description: 'MySQL DDL stays non-transactional (implicit commits)'
      );

      // # Live advisory contention — two sessions dispute one GET_LOCK
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

      if ($optin === false || $reachable === false) {
         return;
      }

      $config = [
         'driver' => 'mysql',
         'host' => $host,
         'port' => $port,
         'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'bootgly',
         'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'root',
         'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
         'timeout' => 5.0,
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 1],
      ];
      $path = BOOTGLY_STORAGE_DIR . 'tests/schema-mysql-lock-' . uniqid() . '/';
      if (is_dir($path) === false) {
         mkdir($path, 0775, true);
      }

      try {
         $First = new SQL($config);
         $Second = new SQL($config);
         $FirstGuard = new Guard($First, $First->Pool, $First->structure()->Dialect, $path, "{$path}first.lock", 'Migration');
         $SecondGuard = new Guard($Second, $Second->Pool, $Second->structure()->Dialect, $path, "{$path}second.lock", 'Migration');

         $FirstGuard->lock();
         $blocked = false;

         try {
            $SecondGuard->lock();
         }
         catch (\RuntimeException) {
            $blocked = true;
         }

         yield assert(
            assertion: $blocked,
            description: 'A second session cannot acquire the held GET_LOCK'
         );

         $FirstGuard->unlock();
         $SecondGuard->lock();
         $SecondGuard->unlock();

         yield assert(
            assertion: true,
            description: 'RELEASE_LOCK frees the advisory lock for the next session'
         );
      }
      finally {
         foreach (['first.lock', 'first.lock.guard', 'second.lock', 'second.lock.guard'] as $artifact) {
            if (@unlink("{$path}{$artifact}") === false) {
               continue;
            }
         }
         @rmdir($path);
      }
   }
);
