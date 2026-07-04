<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_DIR;
use const PHP_EOL;
use function array_slice;
use function array_unique;
use function array_values;
use function explode;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function preg_replace;
use function putenv;
use function realpath;
use function rtrim;
use function scandir;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function substr;
use Closure;
use LogicException;

use const Bootgly\CLI;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Info;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Nothing;
use Bootgly\ACI\Tests\Coverage\Drivers\PCOV;
use Bootgly\ACI\Tests\Coverage\Drivers\XDebug;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Environment\Agent;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;


class TestCommand extends Command
{
   use Formattable;

   // * Config
   public int $group = 1;

   // * Data
   // @ Command
   public string $name = 'test';
   public string $description = 'Perform Bootgly tests';


   public function run (array $arguments = [], array $options = []): bool
   {
      // ? Subcommand: benchmark
      if (($arguments[0] ?? null) === 'benchmark') {
         return $this->benchmark(
            array_slice($arguments, 1),
            $options
         );
      }

      // ! Agent detection
      // When an AI agent drives `bootgly test`, the `bootgly` executable
      // re-invokes itself via proc_open with a pipe on fd 1, drains the
      // pipe, strips ANSI, and extracts the last valid JSON document.
      // So we only need to:
      //   - enable Results collection
      //   - suppress the framework's own human output (guards on Results::$enabled)
      //   - emit Results::toJSON() at the end
      $Agent = Agent::detect();
      if ($Agent->detected) {
         Display::show(Display::NONE);
         Results::$enabled = true;
         Results::$agent = $Agent->name;
      }

      // ! Tester
      // * Config
      Suite::$exitOnFailure = true;

      // !
      // arguments
      // # suite index
      $suite_index = (int) ($arguments[0] ?? 0);
      if ($suite_index < 1) {
         $suite_index = 0;
      }
      // # case index
      $case_index = (int) ($arguments[1] ?? 0);
      if ($case_index < 1) {
         $case_index = 0;
      }
      // options
      // # coverage
      $coverageEnabled = isset($options['coverage']);
      $coverageDriver  = isset($options['coverage-driver'])
         ? strtolower((string) $options['coverage-driver'])
         : null;
      $coverageReport  = isset($options['coverage-report'])
         ? (string) $options['coverage-report']
         : null;
      $coverageNativeMode = isset($options['coverage-native-mode'])
         ? strtolower((string) $options['coverage-native-mode'])
         : Native::MODE_STRICT;
      $coverageDiff = isset($options['coverage-diff']);

      // @ Build Coverage instance when requested
      $Coverage = null;
      if ($coverageEnabled || $coverageDriver !== null || $coverageReport !== null || $coverageDiff) {
         $Coverage = ($GLOBALS['BOOTGLY_COVERAGE'] ?? null) instanceof Coverage
            ? $GLOBALS['BOOTGLY_COVERAGE']
            : null;

         if ($Coverage === null) {
            $Driver = match (true) {
               $coverageDriver === null || $coverageDriver === '' => Coverage::detect(),
               $coverageDriver === 'xdebug'  => new XDebug(),
               $coverageDriver === 'pcov'    => new PCOV(),
               $coverageDriver === 'native'  => new Native(explicit: true, mode: $coverageNativeMode),
               $coverageDriver === 'nothing' => new Nothing(),
               default => throw new LogicException('Unknown coverage driver: ' . $coverageDriver),
            };

            $Coverage = new Coverage($Driver);
            $Coverage->start();
         }

         if ($Coverage->Driver instanceof Native && ! Results::$enabled) {
            echo "Coverage (native): only files loaded after start() are instrumented." . PHP_EOL;
            echo "Coverage (native): ensure opcache.enable_cli=0 for accurate results." . PHP_EOL;
         }
         $Coverage->diff = $coverageDiff;
      }


      // @
      $Tests = new Tests;

      if ($suite_index > 0 && ! isset($Tests->Suites->directories[$suite_index - 1])) {
         $Output = CLI->Terminal->Output;
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Test suite index {$suite_index} was not loaded or does not exist.@.;";
         $Alert->render();

         return false;
      }

      // $Tester = new Tester;
      $Tests->Suites->iterate(
         suite: $suite_index,
         case: $case_index,
         iterator: fn (string $suite_dir, int $index, int $suite) => $this->test($suite_dir, $index, $suite)
      );
      $Tests->Suites->summarize();

      // @ Coverage report
      if ($Coverage !== null) {
         // Restrict coverage to selected suite scopes and, when possible,
         // to each suite's canonical SUT file (pure SUT report).
         $includes = [];
         $targets = [];

         foreach ($Tests->Suites->directories as $index => $dir) {
            if ($suite_index > 0 && $suite_index !== $index + 1) {
               continue;
            }

            // e.g. 'Bootgly/ABI/Data/__Array/' → 'Bootgly/ABI/Data/__Array'
            $scope = rtrim(str_replace('\\', '/', $dir), '/');
            if ($scope === '') {
               continue;
            }

            $source = $scope;
            $targetable = true;

            // Suites can be registered from an explicit lowercase /tests
            // subtree (e.g. HTTP_Client_CLI/tests/Atomic). Test scripts are
            // always excluded by Coverage, so the include scope must point
            // back to the source package that those tests exercise.
            if (str_contains($scope, '/tests/')) {
               [$source] = explode('/tests/', $scope, 2);
               $targetable = false;
            }
            else if (str_ends_with($scope, '/tests')) {
               $source = substr($scope, 0, -6);
               $targetable = false;
            }

            if ($source === '') {
               continue;
            }

            $includes[] = $source;

            // Package suites such as Bootgly/ACI/Tests exercise a whole
            // directory of framework sources. Do not pin them to the
            // aggregate Tests.php file; keep the include scope and let
            // Coverage exclude lowercase /tests/ scripts.
            $target = $targetable ? $this->locate($source) : null;
            if ($target !== null && ! str_ends_with($source, '/Tests')) {
               $targets[] = $target;
            }
         }

         $Coverage->includes = array_values(array_unique($includes));
         if ($suite_index > 0) {
            $Coverage->targets = array_values(array_unique($targets));
         }

         $Coverage->stop();

         // Parse "format[:path]" from the option value (default: text to stdout)
         $reportOption = $coverageReport ?? 'text';
         $parts  = explode(':', $reportOption, 2);
         $format = $parts[0];
         $path   = $parts[1] ?? null;

         $rendered = $Coverage->report($format);

         if ($path !== null) {
            file_put_contents($path, $rendered);
         }
         else {
            echo $rendered;
         }
      }

      // @ JSON output for AI agents — the wrapper in `bootgly` extracts the
      // last valid JSON document from the captured stdout, so a plain echo
      // is sufficient.
      if (Results::$enabled) {
         echo Results::toJSON();
      }

      return $Tests->Suites->failed === 0;
   }

   // # Test Suite
   public function test (string $suite_dir, null|int $index, null|int $suite = null): true|Suite
   {
      // !
      $hasTests = str_contains($suite_dir, '/tests/') || str_ends_with($suite_dir, '/tests');
      $bootstrap_file = str_replace('\\', '/', $suite_dir . ($hasTests ? '' : '/tests') . '/@.php');
      $bootstrap_file = preg_replace('#/{2,}#', '/', $bootstrap_file) ?? $bootstrap_file;
      $bootstrap = BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR
         ? BOOTGLY_WORKING_DIR . $bootstrap_file
         : BOOTGLY_ROOT_DIR . $bootstrap_file;

      if (is_file($bootstrap) === false) {
         $suite ??= 0;
         throw new LogicException("Test suite index {$suite} was not loaded or does not exist: {$suite_dir}");
      }

      $Suite = include $bootstrap;
      // ?
      if ($Suite instanceof Suite === false) {
         $suite ??= 0;
         throw new LogicException("Test suite index {$suite} did not load a valid Suite: {$suite_dir}");
      }

      // ?!
      // * Config
      if ($index) {
         $Suite->target = $index;
      }

      // ! Display
      // Suites (e.g. live-server E2E boots) mute the global Display for their
      // own run; restore the caller's mask so later suites stay visible.
      $segments = Display::$segments;

      // @
      try {
         $autoBoot = $Suite->autoBoot ?? false;
         if ($autoBoot instanceof Closure) {
            return $autoBoot($Suite);
         }
         else if ($autoBoot) {
            $Suite->autoboot($autoBoot);

            if ($Suite->autoInstance) {
               $Suite->autoinstance($Suite->autoInstance);
            }
            if ($Suite->autoSummarize) {
               $Suite->summarize();
            }

            return $Suite;
         }
      }
      finally {
         Display::$segments = $segments;
      }

      $Output = CLI->Terminal->Output;
      $Alert = new Alert($Output);
      $Alert->Type::Failure->set();
      $Alert->message = 'AutoBoot test not configured!';
      $Alert->render();

      $suite ??= 0;
      throw new LogicException("Test suite index {$suite} was not loaded or does not exist: {$suite_dir}");
   }

   private function locate (string $scope): null|string
   {
      $subject = ltrim($scope, '/') . '.php';

      $working = BOOTGLY_WORKING_DIR . $subject;
      if (is_file($working)) {
         $resolved = realpath($working);
         return $resolved !== false ? $resolved : $working;
      }

      $root = BOOTGLY_ROOT_DIR . $subject;
      if (is_file($root)) {
         $resolved = realpath($root);
         return $resolved !== false ? $resolved : $root;
      }

      return null;
   }

   // # Benchmark
   /**
    * Run a benchmark case.
    *
    * Usage: bootgly test benchmark <CASE> [--opponents=name1,name2] [--loads=label1,label2]
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function benchmark (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;
      $Alert = new Alert($Output);

      // ! Case name
      $caseName = $arguments[0] ?? null;
      // ! Help function
      $help = function (?string $caseName = null) use ($options) {
         // @ List available cases
         $benchDirs = [
            BOOTGLY_WORKING_DIR . 'benchmarks/',
            BOOTGLY_WORKING_DIR . '../bootgly_benchmarks/',
         ];
         $cases = [];
         $caseDir = null;
         foreach ($benchDirs as $dir) {
            if (is_dir($dir)) {
               foreach (scandir($dir) as $entry) {
                  if ($entry === '.' || $entry === '..')
                     continue;
                  if (is_dir("$dir$entry") && is_file("$dir$entry/@.php")) {
                     $cases[] = $entry;
                     if ($entry === $caseName) {
                        $caseDir = "$dir$entry";
                     }
                  }
               }
               break;
            }
         }
         $cases = array_unique($cases);
         sort($cases);

         $BOLD = self::wrap(self::_BOLD_STYLE);
         $WHITE = self::wrap(self::_WHITE_BOLD);
         $CYAN = self::wrap(self::_CYAN_FOREGROUND);
         $GREEN = self::wrap(self::_GREEN_FOREGROUND);
         $MAGENTA = self::wrap(self::_MAGENTA_FOREGROUND);
         $RESET = self::_RESET_FORMAT;

         echo "\n";
         if ($caseName !== null) {
            echo "{$BOLD}{$WHITE}  Bootgly Benchmarks — {$caseName}{$RESET}\n";
         } else {
            echo "{$BOLD}{$WHITE}  Bootgly Benchmarks{$RESET}\n";
         }
         echo "\n";
         echo "  {$BOLD}Usage:{$RESET}\n";
         if ($caseName !== null) {
            echo "    bootgly test benchmark {$MAGENTA}{$caseName} --opponents{$RESET}=bootgly {$MAGENTA}--loads{$RESET}=<set>:*\n";
         } else {
            echo "    bootgly test benchmark {$MAGENTA}<CASE> --opponents{$RESET}=bootgly {$MAGENTA}--loads{$RESET}=<set>:*\n";
         }
         echo "\n";

         // @ Available cases (only in generic help)
         if ($caseName === null) {
            echo "  {$BOLD}Available cases:{$RESET}\n";
            foreach ($cases as $case) {
               echo "    {$MAGENTA}{$case}{$RESET}\n";
            }
            echo "\n";
         }

         // @ Case options (common)
         echo "  {$BOLD}Case options:{$RESET}\n";
         echo "    {$MAGENTA}--opponents{$RESET}=LIST  Comma-separated opponent names ({$MAGENTA}*{$RESET} required)\n";
         echo "    {$CYAN}--runner{$RESET}=TYPE     Runner (default: case-dependent)\n";
         echo "    {$MAGENTA}--loads{$RESET}=SET:IDX   Load set + 1-based indices ({$MAGENTA}*{$RESET} required), e.g. techempower:1,2 or default:*\n";
         echo "    {$CYAN}--vary{$RESET}=PARAMS     Vary parameters across rounds (e.g. server-workers:2)\n";
         echo "\n";

         // @ Case-local options + Runner options (only in contextual help)
         if ($caseName !== null && $caseDir !== null) {
            // # Case-local options from options.php
            $optionsFile = "$caseDir/options.php";
            if (is_file($optionsFile)) {
               $caseOptions = include $optionsFile;
               if (is_array($caseOptions) && $caseOptions !== []) {
                  echo "  {$BOLD}{$caseName} options:{$RESET}\n";
                  foreach ($caseOptions as $flag => $desc) {
                     if ( ! is_string($desc) ) {
                        continue;
                     }

                     echo "    {$CYAN}{$flag}{$RESET}  {$desc}\n";
                  }
                  echo "\n";
               }
            }

            // # Runner options (load @.php to get Runner instance)
            $casePath = "$caseDir/@.php";
            $Configs = Configs::parse($options);
            if ($Configs->runner !== null) {
               putenv('BENCHMARK_RUNNER=' . $Configs->runner);
            }
            // ? Help only needs the Runner's options() — signal the case to skip
            //   its mandatory load-set validation (which would otherwise exit).
            putenv('BENCHMARK_HELP=1');
            $Runner = include $casePath;
            putenv('BENCHMARK_HELP');
            if ($Runner instanceof Runner) {
               $runnerOptions = $Runner->options();
               if ($runnerOptions !== []) {
                  $runnerName = $Runner->name ?: 'unknown';
                  echo "  {$BOLD}Runner options ({$runnerName}):{$RESET}\n";
                  foreach ($runnerOptions as $flag => $desc) {
                     echo "    {$CYAN}{$flag}{$RESET}  {$desc}\n";
                  }
                  echo "\n";
               }
            }
         }

         echo "  {$GREEN}--help{$RESET}, {$GREEN}-h{$RESET}          Show help\n";
         echo "\n";

         return false;
      };

      // ? Validate case name
      if ($caseName === null) {
         return $help();
      }

      // ? Help requested with case name
      if (isset($options['help']) || isset($options['h'])) {
         return $help($caseName);
      }

      // @ Resolve @.php path
      $casePath = BOOTGLY_WORKING_DIR . 'benchmarks/' . $caseName . '/@.php';
      // ? Fallback: check parent directory (bootgly_benchmarks/ sibling repo)
      if (!is_file($casePath)) {
         $parentPath = BOOTGLY_WORKING_DIR . '../bootgly_benchmarks/' . $caseName . '/@.php';
         if (is_file($parentPath)) {
            $casePath = $parentPath;
         }
      }
      if ( !is_file($casePath) ) {
         $Alert->Type::Failure->set();
         $Alert->message = "Benchmark case not found: {$casePath}";
         $Alert->render();
         return false;
      }
      // @ Parse options
      $Configs = Configs::parse($options);

      // ? Opponents are mandatory — at least one must be selected.
      if ($Configs->opponents === null) {
         $Alert->Type::Failure->set();
         $Alert->message = "Benchmark requires --opponents=<name>[,<name>...] (e.g. --opponents=bootgly).@.;";
         $Alert->render();
         return false;
      }

      // ? Load selection is mandatory and must use the `<set>:<indexes>` form
      //   (e.g. `techempower:1,2` or `benchmark:*`). Cases without multiple sets
      //   use the explicit `default` set (`--loads=default:*`).
      if ($Configs->loadSet === null) {
         $Alert->Type::Failure->set();
         $Alert->message = "Benchmark requires --loads=<set>:<indexes> (use <set>:* for all loads).@.;";
         $Alert->render();
         return false;
      }
      if ($Configs->loads === []) {
         $Alert->Type::Failure->set();
         $Alert->message = "No load indexes in --loads. Use --loads={$Configs->loadSet}:* or --loads={$Configs->loadSet}:1,2.";
         $Alert->render();
         return false;
      }

      // @ Expose the load set to the case @.php + opponents (mirrors BENCHMARK_RUNNER)
      putenv('BENCHMARK_LOAD_SET=' . $Configs->loadSet);

      // @ Set runner env var before loading @.php
      if ($Configs->runner !== null) {
         putenv('BENCHMARK_RUNNER=' . $Configs->runner);
      }
      // @ Load Runner from @.php
      $Runner = include $casePath;
      if ( !($Runner instanceof Runner) ) {
         $Alert->Type::Failure->set();
         $Alert->message = "Benchmark @.php must return a Runner instance.";
         $Alert->render();
         return false;
      }

      // @ Apply runner-specific CLI options
      $Runner->configure($options);

      // @ Banner
      $Info = Info::collect();
      Summary::banner($Info, $Runner, $Configs, $caseName);
      // @ Summary
      Summary::summary($Runner, $Configs);

      // @ Run benchmark
      $results = $Runner->run($Configs);

      // @ Report
      Summary::report($results, $Runner->metric);

      // @ Build run configuration metadata for the .marks header.
      //   Runner subclasses surface their own keys via $Runner->meta; the case
      //   file (e.g. HTTP_Server_CLI/@.php) adds case-level keys to the same
      //   bag. TestCommand only contributes runner-agnostic fields here.
      $config = [];

      if ($Runner->name !== '') {
         $config['runner'] = $Runner->name;
      }

      // # Server workers — applied uniformly to all opponents by --server-workers.
      $Opponents = $Runner->opponents;
      if (isset($Opponents[0]) && $Opponents[0]->workers !== null && $Opponents[0]->workers > 0) {
         $config['server-workers'] = $Opponents[0]->workers;
      }

      // @ Merge runner + case metadata last so they can override defaults.
      foreach ($Runner->meta as $metaKey => $metaValue) {
         $config[$metaKey] = $metaValue;
      }

      Summary::save($caseName, $results, $config);

      // @ Post-run message
      if ($Runner->postMessage !== '') {
         $DIM   = self::wrap(self::_DIM_STYLE);
         $RESET = self::_RESET_FORMAT;
         echo "\n  {$DIM}{$Runner->postMessage}{$RESET}\n";
      }

      return true;
   }
}
