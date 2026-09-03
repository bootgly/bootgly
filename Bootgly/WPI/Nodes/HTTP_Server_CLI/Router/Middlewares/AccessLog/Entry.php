<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\AccessLog;


use function hrtime;


/**
 * One request as the AccessLog sees it.
 *
 * Opened before the onion runs and parked on the Request's attribute bag, so
 * the snapshot a deferral captures carries this very object; completed by
 * whichever side settles the request — the synchronous unwind, the sealing
 * pass, the lifecycle's terminal transition — and written exactly once.
 */
final class Entry
{
   // * Data
   /** `hrtime(true)` when the request entered the middleware, in nanoseconds. */
   public int $started;
   public string $method = '';
   /** The request target as logged — without its query unless configured. */
   public string $URI = '';
   public string $protocol = '';
   /** The socket peer — what cannot be forged. */
   public string $peer = '';
   /** The application-facing client address (a TrustedProxy may resolve it). */
   public string $address = '';
   /** The request id read back from the response header, when one is stamped. */
   public null|string $id = null;
   /** The status the wire carried — null until known, and for a cancelled request. */
   public null|int $code = null;
   /** Body bytes as the middleware saw them — null when no body was seen (a throw, a handoff). */
   public null|int $bytes = null;
   /** The class of the Throwable that left the onion, on the synchronous throw path. */
   public null|string $throwable = null;

   // * Metadata
   public bool $deferred = false;
   public bool $cancelled = false;
   public bool $written = false;


   public function __construct ()
   {
      $this->started = (int) hrtime(true);
   }
}
