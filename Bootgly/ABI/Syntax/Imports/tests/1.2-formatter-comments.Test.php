<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ABI\Syntax\Imports\Formatter;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Formatter: a comment inside the import block is never rewritten away',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = sys_get_temp_dir() . '/bootgly-imports-comments-' . getmypid();
      @mkdir($dir, 0775, true);

      $Analyzer = new Analyzer();
      $Formatter = new Formatter();

      $fix = function (string $body) use ($Analyzer, $Formatter, $dir): array {
         $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
         $file = $dir . '/probe-' . md5($source) . '.php';
         file_put_contents($file, $source);

         $Result = $Analyzer->analyze($file);
         $types = [];
         foreach ($Result->issues as $Issue) {
            $types[] = $Issue->type;
         }

         return [
            'source'    => $source,
            'corrected' => $Formatter->format($Result),
            'types'     => $types,
         ];
      };

      $probe = "class Probe { public function run (): void { new File('/a'); new Dir('/b'); } }";

      // @ format() rewrites the whole block to reorder it, so a comment between two
      //   imports used to be replaced along with them and lost for good (SYN-2)
      $fixed = $fix(
         "use Bootgly\\ABI\\IO\\FS\\File;\n"
         . "// @ TODO: drop this import when Foo is migrated\n"
         . "use Bootgly\\ABI\\IO\\FS\\Dir;\n\n{$probe}"
      );
      yield assert(
         assertion: str_contains($fixed['corrected'], '// @ TODO: drop this import when Foo is migrated'),
         description: 'A comment between imports must survive, got: '
            . json_encode($fixed['corrected'])
      );
      yield assert(
         assertion: in_array('comment_in_imports', $fixed['types'], true),
         description: 'The un-rewritable block must be reported, got: '
            . json_encode($fixed['types'])
      );
      yield assert(
         assertion: $fixed['corrected'] === $fixed['source'],
         description: 'A block that cannot be rewritten must be left byte-identical, got: '
            . json_encode($fixed['corrected'])
      );

      // @ A docblock is the same case
      $fixed = $fix(
         "use Bootgly\\ABI\\IO\\FS\\File;\n\n"
         . "/**\n * Kept only until the storage migration lands.\n */\n"
         . "use Bootgly\\ABI\\IO\\FS\\Dir;\n\n{$probe}"
      );
      yield assert(
         assertion: str_contains($fixed['corrected'], 'Kept only until the storage migration lands.'),
         description: 'A docblock between imports must survive, got: '
            . json_encode($fixed['corrected'])
      );

      // @ A trailing comment shares the last import's line — it used to be orphaned
      //   below the regenerated block
      $fixed = $fix(
         "use Bootgly\\ABI\\IO\\FS\\File;\n"
         . "use Bootgly\\ABI\\IO\\FS\\Dir; // @ only for the /b probe\n\n{$probe}"
      );
      yield assert(
         assertion: str_contains($fixed['corrected'], 'use Bootgly\ABI\IO\FS\Dir; // @ only for the /b probe'),
         description: 'A trailing comment must stay on its import line, got: '
            . json_encode($fixed['corrected'])
      );

      // @ Comments outside the block are not the block's problem — it still gets fixed
      $fixed = $fix(
         "// @ Filesystem primitives\n"
         . "use Bootgly\\ABI\\IO\\FS\\File;\n"
         . "use Bootgly\\ABI\\IO\\FS\\Dir;\n\n"
         . "// @ The probe itself\n{$probe}"
      );
      yield assert(
         assertion: str_contains($fixed['corrected'], "use Bootgly\ABI\IO\FS\Dir;\nuse Bootgly\ABI\IO\FS\File;")
            && str_contains($fixed['corrected'], '// @ Filesystem primitives')
            && str_contains($fixed['corrected'], '// @ The probe itself'),
         description: 'Comments around the block must not block the fix, got: '
            . json_encode($fixed['corrected'])
      );
      yield assert(
         assertion: in_array('comment_in_imports', $fixed['types'], true) === false,
         description: 'Comments outside the block must not be reported, got: '
            . json_encode($fixed['types'])
      );

      // ! Teardown
      foreach (glob($dir . '/probe-*.php') ?: [] as $file) {
         @unlink($file);
      }
      @rmdir($dir);
   }
);
