<?php

use Bootgly\ABI\Syntax\Lint\Glob;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Glob: * stays in a segment, ** spans segments, a trailing / takes everything below, patterns are anchored; absolute, climbing, backslashed, control-character and dot/empty-segment patterns are refused',
   test: function () {
      // @@ pattern => [path => expected]
      $cases = [
         'vendor/' => ['vendor/a.php' => true, 'vendor/x/b.php' => true, 'vendor' => false, 'src/vendor/a.php' => false],
         '**/vendor/' => ['vendor/a.php' => true, 'src/vendor/a.php' => true, 'src/vendors/a.php' => false],
         '**/tests/fixtures/**' => ['Bootgly/ABI/tests/fixtures/a.php' => true, 'tests/fixtures/x/y.php' => true, 'Bootgly/tests/a.php' => false],
         'Bootgly/*/Foo.php' => ['Bootgly/ABI/Foo.php' => true, 'Bootgly/ABI/X/Foo.php' => false],
         'Bootgly/**/Foo.php' => ['Bootgly/Foo.php' => true, 'Bootgly/ABI/X/Foo.php' => true, 'Other/Foo.php' => false],
         'Bootgly/ACI/Tests/Assertion.php' => ['Bootgly/ACI/Tests/Assertion.php' => true, 'x/Bootgly/ACI/Tests/Assertion.php' => false, 'Bootgly/ACI/Tests/Assertion.php.bak' => false],
         './src/?.php' => ['src/a.php' => true, 'src/ab.php' => false, 'src//.php' => false],
         'a.b(c)+.php' => ['a.b(c)+.php' => true, 'aXb(c)+.php' => false],
         '**' => ['anything/at/all.php' => true],
         // # A name may hold a newline: `**` spans it, `$` never stops before it
         'src/gen/**' => ["src/gen/a\nb.php" => true],
         '**/gen/**' => ["src/gen/a\nb.php" => true],
         'src/*.php' => ["src/a.php\n" => false],
      ];
      $failures = [];
      foreach ($cases as $pattern => $paths) {
         $Glob = new Glob($pattern);
         foreach ($paths as $path => $expected) {
            if ($Glob->match($path) !== $expected) {
               $failures[] = "{$pattern} ~ {$path}";
            }
         }
      }
      yield assert(
         assertion: $failures === [],
         description: 'Every pattern must match exactly its paths, wrong: ' . json_encode($failures)
      );

      // @ The pattern is kept as written
      yield assert(
         assertion: new Glob('./src/')->pattern === './src/',
         description: 'Glob::$pattern must keep the pattern as written'
      );

      // @@ Refused patterns
      $refused = [];
      foreach (['', '/', '/etc/passwd', '../outside', 'a/../../b', 'a\\b', "a\x00b", "a\nb", 'src/./gen/', 'src//gen/', 'a/.'] as $pattern) {
         try {
            new Glob($pattern);
            $refused[] = json_encode($pattern);
         }
         catch (InvalidArgumentException) {
         }
      }
      yield assert(
         assertion: $refused === [],
         description: 'Empty, absolute, climbing, backslashed, control-character and dot/empty-segment patterns must throw, accepted: ' . implode(', ', $refused)
      );
   }
);
