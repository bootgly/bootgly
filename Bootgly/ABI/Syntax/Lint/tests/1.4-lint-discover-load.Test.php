<?php

use Bootgly\ABI\Syntax\Analyzers\Rules;
use Bootgly\ABI\Syntax\Lint;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Lint: discover() finds the nearest bootgly.Lint.php walking up, never above the root; load() returns the configuration or throws — never the defaults',
   test: function () {
      $dir = Temporaries::reserve('syntax-lint-load');
      $root = "{$dir}/root";
      @mkdir("{$root}/projects/Alpha/Models", 0o755, true);
      @mkdir("{$dir}/outside", 0o755, true);
      $written = [];
      $write = function (string $file, string $content) use (&$written): void {
         file_put_contents($file, $content);
         $written[] = $file;
      };

      try {
         $real = realpath($root);

         // @ None anywhere
         yield assert(
            assertion: Lint::discover("{$root}/projects/Alpha/Models", $root) === null,
            description: 'discover() must return null when no file exists up to the root'
         );

         // @ The root's file, from deep inside; never one above the root
         $write("{$dir}/bootgly.Lint.php", "<?php\nreturn null;\n");
         $write("{$root}/bootgly.Lint.php", "<?php\n\nuse Bootgly\\ABI\\Syntax\\Lint;\n\nreturn new Lint(version: 1, paths: ['projects/']);\n");
         yield assert(
            assertion: Lint::discover("{$root}/projects/Alpha/Models", $root) === "{$real}/bootgly.Lint.php",
            description: 'discover() must find the root file from a nested directory and never look above the root'
         );

         // @ The nearest wins
         $write("{$root}/projects/Alpha/bootgly.Lint.php", "<?php\n\nuse Bootgly\\ABI\\Syntax\\Lint;\nuse Bootgly\\ABI\\Syntax\\Analyzers\\Rules;\n\nreturn new Lint(version: 1, paths: ['.'], rules: [Rules::Spacing]);\n");
         yield assert(
            assertion: Lint::discover("{$root}/projects/Alpha/Models", $root) === "{$real}/projects/Alpha/bootgly.Lint.php"
               && Lint::discover("{$root}/projects", $root) === "{$real}/bootgly.Lint.php",
            description: 'discover() must return the nearest file walking up'
         );

         // @ From outside the root (or a missing directory), only the root is searched
         yield assert(
            assertion: Lint::discover("{$dir}/outside", $root) === "{$real}/bootgly.Lint.php"
               && Lint::discover("{$dir}/missing", $root) === "{$real}/bootgly.Lint.php"
               && Lint::discover($root, "{$dir}/missing") === null,
            description: 'discover() must search only the root from outside it, and nothing for a missing root'
         );

         // @ load() — a valid file, with its origin recorded
         $Lint = Lint::load("{$real}/projects/Alpha/bootgly.Lint.php");
         yield assert(
            assertion: $Lint->rules === [Rules::Spacing] && $Lint->paths === ['.'] && $Lint->file === "{$real}/projects/Alpha/bootgly.Lint.php",
            description: 'load() must return the configuration the file builds and record the file'
         );

         // @@ load() — every broken file throws
         $broken = [
            'returns null'      => ["<?php\nreturn null;\n", UnexpectedValueException::class, 'got null'],
            'returns an array'  => ["<?php\nreturn ['paths' => ['.']];\n", UnexpectedValueException::class, 'got array'],
            'parse error'       => ["<?php\nreturn new ;\n", ParseError::class, ''],
            'malformed entry'   => ["<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(version: 1, paths: ['/abs/']);\n", InvalidArgumentException::class, 'is absolute'],
            'no version'        => ["<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(paths: ['.']);\n", ArgumentCountError::class, ''],
            'later version'     => ["<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(version: 2, paths: ['.']);\n", InvalidArgumentException::class, 'Lint version 2 is not supported'],
            'unknown rule'      => ["<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(version: 1, paths: ['.'], rules: [Bootgly\\ABI\\Syntax\\Analyzers\\Rules::from('nope')]);\n", ValueError::class, ''],
            'unknown parameter' => ["<?php\nreturn new Bootgly\\ABI\\Syntax\\Lint(version: 1, paths: ['.'], ignores: []);\n", Error::class, 'ignores'],
         ];
         $wrong = [];
         foreach ($broken as $label => [$content, $class, $needle]) {
            $file = "{$dir}/outside/" . md5($label) . '.php';
            $write($file, $content);
            try {
               Lint::load($file);
               $wrong[$label] = 'accepted';
            }
            catch (Throwable $Throwable) {
               if ($Throwable instanceof $class === false || str_contains($Throwable->getMessage(), $needle) === false) {
                  $wrong[$label] = $Throwable::class . ': ' . $Throwable->getMessage();
               }
            }
         }
         try {
            Lint::load("{$dir}/outside/missing.php");
            $wrong['missing'] = 'accepted';
         }
         catch (UnexpectedValueException) {
         }
         yield assert(
            assertion: $wrong === [],
            description: 'Every broken file must throw — never fall back to the defaults, wrong: ' . json_encode($wrong)
         );
      }
      finally {
         foreach ($written as $file) {
            @unlink($file);
         }
         foreach (["{$root}/projects/Alpha/Models", "{$root}/projects/Alpha", "{$root}/projects", $root, "{$dir}/outside", $dir] as $directory) {
            @rmdir($directory);
         }
      }
   }
);
