<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


class Result
{
   // * Data
   /**
    * Execution time in seconds (code benchmarks).
    */
   public readonly null|string $time;

   /**
    * Memory usage in bytes (code benchmarks).
    */
   public readonly null|int $memory;

   /**
    * Requests per second (server benchmarks).
    */
   public readonly null|float $rps;

   /**
    * Average latency string (server benchmarks).
    */
   public readonly null|string $latency;

   /**
    * Transfer per second string (server benchmarks).
    */
   public readonly null|string $transfer;


   public function __construct (
      null|string $time = null,
      null|int $memory = null,
      null|float $rps = null,
      null|string $latency = null,
      null|string $transfer = null,
   )
   {
      $this->time = $time;
      $this->memory = $memory;
      $this->rps = $rps;
      $this->latency = $latency;
      $this->transfer = $transfer;
   }
}
