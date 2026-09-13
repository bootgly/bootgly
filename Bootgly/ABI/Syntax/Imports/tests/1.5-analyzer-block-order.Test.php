<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: the global block comes first, whole, and each block orders const → function → class on its own',
   test: function () {
      $dir = Temporaries::reserve('syntax-imports-order');

      try {
         $Analyzer = new Analyzer();

         $flag = function (string $imports) use ($Analyzer, $dir): array {
            $source = "<?php\n\nnamespace Demo;\n\n\n{$imports}\n\n\n"
               . "class P { public function run (): string { return strlen(PHP_EOL) . Imports::class . Builtins::class . ArrayIterator::class; } }\n";
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            $found = [];
            foreach ($Analyzer->analyze($file)->issues as $Issue) {
               if ($Issue->type === 'unused_import' || $Issue->type === 'missing_import') {
                  continue;
               }
               $found[] = "{$Issue->type}:{$Issue->symbol}";
            }

            return $found;
         };

         $cases = [
            'globals after a namespaced import' => [
               "use Bootgly\\ABI\\Syntax\\Imports;\nuse const PHP_EOL;\nuse function strlen;",
               ['global_not_first:PHP_EOL', 'global_not_first:strlen'],
            ],
            'a global class after the namespaced block' => [
               "use const PHP_EOL;\nuse function strlen;\n\nuse Bootgly\\ABI\\Syntax\\Imports;\nuse ArrayIterator;",
               ['global_not_first:ArrayIterator'],
            ],
            'the right order' => [
               "use const PHP_EOL;\nuse function strlen;\nuse ArrayIterator;\n\nuse Bootgly\\ABI\\Syntax\\Builtins;\nuse Bootgly\\ABI\\Syntax\\Imports;",
               [],
            ],
            'kinds out of order inside the namespaced block' => [
               "use const PHP_EOL;\nuse function strlen;\nuse ArrayIterator;\n\nuse Bootgly\\ABI\\Syntax\\Imports;\nuse function Bootgly\\ABI\\Syntax\\Builtins;",
               ['wrong_order:Bootgly\\ABI\\Syntax\\Builtins'],
            ],
         ];
         foreach ($cases as $label => [$imports, $expected]) {
            $found = $flag($imports);

            yield assert(
               assertion: $found === $expected,
               description: "{$label}: expected " . json_encode($expected) . ', got ' . json_encode($found)
            );
         }
      }
      finally {
         foreach (glob($dir . '/*.php') ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir($dir);
      }
   }
);
