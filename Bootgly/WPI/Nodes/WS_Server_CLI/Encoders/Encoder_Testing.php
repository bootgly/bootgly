<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Encoders;


use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\WS_Server_CLI\Encoders;


/**
 * Test-mode encoder. The WebSocket pipeline is message-driven (the handler is
 * installed once via `on(MessageReceived, ...)`, not per-request), so the E2E
 * harness drives the real server behavior — this encoder simply delegates to
 * `Encoder_`. A future index-based dispatch can specialize it.
 */
class Encoder_Testing extends Encoders
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   public static function encode (Packages $Package, null|int &$length): string
   {
      return Encoder_::encode($Package, $length);
   }
}
