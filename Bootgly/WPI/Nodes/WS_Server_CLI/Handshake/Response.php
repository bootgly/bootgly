<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;


/**
 * Response adapter passed to a guard's `challenge()` so it can record the
 * `401` status and a `WWW-Authenticate` header (via `Challenge::announce()`).
 * The captured header is then written onto the handshake rejection.
 */
class Response
{
   // * Data
   public int $code = 401;
   public Header $Header;


   public function __construct ()
   {
      $this->Header = new Header;
   }
}
