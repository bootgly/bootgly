<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC\JSON;


use RuntimeException;


/**
 * A JSON-RPC 2.0 error a method raises on purpose: its code, message and
 * optional data reach the client as the response's `error` member.
 *
 * Any other `Throwable` a method raises is an internal error: the client gets
 * `-32603 Internal error` and nothing of the exception.
 */
class Fault extends RuntimeException
{
   // * Config
   /**
    * Optional structured detail, sent as `error.data` (omitted when null).
    */
   public private(set) mixed $data;


   /**
    * @param int $code The JSON-RPC error code (e.g. `JSON::INVALID_PARAMS`,
    *        or an application code outside -32768..-32000).
    * @param string $message A short description of the error.
    * @param mixed $data Optional detail for the client.
    */
   public function __construct (int $code, string $message, mixed $data = null)
   {
      parent::__construct($message, $code);

      // * Config
      $this->data = $data;
   }
}
