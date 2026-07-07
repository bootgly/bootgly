<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_Queue_HTTP_Server_CLI;


use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_WORKING_DIR;
use const FILE_APPEND;
use function date;
use function file_put_contents;

use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;


/**
 * Demo job handler — runs in the `bootgly queue run` worker, not in the HTTP request.
 *
 * A real application does not need this stub: the framework ships
 * `Bootgly\WPI\Services\Mail\Courier`, the handler behind
 * `WPI\Services\Mail::dispatch($Message)`
 * that delivers a queued `Mail\Message` over SMTP. To keep the demo
 * self-contained (no SMTP server required) this handler just appends a line to
 * `storage/queue-demo.log` so you can watch jobs being processed.
 */
final class SendEmail implements Handler
{
   /**
    * Process one queued email job.
    *
    * @param Job $Job The job to handle.
    */
   public function handle (Job $Job): void
   {
      $to = $Job->payload['to'] ?? '(unknown)';
      $subject = $Job->payload['subject'] ?? 'Hello from Bootgly';

      $line = date('Y-m-d H:i:s') . "  sent '{$subject}' to {$to}  [job {$Job->id}]" . "\n";

      file_put_contents(BOOTGLY_STORAGE_DIR . 'queue-demo.log', $line, FILE_APPEND);
   }
}
