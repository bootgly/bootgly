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


#use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests;
use Bootgly\ACI\Logs\LoggableEscaped;


class Test // extends Assertions
{
   use LoggableEscaped;


   public Tests $Tests;

   // * Config
   // ...

   // * Data
   public array $specifications;

   // * Meta
   public mixed $test;
   public array $results;
   public array $descriptions;
   // @ Output
   public false|string $debugged;
   // @ Time
   public float $started;
   public float $finished;
   public string $elapsed;


   public function __construct (Tests&Tester $Tests, array $specifications)
   {
      $this->Tests = $Tests;

      // * Config
      // ...

      // * Data
      $this->specifications = $specifications;

      // * Meta
      $this->test = current($this->Tests->tests); // @ file
      $this->results = [];
      $this->descriptions = [
         $specifications['describe'] ?? null
      ];
      // @ Output
      $this->debugged = false;
      // @ Time
      $this->started = microtime(true);


      // @
      // @ Set PHP assert options
      // 1
      assert_options(ASSERT_ACTIVE, 1);
      // 2
      #assert_options(ASSERT_CALLBACK, function ($file, $line, $code) {});
      // 3
      assert_options(ASSERT_BAIL, 0);
      // 4
      assert_options(ASSERT_WARNING, 0);
      // 5
      assert_options(ASSERT_EXCEPTION, 1);
   }
   public function __get (string $name)
   {
      return match ($name) {
         'success' => array_reduce(
            array: $this->results,
            callback: function ($accumulator, $assertion) {
               return $accumulator && $assertion;
            },
            initial: true
         ),
         default => null
      };
   }

   public function describe (? string $description, bool $status, string $indicator = '╟')
   {
      if (! $description) {
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
      # ...
      // Breakline
      $breakline = '@.;';

      $description = $indicator . $icon . $description . $breakline;

      $this->log($description);
   }
   public function describing (bool $status)
   {
      $descriptions = $this->descriptions;
      $descriptions_count = count($descriptions);

      if (!$descriptions[0] || $descriptions_count === 1) return;

      $index = 1;
      foreach ($descriptions as $description) {
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

      if (!$this->specifications['last'] ?? false) {
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
      #ob_start();

      Tests::$case++;

      if ($this->specifications['skip'] ?? false) {
         $this->Tests->skipped++;
         return false;
      }

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
         $Results = $this->specifications['test'](...$arguments);

         if ($Results instanceof \Generator !== true) {
            $Results = match ($Results) {
               false, true => [$Results],
               default => throw new \AssertionError(
                  'The test function must return boolean or a Generator<boolean>!'
               )
            };
         }

         foreach ($Results as $result) {
            $this->descriptions[] = null;
            $this->results[] = $result ?? true;
            $this->Tests->assertions++;
         }

         if ($this->Tests->autoResult) {
            $this->postest();
            $this->pass();
         }
      } catch (\AssertionError $AssertionError) {
         $this->results[] = false;

         $message = $AssertionError->getMessage();

         if ($this->Tests->autoResult) {
            $this->postest();
            $this->fail($message);
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
      $this->Tests->failed++;
      $this->descriptions[] = null;

      $case = sprintf('%03d', Tests::$case);
      $test = str_pad($this->test . ':', Tests::$width, ' ', STR_PAD_RIGHT);
      $elapsed = $this->elapsed;
      $help = $message ?? $this->specifications['except']();

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
      $this->log($this->debugged);

      // @ exit
      if (Tests::$exitOnFailure) {
         $this->Tests->summarize();
         exit(1);
      }
   }
   public function pass ()
   {
      $this->Tests->passed++;

      $case = sprintf('%03d', Tests::$case);
      $test = str_pad($this->test, Tests::$width, '.', STR_PAD_RIGHT);
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
