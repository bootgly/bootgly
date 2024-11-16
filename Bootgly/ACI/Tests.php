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
   public bool $autoReport;
   public bool $autoSummarize;
   // exit
   public static bool $exitOnFailure = false;
   // pretesting
   /** @var array<object> */
   public array $testables;

   // * Data
   /** @var array<string> */
   public array $tests;
   /** @var array<string,mixed> */
   public array $specifications;

   // * Metadata
   public int $failed;
   public int $passed;
   public int $skipped;
   // @ Stats
   public int $assertions;
   public int $total;
   public static int $suite = 0;
   public static int $cases = 0;
   // @ Time
   public float $started;
   public float $finished;
   public float $elapsed;
   // @ Output
   public static int $width = 0;


   /**
    * Tests constructor.
    * 
    * @param array<string,mixed> $specifications
    */
   abstract public function __construct (array &$specifications); // Suite Specifications

   /**
    * List test cases.
    * 
    * @param array<mixed> $tests
    * @param string $prefix
    * 
    * @return array<string>
    */
   public static function list (array $tests, $prefix = ''): array
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

   /**
    * Run test cases.
    * 
    * @param ?array<mixed> $specifications
    * @return object|false
    */
   abstract public function test (?array &$specifications): object|false;
   /**
    * Summarize test cases.
    * 
    * @return void
    */
   abstract public function summarize ();
}
