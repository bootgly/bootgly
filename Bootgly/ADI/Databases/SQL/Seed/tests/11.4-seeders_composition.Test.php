<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\Composition;


use const BOOTGLY_STORAGE_DIR;
use function array_column;
use function array_search;
use function assert;
use function extension_loaded;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function uniqid;
use function unlink;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Seed\Runner;
use Bootgly\ADI\Databases\SQL\Seed\Tests\Transactions\RecordingSQL;


function clean (string $path): void
{
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


return new Test(
   description: 'Database: SQL seed runner composes a seeder before it opens the transaction',
   test: function () {
      // ! A seeder that reads the database before deciding what to write — the idempotent
      //   shape the seeders guide recommends. It can only ask the pool, so composing it
      //   inside the transaction would ask a pool whose connection is already locked.
      $reading = <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: function (SQL $Database, Seed $Seed): array {
      $Operation = $Database->query('SELECT count(*) AS n FROM users');
      $Database->await($Operation);

      if ((int) ($Operation->Result?->cell ?? 0) > 0) {
         return [];
      }

      return [
         $Database->table(new Identifier('users'))
            ->insert()
            ->set(new Identifier('email'), 'ann@example.test'),
      ];
   }
);
PHP;

      // # The order the queries reach the wire

      $path = BOOTGLY_STORAGE_DIR . 'tests/seeders-composition-' . uniqid();
      $Database = new RecordingSQL;
      $Runner = new Runner($Database, $path, "{$path}.lock");

      try {
         $Runner->create('Reading');
         file_put_contents("{$path}/reading.php", $reading);

         $Runner->run('reading');

         $SQLs = array_column($Database->queries, 'sql');
         $read = array_search('SELECT count(*) AS n FROM users', $SQLs, true);
         $begin = array_search('BEGIN', $SQLs, true);
         $commit = array_search('COMMIT', $SQLs, true);

         yield assert(
            assertion: $read !== false && $begin !== false && $read < $begin,
            description: 'The seeder reads the database before the transaction opens, found: '
               . json_encode([$read, $begin])
         );

         // ? Composing early must not move the WRITES out of the transaction — they are
         //   what the runner is being atomic about.
         $insert = -1;

         foreach ($SQLs as $index => $SQL) {
            if (str_contains($SQL, 'INSERT INTO "users"')) {
               $insert = $index;
            }
         }

         yield assert(
            assertion: $commit !== false && $insert > $begin && $insert < $commit,
            description: 'The queries the seeder returned still run inside the transaction, found: '
               . json_encode([$begin, $insert, $commit])
         );
      }
      finally {
         clean($path);
      }

      // # The symptom, on a pool that holds one connection

      if (extension_loaded('sqlite3') === false) {
         return;
      }

      $path = BOOTGLY_STORAGE_DIR . 'tests/seeders-composition-live-' . uniqid();
      $Live = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'timeout' => 5.0]);

      try {
         is_dir($path) || mkdir($path, 0o775, true);
         file_put_contents("{$path}/reading.php", $reading);

         $Live->await($Live->query('CREATE TABLE users (email TEXT)'));

         $count = static function () use ($Live): mixed {
            $Operation = $Live->query('SELECT count(*) AS n FROM users');
            $Live->await($Operation);

            return $Operation->Result?->cell;
         };
         $seed = static function () use ($Live, $path): null|string {
            try { (new Runner($Live, $path, "{$path}.lock"))->run('reading'); }
            catch (Throwable $Throwable) { return $Throwable->getMessage(); }

            return null;
         };

         $first = $seed();
         $seeded = $count();

         yield assert(
            assertion: $first === null && $seeded === 1,
            description: 'A seeder that reads applies on a pool of one connection, found: '
               . json_encode([$first, $seeded])
         );

         // ? Reading is what makes a seeder idempotent — the second pass must see the row
         //   it wrote and write nothing.
         $second = $seed();

         yield assert(
            assertion: $second === null && $count() === 1,
            description: 'The second pass reads its own work and seeds nothing more, found: '
               . json_encode([$second, $count()])
         );
      }
      finally {
         clean($path);
      }
   }
);
