<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\SQLiteTransactions;


use const BOOTGLY_STORAGE_DIR;
use function assert;
use function extension_loaded;
use function file_exists;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Runner;


return new Specification(
   description: 'Database: SQLite migrations run end-to-end inside transactional DDL',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $path = BOOTGLY_STORAGE_DIR . 'tests/schema-sqlite-' . uniqid() . '/';
      if (is_dir($path) === false) {
         mkdir($path, 0775, true);
      }
      $lock = $path . 'migrate.lock';

      try {
         $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $Schema = $Database->structure();

         yield assert(
            assertion: $Schema->Dialect->transactions === true,
            description: 'SQLite schema dialect enables transactional DDL'
         );

         $file = $path . '20260707000000_create_accounts.php';
         file_put_contents($file, <<<'PHP'
         <?php

         use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
         use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
         use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
         use Bootgly\ADI\Databases\SQL\Schema\Migrating;
         use Bootgly\ADI\Databases\SQL\Schema\Migration;

         return new Migration(
            Up: fn (Migrating $Schema) => $Schema->create('accounts', function (Blueprint $Table): void {
               $Table->add('id', Types::Integer)->constrain(Keys::Primary);
               $Table->add('name', Types::String);
            }),
            Down: fn (Migrating $Schema) => $Schema->drop('accounts')
         );

         PHP);

         $Runner = new Runner($Database, $path, $lock);
         $applied = $Runner->up();

         yield assert(
            assertion: $applied === ['20260707000000_create_accounts'],
            description: 'Runner applies the pending SQLite migration'
         );

         $Insert = $Database->query("INSERT INTO accounts (id, name) VALUES (1, 'bootgly')");

         yield assert(
            assertion: $Insert->error === null && $Insert->Result?->affected === 1,
            description: 'The migrated table accepts writes'
         );

         $History = $Database->query('SELECT count(*) AS total FROM _bootgly_migrations');

         yield assert(
            assertion: $History->Result?->cell === 1,
            description: 'The migration history records the applied batch'
         );

         $reverted = $Runner->down(1);
         $Missing = $Database->query('SELECT count(*) AS total FROM accounts');
         $Empty = $Database->query('SELECT count(*) AS total FROM _bootgly_migrations');

         yield assert(
            assertion: $reverted === ['20260707000000_create_accounts']
               && $Missing->error !== null
               && $Empty->Result?->cell === 0,
            description: 'Runner reverts the migration and clears the history'
         );
      }
      finally {
         foreach (glob($path . '*') ?: [] as $artifact) {
            if (file_exists($artifact)) {
               unlink($artifact);
            }
         }
         if (is_dir($path)) {
            rmdir($path);
         }
      }
   }
);
