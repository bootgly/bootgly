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


use function is_string;
use function strlen;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


class Encoder_ extends Encoders
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   public static function encode (Packages $Package, null|int &$length): string
   {
      $Session = $Package->decoded;

      // ? No session yet (decoder still in the handshake state).
      if ($Session instanceof Session === false) {
         $length = 0;
         return '';
      }

      // # Handshake phase — flush the 101 first, THEN establish. A Connected
      //   handler may send frames during establish(); writing the 101 here
      //   keeps it ahead of those frames on the wire.
      if ($Session->handshake !== '') {
         $response = $Session->handshake;
         $Session->handshake = '';

         $Session->deliver($response);
         $Session->establish();

         $length = 0;
         return '';
      }

      // # Control echoes (pong / close) queued by the decoder.
      if ($Session->outbox !== '') {
         $out = $Session->outbox;
         $Session->outbox = '';

         $length = strlen($out);
         return $out;
      }

      // # Completed data message — dispatch the user handler and frame the reply.
      $Message = $Session->Message;
      if ($Message !== null) {
         $Session->Message = null;

         $reply = null;
         if ( isSet(SAPI::$Handler) ) {
            try {
               $reply = (SAPI::$Handler)($Session, $Message);
            }
            catch (Throwable $Throwable) {
               Throwables::debug($Throwable);
            }
         }

         // ?: Handler returned a string reply — frame it as one message.
         if (is_string($reply) && $reply !== '') {
            $opcode = $Message->binary
               ? WS::OPCODE_BINARY
               : WS::OPCODE_TEXT;

            $rsv1 = 0;
            $payload = $reply;
            if ($Session->Deflator !== null) {
               [$payload, $rsv1] = $Session->deflate($reply);
            }

            $frame = Frame::encode($opcode, $payload, true, $rsv1);

            $length = strlen($frame);
            return $frame;
         }
      }

      // : Intermediate fragment / handler sent out-of-band / nothing to write.
      $length = 0;
      return '';
   }
}
