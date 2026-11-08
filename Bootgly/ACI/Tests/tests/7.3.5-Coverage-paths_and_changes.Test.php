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
      file_put_contents("{$root}/Source/vendor/Package.php", "<?php\n\$p = 1;\n");
      file_put_contents("{$root}/Source/examples/Sample.php", "<?php\nfinal class Sample\n{\n   public function run () { return 1; }\n}\n");
      file_put_contents("{$root}/Source/Loaded.php", "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
      file_put_contents("{$root}/Source/Unloaded.php", "<?php\n\$x = 1;\n\$y = 2;\n");
      file_put_contents("{$root}/Source/notes.txt", "not php\n");
      file_put_contents("{$root}/Source/tests/1.Test.php", "<?php\n\$t = 1;\n");
      $source = str_replace('\\', '/', (string) realpath("{$root}/Source"));
      $loaded = "{$source}/Loaded.php";
      $unloaded = "{$source}/Unloaded.php";
      $command = "{$source}/commands/Run.php";
      $package = "{$source}/vendor/Package.php";

      $Driver = new class ($loaded) extends Driver {
         // * Data
         private string $file;


         public function __construct (string $file)
         {
            // * Data
            $this->file = $file;
         }

         public function collect (): array
         {
            return [$this->file => [2 => 1, 3 => 0, 4 => 1]];
         }
      };

      try {
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
            ->to->be([$loaded, $unloaded, $command, $package])
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
         $Coverage->changes = ["{$root}/Source/examples/Sample.php" => [2 => true, 3 => true]];
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

         // @ A changed source the Native driver never instruments (/vendor/)
         //   — never started here, so an outer coverage run stays untouched
         $Coverage = new Coverage(new Native());
         $Coverage->includes = [$source];
         $Coverage->changes = [$package => [2 => true]];
         $Coverage->stop();
         yield (new Assertion(description: 'under Native, a changed source out of its reach is unmeasured, not dropped'))
            ->expect([$Coverage->unmeasured, $Coverage->data])
            ->to->be([[$package], []])
            ->assert();

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
         @unlink("{$root}/tests/app/Foo.php");
         @rmdir("{$root}/tests/app");
         @rmdir("{$root}/tests");
         @unlink("{$root}/Source/commands/Run.php");
         @unlink("{$root}/Source/vendor/Package.php");
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
