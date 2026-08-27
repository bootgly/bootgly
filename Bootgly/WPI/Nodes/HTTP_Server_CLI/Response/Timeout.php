<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use RuntimeException;


/**
 * Delivered at a deferred response's suspension point when its budget —
 * `Response::$deferredTimeout` or the per-call `defer()` timeout — elapsed
 * while the work was still parked on the reactor.
 *
 * It arrives BEFORE the exchange settles: the deferred work may catch it and
 * answer whatever it wants; left unhandled, the server answers 503.
 */
final class Timeout extends RuntimeException
{
   // * Config
   /** The budget that elapsed, in seconds. */
   public readonly int|float $timeout;


   public function __construct (int|float $timeout)
   {
      $this->timeout = $timeout;

      parent::__construct("HTTP deferred response exceeded its {$timeout}s budget.");
   }
}
