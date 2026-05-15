<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\Migrations;


use const BOOTGLY_WORKING_DIR;
use function assert;
use function basename;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function preg_match;
use function rmdir;
use function str_contains;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Migration;
use Bootgly\ADI\Databases\SQL\Schema\Migrations;


return new Specification(
   description: 'Database: SQL schema migrations discover create and load migration objects',
   test: function () {
      $path = BOOTGLY_WORKING_DIR . 'workdata/tests/schema-migrations-' . uniqid() . '/';
      if (is_dir($path) === false) {
         mkdir($path, 0775, true);
      }

      $Migrations = new Migrations($path);
      $stub = $Migrations->create('Create Users Table');
      $fallback = $Migrations->create('!!!');

      yield assert(
         assertion: file_exists($stub)
            && preg_match('/^\d{14}_create_users_table\.php$/', basename($stub)) === 1,
         description: 'Migrations creates timestamped migration stubs'
      );

      yield assert(
         assertion: file_exists($fallback)
            && preg_match('/^\d{14}_migration\.php$/', basename($fallback)) === 1,
         description: 'Migrations normalizes empty slugs to migration'
      );

      $contents = (string) file_get_contents($stub);

      yield assert(
         assertion: str_contains($contents, 'return new Migration(')
            && str_contains($contents, 'function (Migrating $Schema)')
            && str_contains($contents, 'use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;')
            && str_contains($contents, 'use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\References;')
            && str_contains($contents, 'use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;'),
         description: 'Migration stub imports canonical schema helpers and returns a typed Migration object'
      );

      $file = $path . '20260514000000_create_accounts.php';
      file_put_contents($file, <<<'PHP'
<?php

use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;

return new Migration(
   Up: fn (Migrating $Schema) => $Schema->drop('up_table'),
   Down: fn (Migrating $Schema) => $Schema->drop('down_table')
);

PHP);

      $files = $Migrations->discover();

      yield assert(
         assertion: isset($files['20260514000000_create_accounts'])
            && isset($files[$Migrations->resolve($stub)]),
         description: 'Migrations discovers files keyed by migration name'
      );

      $Migration = $Migrations->load($file);
      $Schema = (new SQL)->structure();
      $Up = $Migration->up($Schema);
      $Down = $Migration->down($Schema);

      yield assert(
         assertion: $Migration instanceof Migration
            && $Migration->name === '20260514000000_create_accounts'
            && $Up->sql === 'DROP TABLE IF EXISTS "up_table"'
            && $Down->sql === 'DROP TABLE IF EXISTS "down_table"',
         description: 'Migrations loads configured Migration objects and derives names'
      );

      foreach (glob($path . '/*.php') ?: [] as $migration) {
         unlink($migration);
      }
      rmdir($path);
   }
);