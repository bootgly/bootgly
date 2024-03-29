<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use Bootgly\ABI\Resources;

use Bootgly\ACI\Logs\LoggableEscaped;


abstract class Tests implements Resources
{
   use LoggableEscaped;


   // * Config
   // auto
   public string $autoBoot;
   public mixed $autoInstance;
   public bool $autoResult;
   public bool $autoSummarize;
   // exit
   public static bool $exitOnFailure = false;
   // pretesting
   public array $testables;

   // * Data
   public array $tests;
   public array $specifications;

   // * Metadata
   public int $failed;
   public int $passed;
   public int $skipped;
   // @ Stats
   public int $assertions;
   public int $total;
   public static int $suite = 0;
   public static int $case = 0;
   public static int $cases = 0;
   // @ Time
   public float $started;
   public float $finished;
   public float $elapsed;
   // @ Output
   public static int $width = 0;


   abstract public function __construct (array &$specifications); // Suite Specifications

   public static function list (array $tests, $prefix = '') : array
   {
      $result = [];

      foreach ($tests as $key => $value) {
         if ( is_array($value) ) {
            $newPrefix = $prefix . $key;
            $result = array_merge(
               $result,
               self::list($value, $newPrefix)
            );
         } else {
            $result[] = $prefix . $value;
         }
      }

      return $result;
   }

   abstract public function test (? array &$specifications) : object|false; // Test Specifications

   abstract public function summarize ();
}
