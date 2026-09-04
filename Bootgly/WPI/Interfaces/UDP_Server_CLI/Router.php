<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use function max;
use function stream_socket_recvfrom;
use function strlen;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


/**
 * Dispatcher for the shared UDP listening socket.
 *
 * Only one instance per worker: registered with the event loop as the
 * EVENT_READ payload of `Server->Socket`. When `stream_select()` marks the
 * socket readable, the loop calls `reading()` here; one bounded turn drains
 * at most its configured batch, resolving each datagram to its per-peer
 * Connection and handing it to the Decoder (or SAPI handler directly).
 */
class Router implements WPI\Connections\Packages
{
   public Logger $Logger;


   public Server $Server;
   public Connections $Connections;
   private int $batch;


   /**
    * @param Server $Server Owning UDP server.
    * @param Connections $Connections Peer registry and admission controller.
    * @param int $batch Maximum datagrams per readiness turn.
    */
   public function __construct (
      Server &$Server, Connections &$Connections, int $batch = 64
   )
   {
      $this->Logger = new Logger(channel: __CLASS__, global: true);
      $this->Server = $Server;
      $this->Connections = $Connections;
      $this->batch = $batch;
   }

   /**
    * Drain one bounded batch from the shared socket and route each datagram
    * to its per-peer Connection.
    *
    * @param resource $Socket
    * @param null|int $length
    * @param null|int $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      $Connections = $this->Connections;

      // @@ A finite batch returns control to signal dispatch, timers and other
      //    ready descriptors even while datagrams arrive continuously.
      $limit = max(1, $this->batch);
      for ($datagrams = 0; $datagrams < $limit; $datagrams++) {
         $peer = '';

         try {
            $buffer = @stream_socket_recvfrom($Socket, 65535, 0, $peer);
         }
         catch (Throwable) {
            $buffer = false;
         }

         if ($buffer === false || $buffer === '') {
            break;
         }

         $received = strlen($buffer);

         // @ Resolve / create per-peer Connection
         $Connection = $Connections->accept($peer);
         if ($Connection === null) {
            Connections::$errors['read']++;
            continue;
         }

         // @ Feed input
         $Connection->changed = ($Connection->input !== $buffer);
         if ($Connection->cache === false || $Connection->changed === true) {
            $Connection->input = $buffer;
         }

         // @ Stats
         if (Connections::$stats) {
            Connections::$reads++;
            Connections::$read += $received;
         }

         // @ Decode + respond
         $DefaultDecoder = Server::$Decoder;
         if ($DefaultDecoder) {
            $Connection->consumed = 0;
            $Connection->rejected = false;
            try {
               $Decoder = $Connection->Decoder ?? $DefaultDecoder;
            }
            catch (Throwable) {
               $Connection->close();
               unset($Connection);
               Lease::drain();
               continue;
            }
            if (ConnectionAuthority::check($Connection) === false) {
               unset($Connection);
               Lease::drain();
               continue;
            }
            $state = $Decoder->decode($Connection, $buffer, $received);

            if (
               $state === States::Complete
               && ConnectionAuthority::check($Connection) // @phpstan-ignore booleanAnd.rightAlwaysTrue
            ) {
               $Connection->write($Socket);
            }
         }
         else {
            // No decoder: run the SAPI handler directly on the raw input.
            $Connection->write($Socket);
         }
         unset($Connection);
         Lease::drain();
      }

      return true;
   }
   public function writing (&$Socket, null|int $length = null): bool
   {
      // UDP datagrams are emitted synchronously by Packages::writing().
      // No EVENT_WRITE registration is needed on the shared server socket.
      return true;
   }
   public function read (&$Socket): void
   {
      // N/A
   }
   public function write (&$Socket, null|int $length = null): bool
   {
      return false;
   }
}
