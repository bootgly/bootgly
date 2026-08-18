<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ABI\Syntax\Imports\Formatter;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Analyzer: every item of a grouped `use` keeps its namespace prefix',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = sys_get_temp_dir() . '/bootgly-imports-' . getmypid();
      @mkdir($dir, 0775, true);

      $Analyzer = new Analyzer();

      $analyze = function (string $body) use ($Analyzer, $dir): array {
         $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
         $file = $dir . '/probe-' . md5($source) . '.php';
         file_put_contents($file, $source);

         $Result = $Analyzer->analyze($file);
         $symbols = [];
         foreach ($Result->imports as $import) {
            $symbols[] = $import['symbol'];
         }

         return ['Result' => $Result, 'symbols' => $symbols];
      };

      // @ The last item of a group is flushed at the `;`, not at a comma — it used to
      //   be recorded under its bare short name, and `--fix` then wrote `use Dir;` (SYN-1)
      $analyzed = $analyze(
         "use Bootgly\\ABI\\IO\\FS\\{File, Dir};\n\n"
         . "class Probe { public function run (): void { new File('/etc/hostname'); new Dir('/etc'); } }"
      );
      yield assert(
         assertion: $analyzed['symbols'] === ['Bootgly\ABI\IO\FS\File', 'Bootgly\ABI\IO\FS\Dir'],
         description: 'Grouped items must keep the prefix, got: ' . json_encode($analyzed['symbols'])
      );

      // @ End to end: what `lint imports --fix` would write back
      $formatted = new Formatter()->format($analyzed['Result']);
      yield assert(
         assertion: str_contains($formatted, 'use Bootgly\ABI\IO\FS\Dir;')
            && str_contains($formatted, "\nuse Dir;") === false,
         description: 'The rewritten block must import Dir from its namespace, got: '
            . json_encode($formatted)
      );

      // @ A group of one has no comma at all, so the `;` branch is its only flush
      $analyzed = $analyze(
         "use Bootgly\\ABI\\IO\\FS\\{File};\n\n"
         . "class Probe { public function run (): void { new File('/etc/hostname'); } }"
      );
      yield assert(
         assertion: $analyzed['symbols'] === ['Bootgly\ABI\IO\FS\File'],
         description: 'A single-item group must keep its prefix, got: '
            . json_encode($analyzed['symbols'])
      );

      // @ An aliased last item keeps both the prefix and the alias
      $analyzed = $analyze(
         "use Bootgly\\ABI\\IO\\FS\\{File, Dir as Folder};\n\n"
         . "class Probe { public function run (): void { new File('/etc/hostname'); new Folder('/etc'); } }"
      );
      yield assert(
         assertion: $analyzed['symbols'] === ['Bootgly\ABI\IO\FS\File', 'Bootgly\ABI\IO\FS\Dir as Folder'],
         description: 'An aliased last item must keep prefix and alias, got: '
            . json_encode($analyzed['symbols'])
      );

      // @ `use function` groups share the same flush
      $analyzed = $analyze(
         "use function Bootgly\\Helpers\\{first, second};\n\n"
         . 'class Probe { public function run (): void { first(); second(); } }'
      );
      yield assert(
         assertion: $analyzed['symbols'] === ['Bootgly\Helpers\first', 'Bootgly\Helpers\second'],
         description: 'Grouped functions must keep the prefix, got: '
            . json_encode($analyzed['symbols'])
      );

      // @ A trailing comma must not duplicate or drop the last item
      $analyzed = $analyze(
         "use Bootgly\\ABI\\IO\\FS\\{File, Dir,};\n\n"
         . "class Probe { public function run (): void { new File('/etc/hostname'); new Dir('/etc'); } }"
      );
      yield assert(
         assertion: $analyzed['symbols'] === ['Bootgly\ABI\IO\FS\File', 'Bootgly\ABI\IO\FS\Dir'],
         description: 'A trailing comma must leave two items, got: '
            . json_encode($analyzed['symbols'])
      );

      // @ Ungrouped imports are read exactly as written
      $analyzed = $analyze(
         "use function count;\nuse Bootgly\\ABI\\IO\\FS\\Dir;\nuse Bootgly\\ABI\\IO\\FS\\File;\n\n"
         . "class Probe { public function run (): void { new File('/etc/hostname'); new Dir('/etc'); count([]); } }"
      );
      yield assert(
         assertion: $analyzed['symbols'] === ['count', 'Bootgly\ABI\IO\FS\Dir', 'Bootgly\ABI\IO\FS\File'],
         description: 'Ungrouped imports must be unchanged, got: '
            . json_encode($analyzed['symbols'])
      );

      // ! Teardown
      foreach (glob($dir . '/probe-*.php') ?: [] as $probe) {
         @unlink($probe);
      }
      @rmdir($dir);
   }
);
