<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Services\Mail;


use RuntimeException;

use Bootgly\ACI\Mail;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;


/**
 * Queue handler that delivers queued mail — runs in the `bootgly queue run`
 * worker, never in the HTTP request.
 *
 * The Job payload is a `Message::export()` array; handle() rebuilds the
 * Message and sends it through the shared mailer. A delivery failure
 * propagates, so the queue Worker retries with backoff until the configured
 * attempts are exhausted (then the job is buried as a dead-letter).
 */
final class Courier implements Handler
{
   // * Data
   /**
    * Shared mailer used to deliver queued messages — assigned by
    * `WPI\Services\Mail::boot()`; the queue worker bootstrap must boot it too.
    */
   public static Mail $Mail;


   /**
    * Deliver one queued mail job.
    *
    * @param Job $Job The job carrying a `Message::export()` payload.
    */
   public function handle (Job $Job): void
   {
      // ? The worker process must boot the mail service first
      if (isset(self::$Mail) === false) {
         throw new RuntimeException(
            'Mail Courier requires a booted mail service — call WPI\Services\Mail::boot() in the worker bootstrap.'
         );
      }

      // @ Rebuild the message and deliver it
      self::$Mail->send(Message::import($Job->payload));
   }
}
