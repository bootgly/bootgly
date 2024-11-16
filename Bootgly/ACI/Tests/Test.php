<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use function array_reduce;
use function count;
use function current;
use function microtime;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use AssertionError;
use Exception;
use Generator;

use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Cases\Assertion;


class Test extends Assertions
{
   use LoggableEscaped;


   public Tests $Tests;

   // * Config
   /** @var array<string,mixed> */
   public array $specifications;
   // ...inherited: descriptions

   // * Data
   // ...inherited: results

   // * Metadata
   private bool $passed;
   private mixed $filename;
   // @ Output
   private false|string $debugged;
   // @ Profiling
   private float $started;
   private float $finished;
   private string $elapsed;
   // @ Reporting
   private ?AssertionError $AssertionError;


   /**
    * Test constructor.
    * 
    * @param Tests&Tester $Tests Test cases
    * @param array<string,mixed> $specifications Test cases specifications
    */
   public function __construct (Tests&Tester $Tests, array $specifications)
   {
      $this->Tests = $Tests;

      // * Config
      $this->specifications = $specifications;
      // ...inherited:
      $this->descriptions = [
         $specifications['describe'] ?? null
      ];

      // * Data
      // ...inherited:
      $this->results = [];

      // * Metadata
      $this->filename = current($this->Tests->tests); // @ file
      // @ Output
      $this->debugged = false;
      // @ Profiling
      $this->started = microtime(true);
      // @ Reporting
      $this->AssertionError = null;
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case "passed":
            $this->passed = array_reduce(
               array: $this->results,
               callback: function ($accumulator, $assertion) {
                  return $accumulator && $assertion;
               },
               initial: true
            );

            return $this->passed;
      };

      return null;
   }

   private function describe (?string $description, ?bool $status, string $indicator = '╟'): void
   {
      if ($description === null) {
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

      $this->log($description);
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

      if (isSet($this->specifications['last']) === false) {
         $this->log(PHP_EOL);
      }
   }
   public function separate (): void
   {
      static $separatorLength;

      $line   = $this->specifications['separator.line']   ?? null;
      $left   = $this->specifications['separator.left']   ?? null;
      $header = $this->specifications['separator.header'] ?? null;

      $width = Tests::$width + 30;

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

         $this->log("{$line} @.;");
      }

      if ($left) {
         $this->log("@.;            \033[3;90m{$left}:\033[0m @.;");
      }

      if ($header) {
         $header = '\\' .str_pad($header, $separatorLength ?? 0, ' ', STR_PAD_BOTH) . '/';
         $header = str_pad($header, $width - 7, ' ', STR_PAD_BOTH);

         $this->log("@#white:{$header} @;@.;");
      }
   }

   // @
   /**
    * Pretest the test case.
    *
    * @return bool
    */
   private function pretest (): bool
   {
      // @ Skip without output (used to skip with command arguments)
      if ($this->specifications['ignore'] ?? false) {
         $this->Tests->skipped++;
         return false;
      }

      $this->separate();

      return true;
   }
   /**
    * Run the test case.
    * 
    * @param mixed ...$arguments
    *
    * @return void
    */
   public function test (mixed ...$arguments): void
   {
      if ($this->pretest() === false) {
         $this->postest();
         return;
      }

      // ---

      // !
      $test = $this->specifications['test'];
      $retest = $this->specifications['retest'] ?? null;

      try {
         ob_start();

         $test instanceof Cases\Assertions
            ? $Assertions = $test->run(...$arguments)
            : $Assertions = $test(...$arguments);

         $this->debugged = ob_get_clean();

         if ($Assertions instanceof Generator !== true) {
            $message = 'The assertion must return boolean, string, NULL, Assertion or a Generator!';

            $Assertions = match (gettype($Assertions)) {
               'boolean', 'string' => [$Assertions],
               'object' => 
                  $Assertions instanceof Assertion
                  || $Assertions instanceof Cases\Assertions
                  ?: throw new AssertionError($message),
               'NULL' => [null],
               default => throw new AssertionError($message)
            };
         }
         
         foreach ($Assertions as $Assertion) {
            if ($Assertion === null) { // ignore
               throw new Exception;
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
                  message: Assertion::$fallback ?? $Assertion
               );
            }
            // $Assertion is TRUE (Test passed!)
            else {
               $this->descriptions[] = Assertion::$description;
               Assertion::$description = null;
            }
   
            $this->results[] = true;
            $this->Tests->assertions++;
         }

         $this->postest();

         // ---
         if ($this->Tests->autoReport) {
            $this->pass();
         }
      }
      catch (AssertionError $AssertionError) {
         $this->postest();

         // ---
         $this->AssertionError = $AssertionError;

         if ($retest === null) {
            $this->descriptions[] = Assertion::$description;
         }
         $this->results[] = false;

         if ($this->Tests->autoReport) {
            $this->fail($AssertionError->getMessage());
         }
      }
      catch (Exception $Exception) {
         // @ ignore
      }
      finally {
         if ($retest) {
            $this->specifications['test'] = $retest;
            $this->specifications['retest'] = null;

            $passed = $this->__get('passed');
            $arguments = [$test, $passed, ...$arguments];

            $this->test(...$arguments);
         }
      }
   }
   /**
    * Postest the test case.
    * 
    * @return void
    */
   private function postest (): void
   {
      #$this->debugged ??= ob_get_clean();

      $this->finished ??= microtime(true);

      $this->elapsed ??= Benchmark::format($this->started, $this->finished);
   }

   // # Reporting
   public function fail (?string $message = null): void
   {
      $this->Tests->failed++;

      $case = sprintf('%03d', $this->specifications['case']);
      $test = str_pad($this->filename . ':', Tests::$width, ' ', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;

      // @ output
      // header
      $this->log(
         "\033[30m\033[47m " . $case . " \033[0m" .
         "\033[0;30;41m FAIL \033[0m " .
         "@@:" . $test . " @;" .
         "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
      );
      // assertions
      $this->describing(status: false);
      // fallback
      $help = $message ?? $this->AssertionError?->getMessage();
      if ($help) {
         $this->log(
            " ↪️\033[91m" . $help . "\033[0m" .
            PHP_EOL
         );
      }

      // @ Debugging
      $this->log($this->debugged);

      // @ exit
      if (Tests::$exitOnFailure && $this->specifications['retest'] === null) {
         $this->Tests->summarize();
         exit(1);
      }
   }
   public function pass (): void
   {
      $this->Tests->passed++;

      $case = sprintf('%03d', $this->specifications['case']);
      $test = str_pad(
         string: $this->filename,
         length: Tests::$width,
         pad_string: '.',
         pad_type: STR_PAD_RIGHT
      );
      $elapsed = $this->elapsed;

      // @ output
      // header
      $this->log(
         "\033[47;30m " . $case . " \033[0m" .
         "\033[0;30;42m PASS \033[0m " .
         "\033[90m" . $test . "\033[0m" .
         "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
      );
      // assertions
      $this->describing(status: true);
   }
}
