<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\ResyncMySQL;


use const BOOTGLY_STORAGE_DIR;
use function assert;
use function count;
use function fclose;
use function file_put_contents;
use function fsockopen;
use function getenv;
use function glob;
use function is_dir;
use function is_resource;
use function json_encode;
use function mkdir;
use function rmdir;
use function uniqid;
use function unlink;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Seed\Runner;


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


return new Test(
   description: 'MySQL(live): seeded explicit keys need no resync — AUTO_INCREMENT moves past them (requires BOOTGLY_MYSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port) {
      $Database = new SQL([
         'driver' => 'mysql',
         'host' => $host,
         'port' => $port,
         'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'bootgly',
         'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'root',
         'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
         'timeout' => 5.0,
         // ! Live servers usually run self-signed certificates — TLS is opt-in.
         //   Plaintext caching_sha2 full auth needs the pinned server public
         //   key (docker: /var/lib/mysql/public_key.pem) in DB_SERVER_PUBLIC_KEY.
         'secure' => [
            'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
            'key' => getenv('DB_SERVER_PUBLIC_KEY') !== false ? (string) getenv('DB_SERVER_PUBLIC_KEY') : '',
         ],
         'pool' => ['min' => 0, 'max' => 1],
      ]);
      $Schema = $Database->structure();
      $suffix = uniqid();
      $table = "i15_{$suffix}_polls";
      $path = BOOTGLY_STORAGE_DIR . "tests/seeders-resync-mysql-{$suffix}";

      try {
         is_dir($path) || mkdir($path, 0o775, true);

         $Database->await($Database->query($Schema->create($table, function (Blueprint $Table): void {
            $Table->add('id', Types::BigInteger)->generate()->constrain(Keys::Primary);
            $Table->add('question', Types::Text);
         })));
         file_put_contents("{$path}/polls.php", <<<PHP
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL \$Database, Seed \$Seed) => \$Database->table(new Identifier('{$table}'))
      ->insert()
      ->set(new Identifier('id'), 1, 2)
      ->set(new Identifier('question'), 'A?', 'B?')
      ->upsert(new Identifier('id'))
);
PHP);

         $Runner = new Runner($Database, $path, "{$path}.lock");
         $preview = $Runner->preview('polls');

         yield assert(
            assertion: count($preview['polls'] ?? []) === 1,
            description: 'The MySQL preview holds the INSERT alone, found: ' . json_encode($preview)
         );

         try {
            $Runner->run('polls');
            $Operation = $Database->query(
               $Database->table(new Identifier($table))->insert()->set(new Identifier('question'), 'app')
            );
            $Database->await($Operation);
            $next = $Operation->Result?->inserted;
         }
         catch (Throwable $Throwable) {
            $next = $Throwable->getMessage();
         }

         yield assert(
            assertion: (int) $next === 3,
            description: 'The first generated id after seeding ids 1,2 is 3, found: ' . json_encode($next)
         );
      }
      finally {
         try { $Database->await($Database->query($Schema->drop($table))); }
         catch (Throwable) {}

         foreach (glob("{$path}/*.php") ?: [] as $file) {
            unlink($file);
         }
         if (is_dir($path)) {
            rmdir($path);
         }
         foreach (glob("{$path}.lock*") ?: [] as $file) {
            unlink($file);
         }
      }
   }
);
