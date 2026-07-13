<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_VERSION;
use const BOOTGLY_WORKING_DIR;
use const PHP_EOL;
use const STDERR;
use const STR_PAD_LEFT;
use function array_intersect_key;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function fwrite;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function ltrim;
use function ob_end_clean;
use function ob_start;
use function preg_match;
use function preg_replace;
use function putenv;
use function realpath;
use function rtrim;
use function scandir;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function strtolower;
use function substr;
use Closure;
use LogicException;
use Throwable;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use const Bootgly\CLI;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;
use Bootgly\ACI\Tests\Benchmark\Info;
use Bootgly\ACI\Tests\Benchmark\Provenance;
use Bootgly\ACI\Tests\Benchmark\Report;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Benchmark\Summary;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Nothing;
use Bootgly\ACI\Tests\Coverage\Drivers\PCOV;
use Bootgly\ACI\Tests\Coverage\Drivers\XDebug;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Environment;
use Bootgly\API\Environment\Agent;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Charts\Bars;


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
      $bootstrap_file = str_replace('\\', '/', $suite_dir . ($hasTests ? '' : '/tests') . '/' . BOOTSTRAP_FILENAME);
      $bootstrap_file = preg_replace('#/{2,}#', '/', $bootstrap_file) ?? $bootstrap_file;
      $bootstrap = BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR
         ? BOOTGLY_WORKING_DIR . $bootstrap_file
         : BOOTGLY_ROOT_DIR . $bootstrap_file;

      if (is_file($bootstrap) === false) {
         $suite ??= 0;
         throw new LogicException("Test suite index {$suite} was not loaded or does not exist: {$suite_dir}");
      }

      // ? Trace each suite boundary to STDERR (immune to Display muting) — set
      //   BOOTGLY_TEST_TRACE=1 to locate a stall in otherwise-silent suites.
      if (Environment::get('BOOTGLY_TEST_TRACE')) {
         fwrite(STDERR, "[test-trace] suite {$suite}: {$suite_dir}" . PHP_EOL);
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
                  if (is_dir("$dir$entry") && is_file("$dir$entry/" . BOOTSTRAP_FILENAME)) {
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

         // @ Global options (common to every case) — required first, then optional
         echo "  {$BOLD}Global options:{$RESET}\n";
         echo "    {$MAGENTA}--opponents{$RESET}=LIST  Comma-separated opponent names ({$MAGENTA}*{$RESET} required)\n";
         echo "    {$MAGENTA}--loads{$RESET}=SET:IDX   Load set + 1-based indices ({$MAGENTA}*{$RESET} required), e.g. techempower:1,2 or default:*\n";
         echo "    {$CYAN}--runner{$RESET}=TYPE     Runner (default: case-dependent)\n";
         echo "    {$CYAN}--output{$RESET}=STYLE    Output style: full | compact (default: auto — compact when sweeping)\n";
         echo "    {$CYAN}--format{$RESET}=FORMAT   Results serialization: text | json (default: text)\n";
         echo "                      json: prints only the JSON document (human output suppressed)\n";
         echo "    {$CYAN}--results{$RESET}=LEVEL   Generated artifacts: marks | report | charts (default: marks)\n";
         echo "\n";

         // @ Case-local options + Runner options (only in contextual help)
         if ($caseName !== null && $caseDir !== null) {
            // # Case-local options from the options.php schema
            try {
               $caseOptions = Options::load("$caseDir/options.php")->render();

               if ($caseOptions !== []) {
                  echo "  {$BOLD}Case options - {$caseName}:{$RESET}\n";
                  foreach ($caseOptions as $flag => $desc) {
                     echo "    {$CYAN}{$flag}{$RESET}  {$desc}\n";
                  }
                  echo "\n";
               }
            }
            catch (Throwable $Throwable) {
               $DIM = self::wrap(self::_DIM_STYLE);
               echo "  {$DIM}Invalid {$caseName} options schema: {$Throwable->getMessage()}{$RESET}\n\n";
            }

            // # Runner options (load autoboot.php to get Runner instance)
            $casePath = "$caseDir/" . BOOTSTRAP_FILENAME;
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
                  echo "  {$BOLD}Runner options - {$runnerName}:{$RESET}\n";
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

      // @ Resolve autoboot.php path
      $casePath = BOOTGLY_WORKING_DIR . 'benchmarks/' . $caseName . '/' . BOOTSTRAP_FILENAME;
      // ? Fallback: check parent directory (bootgly_benchmarks/ sibling repo)
      if (!is_file($casePath)) {
         $parentPath = BOOTGLY_WORKING_DIR . '../bootgly_benchmarks/' . $caseName . '/' . BOOTSTRAP_FILENAME;
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

      // # Capture source state before loading or running the benchmark case.
      //   Git-backed local checkouts are inspected directly; packaged sources
      //   can supply the documented BOOTGLY_* provenance environment fallbacks.
      $provenance = [
         'framework-version' => BOOTGLY_VERSION,
         ...Provenance::collect(BOOTGLY_ROOT_DIR, dirname($casePath)),
      ];

      // @ Parse options
      $Configs = Configs::parse($options);

      // ? --vary was replaced by sweep values on the case options themselves
      if (isset($options['vary'])) {
         $Alert->Type::Failure->set();
         $Alert->message = "--vary was removed — pass sweep values directly, e.g. --server-workers=1..24:4.";
         $Alert->render();
         return false;
      }
      // ? Global output/format/results enums
      if ($Configs->output !== null && in_array($Configs->output, ['full', 'compact'], true) === false) {
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid --output '{$Configs->output}'. Use: full | compact.";
         $Alert->render();
         return false;
      }
      if (in_array($Configs->format, ['text', 'json'], true) === false) {
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid --format '{$Configs->format}'. Use: text | json.";
         $Alert->render();
         return false;
      }
      if (in_array($Configs->results, ['marks', 'report', 'charts'], true) === false) {
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid --results '{$Configs->results}'. Use: marks | report | charts.";
         $Alert->render();
         return false;
      }

      // # Machine mode — STDOUT carries only the JSON document; every
      //   human-readable byte from here on (banner, progress, tables) is
      //   discarded so terminals, pipes and AI agents get pure JSON
      if ($Configs->format === 'json') {
         ob_start(static fn (string $chunk): string => '', 1);
      }

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

      // @ Resolve case options against the options.php schema (sweep expansion)
      try {
         $Options = Options::load(dirname($casePath) . '/options.php');
         $Options->parse($options);
      }
      catch (Throwable $Throwable) {
         $Alert->Type::Failure->set();
         $Alert->message = $Throwable->getMessage();
         $Alert->render();
         return false;
      }

      // @ Expose the load set to the case autoboot.php + opponents (mirrors BENCHMARK_RUNNER)
      putenv('BENCHMARK_LOAD_SET=' . $Configs->loadSet);

      // @ Surface the serialization format so case files can silence diagnostics
      putenv('BENCHMARK_FORMAT=' . $Configs->format);

      // @ Set runner env var before loading autoboot.php
      if ($Configs->runner !== null) {
         putenv('BENCHMARK_RUNNER=' . $Configs->runner);
      }
      // @ Load Runner from autoboot.php
      $Runner = include $casePath;
      if ( !($Runner instanceof Runner) ) {
         $Alert->Type::Failure->set();
         $Alert->message = "Benchmark autoboot.php must return a Runner instance.";
         $Alert->render();
         return false;
      }

      // @ Apply runner-specific CLI options
      $Runner->configure($options);

      // ! Sweep state
      $rounds = $Options->rounds;
      $total = count($rounds);
      $sweeping = $total > 1;
      $style = $Configs->output ?? ($sweeping ? 'compact' : 'full');

      // @ Banner + summary — once per run, regardless of rounds
      $Info = Info::collect();
      Summary::banner($Info, $Runner, $Configs, $caseName);
      Summary::summary($Runner, $Configs);
      if ($sweeping) {
         Summary::sweep($Options->sweeps, $total);
      }

      // @@ Execution rounds — one benchmark run per resolved value map
      $exports = [];
      foreach ($rounds as $index => $round) {
         // @ Apply case option values (framework-known keys, e.g. server-workers)
         $Runner->apply($round);

         if ($sweeping) {
            Summary::open(array_intersect_key($round, $Options->sweeps), $index + 1, $total);
         }

         $results = $Runner->run($Configs);

         Summary::report($results, $Runner->metric, compact: $style === 'compact');

         // # Build run configuration metadata for the .marks header.
         //   Runner subclasses surface their own keys via $Runner->meta; the case
         //   file (e.g. HTTP_Server_CLI/autoboot.php) adds case-level keys to the same
         //   bag. TestCommand only contributes runner-agnostic fields here.
         $config = [];

         if ($Runner->name !== '') {
            $config['runner'] = $Runner->name;
         }

         $Opponents = $Runner->opponents;
         if (isset($Opponents[0]) && $Opponents[0]->workers !== null && $Opponents[0]->workers > 0) {
            $config['server-workers'] = $Opponents[0]->workers;
         }

         foreach ($Runner->meta as $metaKey => $metaValue) {
            $config[$metaKey] = $metaValue;
         }

         // ! Provenance is runner-agnostic and authoritative: a case cannot
         //   accidentally shadow the source tree that actually produced a run.
         foreach ($provenance as $metaKey => $metaValue) {
            $config[$metaKey] = $metaValue;
         }

         // # One .marks per round — the rNN suffix disambiguates same-second saves
         $suffix = $sweeping ? 'r' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) : '';
         $marks = Summary::save($caseName, $results, $config, $suffix);

         $exports[] = ['options' => $round, 'results' => $results, 'marks' => $marks, 'config' => $config];
      }

      // @ --results=report|charts — generate the Markdown report (+ SVG charts)
      $generated = [];
      if ($Configs->results !== 'marks') {
         $run = self::extract($caseName, $Info, $Runner, $Configs, $Options, $exports, $options);
         $resultsDir = BOOTGLY_STORAGE_DIR . "tests/benchmarks/{$caseName}/results";

         $Report = new Report(charts: $Configs->results === 'charts');
         foreach ($Report->save($resultsDir, $run) as $file) {
            $generated[] = "storage/tests/benchmarks/{$caseName}/results/{$file}";
         }
      }

      // @ Serialize the whole run as the ONLY stdout document (machine mode)
      if ($Configs->format === 'json') {
         // ! Swept keys live in `sweep`/per-round `options` — not in the shared config
         $config = $exports !== [] ? $exports[0]['config'] : [];
         foreach (array_keys($Options->sweeps) as $swept) {
            unset($config[$swept]);
         }

         // ? Release the suppression — the JSON document must reach STDOUT
         ob_end_clean();

         echo Summary::export($caseName, $Runner->metric, $config, $Options->sweeps, $exports, $generated);

         // ! Re-arm the suppression: late shutdown output must not trail the
         //   JSON document on STDOUT
         ob_start(static fn (string $chunk): string => '', 1);

         return true;
      }

      // @ Artifacts footer — full AND compact styles always point at the files
      $marks = [];
      foreach ($exports as $export) {
         $marks[] = $export['marks'];
      }
      Summary::locate($marks, $generated);

      // @ ANSI chart — opponents × mean throughput of the last round (server runs)
      if ($exports !== []) {
         $series = [];
         foreach ($exports[array_key_last($exports)]['results'] as $opponent => $loads) {
            $sum = 0.0;
            $counted = 0;
            foreach ($loads as $Result) {
               if ($Result->rps !== null) {
                  $sum += $Result->rps;
                  $counted++;
               }
            }

            if ($counted > 0) {
               $series[(string) $opponent] = $sum / $counted;
            }
         }

         if (count($series) > 1) {
            echo "\n";

            $Bars = new Bars(CLI->Terminal->Output);
            $Bars->precision = 0;
            $Bars->series = $series;
            $Bars->render();
         }
      }

      // @ Post-run message
      if ($Runner->postMessage !== '') {
         $DIM   = self::wrap(self::_DIM_STYLE);
         $RESET = self::_RESET_FORMAT;
         echo "\n  {$DIM}{$Runner->postMessage}{$RESET}\n";
      }

      return true;
   }

   /**
    * Extract the scalar run structure consumed by Report from the round exports.
    *
    * @param array<int,array{options:array<string,scalar>,results:array<string,array<string,\Bootgly\ACI\Tests\Benchmark\Result>>,marks:string,config:array<string,scalar|array<int,scalar>>}> $exports
    * @param array<string,bool|int|string> $options
    *
    * @return array{
    *    case: string, loadSet: string, metric: string, command: string,
    *    env: array<string,string>, config: array<string,scalar>,
    *    sweep: array<string,array<int,int>>, loads: array<int,string>,
    *    opponents: array<int,string>,
    *    data: array<string,array<string,array<int,null|float>>>,
    *    latencies: array<string,array<string,array<int,null|float>>>,
    *    marks: array<int,string>
    * }
    */
   private static function extract (
      string $caseName,
      Info $Info,
      Runner $Runner,
      Configs $Configs,
      Options $Options,
      array $exports,
      array $options
   ): array
   {
      // ! Opponent + load label unions (insertion order across rounds)
      $opponents = [];
      $loads = [];
      foreach ($exports as $export) {
         foreach ($export['results'] as $opponent => $labels) {
            if (in_array($opponent, $opponents, true) === false) {
               $opponents[] = $opponent;
            }
            foreach (array_keys($labels) as $label) {
               if (in_array($label, $loads, true) === false) {
                  $loads[] = $label;
               }
            }
         }
      }

      // ! Series — load => opponent => value per round
      $data = [];
      $latencies = [];
      foreach ($exports as $index => $export) {
         foreach ($loads as $label) {
            foreach ($opponents as $opponent) {
               $Result = $export['results'][$opponent][$label] ?? null;

               $data[$label][$opponent][$index] = $Result?->rps;

               // # Latency strings ("458.55us", "2.97ms", "1.2s") → milliseconds
               $latency = null;
               if ($Result?->latency !== null && preg_match('/^([\d.,]+)(µs|us|ms|s)$/', $Result->latency, $matches) === 1) {
                  $value = (float) str_replace(',', '', $matches[1]);
                  $latency = match ($matches[2]) {
                     'us', 'µs' => $value / 1000,
                     's' => $value * 1000,
                     default => $value,
                  };
               }
               $latencies[$label][$opponent][$index] = $latency;
            }
         }
      }

      // ! Reproduction command (from the received CLI options)
      $command = "bootgly test benchmark {$caseName}";
      foreach ($options as $name => $value) {
         $command .= $value === true ? " --{$name}" : " --{$name}={$value}";
      }

      // ! Non-swept config from the first round's .marks metadata
      $config = [];
      foreach ($exports !== [] ? $exports[0]['config'] : [] as $key => $value) {
         if (isset($Options->sweeps[$key]) || is_array($value)) {
            continue;
         }
         $config[$key] = $value;
      }

      // : Scalar run structure
      return [
         'case' => $caseName,
         'loadSet' => (string) $Configs->loadSet,
         'metric' => $Runner->metric,
         'command' => $command,
         'env' => [
            'OS' => $Info->os,
            'CPU' => "{$Info->cpuModel} ({$Info->cpuCount} cores)",
            'RAM' => $Info->ram,
         ],
         'config' => $config,
         'sweep' => $Options->sweeps,
         'loads' => $loads,
         'opponents' => $opponents,
         'data' => $data,
         'latencies' => $latencies,
         'marks' => array_values(array_map(static fn (array $export): string => $export['marks'], $exports)),
      ];
   }
}
