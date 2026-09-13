<?php

use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: `namespace:` as an argument label is not a namespace declaration',
   test: function () {
      $dir = Temporaries::reserve('syntax-imports-label');

      try {
         $Analyzer = new Analyzer();

         $write = function (string $source) use ($dir): string {
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            return $file;
         };

         // @@ A file without a namespace stays a global-scope file — no import is asked for
         $global = $Analyzer->analyze($write(
            "<?php\n\n\$Exporter = new Exporter(namespace: 'bootgly', registry: \$Registry);\n\$ok = str_contains(\$Exporter->export(), 'bootgly_');\n"
         ));
         yield assert(
            assertion: $global->namespace === '' && $global->issues === [],
            description: 'A global-scope file with a `namespace:` label must report no namespace and no issue, got: '
               . json_encode(['namespace' => $global->namespace, 'issues' => count($global->issues)])
         );

         // @@ A label before a real declaration cannot hide it: the declaration still counts once
         $labelled = $Analyzer->analyze($write(
            "<?php\n\nnamespace Demo;\n\n\n\$Exporter = new Exporter(namespace: 'bootgly', registry: \$Registry);\n\$n = strlen('x');\n"
         ));
         $missing = [];
         foreach ($labelled->issues as $Issue) {
            $missing[] = $Issue->type . ':' . $Issue->symbol;
         }
         yield assert(
            assertion: $labelled->namespace === 'Demo' && $missing === ['missing_import:strlen'],
            description: 'A namespaced file with a `namespace:` label must still be checked (strlen unimported), got: '
               . json_encode(['namespace' => $labelled->namespace, 'issues' => $missing])
         );

         // @@ A namespaced file keeps its real namespace, whatever labels its body carries
         $namespaced = $Analyzer->analyze($write(
            "<?php\n\nnamespace Demo;\n\n\nuse function str_contains;\n\n\n\$Exporter = new Exporter(namespace: 'bootgly', registry: \$Registry);\n\$ok = str_contains(\$Exporter->export(), 'bootgly_');\n"
         ));
         yield assert(
            assertion: $namespaced->namespace === 'Demo' && $namespaced->issues === [],
            description: 'The declared namespace must win over an argument label, got: '
               . json_encode(['namespace' => $namespaced->namespace, 'issues' => count($namespaced->issues)])
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
