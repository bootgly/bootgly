<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_PROJECT;
use const BOOTGLY_WORKING_DIR;
use const PHP_EOL;
use const SIGINT;
use const SIGTERM;
use function defined;
use function function_exists;
use function getcwd;
use function is_array;
use function is_dir;
use function is_file;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function scandir;
use function usleep;

use const Bootgly\CLI;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Worker;
use Bootgly\CLI\Command;


/**
 * Queue worker.
 *
 * - `bootgly queue run [queue]` — drain a queue (default `default`) until SIGTERM/SIGINT.
 * - `bootgly queue list`        — print known queues and their ready counts.
 *
 * Configuration is optional via `<project>/queues.php`, which returns a config
 * array (or `Queues\Config`); without it the defaults run (file driver).
 */
class QueueCommand extends Command
{
   // * Config
   public int $group = 1;

   public string $name = 'queue';
   public string $description = 'Run queue workers';

   // * Metadata
   /**
    * Worker run flag, cleared by the SIGTERM/SIGINT handler.
    */
   private bool $running = true;


   /**
    * Route the `run` / `list` action, or print usage.
    *
    * @param array<string> $arguments The arguments passed to the command.
    * @param array<string,bool|int|string> $options The options passed to the command.
    */
   public function run (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      $action = $arguments[0] ?? null;

      // ? Worker loop
      if ($action === 'run') {
         $Queues = $this->boot();
         $name = $arguments[1] ?? 'default';

         // :
         return $this->work($Queues, $name);
      }

      // ? List queues and their ready counts
      if ($action === 'list') {
         $Queues = $this->boot();

         $path = $Queues->Config->path;
         $names = is_dir($path) === true ? $this->scan($path) : [];

         // ?
         if ($names === []) {
            $Output->render('@.;@#Black:No queues found.@;@.;' . PHP_EOL);

            return true;
         }

         foreach ($names as $name) {
            $count = $Queues->fetch($name)->count();
            $Output->render("@#cyan:{$name}@;\t@#Black:ready: {$count}@;" . PHP_EOL);
         }

         // :
         return true;
      }

      // : Usage
      $Output->render('@.;@#green:Usage:@; bootgly queue @#cyan:run@; [queue] | @#cyan:list@;@.;' . PHP_EOL);

      return true;
   }

   /**
    * Build the queue manager from the first `queues.php` found.
    *
    * Looks in the booted project directory, then the current working directory
    * (so a worker started inside a project loads that project's config and
    * handlers), then the Bootgly working directory. Falls back to defaults.
    */
   private function boot (): Queues
   {
      // ! Candidate base directories, in priority order
      $bases = [];
      if (defined('BOOTGLY_PROJECT')) {
         $bases[] = BOOTGLY_PROJECT->path;
      }
      $cwd = getcwd();
      if ($cwd !== false) {
         $bases[] = "{$cwd}/";
      }
      $bases[] = BOOTGLY_WORKING_DIR;

      // @ First file wins (requiring it also loads any handlers it pulls in)
      foreach ($bases as $base) {
         $file = "{$base}queues.php";
         if (is_file($file) === false) {
            continue;
         }

         $config = require $file;

         // ?: A prepared Config value
         if ($config instanceof Config) {
            return new Queues($config);
         }
         // ?: A plain config array
         if (is_array($config) === true) {
            /** @var array<string,mixed> $config */
            return new Queues($config);
         }

         break;
      }

      // : No usable config file — run with defaults (file driver, workdata/queues)
      return new Queues();
   }

   /**
    * Drain a queue until SIGTERM/SIGINT, retrying or burying failed jobs.
    */
   private function work (Queues $Queues, string $name): bool
   {
      $Output = CLI->Terminal->Output;

      $Queue = $Queues->fetch($name);
      $Worker = new Worker($Queue, $Queues->Config);

      // ! Graceful shutdown: clear the run flag on SIGTERM/SIGINT
      $this->running = true;
      if (function_exists('pcntl_signal')) {
         $stop = function () { $this->running = false; };

         pcntl_signal(SIGTERM, $stop);
         pcntl_signal(SIGINT, $stop);
      }

      $Output->render("@#green:Queue worker started@; @#Black:({$name} — Ctrl+C to stop)@;" . PHP_EOL);

      // ! Recover stale claims left by a previous crash
      $Queue->recover();

      // @ Drain when busy; idle-sleep + periodic reaper when empty
      $idle = 0;
      while ($this->check()) {
         // ?: A job was handled — keep draining at full speed
         if ($Worker->tick() === true) {
            $idle = 0;
            continue;
         }

         // ! Idle: run the reaper occasionally, then sleep briefly
         if (++$idle % 100 === 0) {
            $Queue->recover();
         }

         usleep(100_000); // 100 ms
      }

      $Output->render(PHP_EOL . '@#yellow:Queue worker stopped.@;' . PHP_EOL);

      // :
      return true;
   }

   /**
    * Dispatch pending signals and report whether the worker should keep running.
    */
   private function check (): bool
   {
      if (function_exists('pcntl_signal_dispatch')) {
         pcntl_signal_dispatch();
      }

      // :
      return $this->running;
   }

   /**
    * List queue sub-directory names under the base path.
    *
    * @return array<int,string>
    */
   private function scan (string $path): array
   {
      $entries = scandir($path);
      // ?
      if ($entries === false) {
         return [];
      }

      $names = [];
      foreach ($entries as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }
         if (is_dir("{$path}/{$entry}") === true) {
            $names[] = $entry;
         }
      }

      // :
      return $names;
   }
}
