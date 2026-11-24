<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers\S3;


use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;


/**
 * The failure of one S3 call: the HTTP status, the store's error code and its kind.
 *
 * An S3 disk keeps the failure of the last call made in the current Fiber on `$S3->Failure`
 * (null after a call that succeeded), so concurrent requests sharing one driver each read their
 * own. It carries no request bytes, no credentials and nothing of the store's error message.
 */
final class Failure
{
   // * Config
   /** The HTTP status of the answer; 0 when no answer arrived or nothing was sent. */
   public private(set) int $status;
   /** The store's error code (`NoSuchKey`, `AccessDenied`, `SlowDown`, …); `''` when it gave none. */
   public private(set) string $code;
   /** Transport, Protocol or Refusal. */
   public private(set) Kinds $kind;
   /**
    * The reason of the request that failed, in words. `$error` tells the outcome of the whole call
    * and may word it otherwise: a later step that failed too (`clear()`), or the generic `HTTP 0`
    * of a request the driver could not sign.
    */
   public private(set) string $message;


   /**
    * Describe one failed S3 request.
    */
   public function __construct (int $status, string $code, Kinds $kind, string $message)
   {
      // * Config
      $this->status = $status;
      $this->code = $code;
      $this->kind = $kind;
      $this->message = $message;
   }
}
