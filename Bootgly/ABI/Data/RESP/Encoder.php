<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\RESP;


use function count;
use function strlen;


/**
 * RESP command encoder.
 *
 * Client requests are always RESP2 multibulk arrays of bulk strings, accepted
 * by every Redis server regardless of negotiated protocol. Stateless.
 */
class Encoder
{
   /**
    * Encode a command (verb + arguments) into RESP multibulk wire bytes.
    *
    * @param array<int,int|float|string> $command
    */
   public function encode (array $command): string
   {
      $count = count($command);
      $out = "*{$count}\r\n";

      foreach ($command as $argument) {
         $argument = (string) $argument;
         $length = strlen($argument);
         $out .= "\${$length}\r\n{$argument}\r\n";
      }

      return $out;
   }
}
