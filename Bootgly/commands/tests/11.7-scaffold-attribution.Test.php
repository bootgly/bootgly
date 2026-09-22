<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use function assert;
use function file_get_contents;
use function json_encode;
use function str_contains;
use function str_ends_with;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ADI\Databases\SQL\Schema\Migrations;
use Bootgly\ADI\Databases\SQL\Seed\Seeders;


/**
 * What the scaffolders write into a user's project: never the framework's
 * license block (the code belongs to the user), and a `.gitignore` that keeps
 * the config `.env` files — the secrets — out of the project's repository.
 */

return new Test(
   description: 'Scaffolds never stamp the Bootgly license block, and the project .gitignore keeps config .env files out',
   test: function () {
      $Stamp = static fn (string $content): bool => str_contains($content, 'Bootgly PHP Framework')
         || str_contains($content, 'Licensed under MIT');

      // @@ Every PHP stub a project scaffold copies
      $stubs = BOOTGLY_ROOT_BASE . '/Bootgly/commands/stubs';
      $stamped = [];
      $count = 0;
      foreach (['CLI', 'WPI', 'project'] as $folder) {
         $Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$stubs}/{$folder}", RecursiveDirectoryIterator::SKIP_DOTS));
         /** @var \SplFileInfo $File */
         foreach ($Files as $File) {
            if (str_ends_with($File->getFilename(), '.php') === false) {
               continue;
            }
            $count++;
            if ($Stamp((string) file_get_contents($File->getPathname())) === true) {
               $stamped[] = $File->getPathname();
            }
         }
      }
      yield assert(
         assertion: $count > 0 && $stamped === [],
         description: "no project stub carries the framework license block ({$count} scanned), stamped: " . json_encode($stamped)
      );

      // @ What `migrate create` and `seed create` write
      $dir = Temporaries::reserve('scaffold-attribution');
      $migration = (string) file_get_contents((new Migrations("{$dir}/migrations"))->create('create_items'));
      $seeder = (string) file_get_contents((new Seeders("{$dir}/seeders"))->create('items'));
      yield assert(
         assertion: $Stamp($migration) === false && str_contains($migration, 'return new Migration('),
         description: '`migrate create` writes a migration without the framework license block'
      );
      yield assert(
         assertion: $Stamp($seeder) === false && str_contains($seeder, 'return new Seeder('),
         description: '`seed create` writes a seeder without the framework license block'
      );

      // @ The project's own ignore file keeps the config environment files out
      $ignore = (string) file_get_contents("{$stubs}/project/.gitignore");
      yield assert(
         assertion: str_contains($ignore, "configs/**/.env\n") && str_contains($ignore, "configs/**/.env.*\n")
            && str_contains($ignore, "/vendor/\n"),
         description: 'the scaffolded .gitignore ignores configs/**/.env and configs/**/.env.* (plus /vendor/), got: ' . json_encode($ignore)
      );
   }
);
