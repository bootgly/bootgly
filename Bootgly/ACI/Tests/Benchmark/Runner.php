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
   /** @var array<Opponent> */
   public protected(set) array $opponents = [];
   /** @var array<\Bootgly\ACI\Tests\Benchmark\Configs\Load> */
   public protected(set) array $loads = [];
   /** @var string Throughput unit displayed in the results table (e.g. "req/s", "msg/s"). */
   public string $metric = 'req/s';
   /** @var string Post-run message (e.g. "HTTP server stopped..."). Empty = no message. */
   public string $postMessage = '';
   /**
    * Case-supplied metadata merged into the `.marks` Config header by TestCommand
    * (e.g. `load-set`, `target-url`). Keep keys lowercase + kebab-cased.
    *
    * @var array<string,scalar>
    */
   public array $meta = [];


   public function add (Opponent $Opponent): void
   {
      $this->opponents[] = $Opponent;
   }

   /**
    * Apply resolved case option values (one sweep round) to the Runner.
    *
    * Framework-known keys:
    * - `server-workers` — uniform worker count for every Opponent.
    *
    * @param array<string,scalar> $values
    */
   public function apply (array $values): void
   {
      // # server-workers
      if (isset($values['server-workers'])) {
         foreach ($this->opponents as $Opponent) {
            $Opponent->workers = (int) $values['server-workers'];
         }
      }
   }

   /**
    * Configure runner from CLI options.
    *
    * @param array<string, bool|int|string> $options
    */
   abstract public function configure (array $options): void;

   /**
    * Load loads from a directory.
    *
    * @param string $dir Absolute path.
    */
   public function load (string $dir): void
   {
      // Default: no loads to load (e.g. Code runner)
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
    * @return array<string,array<string,Result>> Opponent name => Load label => Result.
    */
   abstract public function run (Configs $Configs): array;
}
