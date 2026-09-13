<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: a file declaring more than one namespace carries a notice — not linted, never silently passed',
   test: function () {
      $dir = Temporaries::reserve('syntax-imports-multi');

      try {
         $Analyzer = new Analyzer();

         $write = function (string $source) use ($dir): string {
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            return $file;
         };
         $types = static function (array $Issues): array {
            $found = [];
            foreach ($Issues as $Issue) {
               $found[] = $Issue->type;
            }

            return $found;
         };

         // @@ Two blocks — the second one is where the missing import lives
         $Two = $Analyzer->analyze($write(
            "<?php\n\nnamespace A;\n\n\nuse ArrayIterator;\n\n\nclass X { public function run (): string { return ArrayIterator::class; } }\n\n"
            . "namespace B;\n\n\nuse ArrayObject;\n\n\nclass Y { public function run (): int { return count([]) + strlen(ArrayObject::class); } }\n"
         ));
         yield assert(
            assertion: $Two->issues === [] && $Two->failed === false && $Two->notice !== null
               && str_contains($Two->notice, 'More than one namespace'),
            description: 'Two namespace blocks must yield no issue and a notice saying the file is not linted, got: '
               . json_encode(['issues' => $types($Two->issues), 'notice' => $Two->notice])
         );

         // @@ A label is not a declaration: one block plus `namespace:` stays a plain file
         $One = $Analyzer->analyze($write(
            "<?php\n\nnamespace A;\n\n\nuse function strlen;\n\n\n\$E = new Exporter(namespace: 'x', registry: \$R);\n\$n = strlen('x');\n"
         ));
         yield assert(
            assertion: $One->issues === [] && $One->notice === null,
            description: 'One declaration plus an argument label must not count as two, got: '
               . json_encode(['issues' => $types($One->issues), 'notice' => $One->notice])
         );
      }
      finally {
         foreach (glob($dir . '/*.php') ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir($dir);
      }
   }
);
