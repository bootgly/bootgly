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


use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use function json_encode;
use function round;


class Results
{
   // * Config
   public static bool $enabled = false;

   // * Data
   public static null|string $agent = null;
   /**
    * Collected test case results.
    *
    * @var array<int,array{suite:string,case:int,file:string,status:string,message:?string,elapsed_ms:float}>
    */
   public static array $cases = [];

   // * Metadata
   // # Suites
   public static int $suitesTotal = 0;
   public static int $suitesFailed = 0;
   public static int $suitesSkipped = 0;
   public static int $suitesPassed = 0;
   // # Assertions
   public static int $assertions = 0;
   // # Time
   public static float $durationMs = 0;


   /**
    * Record a test case result.
    *
    * @param string $suite
    * @param int $case
    * @param string $file
    * @param string $status 'passed'|'failed'|'skipped'
    * @param null|string $message
    * @param float $elapsedMs
    *
    * @return void
    */
   public static function record (
      string $suite,
      int $case,
      string $file,
      string $status,
      null|string $message = null,
      float $elapsedMs = 0
   ): void
   {
      if (self::$enabled === false) {
         return;
      }

      self::$cases[] = [
         'suite'      => $suite,
         'case'       => $case,
         'file'       => $file,
         'status'     => $status,
         'message'    => $message,
         'elapsed_ms' => round($elapsedMs, 2),
      ];
   }

   /**
    * Build the structured result array.
    *
    * @return array<string,mixed>
    */
   public static function toArray (): array
   {
      // @ Count cases
      $casesFailed = 0;
      $casesSkipped = 0;
      $casesPassed = 0;
      $failures = [];

      foreach (self::$cases as $entry) {
         match ($entry['status']) {
            'failed' => $casesFailed++,
            'skipped' => $casesSkipped++,
            'passed' => $casesPassed++,
            default => null,
         };

         if ($entry['status'] === 'failed') {
            $failures[] = [
               'suite'      => $entry['suite'],
               'case'       => $entry['case'],
               'file'       => $entry['file'],
               'message'    => $entry['message'],
               'elapsed_ms' => $entry['elapsed_ms'],
            ];
         }
      }

      $result = [
         'result'     => $casesFailed > 0 ? 'failed' : 'passed',
         'agent'      => self::$agent,
         'suites'     => [
            'total'   => self::$suitesTotal,
            'failed'  => self::$suitesFailed,
            'skipped' => self::$suitesSkipped,
            'passed'  => self::$suitesPassed,
         ],
         'cases'      => [
            'total'   => $casesFailed + $casesSkipped + $casesPassed,
            'failed'  => $casesFailed,
            'skipped' => $casesSkipped,
            'passed'  => $casesPassed,
         ],
         'assertions' => self::$assertions,
         'duration_ms' => round(self::$durationMs, 2),
      ];

      if ($failures !== []) {
         $result['failures'] = $failures;
      }

      return $result;
   }

   /**
    * Return the JSON representation of the results.
    *
    * @return string
    */
   public static function toJSON (): string
   {
      return json_encode(
         self::toArray(),
         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
      ) . PHP_EOL;
   }
}
