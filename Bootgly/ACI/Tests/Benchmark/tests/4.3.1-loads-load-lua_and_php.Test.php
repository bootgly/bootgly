<?php


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Configs\Loads;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should load .lua and .php loads of a directory together, in file order',
   test: new Assertions(Case: function (): Generator
   {
      // ! A throwaway loads directory: one Lua load, two PHP loads (one bare)
      //   and a stray file no extension claims
      $directory = sys_get_temp_dir() . '/bootgly-loads-' . bin2hex(random_bytes(6));
      mkdir($directory, 0700, true);

      file_put_contents("$directory/2-php.php", <<<'LOAD'
      <?php
      // @label: PHP load
      // @group: php
      // @opponents: bootgly,swoole
      return static fn () => null;
      LOAD);
      file_put_contents("$directory/1-lua.lua", <<<'LOAD'
      -- @label: Lua load
      -- @group: lua
      -- @opponents: bootgly
      wrk.method = "GET"
      LOAD);
      file_put_contents("$directory/3-bare.php", "<?php\nreturn null;\n");
      file_put_contents("$directory/4-ignored.txt", "-- @label: ignored\n");

      try {
         $Loads = Loads::load($directory);

         yield new Assertion(
            description: 'Both extensions are loaded, the stray file is not',
            fallback: 'Loads::load() did not read exactly the .lua and .php files!'
         )
            ->expect(count($Loads), Op::Identical, 3)
            ->assert();

         yield new Assertion(
            description: 'Every entry is a Load',
            fallback: 'Loads::load() returned a non-Load entry!'
         )
            ->expect(
               array_map(static fn ($Load): bool => $Load instanceof Load, $Loads),
               Op::Identical,
               [true, true, true]
            )
            ->assert();

         yield new Assertion(
            description: 'Files are ordered across extensions, not per extension',
            fallback: 'Loads::load() did not sort .lua and .php files together!'
         )
            ->expect(
               array_map(static fn (Load $Load): string => basename($Load->file), $Loads),
               Op::Identical,
               ['1-lua.lua', '2-php.php', '3-bare.php']
            )
            ->assert();

         yield new Assertion(
            description: 'Lua metadata is parsed from `--` comments',
            fallback: 'Lua load metadata was not parsed!'
         )
            ->expect(
               [$Loads[0]->label, $Loads[0]->group, $Loads[0]->opponents],
               Op::Identical,
               ['Lua load', 'lua', 'bootgly']
            )
            ->assert();

         yield new Assertion(
            description: 'PHP metadata is parsed from `//` comments',
            fallback: 'PHP load metadata was not parsed!'
         )
            ->expect(
               [$Loads[1]->label, $Loads[1]->group, $Loads[1]->opponents],
               Op::Identical,
               ['PHP load', 'php', 'bootgly,swoole']
            )
            ->assert();

         yield new Assertion(
            description: 'A load without metadata falls back to its filename and defaults',
            fallback: 'Bare load defaults were not applied!'
         )
            ->expect(
               [$Loads[2]->label, $Loads[2]->group, $Loads[2]->opponents],
               Op::Identical,
               ['3-bare', '', 'all']
            )
            ->assert();

         yield new Assertion(
            description: 'A missing directory yields no loads',
            fallback: 'Loads::load() did not return an empty list for a missing directory!'
         )
            ->expect(Loads::load("$directory/missing"), Op::Identical, [])
            ->assert();
      }
      finally {
         foreach (glob("$directory/*") ?: [] as $file) {
            unlink($file);
         }
         rmdir($directory);
      }
   })
);
