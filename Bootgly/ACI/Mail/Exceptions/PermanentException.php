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
 * 5xx SMTP reply — the server refused permanently; retrying the same send
 * will not succeed. Also thrown for local pre-flight failures with 5xx
 * semantics (payload over the advertised SIZE, non-ASCII address without
 * SMTPUTF8). `$code` carries the SMTP reply code.
 */
final class PermanentException extends Exception implements Exceptioning
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
