<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail\Exceptions;


use Exception;
use Throwable;

use Bootgly\ACI\Mail\Exceptioning;


/**
 * 4xx SMTP reply — the server refused temporarily; the same send may be
 * retried later (backoff recommended). `$code` carries the SMTP reply code.
 */
final class TransientException extends Exception implements Exceptioning
{
   // * Data
   /**
    * Enhanced status code (RFC 3463) — '' when the server sent none.
    */
   public private(set) string $status;


   public function __construct (
      string $message,
      int $code = 0,
      string $status = '',
      null|Throwable $previous = null
   ) {
      parent::__construct($message, $code, $previous);

      // * Data
      $this->status = $status;
   }
}
