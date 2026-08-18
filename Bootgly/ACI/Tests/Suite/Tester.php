<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Suite;


use const E_ALL;
use const PHP_EOL;
use const STR_PAD_BOTH;
use const STR_PAD_RIGHT;
use function array_reduce;
use function array_values;
use function count;
use function current;
use function error_reporting;
use function file_put_contents;
use function gettype;
use function is_a;
use function microtime;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use AssertionError;
use Closure;
use Generator;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use UnderflowException;

use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;


class Tester
{
   // * Data
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   public Suite $Suite;

   // * Config
   public Test $Test;
   // # Assertions
   /**
    * The descriptions of the Assertions.
    * @var array<string|null>
    */
   public array $descriptions = [];

   // * Data
   // # Assertions
   /**
    * The results of the Assertions.
    * @var array<bool|null>
    */
   protected array $results = [];

   // * Metadata
   private string $filename;
   // # Result
   private bool $passed;
   // # Output
   private false|string $debugged;
   // @ Profiling
   private float $started;
   private float $finished;
   private string $elapsed;
   // @ Reporting
   private null|AssertionError $AssertionError;
   // @ Retesting
   private null|bool $retested = null;


   /**
    * Test Case constructor.
    * 
    * @param Suite $Suite Test Suite instance
    * @param Test $Test Test case
    */
   public function __construct (Suite $Suite, Test $Test)
   {
      $this->Suite = $Suite;

      // * Config
      $this->Test = $Test;
      // ...inherited:
      $this->descriptions = [
         $this->Test->description ?? null
      ];

      // * Data
      // ...inherited:
      $this->results = [];

      // * Metadata
      // ! The name recorded on the case when the Suite resolved its file. The
      //   array pointer below is only the fallback for runners that index a
      //   Test themselves without a file (the WPI SAPI runner), and it is the
      //   very thing that made a targeted run report a passing spec's name for
      //   a failing case.
      $this->filename = $this->Test->file ?? (current($this->Suite->tests) ?: '');
      // # Output
      $this->debugged = false;
      // # Profiling
      $this->started = microtime(true);
      $this->finished = $this->started;
      $this->elapsed = Benchmark::format($this->started, $this->finished);
      // # Reporting
      $this->AssertionError = null;
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case "passed":
            $this->passed = array_reduce(
               array: $this->results,
               // ! `null` is the marker a SKIPPED assertion pushes — only
               //   `false` is a failure. Folding it with `&&` turned every
               //   skip into a failed case with no AssertionError behind it
               //   (a case reported red with a null message).
               callback: function ($accumulator, $assertion) {
                  return $accumulator && ($assertion ?? true);
               },
               initial: true
            );

            return $this->passed;
      };

      return null;
   }

   private function describe (null|string $description, ?bool $status, string $indicator = '╟'): void
   {
      if ($description === null) {
         return;
      }
      if (Results::$enabled || Suite::$quiet) {
         return;
      }

      // Indicator
      # ╚ • ╟ ─
      // Icon
      # ⮡ ↳➡️↪↪️ ✓✘ ✅❌
      $icon = match ($status) {
         // PASS
         true  => ' @#green:✓ @; ',
         // FAIL
         false => ' @#red:✘ @; ',
         // SKIP (null)
         default => ' @#yellow:─ @; ',
      };
      // Description
      $description = "@#white:{$description}@;";
      // Breakline
      $breakline = '@.;';

      $description = "{$indicator}{$icon}{$description}{$breakline}";

      $this->Logger->log(debug: $description);
   }
   private function describing (bool $status): void
   {
      $descriptions_count = count($this->descriptions);

      if (!$this->descriptions[0] || $descriptions_count === 1) return;

      $index = 1;
      foreach ($this->descriptions as $description) {
         if ($descriptions_count === 2 && $description === null && $index === 2)
            break;

         // description
         $description ??= 'Assertion @:info:#' . ($index - 1) . '@;';
         // status
         $status = (
            !$status && ($index === $descriptions_count || $index === 1)
         )
            ? false
            : true;
         $status = $index === 1 ? $status : $this->results[$index - 2];
         // indicator
         $indicator = match ($index) {
            1                   => '╟',
            $descriptions_count => '╚══',
            default             => '╟──',
         };

         $this->describe(
            $description,
            $status,
            $indicator
         );

         $index++;
      }
   }
   public function separate (): void
   {
      if (Results::$enabled || Suite::$quiet) {
         return;
      }

      static $separatorLength;

      $line   = $this->Test->Separator->line   ?? null;
      $left   = $this->Test->Separator->left   ?? null;
      $header = $this->Test->Separator->header ?? null;

      $width = Suite::$width + 30;

      if ($line) {
         if ($line !== true) {
            $separatorLength = strlen($line);
            $line = "@:i: {$line}  @;";

            // Text + `-`
            $line = str_pad($line, $width, '-', STR_PAD_BOTH);
         }
         else {
            $line = str_repeat('-', $width - 7);
         }

         $this->Logger->log(debug: "{$line} @.;");
      }

      if ($left) {
         $this->Logger->log(debug: "@.;            \033[3;90m{$left}:\033[0m @.;");
      }

      if ($header) {
         $header = '\\' .str_pad($header, $separatorLength ?? 0, ' ', STR_PAD_BOTH) . '/';
         $header = str_pad($header, $width - 7, ' ', STR_PAD_BOTH);

         $this->Logger->log(debug: "@#white:{$header} @;@.;");
      }
   }

   // # Test Case
   /**
    * Pretest the Test Case.
    *
    * @return bool
    */
   private function pretest (): bool
   {
      $retested = $this->retested ?? null;

      // @ Skip without output (used to skip with command arguments)
      if ($this->Test->ignore ?? false) {
         $this->Suite->skipped++;
         return false;
      }

      if ($retested !== true) {
         $this->separate();
      }

      // @ Fixture lifecycle — idempotent: no-op if already Ready (e.g. when
      //   a domain runner like WPI HTTP_Server_CLI prepared the fixture
      //   before sending the request).
      $this->Test->Fixture?->prepare();

      return true;
   }
   /**
    * Run the Test Case.
    * 
    * @param mixed ...$arguments
    *
    * @return void
    */
   public function test (mixed ...$arguments): void
   {
      $arguments = array_values($arguments);

      if ($this->pretest() === false) {
         $this->postest();
         return;
      }

      // ---

      // !
      $test = $this->Test->test;
      $retest = $this->Test->retest ?? null;
      $runtimeArguments = $arguments;

      // ! The runner owns the diagnostic environment of a case instead of
      //   inheriting the host's php.ini mask, which makes the suite blind to
      //   whatever that mask hides — on a stock php.ini that is E_DEPRECATED,
      //   exactly the class a framework must see before a PHP upgrade. ABI's
      //   handler is registered at E_ALL but short-circuits on
      //   `error_reporting() & $level`, so raising it here is what makes it fire.
      $reporting = error_reporting(E_ALL);

      try {
         ob_start();

         if ($test instanceof Assertions) {
            $test->Fixture ??= $this->Test->Fixture;
            $Assertions = $test->run(...$runtimeArguments);
         }
         else {
            $runtimeArguments = $this->inject($runtimeArguments, $test);
            $Assertions = $test(...$runtimeArguments);
         }

         $this->debugged = ob_get_clean();

         if ($Assertions instanceof Generator !== true) {
            $message = 'The assertion must return boolean, string, NULL, Assertion or a Generator!';

            $Assertions = match (gettype($Assertions)) {
               'boolean', 'string' => [$Assertions],
               // ! `A || B ?: throw` evaluates to the BOOLEAN of the check, and
               //   the elvis then keeps that `true` — so the object was thrown
               //   away and `foreach (true)` iterated nothing. A returned
               //   Assertion (a `->skip()`, typically) recorded no result at
               //   all and the case reported green with zero assertions.
               'object' =>
                  $Assertions instanceof Assertion
                  || $Assertions instanceof Assertions
                     ? [$Assertions]
                     : throw new AssertionError($message),
               'NULL' => [null],
               default => throw new AssertionError($message)
            };
         }

         /** @var Assertion|bool|string|null $Assertion */
         foreach ($Assertions as $Assertion) { // @phpstan-ignore-line
            if ($Assertion === null) { // ignore
               throw new UnderflowException;
            }

            // Assertion instance
            if ($Assertion instanceof Assertion) {
               if ($Assertion->skipped) { // skip
                  $this->descriptions[] = $Assertion::$description;
                  $this->results[] = null;
                  continue;
               }

               $Assertion->asserted ?:
                  throw new AssertionError(
                     message: 'Using the `->assert(...)` method is mandatory before returning `new Assertion`!'
                  );
   
               $this->descriptions[] = $Assertion::$description;
            }
            // $Assertion is FALSE or a string (Test failed!)
            else if ($Assertion === false || $Assertion !== true) {
               throw new AssertionError(
                  message: Assertion::$fallback ?? ($Assertion ?: 'Test failed!')
               );
            }
            // $Assertion is TRUE (Test passed!)
            else {
               $this->descriptions[] = Assertion::$description;
               Assertion::$description = null;
            }
   
            $this->results[] = true;
         }

         $this->postest();

         // ---
         if ($this->Suite->autoReport) {
            $this->pass();
         }
      }
      catch (AssertionError $AssertionError) {
         @ob_end_clean();

         $this->postest();

         // ---
         $this->AssertionError = $AssertionError;

         $this->descriptions[] = Assertion::$description;
         $this->results[] = false;

         if ($this->Suite->autoReport) {
            $this->fail($AssertionError->getMessage());
         }
      }
      catch (UnderflowException $Exception) {
         // @ ignore — but still run postest() so the fixture lifecycle
         //   completes even when an assertion yielded null.
         $this->postest();
      }
      catch (Throwable $Throwable) {
         @ob_end_clean();

         $this->postest();

         // ? A crashed Case is a failed test, not a runner abort: rethrowing
         //   would reach the global exception handler, which exits the
         //   process silently under suppressed display — no report, no
         //   AI_AGENT JSON, exit 1 (observed on single-case runs). The
         //   fixture was already disposed by `postest()` above.
         $retest = null;

         $origin = $Throwable::class;
         $message = "{$origin}: {$Throwable->getMessage()} in {$Throwable->getFile()}:{$Throwable->getLine()}";
         $this->AssertionError = new AssertionError(message: $message);

         $this->descriptions[] = Assertion::$description;
         $this->results[] = false;

         if ($this->Suite->autoReport) {
            $this->fail($message);
         }
      }
      finally {
         error_reporting($reporting);

         if ($retest) {
            $this->Test->test = $retest;
            $this->Test->retest = null;
            $this->retested = true;

            $passed = $this->__get('passed');
            $arguments = [$test, $passed, ...$arguments];

            $this->test(...$arguments);
         }
      }
   }

   /**
    * @param array<int,mixed> $arguments
    *
    * @return array<int,mixed>
    */
   private function inject (array $arguments, Closure $Closure): array
   {
      $Fixture = $this->Test->Fixture;
      if ($Fixture === null) {
         return $arguments;
      }

      $Function = new ReflectionFunction($Closure);
      $parameters = $Function->getParameters();
      $Parameter = $parameters[count($arguments)] ?? null;

      if ($Parameter === null) {
         foreach ($parameters as $Candidate) {
            if ($Candidate->isVariadic()) {
               $Parameter = $Candidate;
               break;
            }
         }
      }

      if ($Parameter === null) {
         return $arguments;
      }

      if ($this->check($Parameter->getType(), $Fixture) === false) {
         return $arguments;
      }

      $arguments[] = $Fixture;

      return $arguments;
   }

   /**
    * Check if a reflected parameter type accepts the active Fixture.
    */
   private function check (null|ReflectionType $Type, Fixture $Fixture): bool
   {
      if ($Type === null) {
         return true;
      }

      if ($Type instanceof ReflectionUnionType) {
         foreach ($Type->getTypes() as $Inner) {
            if ($this->check($Inner, $Fixture)) {
               return true;
            }
         }

         return false;
      }

      if ($Type instanceof ReflectionIntersectionType) {
         foreach ($Type->getTypes() as $Inner) {
            if ($this->check($Inner, $Fixture) === false) {
               return false;
            }
         }

         return true;
      }

      if ($Type instanceof ReflectionNamedType) {
         $name = $Type->getName();
         if ($name === 'mixed' || $name === 'object') {
            return true;
         }
         if ($Type->isBuiltin()) {
            return false;
         }

         return is_a($Fixture, $name);
      }

      return false;
   }

   /**
    * Postest the Test Case.
    * 
    * @return void
    */
   private function postest (): void
   {
      #$this->debugged ??= ob_get_clean();

      $this->finished = microtime(true);

      $this->elapsed = Benchmark::format($this->started, $this->finished);

      // @ Fixture lifecycle — idempotent: no-op if already Disposed
      //   (when a domain runner already disposed before reaching here).
      $this->Test->Fixture?->dispose();
   }

   // @ Reporting
   public function fail (null|string $message = null): void
   {
      $this->Suite->failed++;

      $case = sprintf('%03d', $this->Test->case);
      $test = str_pad("{$this->filename}:", Suite::$width, ' ', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;

      // @ output
      $help = $message ?? $this->AssertionError?->getMessage();

      // @ Record the case for runner views
      // ? The failing assertion was already pushed to results/descriptions:
      //   descriptions[0] is the Test description, assertion i lives
      //   at descriptions[i] — the failure is assertion #count(results).
      $this->Suite->records[] = [
         'case' => $this->Test->case ?? 0,
         'file' => $this->filename,
         'status' => 'failed',
         'results' => $this->results,
         'description' => $this->descriptions[count($this->results)] ?? null,
         'message' => $help,
         'debug' => $this->debugged ?: null,
         'elapsed' => $this->elapsed,
      ];
      if (Suite::$Observer !== null) {
         (Suite::$Observer)($this->Suite);
      }

      if (Results::$enabled === false && Suite::$quiet === false) {
         // header
         $this->Logger->log(debug: 
            "\033[30m\033[47m " . $case . " \033[0m" .
            "\033[0;30;41m FAIL \033[0m " .
            "@@:" . $test . " @;" .
            "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
         );
         // assertions
         $this->describing(status: false);
         // fallback
         if ($help) {
            $this->Logger->log(debug: 
               " ↪️\033[91m" . $help . "\033[0m" .
               PHP_EOL . PHP_EOL
            );
         }

         // # Debugging
         $this->Logger->log(debug: $this->debugged ?: '');
      }

      // @ Record result for AI agent output
      Results::record(
         suite: $this->Suite->name,
         case: $this->Test->case ?? 0,
         file: $this->filename,
         status: 'failed',
         message: $help,
         elapsedMs: ($this->finished - $this->started) * 1000
      );

      // @ exit
      // ? Quiet runs feed a runner view (dashboard) — they must survive the
      //   whole run, even when a mid-suite `new Suite` re-enables the static.
      if (Suite::$exitOnFailure && Suite::$quiet === false && $this->Test->retest === null) {
         $this->Suite->summarize();

         if (Results::$enabled) {
            file_put_contents('php://stdout', Results::toJSON());
         }

         exit(1);
      }
   }
   public function pass (): void
   {
      $this->Suite->passed++;

      $case = sprintf('%03d', $this->Test->case);
      $test = str_pad($this->filename, Suite::$width, ' ', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;

      // @ Record the case for runner views
      $this->Suite->records[] = [
         'case' => $this->Test->case ?? 0,
         'file' => $this->filename,
         'status' => 'passed',
         'results' => $this->results,
         'description' => null,
         'message' => null,
         'debug' => null,
         'elapsed' => $elapsed,
      ];
      if (Suite::$Observer !== null) {
         (Suite::$Observer)($this->Suite);
      }

      // @ output
      if (Results::$enabled === false && Suite::$quiet === false) {
         // header
         $this->Logger->log(debug: 
            "\033[47;30m " . $case . " \033[0m" .
            "\033[0;30;42m PASS \033[0m " .
            "\033[90m" . $test . "\033[0m" .
            "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
         );
         // assertions
         $this->describing(status: true);
      }

      // @ Record result for AI agent output
      Results::record(
         suite: $this->Suite->name,
         case: $this->Test->case ?? 0,
         file: $this->filename,
         status: 'passed',
         elapsedMs: ($this->finished - $this->started) * 1000
      );
   }
}
