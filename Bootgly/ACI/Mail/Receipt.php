<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


/**
 * Successful `send()` result — the server's acceptance evidence.
 *
 * Carries only scalars extracted from the final DATA reply by the SMTP
 * client (the reply text usually includes the server queue id), so callers
 * can log/audit deliveries without keeping protocol objects alive.
 */
final class Receipt
{
   // * Data
   /**
    * Final SMTP reply code (usually 250).
    */
   public readonly int $code;
   /**
    * Enhanced status code (RFC 3463) — '' when absent.
    */
   public readonly string $status;
   /**
    * Final reply text — the server confirmation (often a queue id).
    */
   public readonly string $reply;
   /**
    * Accepted envelope recipients.
    * @var array<int,string>
    */
   public readonly array $recipients;
   /**
    * DATA bytes transmitted (post dot-stuffing, excluding the terminator).
    */
   public readonly int $size;


   /**
    * @param array<int,string> $recipients
    */
   public function __construct (
      int $code,
      string $status,
      string $reply,
      array $recipients,
      int $size
   ) {
      // * Data
      $this->code = $code;
      $this->status = $status;
      $this->reply = $reply;
      $this->recipients = $recipients;
      $this->size = $size;
   }
}
