<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


/**
 * Guard decoder installed when a response hijacks its HTTP/1.1 connection
 * for server push (e.g. an SSE stream): the connection becomes write-only,
 * so every inbound byte is discarded — a pipelined second request must
 * never interleave a response into the open stream.
 */
class Decoder_Streaming extends Decoders
{
   public function decode (Packages $Package, string $buffer, int $size): States
   {
      // @ Discard inbound bytes
      $Package->consumed = $size;

      // : Never Complete — no response cycle runs on a hijacked connection
      return States::Incomplete;
   }
}
