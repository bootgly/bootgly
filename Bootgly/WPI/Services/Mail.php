<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Services;


use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\Receipt;
use Bootgly\ACI\Queues\Job;
use Bootgly\WPI\Services\Mail\Messenger;


/**
 * Web-platform mail service.
 *
 * Static sugar over a shared `Mail\Messenger` so request handlers can mail
 * with one call: `Mail::send($Message)` delivers synchronously,
 * `Mail::dispatch($Message)` enqueues it for the `queue run` worker (the
 * `Courier` handler delivers it there, through the shared `WPI\Queues`
 * messenger). boot() sets the shared messenger from the SMTP config;
 * send()/dispatch() lazily create a default one.
 */
class Mail
{
   // * Data
   public static Messenger $Messenger;


   /**
    * Build and store the shared messenger from config.
    *
    * @param array<string,mixed>|Config $config Mail (SMTP) configuration.
    */
   public static function boot (array|Config $config = []): Messenger
   {
      // :
      return self::$Messenger = new Messenger($config);
   }

   /**
    * Send a mail synchronously through the shared messenger (lazily created
    * on first use).
    *
    * @param array<int,string>|string $recipients
    */
   public static function send (string|Message $sender, array|string $recipients = [], string $data = ''): Receipt
   {
      // ?
      if (isset(self::$Messenger) === false) {
         self::$Messenger = new Messenger();
      }

      // :
      return self::$Messenger->send($sender, $recipients, $data);
   }

   /**
    * Enqueue a message for background delivery through the shared messenger
    * (lazily created on first use).
    *
    * @param Message $Message Message to deliver in the queue worker.
    * @param string $queue Target queue name.
    */
   public static function dispatch (Message $Message, string $queue = 'mail'): Job
   {
      // ?
      if (isset(self::$Messenger) === false) {
         self::$Messenger = new Messenger();
      }

      // :
      return self::$Messenger->dispatch($Message, $queue);
   }
}
