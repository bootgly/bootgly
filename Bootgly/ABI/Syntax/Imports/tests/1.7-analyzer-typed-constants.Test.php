<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: a typed class constant declaration is not a constant usage',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = Temporaries::reserve('syntax-imports-typed');

      try {
         $Analyzer = new Analyzer();

         $write = function (string $body) use ($dir): string {
            $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            return $file;
         };
         $flag = function (string $body) use ($Analyzer, $write): array {
            $Result = $Analyzer->analyze($write($body));

            $missing = [];
            foreach ($Result->issues as $Issue) {
               if ($Issue->type === 'missing_import' && $Issue->kind === 'const') {
                  $missing[] = $Issue->symbol;
               }
            }

            return $missing;
         };

         // ! The scanner must be alive: a bare read of a builtin constant IS a
         //   missing import — without this control every "not flagged" assertion
         //   below would pass vacuously
         yield assert(
            assertion: $flag('class Probe { public function run (): string { return PHP_EOL; } }') === ['PHP_EOL'],
            description: 'A bare builtin constant read must be reported missing'
         );

         // @@ Declarations named like a builtin constant — the name follows the
         //    `const` keyword directly or through a type, never a usage
         $declarations = [
            'untyped'         => 'class Probe { const PHP_EOL = "x"; }',
            'typed'           => 'class Probe { private const string PHP_EOL = "x"; }',
            'union typed'     => 'class Probe { public const int|string PHP_EOL = 1; }',
            'nullable typed'  => 'class Probe { public const null|string PHP_EOL = null; }',
            'array typed'     => 'class Probe { public const array PHP_EOL = []; }',
            'class typed'     => 'class Probe { public const \Demo\Probe|null PHP_EOL = null; }',
            'DNF typed'       => 'class Probe { public const (\Demo\A&\Demo\B)|null PHP_EOL = null; }',
         ];

         foreach ($declarations as $label => $body) {
            yield assert(
               assertion: $flag($body) === [],
               description: "A {$label} class constant declaration must not be reported missing, got: "
                  . json_encode($flag($body))
            );
         }

            // @ A declaration LIST declares every name in it, typed or not
         yield assert(
            assertion: $flag('class Probe { const PHP_EOL = 1, PHP_INT_MAX = 2; public const int PHP_INT_MIN = 3, PHP_FLOAT_DIG = 4; }') === [],
            description: 'Every constant of a declaration list is a declaration, not a read'
         );

         // @ The declaration skip must not hide a genuine read in the same body
         yield assert(
            assertion: $flag(
               'class Probe { private const string PHP_EOL = "x"; '
               . 'public function run (): string { return PHP_EOL; } }'
            ) === ['PHP_EOL'],
            description: 'A bare read next to a typed declaration is still reported missing'
         );

      }
      finally {
         foreach (glob($dir . '/*.php') ?: [] as $path) {
            @unlink($path);
         }
         @rmdir($dir);
      }
   }
);
