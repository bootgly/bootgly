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


abstract class Runner
{
   // * Data
   public protected(set) string $name = '';
   /** @var array<Competitor> */
   public protected(set) array $competitors = [];
   /** @var array<\Bootgly\ACI\Tests\Benchmark\Configs\Scenario> */
   public protected(set) array $scenarios = [];
   /** @var string Post-run message (e.g. "HTTP server stopped..."). Empty = no message. */
   public string $postMessage = '';


   public function add (Competitor $Competitor): void
   {
      $this->competitors[] = $Competitor;
   }

   /**
    * Configure runner from CLI options.
    *
    * @param array<string, bool|int|string> $options
    */
   abstract public function configure (array $options): void;

   /**
    * Load scenarios from a directory.
    *
    * @param string $dir Absolute path.
    */
   public function load (string $dir): void
   {
      // Default: no scenarios to load (e.g. Code runner)
   }

   /**
    * Return banner sections with key-value pairs.
    *
    * @param Configs $Configs
    *
    * @return array<string,array<string,string>>
    */
   public function banner (Configs $Configs): array
   {
      return [];
   }

   /**
    * Return runner-specific CLI options for help display.
    *
    * @return array<string,string> e.g. ['--connections=N' => 'Number of TCP connections (default: 514)']
    */
   public function options (): array
   {
      return [];
   }

   /**
    * Run the benchmark.
    *
    * @param Configs $Configs
    *
    * @return array<string,array<string,Result>> Competitor name => Scenario label => Result.
    */
   abstract public function run (Configs $Configs): array;
}
