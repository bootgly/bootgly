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
 * Opened before the onion runs and held by the middleware — against the
 * generation's lifecycle token while a deferral is in flight, so the sealing
 * pass reaches it through the captured snapshot. Completed by whichever side
 * settles the request — the synchronous unwind, the sealing pass, the
 * lifecycle's terminal transition — and written exactly once.
 *
 * Every field is public: the entry is the middleware's own record, not a user
 * input and not state anything else reads.
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
   /** The class of the Throwable that left the onion around this request. */
   public null|string $throwable = null;
   /** Whether the response was generated after the onion unwound. */
   public bool $deferred = false;
   /** Whether the generation ended with no answer — the client left, or it was abandoned. */
   public bool $cancelled = false;
   /** Whether the line was written; the entry accepts exactly one. */
   public bool $written = false;


   public function __construct ()
   {
      $this->started = (int) hrtime(true);
   }
}
