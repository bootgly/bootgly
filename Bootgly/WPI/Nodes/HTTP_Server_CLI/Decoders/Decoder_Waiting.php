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


use function min;
use function substr;
use function time;

use const Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


class Decoder_Waiting extends Decoders implements Disconnecting
{
   // * Data
   //   Owning Request, bound at the decoder install site in Request::decode():
   //   body continuations must never resolve the worker-global Request —
   //   another connection may replace or claim it between transport reads.
   //   Final so `disconnect()` can drop it: an unset is only safe when no
   //   subclass can attach hooks to the slot.
   final public Request $Request;

   // * Metadata
   //   Share of the worker-wide unfinished-body budget held by this decoder.
   public protected(set) Bodies $Bodies;
   private int $decoded = 0;


   public function init (): void
   {
      $this->Bodies = new Bodies;
      $this->decoded = time();
   }

   /**
    * Transport teardown (`Connection::close()`), on every close path including
    * an abrupt peer EOF. Without this the retained body stays reachable from
    * the closed Package until the cycle collector happens to run — precisely
    * the window a burst of half-sent bodies exploits.
    */
   public function disconnect (): void
   {
      $this->Bodies->release();

      if ( isSet($this->Request) ) {
         $this->Request->Body->raw = '';
         $this->Request->Body->waiting = false;
         unset($this->Request);
      }
   }


   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var TCP_Packages $Package */
      // !
      $WPI = WPI;
      /** @var Server $Server */
      $Server = $WPI->Server;

      $Request = $this->Request;
      $Body = $Request->Body;

      // @ Check if Request Body is waiting data
      if ($Body->waiting) {
         // * Metadata
         if ($this->decoded === 0) {
            $this->decoded = time();
         }

         // ? Valid HTTP Client Body Timeout
         /**
          * Validate if the client sends the complete body within the absolute
          * 60-second body deadline.
          */
         $elapsed = time() - $this->decoded;
         if ($elapsed >= 60) {
            $Body->waiting = false;
            $this->Bodies->release();
            $Package->Decoder = null;
            $Package->consumed = 0;
            $Package->reject("HTTP/1.1 408 Request Timeout\r\n\r\n");
            return States::Rejected;
         }

         // @ Consume only bytes that belong to the declared body. Any bytes
         // after Content-Length remain in the current transport read and are
         // processed by TCP_Server_CLI's pipeline after this POST completes.
         $length = $Body->length ?? 0;
         $downloaded = $Body->downloaded ?? 0;
         $remaining = $length - $downloaded;
         $consumed = $remaining > 0 ? min($size, $remaining) : 0;

         // ? The per-request cap bounds THIS body; the worker budget bounds the
         //   sum of every unfinished one. Reserve before appending: a body that
         //   does not fit must never be allocated in the first place.
         if ($consumed > 0 && $this->Bodies->reserve($downloaded + $consumed) === false) {
            $Body->waiting = false;
            $Body->raw = '';
            $this->Bodies->release();
            $Package->Decoder = null;
            $Package->consumed = 0;
            $Package->reject("HTTP/1.1 503 Service Unavailable\r\n\r\n");
            return States::Rejected;
         }

         if ($consumed > 0) {
            $Body->raw .= $consumed === $size
               ? $buffer
               : substr($buffer, 0, $consumed);
         }

         $downloaded += $consumed;
         $Body->downloaded = $downloaded;
         $Package->consumed = $consumed;

         if ($downloaded < $length) {
            return States::Incomplete;
         }

         // ! The body is complete: it is now the Request's, dispatched and
         //   freed with it, so it no longer belongs to the unfinished budget.
         $this->Bodies->release();

         $Body->waiting = false;
         $Package->Decoder = null;
         // ! Restore the worker-global Request before the response cycle:
         //   `Encoder_::encode()` serializes `Server::$Request` (mirror of
         //   the L1-hit path in `Decoder_::decode()`).
         Server::$Request = $Request;
         return States::Complete;
      }

      $Package->Decoder = null;
      return $Server::$Decoder->decode($Package, $buffer, $size); // @phpstan-ignore method.nonObject
   }
}
