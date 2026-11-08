<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Coverage — measured paths add unloaded sources at zero; changes restrict the report to changed lines',

   test: new Assertions(Case: function (): Generator {
      // ! A source tree: one loaded file, one never loaded, one test script
      $root = Temporaries::reserve('coverage-paths');
      mkdir("{$root}/Source");
      mkdir("{$root}/Source/tests");
      mkdir("{$root}/Source/examples");
      mkdir("{$root}/Source/commands");
      mkdir("{$root}/Source/vendor");
      file_put_contents("{$root}/Source/commands/Run.php", "<?php\n\$r = 1;\n");
      mkdir("{$root}/Source/vendor/examples");
      file_put_contents("{$root}/Source/vendor/Package.php", "<?php\n\$p = max(\n   1, 2\n);\n// note\n");
      file_put_contents("{$root}/Source/vendor/examples/Demo.php", "<?php\n\$d = 1;\n");
      file_put_contents("{$root}/Source/vendor/Zeta.php", "<?php\n\$z = 1;\n");
      file_put_contents("{$root}/Source/Upper.PHP", "<?php\n\$u = 1;\n");
      file_put_contents("{$root}/Source/examples/Sample.php", "<?php\nfinal class Sample\n{\n   public function run () { return 1; }\n}\n");
      file_put_contents("{$root}/Source/Loaded.php", "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
      file_put_contents("{$root}/Source/Unloaded.php", "<?php\n\$x = 1;\n\$y = 2;\n");
      // Statements over several lines: only their first line is executable
      // (2, 6, 10, 14 and 18) — arguments, a heredoc, an interpolation, a
      // `for` header, a closure argument; then control bodies (23–41), match
      // arms (42–49), a call closed after a closure (52), inline HTML
      // after a close tag that ends a statement (53–56), a braceless `else`
      // body (61–63), a `case` holding a ternary (65–68), match arms with a
      // nullable type, an array, a closure and an attribute (70–82), a
      // `case` with a nullable type (83–88), braceless `while`/`for`/
      // `elseif` and an alternative `switch` (89–107), bodies sharing a
      // row with `catch`/`declare` (108–116), a one-line `if` (117–119) and a
      // body sharing its row with a `case` label (120–125) and a braceless
      // `else if` body (126–130)
      file_put_contents("{$root}/Source/Multi.php", <<<'PHP'
      <?php
      $m = max(
         1,
         2
      );
      $s = <<<TXT
        body
        TXT;
      // note
      $v = max(
         "{$s}",
         1
      );
      for ($i = 0;
         $i < 1;
         $i++) {
      }
      $f = array_map(static function ($x) {
         return $x;
      }, [
         1,
      ]);
      switch ($m) {
         case 1:
            $r = max(
               5, 6
            );
            break;
         default: $r = max(
            7, 8
         );
      }
      if ($m)
         echo max(
            9, 10
         );
      foreach ([1] as $i):
         echo max(
            13, 14
         );
      endforeach;
      $v = match ($m) {
         1 => max(
            1, 2
         ),
         default => min(
            3, 4
         ),
      };
      $w = array_map(function ($x) {
         return $x;
      });
      $q = max(1, 2) ?>
      <p>
        x
      </p>
      <?php
      $z = 1;
      if ($m) {
      } else
         echo max(
            9, 10
         );
      switch ($m) {
         case $m ? 1 : 2:
            echo max(
               5, 6
            );
      }
      $n = match ($m) {
         1 => static fn (?int $v): ?int => $v,
         2 => [
            1,
         ],
         3 => function () {
            return 1;
         },
         4 => #[A,
            B] static fn () => max(
            1, 2
         ),
      };
      switch ($m) {
         case array_map(fn (?int $v) => $v, [1]):
            echo max(
               1, 2
            );
      }
      while ($m)
         echo max(
            1, 2
         );
      for (; $m;)
         echo max(
            1, 2
         );
      if ($m) {
      } elseif ($m)
         echo max(
            1, 2
         );
      switch ($m):
         case 1:
            echo max(
               1, 2
            );
      endswitch;
      try {
      } catch (Exception $e) { $r = max(
         1,
         2
      ); }
      declare(ticks=1) { $r = max(
         1,
         2
      ); }
      if ($m) echo max(
         1, 2
      );
      switch ($m) {
         case 2: $r = max(
            1,
            2
         );
      }
      if ($m) {
      } else if ($m)
         echo max(
            1, 2
         );

      PHP);
      file_put_contents("{$root}/Source/notes.txt", "not php\n");
      file_put_contents("{$root}/Source/tests/1.Test.php", "<?php\n\$t = 1;\n");
      $source = str_replace('\\', '/', (string) realpath("{$root}/Source"));
      $loaded = "{$source}/Loaded.php";
      $unloaded = "{$source}/Unloaded.php";
      $command = "{$source}/commands/Run.php";
      $package = "{$source}/vendor/Package.php";
      $zeta = "{$source}/vendor/Zeta.php";
      $upper = "{$source}/Upper.PHP";
      $multi = "{$source}/Multi.php";

      $Driver = new class ($loaded) extends Driver {
         // * Data
         private string $file;
         /** @var array<int,int> */
         private array $lines;


         /**
          * @param array<int,int> $lines
          */
         public function __construct (string $file, array $lines = [2 => 1, 3 => 0, 4 => 1])
         {
            // * Data
            $this->file = $file;
            $this->lines = $lines;
         }

         public function collect (): array
         {
            return [$this->file => $this->lines];
         }
      };

      try {
         // ? The temporary root itself sits in a resource directory (a TMPDIR
         //   such as /home/ci/tests/tmp): sources outside the working
         //   directory are read by their absolute path, so every one here
         //   would count as a test script
         foreach (explode('/', $source) as $segment) {
            if (isset(Coverage::RESOURCES[$segment])) {
               yield (new Assertion(description: "the temporary root sits in a '{$segment}/' directory: {$source}"))->skip();
               return;
            }
         }

         // @ Without paths: only what ran
         $Coverage = new Coverage($Driver);
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'without paths the report holds only loaded files'))
            ->expect(array_keys($Coverage->data))
            ->to->be([$loaded])
            ->assert();

         // @ With a measured path
         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->start();
         $Coverage->stop();

         yield (new Assertion(description: 'an unloaded source joins the report (commands/ is code); tests/ and examples/ resource folders do not'))
            ->expect(array_keys($Coverage->data))
            ->to->be([$loaded, $multi, $unloaded, $upper, $command, $package, $zeta])
            ->assert();

         yield (new Assertion(description: 'the unloaded source is counted at zero hits'))
            ->expect($Coverage->data[$unloaded])
            ->to->be([2 => 0, 3 => 0])
            ->assert();

         yield (new Assertion(description: 'a loaded source keeps its measured hits'))
            ->expect($Coverage->data[$loaded])
            ->to->be([2 => 1, 3 => 0, 4 => 1])
            ->assert();

         // @ The include scope still applies to unloaded sources
         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->includes = ['/Loaded.php'];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'unloaded sources outside the include scope stay out'))
            ->expect(array_keys($Coverage->data))
            ->to->be([$loaded])
            ->assert();

         // @ A path that is not a directory
         $Coverage = new Coverage($Driver);
         $Coverage->paths = ["{$root}/missing"];
         $Coverage->start();
         $thrown = false;
         try {
            $Coverage->stop();
         }
         catch (LogicException) {
            $thrown = true;
         }
         yield (new Assertion(description: 'a missing measured path throws a LogicException'))
            ->expect($thrown)
            ->to->be(true)
            ->assert();

         // @ Changed lines
         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->changes = [
            $loaded => [3 => true, 4 => true, 9 => true],
            "{$root}/Source/../Source/Unloaded.php" => [1 => true],
         ];
         $Coverage->start();
         $Coverage->stop();

         yield (new Assertion(description: 'changes keep only the changed executable lines'))
            ->expect($Coverage->data)
            ->to->be([$loaded => [3 => 0, 4 => 1]])
            ->assert();

         // @ A changed source no test loaded — without a measured path
         $Coverage = new Coverage($Driver);
         $Coverage->changes = [$unloaded => [2 => true, 3 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source no test loaded joins at zero even without paths'))
            ->expect($Coverage->data)
            ->to->be([$unloaded => [2 => 0, 3 => 0]])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->includes = ['/Loaded.php'];
         $Coverage->changes = [$unloaded => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source outside the include scope stays out'))
            ->expect([$Coverage->data, $Coverage->unmeasured])
            ->to->be([[], []])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = ["{$root}/Source/examples/Sample.php" => [2 => true, 3 => true, 4 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed resource source (examples/) stays out'))
            ->expect($Coverage->data)
            ->to->be([])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = ["{$root}/Source/tests/1.Test.php" => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed test script stays out of the report'))
            ->expect($Coverage->data)
            ->to->be([])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = [];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'an empty change set measures nothing'))
            ->expect($Coverage->data)
            ->to->be([])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->paths = [$source];
         $Coverage->changes = [$unloaded => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source no test loaded is measured at zero'))
            ->expect($Coverage->data)
            ->to->be([$unloaded => [2 => 0]])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = [$command => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed source under commands/ is code, not a resource'))
            ->expect($Coverage->data)
            ->to->be([$command => [2 => 0]])
            ->assert();

         $Coverage = new Coverage($Driver);
         $Coverage->changes = [$upper => [2 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a changed .PHP source (any case) no test loaded joins at zero'))
            ->expect($Coverage->data)
            ->to->be([$upper => [2 => 0]])
            ->assert();

         // @ A changed line inside a statement over several lines counts as
         //   the statement's executable line; a comment line counts as none
         $results = [];
         foreach ([[4 => true], [7 => true], [9 => true], [3 => true, 8 => true], [12 => true], [15 => true], [20 => true], [21 => true], [17 => true]] as $rows) {
            $Coverage = new Coverage($Driver);
            $Coverage->changes = [$multi => $rows];
            $Coverage->start();
            $Coverage->stop();
            $results[] = $Coverage->data;
         }
         yield (new Assertion(description: 'a changed source no test loaded: argument, heredoc, interpolation, for-header and closure-argument rows map to their statement; a comment or a closing brace maps to none'))
            ->expect($results)
            ->to->be([
               [$multi => [2 => 0]], [$multi => [6 => 0]], [], [$multi => [2 => 0, 6 => 0]],
               [$multi => [10 => 0]], [$multi => [14 => 0]], [$multi => [18 => 0]], [$multi => [18 => 0]], [],
            ])
            ->assert();

         $Coverage = new Coverage(new (get_class($Driver))($multi, [2 => 1, 6 => 0]));
         $Coverage->changes = [$multi => [5 => true, 9 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a loaded source keeps the statement whose closing row changed'))
            ->expect($Coverage->data)
            ->to->be([$multi => [2 => 1]])
            ->assert();

         // @ A driver that marks control headers and the first row of each
         //   body (as PCOV does): a body row maps to its body statement, never
         //   to the header — which runs whether or not the body does
         $map = [
            2 => 1, 23 => 1, 24 => 1, 25 => 0, 28 => 0, 29 => 0, 33 => 1, 34 => 0, 37 => 1, 38 => 0,
            41 => 1, 42 => 1, 43 => 1, 46 => 0, 50 => 1, 51 => 1, 53 => 1, 54 => 0, 58 => 1,
            59 => 1, 61 => 0, 64 => 1, 65 => 1, 66 => 0,
            70 => 1, 71 => 1, 72 => 0, 75 => 0, 76 => 0, 78 => 0, 79 => 0, 84 => 1, 85 => 0,
            89 => 1, 90 => 0, 93 => 1, 94 => 0, 97 => 1, 98 => 1, 99 => 0, 102 => 1, 103 => 1, 104 => 0,
            109 => 1, 111 => 0, 113 => 1, 115 => 0, 117 => 1, 121 => 1, 123 => 0, 126 => 1, 127 => 1, 128 => 0,
         ];
         $results = [];
         foreach ([26, 30, 35, 39, 47, 52, 55, 62, 67, 74, 80, 86, 91, 95, 100, 105, 110, 114, 118, 122, 129] as $row) {
            $Coverage = new Coverage(new (get_class($Driver))($multi, $map));
            $Coverage->changes = [$multi => [$row => true]];
            $Coverage->start();
            $Coverage->stop();
            $results[$row] = $Coverage->data[$multi] ?? [];
         }
         yield (new Assertion(description: 'body rows map to their body statement (case, default, braceless, alternative syntax, else, match arm, `});`, inline HTML), preferring a row no header shares; a one-line `if` keeps its shared row'))
            ->expect($results)
            ->to->be([
               26 => [25 => 0], 30 => [29 => 0], 35 => [34 => 0], 39 => [38 => 0], 47 => [46 => 0],
               52 => [50 => 1], 55 => [54 => 0], 62 => [61 => 0], 67 => [66 => 0],
               74 => [72 => 0], 80 => [78 => 0], 86 => [85 => 0], 91 => [90 => 0], 95 => [94 => 0],
               100 => [99 => 0], 105 => [104 => 0], 110 => [111 => 0], 114 => [115 => 0], 118 => [117 => 1],
               122 => [123 => 0], 129 => [128 => 0],
            ])
            ->assert();

         $Coverage = new Coverage(new (get_class($Driver))($multi, [5 => 1]));
         $Coverage->changes = [$multi => [3 => true]];
         $Coverage->start();
         $Coverage->stop();
         yield (new Assertion(description: 'a driver that marks only the closing row of a statement still sees a change to its argument'))
            ->expect($Coverage->data)
            ->to->be([$multi => [5 => 1]])
            ->assert();

         // @ Changed sources the Native driver never instruments (/vendor/)
         //   — never started here, so an outer coverage run stays untouched
         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->changes = [$zeta => [2 => true], $package => [2 => true]];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, changed sources out of its reach are unmeasured (sorted), not dropped'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[$package, $zeta], []])
            ->assert();

         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->changes = [$package => [5 => true]];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a comment-only change out of its reach is not unmeasured'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[], []])
            ->assert();

         $results = [];
         foreach ([[3 => true], [3 => true, 5 => true]] as $rows) {
            $Coverage = new Coverage(new Native());
            $Coverage->includes = [$source];
            $Coverage->changes = [$package => $rows];
            $Coverage->stop();
            $results[] = $Coverage->unmeasured;
         }
         yield (new Assertion(description: 'under Native, a change inside a statement out of its reach is unmeasured, with or without a comment change beside it'))
            ->expect($results)
            ->to->be([[$package], [$package]])
            ->assert();

         // @ A source a test loaded that Native never instruments
         //   — and one under a resource folder, which no report holds
         $demo = "{$source}/vendor/examples/Demo.php";
         foreach ([$package, $demo] as $file) {
            (static function (string $file): void {
               require $file;
            })($file);
         }
         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a loaded source out of its reach is unmeasured, not dropped; a loaded resource is neither'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[$package], []])
            ->assert();

         // @ The coverage engine is always loaded and never instruments
         //   itself: it is not a source the tests failed to reach
         $engine = array_values(array_filter(
            get_included_files(),
            static fn (string $file): bool => str_contains(str_replace('\\', '/', $file), Native::ENGINE)
         ));
         $Coverage = new Coverage(new Native());
         $Coverage->includes = ['/Bootgly/ACI/Tests/'];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, the loaded coverage engine is never reported as unmeasured'))
            ->expect([$engine !== [], array_values(array_filter(
               $Coverage->unmeasured,
               static fn (string $file): bool => str_contains($file, Native::ENGINE)
            ))])
            ->to->be([true, []])
            ->assert();

         // ? Only the engine's own directory: a project source at a
         //   same-named path (an extension kept under `projects/…`) is not it
         mkdir("{$root}/Kit/Bootgly/ACI/Tests/Coverage", 0o755, true);
         file_put_contents("{$root}/Kit/Bootgly/ACI/Tests/Coverage/Extension.php", "<?php\n\$e = 1;\n");
         $extension = str_replace('\\', '/', (string) realpath("{$root}/Kit/Bootgly/ACI/Tests/Coverage/Extension.php"));
         (static function (string $file): void {
            require $file;
         })($extension);
         $Coverage = new Coverage(new Native());
         $Coverage->includes = ["{$root}/Kit/"];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a loaded project source at a path named like the engine stays unmeasured'))
            ->expect($Coverage->unmeasured)
            ->to->be([$extension])
            ->assert();

         // @ Native with the temporary tree in its reach (only /tmp/ dropped
         //   from its exclusions, restored below): an unloaded source joins
         //   at zero, and only a source still out of reach is unmeasured
         //   — every stop() runs before the list is restored, and only then
         //   are the results asserted
         $excludes = Native::$excludes;
         $reach = null;
         Native::$excludes = array_values(array_diff($excludes, ['/tmp/']));
         try {
            if (Native::allow($unloaded)) {
               $Changed = new Coverage(new Native());
               $Changed->includes = [$source];
               $Changed->changes = [$unloaded => [2 => true]];
               $Changed->stop();

               // ! A loaded source in its reach that the (idle) driver
               //   recorded nothing for is not unmeasured
               (static function (string $file): void {
                  require $file;
               })($loaded);
               $Loaded = new Coverage(new Native());
               $Loaded->includes = [$source];
               $Loaded->stop();

               $Measured = new Coverage(new Native());
               $Measured->includes = [$source];
               $Measured->paths = [$source];
               $Measured->stop();

               $Both = new Coverage(new Native());
               $Both->includes = [$source];
               $Both->paths = [$source];
               $Both->changes = [$unloaded => [2 => true]];
               $Both->stop();

               $reach = [$Changed, $Loaded, $Measured, $Both];
            }
         }
         finally {
            Native::$excludes = $excludes;
         }

         if ($reach === null) {
            yield (new Assertion(description: "Native reach probes: the temporary tree stays excluded without /tmp/ ({$source})"))->skip();
         }
         else {
            [$Changed, $Loaded, $Measured, $Both] = $reach;
            yield (new Assertion(description: 'under Native, a changed source in its reach joins at zero, not as unmeasured'))
               ->expect([$Changed->unmeasured, $Changed->data])
               ->to->be([[], [$unloaded => [2 => 0]]])
               ->assert();

            yield (new Assertion(description: 'under Native, a loaded source in its reach is not unmeasured'))
               ->expect($Loaded->unmeasured)
               ->to->be([$package])
               ->assert();

            yield (new Assertion(description: 'under Native, a measured path reports the sources out of its reach as unmeasured'))
               ->expect([$Measured->unmeasured, isset($Measured->data[$unloaded]), isset($Measured->data[$package])])
               ->to->be([[$package, $zeta], true, false])
               ->assert();

            yield (new Assertion(description: 'under Native, a diff keeps only the changed sources out of its reach'))
               ->expect([$Both->unmeasured, $Both->data])
               ->to->be([[], [$unloaded => [2 => 0]]])
               ->assert();
         }

         // @ A checkout that itself sits in a tests/ directory (a CI
         //   workspace named so): its sources are not test scripts. The
         //   working directory is a constant, so a child process sets it.
         $workspace = "{$root}/tests/app";
         mkdir($workspace, 0o755, true);
         file_put_contents("{$workspace}/Foo.php", "<?php\n\$f = 1;\n");
         $foo = str_replace('\\', '/', (string) realpath("{$workspace}/Foo.php"));
         file_put_contents("{$root}/child.php", <<<'PHP'
         <?php
         [, $framework, $working, $file] = $argv;
         define('BOOTGLY_WORKING_DIR', "{$working}/");
         spl_autoload_register(static function (string $class) use ($framework): void {
            $path = $framework . str_replace('\\', '/', $class) . '.php';
            if (is_file($path)) {
               require $path;
            }
         });
         $Driver = new class ($file) extends Bootgly\ACI\Tests\Coverage\Driver {
            private string $file;
            public function __construct (string $file) { $this->file = $file; }
            public function collect (): array { return [$this->file => [2 => 0]]; }
         };
         $Idle = new class extends Bootgly\ACI\Tests\Coverage\Driver {
            public function collect (): array { return []; }
         };
         $result = [];
         foreach ([[$Driver, null], [$Driver, [$file => [2 => true]]], [$Idle, [$file => [2 => true]]]] as [$Used, $changes]) {
            $Coverage = new Bootgly\ACI\Tests\Coverage($Used);
            $Coverage->changes = $changes;
            $Coverage->start();
            $Coverage->stop();
            $result[] = $Coverage->data;
         }
         echo json_encode($result);
         PHP);
         $process = proc_open(
            [PHP_BINARY, "{$root}/child.php", BOOTGLY_ROOT_DIR, (string) realpath($workspace), $foo],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
         );
         $output = '';
         if (is_resource($process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
         }
         yield (new Assertion(description: 'a checkout under a tests/ directory keeps its sources: loaded in full and diff mode, and changed but never loaded'))
            ->expect(json_decode($output, true))
            ->to->be([[$foo => [2 => 0]], [$foo => [2 => 0]], [$foo => [2 => 0]]])
            ->assert();
      }
      finally {
         @unlink("{$root}/child.php");
         @unlink("{$root}/Kit/Bootgly/ACI/Tests/Coverage/Extension.php");
         foreach (['/Kit/Bootgly/ACI/Tests/Coverage', '/Kit/Bootgly/ACI/Tests', '/Kit/Bootgly/ACI', '/Kit/Bootgly', '/Kit'] as $directory) {
            @rmdir("{$root}{$directory}");
         }
         @unlink("{$root}/tests/app/Foo.php");
         @rmdir("{$root}/tests/app");
         @rmdir("{$root}/tests");
         @unlink("{$root}/Source/commands/Run.php");
         @unlink("{$root}/Source/vendor/Package.php");
         @unlink("{$root}/Source/vendor/Zeta.php");
         @unlink("{$root}/Source/vendor/examples/Demo.php");
         @rmdir("{$root}/Source/vendor/examples");
         @unlink("{$root}/Source/Multi.php");
         @unlink("{$root}/Source/Upper.PHP");
         @rmdir("{$root}/Source/commands");
         @rmdir("{$root}/Source/vendor");
         @unlink("{$root}/Source/tests/1.Test.php");
         @unlink("{$root}/Source/examples/Sample.php");
         @rmdir("{$root}/Source/examples");
         @rmdir("{$root}/Source/tests");
         @unlink("{$root}/Source/Loaded.php");
         @unlink("{$root}/Source/Unloaded.php");
         @unlink("{$root}/Source/notes.txt");
         @rmdir("{$root}/Source");
         @rmdir($root);
      }
   })
);
