<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use function stream_socket_recvfrom;
use function strlen;
use Throwable;

use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;


/**
 * Dispatcher for the shared UDP listening socket.
 *
 * Only one instance per worker: registered with the event loop as the
 * EVENT_READ payload of `Server->Socket`. When `stream_select()` marks the
 * socket readable, the loop calls `reading()` here; we drain every pending
 * datagram, resolve each one to its per-peer Connection, feed `$input`,
 * and hand off to the Decoder (or the SAPI handler directly).
 */
class Router implements WPI\Connections\Packages
{
   use LoggableEscaped;


   public Server $Server;
   public Connections $Connections;


   public function __construct (Server &$Server, Connections &$Connections)
   {
      $this->Logger = new Logger(channel: __CLASS__);
      $this->Server = $Server;
      $this->Connections = $Connections;
   }

   /**
    * Drain every pending datagram from the shared socket and route each
    * one to its per-peer Connection.
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
      $Server = $this->Server;
      $Connections = $this->Connections;

      while (true) {
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
         if (Server::$Decoder) {
            $decoded = ($Connection->Decoder ?? Server::$Decoder)
               ->decode($Connection, $buffer, $received);

            if ($decoded > 0) {
               $Connection->write($Socket);
            }
         }
         else {
            // No decoder: run the SAPI handler directly on the raw input.
            $Connection->write($Socket);
         }
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
