<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI;


use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;
use Bootgly\WPI\Queues\Messenger;


/**
 * Web-platform queue facade.
 *
 * Static sugar over a shared `Queues\Messenger` so request handlers can enqueue
 * with one call (`Queues::dispatch(SendEmail::class, $payload)`). boot() sets the
 * shared messenger from config; dispatch()/push() lazily create a default one.
 */
class Queues
{
   // * Data
   public static Messenger $Messenger;


   /**
    * Build and store the shared messenger from config.
    *
    * @param array<string,mixed>|Config $config Queue configuration.
    */
   public static function boot (array|Config $config = []): Messenger
   {
      // :
      return self::$Messenger = new Messenger($config);
   }

   /**
    * Dispatch a job through the shared messenger (lazily created on first use).
    *
    * @param class-string<Handler> $Handler Handler that will process the job.
    * @param array<string,mixed> $payload Serializable payload for the handler.
    * @param string $queue Target queue name.
    */
   public static function dispatch (string $Handler, array $payload = [], string $queue = 'default'): Job
   {
      // ?
      if (isset(self::$Messenger) === false) {
         self::$Messenger = new Messenger();
      }

      // :
      return self::$Messenger->dispatch($Handler, $payload, $queue);
   }

   /**
    * Push a prepared job through the shared messenger (lazily created on first use).
    *
    * @param Job $Job Job to enqueue.
    * @param string $queue Target queue name.
    */
   public static function push (Job $Job, string $queue = 'default'): bool
   {
      // ?
      if (isset(self::$Messenger) === false) {
         self::$Messenger = new Messenger();
      }

      // :
      return self::$Messenger->push($Job, $queue);
   }
}
