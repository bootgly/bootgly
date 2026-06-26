<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use Bootgly\WPI\Endpoints\Servers\Decoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;


abstract class Decoders implements Decoder
{
   // * Metadata
   // @ Shared (stateless) per-connection frame decoder, installed by the node.
   //   Held on the base so the entry decoder can swap to it without referencing
   //   an alphabetically-later sibling directly.
   public static null|Decoder_Framing $Framing = null;


   abstract public function decode (Packages $Package, string $buffer, int $size): States;
}
