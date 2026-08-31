<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Schedule;
// use Bootgly\ACI\Schedule\Catchups;
// use Bootgly\ACI\Schedule\Frequencies;


/**
 * __NAME__ — cron-style scheduled jobs.
 *
 * Declare jobs below and drive the worker with:
 *
 *    php bootgly project __PATH__ schedule run     # minute-aligned worker loop
 *    php bootgly project __PATH__ schedule list    # registered jobs + next run
 *
 * The worker mounts the project environment (configs, autoload, log provenance)
 * without starting any server, registers a PID-addressable instance and stops
 * gracefully on SIGTERM/SIGINT. Docs: https://docs.bootgly.com/guide/scheduler/overview/
 */
return static function (Schedule $Schedule): void {
   // ? Examples — uncomment to activate:

   // $Schedule->add('heartbeat', static function (): void {
   //    // ... your job code ...
   // })->repeat('*/5 * * * *');                     // every 5 minutes (raw cron)

   // $Schedule->add('backup', BackupJob::class)     // invokable class-string
   //    ->repeat(Frequencies::Daily, at: '03:00')   // every day at 03:00
   //    ->lock()                                    // never overlap a previous run
   //    ->recover(Catchups::Once);                  // one catch-up after downtime
};
