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


use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Assertions\Assertion;


class Test extends Assertions
{
   use LoggableEscaped;


   public Tests $Tests;

   // * Config
   // ...inherited

   // * Data
   public array $specifications;
   // ...inherited

   // * Meta
   private mixed $filename;
   // @ Output
   private false|string $debugged;
   // @ Profiling
   private float $started;
   private float $finished;
   private string $elapsed;
   // @ Reporting
   private ? \AssertionError $AssertionError;


   public function __construct (Tests&Tester $Tests, array $specifications)
   {
      $this->Tests = $Tests;

      // * Config
      // ...inherited:
      $this->descriptions = [
         $specifications['describe'] ?? null
      ];

      // * Data
      $this->specifications = $specifications;
      // ...inherited:
      $this->results = [];

      // * Meta
      $this->filename = current($this->Tests->tests); // @ file
      // @ Output
      $this->debugged = false;
      // @ Profiling
      $this->started = microtime(true);
      // @ Reporting
      $this->AssertionError = null;
   }
   public function __get (string $name)
   {
      return match ($name) {
         'passed' => array_reduce(
            array: $this->results,
            callback: function ($accumulator, $assertion) {
               return $accumulator && $assertion;
            },
            initial: true
         ),
         default => null
      };
   }

   private function describe (? string $description, bool $status, string $indicator = '╟')
   {
      if ($description === null) {
         return;
      }

      // Indicator
      # ╚ • ╟ ─
      // Icon
      # ⮡ ↳➡️↪↪️ ✓✘ ✅❌
      $icon = match ($status) {
         true  => ' @#green:✓ @; ',
         false => ' @#red:✘ @; '
      };
      // Description
      $description = '@#white:' . $description . '@;';
      // Breakline
      $breakline = '@.;';

      $description = $indicator . $icon . $description . $breakline;

      $this->log($description);
   }
   private function describing (bool $status)
   {
      $descriptions_count = count($this->descriptions);

      if (!$this->descriptions[0] || $descriptions_count === 1) return;

      $index = 1;
      foreach ($this->descriptions as $description) {
         if ($descriptions_count === 2 && $description === null && $index === 2)
            break;

         $indicator = match ($index) {
            1                   => '╟',
            $descriptions_count => '╚══',
            default             => '╟──',
         };

         $this->describe(
            $description ?? 'Assertion @:info:#' . ($index - 1) . '@;',
            (!$status && ($index === $descriptions_count || $index === 1)) ? false : true,
            $indicator
         );

         $index++;
      }

      if (isSet($this->specifications['last']) === false) {
         $this->log(PHP_EOL);
      }
   }
   public function separate ()
   {
      static $separatorLength;

      $line   = $this->specifications['separator.line']   ?? null;
      $left   = $this->specifications['separator.left']   ?? null;
      $header = $this->specifications['separator.header'] ?? null;

      $width = Tests::$width + 30;

      if ($line) {
         if ($line !== true) {
            $separatorLength = strlen($line);
            $line = '@:i: ' . $line . '  @;';

            // Text + `-`
            $line = str_pad($line, $width, '-', STR_PAD_BOTH);
         } else {
            $line = str_repeat('-', $width - 7);
         }

         $this->log($line . ' @.;');
      }

      if ($left) {
         $this->log("@.;            \033[3;90m" . $left . ":\033[0m @.;");
      }

      if ($header) {
         $header = '\\' .str_pad($header, $separatorLength ?? 0, ' ', STR_PAD_BOTH) . '/';
         $header = str_pad($header, $width - 7, ' ', STR_PAD_BOTH);

         $this->log('@#white:' . $header . ' @;@.;');
      }
   }

   // @
   private function pretest () : bool
   {
      Tests::$case++;

      if ($this->specifications['skip'] ?? false) {
         $this->Tests->skipped++;
         return false;
      }

      $this->separate();

      return true;
   }
   public function test (...$arguments)
   {
      $prepass = $this->pretest();
      if ($prepass === false) {
         $this->postest();
         return;
      }

      try {
         $test = $this->specifications['test'];
         unset($this->specifications['test']);
         $test = $test->bindTo($this, self::class);

         ob_start();

         $Results = $test(...$arguments);

         $this->debugged = \ob_get_clean();

         if ($Results instanceof \Generator !== true) {
            $message = 'The test function must return boolean, string, Assertion or a Generator!';

            $Results = match (gettype($Results)) {
               'boolean', 'string' => [$Results],
               'object' => $Results instanceof Assertion ?: throw new \AssertionError($message),
               default => throw new \AssertionError($message)
            };
         }

         foreach ($Results as $Result) {
            if ($Result instanceof Assertion) {
               Assertion::$fallback === null ?:
                  throw new \AssertionError(message: Assertion::$fallback);

               $this->descriptions[] = $Result::$description;
            } else if ($Result === false || $Result !== true) {
               throw new \AssertionError(message: Assertion::$fallback ?? $Result);
            } else {
               $this->descriptions[] = Assertion::$description;
               Assertion::$description = null;
            }

            $this->results[] = true;
            $this->Tests->assertions++;
         }

         if ($this->Tests->autoResult) {
            $this->pass();
         }
      } catch (\AssertionError $AssertionError) {
         $this->descriptions[] = Assertion::$description;
         $this->results[] = false;

         $this->AssertionError = $AssertionError;

         if ($this->Tests->autoResult) {
            $this->fail($AssertionError->getMessage());
         }
      }

      $this->postest();
   }
   private function postest ()
   {
      #$this->debugged ??= ob_get_clean();

      $this->finished ??= microtime(true);

      $this->elapsed ??= Benchmark::format($this->started, $this->finished);
   }

   public function fail (? string $message = null)
   {
      $this->postest();

      $this->Tests->failed++;

      $case = sprintf('%03d', Tests::$case);
      $test = str_pad($this->filename . ':', Tests::$width, ' ', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;
      $help = $message ?? $this->AssertionError?->getMessage();

      // @ output
      $this->log(
         "\033[30m\033[47m " . $case . " \033[0m" .
         "\033[0;30;41m FAIL \033[0m " .
         "@@:" . $test . " @;" .
         "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
      );
      $this->describing(status: false);

      $this->log(
         " ↪️ \033[91m" . $help . "\033[0m" .
         PHP_EOL
      );

      // @ Debugging
      $this->log($this->debugged);

      // @ exit
      if (Tests::$exitOnFailure) {
         $this->Tests->summarize();
         exit(1);
      }
   }
   public function pass ()
   {
      $this->postest();

      $this->Tests->passed++;

      $case = sprintf('%03d', Tests::$case);
      $test = str_pad($this->filename, Tests::$width, '.', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;

      // @ output
      $this->log(
         "\033[47;30m " . $case . " \033[0m" .
         "\033[0;30;42m PASS \033[0m " .
         "\033[90m" . $test . "\033[0m" .
         "\033[1;35m +" . $elapsed . "s\033[0m" . PHP_EOL
      );
      $this->describing(status: true);
   }
}
