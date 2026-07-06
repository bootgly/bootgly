<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\Receipt;
use Bootgly\ACI\Mail\SMTP_Client;


/**
 * Mail — the framework mail service.
 *
 * `send()` takes a `Message` (built with the MIME builder — attachments,
 * inline images, alternative bodies) or an explicit envelope plus a raw
 * RFC 5322 string. Template-based emails and queue integration land in
 * later cuts on top of this same facade.
 */
class Mail
{
   // * Config
   public Config $Config;

   // * Data
   protected SMTP_Client $SMTP_Client;


   /**
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $this->Config = $config instanceof Config ? $config : new Config($config);

      // * Data
      $this->SMTP_Client = new SMTP_Client($this->Config);
   }

   /**
    * Pre-flight the SMTP session (TCP + TLS + EHLO + AUTH) without sending
    * anything — useful as a boot-time connectivity/credential check.
    * `send()` connects lazily, so calling this first is optional.
    */
   public function connect (): bool
   {
      // :
      return $this->SMTP_Client->connect();
   }

   /**
    * Send a mail: a `Message` (envelope and data derived from it) or an
    * explicit envelope plus a raw RFC 5322 string. Returns the server
    * acceptance evidence; every failure throws a `Mail\Exceptioning`
    * exception (4xx replies are Transient/retryable, 5xx are Permanent).
    *
    * Raw payloads must already be 7-bit safe: the transaction fails closed
    * — locally, before MAIL — when the payload carries 8-bit bytes and the
    * server does not advertise `8BITMIME`, or when the envelope/headers
    * carry non-ASCII and it does not advertise `SMTPUTF8`. `Message`
    * renders are always 7-bit ASCII and never hit these guards.
    *
    * @param array<int,string>|string $recipients
    */
   public function send (string|Message $sender, array|string $recipients = [], string $data = ''): Receipt
   {
      // :
      return $this->SMTP_Client->send($sender, $recipients, $data);
   }

   /**
    * Close the SMTP session (best-effort QUIT). Idempotent; also runs on
    * destruction.
    */
   public function disconnect (): bool
   {
      // :
      return $this->SMTP_Client->disconnect();
   }
}
