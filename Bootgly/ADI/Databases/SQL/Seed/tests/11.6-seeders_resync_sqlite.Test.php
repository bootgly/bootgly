<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\ResyncSQLite;


use const BOOTGLY_STORAGE_DIR;
use function assert;
use function count;
use function extension_loaded;
use function file_put_contents;
use function glob;
use function is_dir;
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


return new Test(
   description: 'SQLite(live): seeded explicit keys need no resync — AUTOINCREMENT moves past them',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'timeout' => 5.0]);
      $Schema = $Database->structure();
      $path = BOOTGLY_STORAGE_DIR . 'tests/seeders-resync-sqlite-' . uniqid();

      try {
         is_dir($path) || mkdir($path, 0o775, true);

         $Database->await($Database->query($Schema->create('polls', function (Blueprint $Table): void {
            $Table->add('id', Types::BigInteger)->generate()->constrain(Keys::Primary);
            $Table->add('question', Types::Text);
         })));
         file_put_contents("{$path}/polls.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => $Database->table(new Identifier('polls'))
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
            description: 'The SQLite preview holds the INSERT alone, found: ' . json_encode($preview)
         );

         try {
            $Runner->run('polls');
            $Operation = $Database->query(
               $Database->table(new Identifier('polls'))->insert()->set(new Identifier('question'), 'app')
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
