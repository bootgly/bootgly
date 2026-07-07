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


use Bootgly\ACI\Mail;
use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\Receipt;
use Bootgly\ACI\Queues\Job;
use Bootgly\WPI\Queues;
use Bootgly\WPI\Services\Mail\Courier;


/**
 * Web-facing mail adapter over the `ACI/Mail` contract.
 *
 * send() delivers synchronously through the shared mailer; dispatch() only
 * **enqueues** the message through the platform `WPI\Queues` messenger (a
 * quick local write or one Redis round-trip) — the SMTP delivery runs in the
 * `queue run` worker via the `Courier` handler, never on the HTTP event
 * loop. Configure the queue store once, in `WPI\Queues::boot()`.
 */
class Messenger
{
   // * Data
   public Mail $Mail;


   /**
    * Wrap a mailer, building one from config when needed.
    *
    * @param array<string,mixed>|Config|Mail $config Mail (SMTP) config array, a prepared Config, or an existing mailer.
    */
   public function __construct (array|Config|Mail $config = [])
   {
      // * Data
      $this->Mail = $config instanceof Mail ? $config : new Mail($config);

      // ! The queue Courier delivers through this same mailer
      Courier::$Mail = $this->Mail;
   }

   /**
    * Send a mail synchronously through the shared mailer.
    *
    * @param array<int,string>|string $recipients
    */
   public function send (string|Message $sender, array|string $recipients = [], string $data = ''): Receipt
   {
      // :
      return $this->Mail->send($sender, $recipients, $data);
   }

   /**
    * Enqueue a message for background delivery by the mail `Courier`,
    * through the shared platform queue messenger.
    *
    * @param Message $Message Message to deliver (exported into the Job payload).
    * @param string $queue Target queue name.
    */
   public function dispatch (Message $Message, string $queue = 'mail'): Job
   {
      $Job = new Job(Courier::class, $Message->export());

      Queues::push($Job, $queue);

      // :
      return $Job;
   }
}
