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
use function array_slice;
use function array_unique;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function putenv;
use function scandir;
use function sort;
use function str_contains;
use function str_ends_with;
use Closure;

use const Bootgly\CLI;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Info;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Benchmark\Summary;
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
      $Agent = Agent::detect();
      if ($Agent->detected) {
         Logger::$display = Logger::DISPLAY_NONE;
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
      // ...


      // @
      $Tests = new Tests;
      // $Tester = new Tester;
      $Tests->Suites->iterate(
         suite: $suite_index,
         case: $case_index,
         iterator: fn (string $suite_dir, int $index) => $this->test($suite_dir, $index)
      );
      $Tests->Suites->summarize();

      // @ JSON output for AI agents
      if (Results::$enabled) {
         echo Results::toJSON();
      }

      return $Tests->Suites->failed === 0;
   }

   // # Test Suite
   public function test (string $suite_dir, null|int $index): null|true|Suite
   {
      // !
      $hasTests = str_contains($suite_dir, '/tests/') || str_ends_with($suite_dir, '/tests');
      $bootstrap_file = Path::normalize($suite_dir . ($hasTests ? '' : '/tests') . '/@.php');
      BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR
         ? $Suite = (include BOOTGLY_WORKING_DIR . $bootstrap_file)
         : $Suite = (include BOOTGLY_ROOT_DIR . $bootstrap_file);
      // ?
      if ($Suite instanceof Suite === false) {
         return null;
      }

      // ?!
      // * Config
      if ($index) {
         $Suite->target = $index;
      }

      // @
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

      $Alert = new Alert(CLI->Terminal->Output);
      $Alert->Type::Failure->set();
      $Alert->message = 'AutoBoot test not configured!';
      $Alert->render();

      return null;
   }

   // # Benchmark
   /**
    * Run a benchmark case.
    *
    * Usage: bootgly test benchmark <CASE> [--competitors=name1,name2] [--scenarios=label1,label2]
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
            echo "    bootgly test benchmark {$MAGENTA}{$caseName} --competitors{$RESET}=bootgly\n";
         } else {
            echo "    bootgly test benchmark {$MAGENTA}<CASE> --competitors{$RESET}=bootgly\n";
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
         echo "    {$MAGENTA}--competitors{$RESET}=LIST  Comma-separated competitor names ({$MAGENTA}*{$RESET} required)\n";
         echo "    {$CYAN}--runner{$RESET}=TYPE       Runner (default: case-dependent)\n";
         echo "    {$CYAN}--scenarios{$RESET}=LIST    Comma-separated scenario numbers (default: all)\n";
         echo "    {$CYAN}--vary{$RESET}=PARAMS       Vary parameters across rounds (e.g. server-workers:2)\n";
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
            $Runner = include $casePath;
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

         echo "  {$GREEN}--help{$RESET}, {$GREEN}-h{$RESET}            Show help\n";
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
      Summary::save($caseName, $results);

      // @ Post-run message
      if ($Runner->postMessage !== '') {
         $DIM   = self::wrap(self::_DIM_STYLE);
         $RESET = self::_RESET_FORMAT;
         echo "\n  {$DIM}{$Runner->postMessage}{$RESET}\n";
      }

      return true;
   }
}
