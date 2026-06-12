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
use function date;
use function defined;
use function function_exists;
use function is_file;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function sleep;
use function time;
use Closure;

use const Bootgly\CLI;
use Bootgly\ACI\Schedule;
use Bootgly\CLI\Command;


/**
 * Cron-style job scheduler worker.
 *
 * - `bootgly schedule run`  — start the minute-aligned worker loop.
 * - `bootgly schedule list` — print registered jobs and their next run.
 *
 * Jobs are declared in `<project>/schedule.php`, which returns a
 * `Closure(Schedule $Schedule): void`.
 */
class ScheduleCommand extends Command
{
   // * Config
   public int $group = 1;

   public string $name = 'schedule';
   public string $description = 'Run cron-style scheduled jobs';

   // * Metadata
   /**
    * Worker run flag, cleared by the SIGTERM/SIGINT handler.
    */
   private bool $running = true;


   public function run (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      $action = $arguments[0] ?? null;

      // ? Worker loop
      if ($action === 'run') {
         $Schedule = $this->boot();

         // :
         return $Schedule !== null && $this->work($Schedule);
      }

      // ? List registered jobs
      if ($action === 'list') {
         $Schedule = $this->boot();

         if ($Schedule === null) {
            return false;
         }

         foreach ($Schedule->Jobs as $Job) {
            $expression = isSet($Job->Cron) ? $Job->Cron->expression : '(no cadence)';
            $next = isSet($Job->Cron) ? date('Y-m-d H:i', $Job->Cron->advance(time())) : '-';

            $Output->render("@#cyan:{$Job->id}@;\t{$expression}\t@#Black:next: {$next}@;" . PHP_EOL);
         }

         // :
         return true;
      }

      // : Usage
      $Output->render('@.;@#green:Usage:@; bootgly schedule @#cyan:run@;|@#cyan:list@;@.;' . PHP_EOL);

      return true;
   }

   /**
    * Build the Schedule from the project's `schedule.php` definition file.
    */
   private function boot (): null|Schedule
   {
      $Output = CLI->Terminal->Output;

      // ! Resolve the definition file from the booted project, else the working dir
      $base = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->path : BOOTGLY_WORKING_DIR;
      $file = "{$base}schedule.php";

      // ?
      if (is_file($file) === false) {
         $Output->render("@.;@#red:No schedule file found at@; @#cyan:{$file}@;@.;" . PHP_EOL);

         return null;
      }

      $definition = require $file;

      $Schedule = new Schedule();

      if ($definition instanceof Closure) {
         $definition($Schedule);
      }

      // :
      return $Schedule;
   }

   /**
    * Run the minute-aligned worker loop until SIGTERM/SIGINT.
    */
   private function work (Schedule $Schedule): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Graceful shutdown: clear the run flag on SIGTERM/SIGINT
      $this->running = true;
      if (function_exists('pcntl_signal')) {
         $stop = function () { $this->running = false; };

         pcntl_signal(SIGTERM, $stop);
         pcntl_signal(SIGINT, $stop);
      }

      $Output->render('@#green:Schedule worker started.@; @#Black:(Ctrl+C to stop)@;' . PHP_EOL);

      // ! Catch-up missed runs once on boot
      $Schedule->recover(time());

      // @ One tick per minute, aligned to the wall-clock minute boundary
      while ($this->check()) {
         $now = time();

         $Schedule->tick($now);

         // ! check() short-circuits an in-progress sleep on shutdown; PHPStan
         // ! cannot see the async signal handler clear the flag (TCP_Server_CLI idiom).
         $next = $now - ($now % 60) + 60;
         while (time() < $next && $this->check()) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
            sleep(1);
         }
      }

      $Output->render(PHP_EOL . '@#yellow:Schedule worker stopped.@;' . PHP_EOL);

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
}
