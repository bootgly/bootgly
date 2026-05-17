<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\Files;


use function assert;
use function basename;
use function file_exists;
use function file_put_contents;
use function glob;
use function is_dir;
use function preg_match;
use function rmdir;
use function str_contains;
use function uniqid;
use function unlink;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Seed\Seeders;


function clean (string $path): void
{
   foreach (glob("{$path}/*.php") ?: [] as $file) {
      unlink($file);
   }

   if (is_dir($path)) {
      rmdir($path);
   }
}


return new Specification(
   description: 'Database: SQL seeders discover create and load seeder objects',
   test: function () {
      $path = BOOTGLY_WORKING_DIR . 'workdata/tests/seeders-files-' . uniqid();
      $Seeders = new Seeders($path);

      $file = $Seeders->create('Demo Users');
      $contents = (string) file_get_contents($file);

      yield assert(
         assertion: basename($file) === 'demo_users.php'
            && file_exists($file)
            && str_contains($contents, 'new Seeder')
            && str_contains($contents, 'function (SQL $Database, Seed $Seed)')
            && str_contains($contents, 'Avoid top-level class/function declarations'),
         description: 'Seeders creates stable non-timestamped seeder stubs'
      );

      $blocked = false;
      try {
         $Seeders->create('Demo Users');
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Seeders does not overwrite an existing seeder file'
      );

      $fallback = $Seeders->create('');

      yield assert(
         assertion: preg_match('/^seeder\.php$/', basename($fallback)) === 1,
         description: 'Seeders normalizes empty slugs to seeder'
      );

      file_put_contents("{$path}/accounts.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => null
);
PHP);

      $discovered = $Seeders->discover();
      $Loaded = $Seeders->load("{$path}/accounts.php");

      yield assert(
         assertion: array_keys($discovered) === ['accounts', 'demo_users', 'seeder']
            && $Loaded->name === 'accounts',
         description: 'Seeders discovers files in order and names loaded seeders from filenames'
      );

      file_put_contents("{$path}/broken.php", '<?php return null;');

      $invalid = false;
      try {
         $Seeders->load("{$path}/broken.php");
      }
      catch (InvalidArgumentException) {
         $invalid = true;
      }

      yield assert(
         assertion: $invalid,
         description: 'Seeders rejects files that do not return Seeder objects'
      );

      clean($path);
   }
);
