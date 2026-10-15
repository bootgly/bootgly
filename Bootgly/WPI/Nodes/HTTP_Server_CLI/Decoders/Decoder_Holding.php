<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


/**
 * Guard decoder installed while an HTTP/1.1 connection's deferral is parked
 * (`Response::defer()`): the requests pipelined behind it wait, undecoded, in
 * the transport carry — charged to the worker budget — so a connection parks
 * one deferral at a time and its responses leave in request order (RFC 9112
 * §9.3.2). The deferral removes it once its exchange finishes — on the next
 * reactor tick, after its response is written — and the held requests are
 * decoded then.
 *
 * More than one transport read held (`TCP_Server_CLI\Packages::READ`) closes
 * the connection, which also cancels the parked deferral: a client that keeps
 * pipelining behind a parked response is not owed a bigger buffer.
 */
class Decoder_Holding extends Decoders
{
   public function decode (Packages $Package, string $buffer, int $size): States
   {
      // ! Nothing decodes while the connection's deferral is parked
      $Package->consumed = 0;

      // ? More than one read held: close the connection (cancels the park)
      if ($size > TCP_Packages::READ) {
         /** @var TCP_Packages $Package */
         $Package->Connection->close();

         return States::Rejected;
      }

      // : The transport keeps every byte in its carry (`retain()`), charged
      return States::Incomplete;
   }
}
