<?php

use function fclose;
use function fsockopen;
use function getenv;
use function is_resource;
use function uniqid;

use Bootgly\ACI\Tests\Suite\Test\Specification;
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


return new Specification(
   description: 'MySQL(live): auth, text + prepared round trips, transactions and insert ids (requires BOOTGLY_MYSQL_E2E=1)',
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

      $table = 'bootgly_e2e_' . uniqid();

      $Database->await($Database->query("DROP TABLE IF EXISTS {$table}"));
      $Create = $Database->await($Database->query(
         "CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, score DOUBLE NULL)"
      ));

      yield assert(
         assertion: $Create->error === null,
         description: 'Live connection authenticates and executes DDL'
      );

      // # Text protocol + insert id
      $Insert = $Database->await($Database->query("INSERT INTO {$table} (name, score) VALUES ('ada', 1.5), ('grace', 2.5)"));

      yield assert(
         assertion: $Insert->Result?->affected === 2 && $Insert->Result->inserted >= 1,
         description: 'Text INSERT reports affected rows and the first generated id'
      );

      // # Prepared (binary) round trip
      $Row = $Database->await($Database->query(
         "SELECT id, name, score FROM {$table} WHERE name = ? AND score > ?",
         ['grace', 2.0]
      ));

      yield assert(
         assertion: $Row->error === null
            && $Row->Result?->count === 1
            && $Row->Result->row['name'] === 'grace'
            && $Row->Result->row['score'] === 2.5,
         description: 'Prepared statements bind and hydrate typed binary rows'
      );

      // @ Statement reuse
      $Reused = $Database->await($Database->query(
         "SELECT id, name, score FROM {$table} WHERE name = ? AND score > ?",
         ['ada', 1.0]
      ));

      yield assert(
         assertion: $Reused->Result?->row['name'] === 'ada',
         description: 'The statement cache reuses the prepared statement'
      );

      // # Transaction — rollback then commit
      $Transaction = $Database->begin();

      if ($Transaction->Operation !== null) {
         $Database->await($Transaction->Operation);
      }

      $Database->await($Transaction->query("INSERT INTO {$table} (name) VALUES (?)", ['discarded']));
      $Database->await($Transaction->rollback());

      $Transaction = $Database->begin();

      if ($Transaction->Operation !== null) {
         $Database->await($Transaction->Operation);
      }

      $Database->await($Transaction->query("INSERT INTO {$table} (name) VALUES (?)", ['kept']));
      $Database->await($Transaction->commit());

      $Count = $Database->await($Database->query("SELECT count(*) AS total FROM {$table}"));

      yield assert(
         assertion: $Count->Result?->cell === 3,
         description: 'ROLLBACK discards and COMMIT keeps transactional writes'
      );

      $Database->await($Database->query("DROP TABLE IF EXISTS {$table}"));
   }
);
