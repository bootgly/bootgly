<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use Closure;


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
   /**
    * Optional case-level validation executed after concrete opponent/load
    * selection is known and before the banner or any measured process starts.
    */
   public null|Closure $Validator = null;
   public protected(set) null|Artifacts $Artifacts = null;
   protected null|Child $Child = null;


   public function add (Opponent $Opponent): void
   {
      $this->opponents[] = $Opponent;
   }

   /**
    * Bind this runner to the invocation-owned artifact workspace.
    */
   public function bind (Artifacts $Artifacts): void
   {
      $this->Artifacts = $Artifacts;
      $this->Child = new Child($Artifacts);
   }

   /**
    * Execute a child with run-local, distinct stdout and stderr artifacts.
    *
    * @param array<int,string> $command
    * @param array<string,string>|null $environment
    *
    * @return array{exit:int,stdout:string,stderr:string,status:string}
    */
   protected function spawn (
      array $command,
      string $scope,
      null|array $environment = null,
      null|string $input = null,
      null|float $timeout = null,
      float $grace = 2.0,
   ): array
   {
      if ($this->Child === null) {
         throw new \RuntimeException('Benchmark runner has no artifact workspace');
      }

      return $this->Child->run($command, $scope, $environment, $input, $timeout, $grace);
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
    * Validate the resolved case selection before execution.
    *
    * @param array<int,array<string,scalar>> $rounds Fully resolved execution rounds.
    */
   public function validate (Configs $Configs, array $rounds = []): void
   {
      if ($this->Validator !== null) {
         ($this->Validator)($this, $Configs, $rounds);
      }
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
