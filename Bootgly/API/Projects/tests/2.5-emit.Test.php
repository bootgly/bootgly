<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::write() emits every registry value as an escaped PHP literal',
   test: function () {
      // ! Scratch registry seeded with a LEGACY quoted key — hand-registered
      //   before the naming alphabet existed, it must survive a re-emission
      //   untouched: vet() gates only the path being registered, never the
      //   entries loaded back from the file.
      $file = sys_get_temp_dir() . '/bootgly-test-emit-' . getmypid() . '.php';
      @unlink($file);
      @unlink("{$file}.tmp");
      file_put_contents(
         $file,
         "<?php\nreturn [\n   'Legacy\\'s' => ['interfaces' => ['CLI']],\n];\n"
      );

      // @ Re-emit through an ordinary registration
      yield assert(
         assertion: Projects::register('App/API', ['interfaces' => ['CLI', 'WPI']], $file) === true,
         description: 'registering beside a legacy quoted key succeeds'
      );

      $registry = include $file;
      yield assert(
         assertion: is_array($registry)
            && array_key_exists("Legacy's", $registry)
            && ($registry["Legacy's"]['interfaces'] ?? null) === ['CLI'],
         description: 'the legacy quoted key survives the re-emission as the same string'
      );
      yield assert(
         assertion: ($registry['App/API']['interfaces'] ?? null) === ['CLI', 'WPI'],
         description: 'the new entry round-trips with its interfaces list'
      );

      // @ The emission itself is escaped, not merely parseable
      $content = (string) file_get_contents($file);
      yield assert(
         assertion: str_contains($content, "\\'") === true,
         description: 'the quoted key is emitted with an escaped literal'
      );

      // @ Column alignment survives escaping — the width comes from the
      //   emitted literal, so every entry keeps its `=>` at one column
      $columns = [];
      foreach ((array) file($file) as $line) {
         // ? Entry lines only — the canonical header also carries a `=>`
         if (preg_match("#^   '#", (string) $line) !== 1) {
            continue;
         }
         $position = strpos((string) $line, ' => ');
         if ($position !== false) {
            $columns[] = $position;
         }
      }
      yield assert(
         assertion: $columns !== [] && count(array_unique($columns)) === 1,
         description: 'every emitted entry aligns its arrow at the same column, found: '
            . json_encode($columns)
      );

      // @ Tmp hygiene — the atomic write never strands its temp file
      yield assert(
         assertion: is_file("{$file}.tmp") === false,
         description: 'no .tmp file is left beside the registry'
      );

      @unlink($file);
      @unlink("{$file}.tmp");
   }
);
