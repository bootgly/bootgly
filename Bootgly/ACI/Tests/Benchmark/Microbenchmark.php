<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use const BOOTGLY_ROOT_DIR;
use const FILTER_VALIDATE_BOOL;
use const PHP_BINARY;
use const PHP_EOL;
use const PHP_FLOAT_MAX;
use const PHP_OS_FAMILY;
use const PHP_VERSION;
use function array_key_exists;
use function array_keys;
use function array_map;
use function basename;
use function date;
use function escapeshellarg;
use function exec;
use function explode;
use function filter_var;
use function file_get_contents;
use function function_exists;
use function glob;
use function hrtime;
use function is_array;
use function is_bool;
use function is_callable;
use function is_file;
use function is_float;
use function is_int;
use function json_decode;
use function min;
use function number_format;
use function opcache_get_status;
use function printf;
use function round;
use function sprintf;
use function str_repeat;
use function trim;
use Closure;

use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Comparison;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Results;


/**
 * A microbenchmark — the ns/op counterpart of the server Benchmark next door.
 *
 * A microbenchmark is not a test, and this API does not pretend to be one. A
 * test asserts one behaviour; a microbenchmark **compares N implementations of
 * the same operation over a controlled input and attaches a decision to the
 * result**. A case file declares one and returns it:
 *
 *    return new Microbenchmark(
 *       title: 'boundary — ->Last vs native',
 *       inputs: ['size' => 20],                       // knobs, overridable by the runner
 *       Gate: fn (array $inputs) => $mine === $native, // a faster wrong answer is not a result
 *       Comparisons: static function (array $inputs): array {
 *          $array = Arrays::build(Shapes::Map, $inputs['size']);   // scoped here, leaks nowhere
 *
 *          return [new Comparison(
 *             name: 'map of 20',
 *             Cases: ['native' => static fn () => ..., 'wrapped' => static fn () => ...],
 *             baseline: 'native',
 *             recommendation: 'native — the wrapper only adds dispatch',
 *          )];
 *       },
 *    );
 *
 * Fixtures built inside the Comparisons closure never leak into file scope and
 * never collide between comparisons, so `Cases` is unmistakably the measured
 * code and everything else is scaffolding.
 *
 * Measure on an idle machine, and store through sweep(): a single process is
 * not trustworthy — see Results::merge().
 *
 * @phpstan-import-type Payload from Results
 * @phpstan-import-type Section from Results
 */
class Microbenchmark
{
   // * Config
   public string $title;
   /**
    * Declared knobs with their defaults. The runner overrides them by name and
    * coerces to the default's type, so a sweep never means editing the file —
    * and the resolved values are stored with the results, because a
    * measurement without its input is ambiguous.
    *
    * @var array<string,mixed>
    */
   public array $inputs;
   /** @var Closure(array<string,mixed>):array<int,Comparison>|array<int,Comparison> */
   public Closure|array $Comparisons;
   /** Printed after the banner — the question this case answers. */
   public string $description;
   /** Printed after the tables — the conclusion that outlives one run. */
   public string $conclusion;
   /**
    * Ran with the resolved inputs before anything is measured. Returning false
    * aborts the case.
    *
    * @var null|Closure(array<string,mixed>):bool
    */
   public null|Closure $Gate;

   // * Data
   public static int $iterations = 200000;
   public static int $rounds = 5;
   public static int $warmup = 2000;

   // * Metadata
   private static float $floor;


   /**
    * @param Closure(array<string,mixed>):array<int,Comparison>|array<int,Comparison> $Comparisons
    * @param array<string,mixed> $inputs
    * @param null|Closure(array<string,mixed>):bool $Gate
    */
   public function __construct (
      string $title,
      Closure|array $Comparisons,
      array $inputs = [],
      string $description = '',
      string $conclusion = '',
      null|Closure $Gate = null
   ) {
      // * Config
      $this->title = $title;
      $this->Comparisons = $Comparisons;
      $this->inputs = $inputs;
      $this->description = $description;
      $this->conclusion = $conclusion;
      $this->Gate = $Gate;
   }

   /**
    * Measure every comparison in this process and return the stored payload.
    *
    * @param array<string,string> $overrides Raw `name => value` from the runner.
    * @return Payload
    */
   public function measure (string $case, array $overrides = []): array
   {
      $Runtime = self::identify();
      $inputs = $this->resolve($overrides);

      echo PHP_EOL;
      echo "\033[1m{$this->title}\033[0m", PHP_EOL;
      echo str_repeat('-', 78), PHP_EOL;
      printf(
         "PHP %s | opcache %s | JIT %s | best-of-%d x %s iterations%s",
         $Runtime['php'],
         $Runtime['opcache'],
         $Runtime['jit'],
         self::$rounds,
         number_format(self::$iterations),
         PHP_EOL
      );

      if ($inputs !== []) {
         echo 'inputs: ', Results::describe($inputs), PHP_EOL;
      }

      if ($this->description !== '') {
         echo PHP_EOL, self::indent($this->description), PHP_EOL;
      }

      // ? Correctness gate — a faster wrong answer is not a result
      if ($this->Gate !== null && ($this->Gate)($inputs) === false) {
         echo PHP_EOL, "  \033[31mABORTED — the correctness gate failed.\033[0m", PHP_EOL;

         exit(1);
      }

      // ---

      // @@
      $Comparisons = is_callable($this->Comparisons)
         ? ($this->Comparisons)($inputs)
         : $this->Comparisons;

      $sections = [];
      foreach ($Comparisons as $Comparison) {
         $Section = $Comparison->measure(self::calibrate(), self::$rounds, self::$warmup, self::$iterations);

         self::show($Section);

         $sections[] = $Section;
      }

      if ($this->conclusion !== '') {
         echo PHP_EOL, '  ', str_repeat('-', 66), PHP_EOL;
         echo self::indent($this->conclusion), PHP_EOL;
      }

      // :
      return [
         'case' => $case,
         'title' => $this->title,
         'measured' => date('c'),
         'runtime' => $Runtime,
         'inputs' => $inputs,
         'method' => [
            'iterations' => self::$iterations,
            'rounds' => self::$rounds,
            'warmup' => self::$warmup,
            'estimator' => 'best-of-rounds, closure floor subtracted',
            'floor_ns' => round(self::calibrate(), 2),
         ],
         'sections' => $sections,
      ];
   }

   /**
    * Merge runner overrides onto the declared defaults, coercing to the type
    * the default declares (so `--sizes=5,1000` becomes a list of ints).
    *
    * @param array<string,string> $overrides
    * @return array<string,mixed>
    */
   public function resolve (array $overrides): array
   {
      $inputs = $this->inputs;

      // @@
      foreach ($overrides as $name => $raw) {
         // ? An undeclared knob is a typo, not a feature
         if ( array_key_exists($name, $this->inputs) === false ) {
            echo "Unknown input '{$name}'. Declared: ", implode(', ', array_keys($this->inputs)), PHP_EOL;

            exit(1);
         }

         $default = $this->inputs[$name];

         $inputs[$name] = match (true) {
            is_array($default) => array_map(
               static fn (string $piece): mixed => is_int($default[0] ?? 0) ? (int) trim($piece) : trim($piece),
               explode(',', $raw)
            ),
            is_int($default) => (int) $raw,
            is_float($default) => (float) $raw,
            is_bool($default) => filter_var($raw, FILTER_VALIDATE_BOOL),
            default => $raw,
         };
      }

      // :
      return $inputs;
   }

   /**
    * One process: load a case file, measure it, store the result.
    *
    * @param array<string,string> $overrides
    * @return Payload
    */
   public static function sample (string $file, string $directory, array $overrides = []): array
   {
      // ? A case that does not declare a Micro cannot be measured
      $Micro = is_file($file) ? require $file : null;

      if ($Micro instanceof self === false) {
         echo "Not a microbenchmark case: {$file}", PHP_EOL;

         exit(1);
      }

      $case = basename($file, '.Microbenchmark.php');
      $Payload = $Micro->measure($case, $overrides);

      Results::save($directory, $Payload);

      // :
      return $Payload;
   }

   /**
    * Several processes: sample repeatedly and store the merged result.
    *
    * Fresh processes are the point — PHP's tracing JIT does not make the same
    * decisions on every run, so one process cannot produce a number worth
    * committing. See Results::merge().
    *
    * @param array<string,string> $overrides
    * @return null|Payload
    */
   public static function sweep (
      string $file,
      string $directory,
      int $processes = 5,
      array $overrides = []
   ): null|array {
      $forwarded = '';
      foreach ($overrides as $name => $value) {
         $forwarded .= ' ' . escapeshellarg("--{$name}={$value}");
      }

      $Payloads = [];

      // @@ Each repeat is a fresh process — that is the entire point
      for ($process = 1; $process <= $processes; $process++) {
         exec(
            sprintf(
               '%s -d opcache.enable_cli=1 %s test benchmark micro %s --once%s 2>&1',
               escapeshellarg(PHP_BINARY),
               escapeshellarg(BOOTGLY_ROOT_DIR . 'bootgly'),
               escapeshellarg($file),
               $forwarded
            ),
            $output,
            $status
         );
         $output = [];

         // ? A failed process contributes nothing rather than a wrong number
         if ($status !== 0) {
            continue;
         }

         // ! The child persists its own result — read the file, never parse
         //   stdout: the human table shares that stream
         $stored = glob("{$directory}/" . basename($file, '.Microbenchmark.php') . '.php-*.json') ?: [];

         if ($stored === []) {
            continue;
         }

         /** @var null|Payload $Payload */
         $Payload = json_decode((string) file_get_contents($stored[0]), true);

         if ( is_array($Payload) ) {
            $Payloads[] = $Payload;
         }
      }

      // ?
      if ($Payloads === []) {
         return null;
      }

      $Merged = Results::merge($Payloads);
      Results::save($directory, $Merged);

      // :
      return $Merged;
   }

   /**
    * The runtime identity these numbers belong to — results are only
    * comparable against the same one.
    *
    * @return array<string,string>
    */
   public static function identify (): array
   {
      $Status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

      return [
         'php' => PHP_VERSION,
         'opcache' => is_array($Status) && ($Status['opcache_enabled'] ?? false) ? 'on' : 'off',
         'jit' => is_array($Status) && ($Status['jit']['on'] ?? false) ? 'on' : 'off',
         'os' => PHP_OS_FAMILY,
      ];
   }

   /**
    * Calibrate (once) the cost of invoking an empty closure, so a row reports
    * the operation and not the harness invoking it.
    */
   public static function calibrate (): float
   {
      if ( isSet(self::$floor) ) {
         return self::$floor;
      }

      $Empty = static fn (): int => 0;

      $sink = 0;
      for ($i = 0; $i < self::$warmup; $i++) {
         $sink += $Empty();
      }

      $best = PHP_FLOAT_MAX;
      for ($round = 0; $round < self::$rounds; $round++) {
         $started = hrtime(true);

         for ($i = 0; $i < self::$iterations; $i++) {
            $sink += $Empty();
         }

         $best = min($best, (hrtime(true) - $started) / self::$iterations);
      }

      // :
      return self::$floor = $best;
   }

   /**
    * Print one measured section — used for a live run and for a merged result.
    *
    * @param array{
    *    section:string, baseline:string, fastest:string, recommendation:string,
    *    stable:bool, worst_spread:float, verdict:string,
    *    rows:array<int,array{label:string,ns:float,ratio:float,spread:float}>
    * } $Section
    */
   public static function show (array $Section): void
   {
      if ($Section['section'] !== '') {
         echo PHP_EOL, "  # {$Section['section']}", PHP_EOL;
      }

      echo PHP_EOL;
      foreach ($Section['rows'] as $Row) {
         $ratio = $Row['ratio'];

         $note = match (true) {
            $Row['label'] === $Section['baseline'] => '  (baseline)',
            $ratio < 0.98 => sprintf("  \033[32m%.0f%% faster\033[0m", (1 - $ratio) * 100),
            $ratio > 1.02 => sprintf("  \033[31m+%.0f%% cost\033[0m", ($ratio - 1) * 100),
            default => '  ~parity',
         };

         printf("  %-42s %9.1f ns/op  %6.2fx%s%s", $Row['label'], $Row['ns'], $ratio, $note, PHP_EOL);
      }

      // ? Say it out loud when the rounds disagreed
      if ($Section['stable'] === false) {
         printf(
            "%s  \033[33mUNSTABLE: slowest round was %.2fx the fastest — re-run on an idle machine.\033[0m%s",
            PHP_EOL,
            $Section['worst_spread'],
            PHP_EOL
         );
      }

      echo PHP_EOL, "  \033[1;36mUse:\033[0m {$Section['recommendation']}", PHP_EOL;

      if ($Section['verdict'] !== '') {
         echo "  \033[1mWhy:\033[0m {$Section['verdict']}", PHP_EOL;
      }
   }

   /**
    * Indent a prose block to the table gutter.
    */
   private static function indent (string $text): string
   {
      return '  ' . implode("\n  ", explode("\n", trim($text)));
   }
}
