<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ABI\Syntax\Imports\Formatter;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Analyzer: an import is unused only when nothing in the body names it',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = sys_get_temp_dir() . '/bootgly-imports-unused-' . getmypid();
      @mkdir($dir, 0775, true);

      $Analyzer = new Analyzer();
      $Formatter = new Formatter();

      $write = function (string $body) use ($dir): string {
         $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
         $file = $dir . '/probe-' . md5($source) . '.php';
         file_put_contents($file, $source);

         return $file;
      };
      $flag = function (string $body) use ($Analyzer, $write): array {
         $Result = $Analyzer->analyze($write($body));

         $unused = [];
         foreach ($Result->issues as $Issue) {
            if ($Issue->type === 'unused_import') {
               $unused[] = $Issue->symbol;
            }
         }
         sort($unused);

         return $unused;
      };

      // ! Every probe imports Dir AND File, uses only File, and expects exactly
      //   Dir back. Dir is the control: without it a probe that failed to parse
      //   would report nothing and every "not flagged" assertion would pass
      //   vacuously.
      $pair = 'use Bootgly\ABI\IO\FS\Dir;' . "\n" . 'use Bootgly\ABI\IO\FS\File;' . "\n\n";
      $control = ['Bootgly\ABI\IO\FS\Dir'];

      // @@ Positions the missing-import scanner never records — each of these
      //    would be deleted by inverting its symbol table
      $positions = [
         'a parameter type'  => 'class Probe { public function run (File $f): void {} }',
         'a return type'     => 'class Probe { public function run (): File { throw new Exception; } }',
         'a property type'   => 'class Probe { public File $handle; }',
         'a catch clause'    => 'class Probe { public function run (): void { try { $x = 1; } catch (File $e) { } } }',
         'a docblock'        => "class Probe {\n/**\n * @param File \$f\n */\npublic function run (\$f): void {} }",
         'an attribute'      => 'class Probe { #[File(1)] public int $n = 0; }',
         'a trait use'       => 'class Probe { use File; }',
         'a qualified name'  => 'class Probe extends File\Sub {}',
         'a class constant'  => 'class Probe { public function run (): string { return File::class; } }',
         'an instantiation'  => 'class Probe { public function run (): void { new File(); } }',
      ];

      foreach ($positions as $label => $body) {
         yield assert(
            assertion: $flag($pair . $body) === $control,
            description: "An import used as {$label} must not be reported unused, got: "
               . json_encode($flag($pair . $body))
         );
      }

      // @@ The true-positive direction, per kind
      yield assert(
         assertion: $flag(
            'use function array_map;' . "\n" . 'use function array_keys;' . "\n\n"
            . 'class Probe { public function run (array $a): array { return array_keys($a); } }'
         ) === ['array_map'],
         description: 'An imported function nothing calls must be reported unused'
      );
      yield assert(
         assertion: $flag(
            'use const PHP_EOL;' . "\n" . 'use const PHP_INT_MAX;' . "\n\n"
            . 'class Probe { public function run (): int { return PHP_INT_MAX; } }'
         ) === ['PHP_EOL'],
         description: 'An imported constant nothing reads must be reported unused'
      );

      // @@ Aliases: the alias is the name the body can use, so it is the name
      //    that decides — in both directions
      yield assert(
         assertion: $flag(
            'use Bootgly\ABI\IO\FS\Dir as Folder;' . "\n\n"
            . 'class Probe { public function run (Folder $f): void {} }'
         ) === [],
         description: 'An import referenced through its alias must not be reported unused'
      );
      yield assert(
         assertion: $flag(
            'use Bootgly\ABI\IO\FS\Dir as Folder;' . "\n\n"
            . 'class Probe { public function run (Dir $d): void {} }'
         ) === ['Bootgly\ABI\IO\FS\Dir as Folder'],
         description: 'An alias nothing names is unused even when the original name appears'
      );

      // @@ PHP resolves constants case-sensitively, unlike classes and functions
      yield assert(
         assertion: $flag(
            'use const PHP_EOL;' . "\n\n"
            . 'class Probe { public function run (): string { return php_eol(); } }'
         ) === ['PHP_EOL'],
         description: 'A constant import is not satisfied by a different case'
      );
      yield assert(
         assertion: $flag(
            'use Bootgly\ABI\IO\FS\Dir;' . "\n\n"
            . 'class Probe { public function run (): void { new DIR(); } }'
         ) === [],
         description: 'A class import IS satisfied by a different case'
      );

      // @@ The fix: the unused statement goes, everything else stays
      $file = $write(
         $pair . 'class Probe { public function run (File $f): void {} }'
      );
      $corrected = $Formatter->format($Analyzer->analyze($file));

      yield assert(
         assertion: str_contains($corrected, 'use Bootgly\ABI\IO\FS\Dir;') === false,
         description: 'The unused import must be gone after the fix, got: ' . json_encode($corrected)
      );
      yield assert(
         assertion: str_contains($corrected, 'use Bootgly\ABI\IO\FS\File;'),
         description: 'The used import must survive the fix, got: ' . json_encode($corrected)
      );

      // @@ Every import unused: the block empties and the file takes the shape
      //    of one that never had imports — two blank lines, then the code
      $file = $write($pair . 'class Probe { public function run (): void {} }');
      $corrected = $Formatter->format($Analyzer->analyze($file));

      yield assert(
         assertion: str_contains($corrected, 'use Bootgly') === false,
         description: 'An all-unused block must empty completely, got: ' . json_encode($corrected)
      );
      yield assert(
         assertion: str_contains($corrected, "namespace Demo;\n\n\nclass Probe"),
         description: 'An emptied block must leave two blank lines, got: ' . json_encode($corrected)
      );

      // ! The fix must always still be PHP
      $probe = $dir . '/corrected.php';
      file_put_contents($probe, $corrected);
      exec('php -l ' . escapeshellarg($probe) . ' 2>&1', $output, $status);
      yield assert(
         assertion: $status === 0,
         description: 'An emptied block must leave valid PHP, got: ' . implode(' ', $output)
      );

      // ! Teardown
      foreach (glob($dir . '/*.php') ?: [] as $path) {
         @unlink($path);
      }
      @rmdir($dir);
   }
);
