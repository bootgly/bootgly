<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use const INF;
use const PHP_EOL;
use function fclose;
use function hrtime;
use function is_resource;
use function max;
use function microtime;
use function min;
use function stream_select;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_get_name;
use function strpos;
use Closure;
use Fiber;
use RuntimeException;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


class Connections implements WPI\Connections
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   public null|Client $Client;

   // * Config
   public null|float $timeout;
   public bool $async;
   public bool $blocking;

   // * Data
   /** @var resource */
   public $Socket;

   // * Metadata
   // @ Error
   /** @var array<string> */
   public array $error = [];
   // @ Local
   /** @var array<int,Connection> */
   public array $Connections;
   // @ Stats
   public bool $stats;
   // Connections
   public int $connections;
   // Errors
   /** @var array<string,int> */
   public array $errors;
   // Packages
   public int $writes;
   public int $reads;
   public int $written;
   public int $read;

   public Packages $Packages;


   public function __construct (null|Client &$Client = null)
   {
      $this->Client = $Client;

      // * Config
      $this->timeout = 5;
      $this->async = true;
      $this->blocking = false;

      // * Data
      // ... dynamicaly

      // * Metadata
      // @ Error
      $this->error = [];
      // @ Remote
      $this->Connections = []; // Connections peers
      // @ Stats
      $this->stats = false;
      // Connections
      $this->connections = 0;  // Connections count
      // Errors
      $this->errors = [
         'connection' => 0,    // Socket Connection errors
         'write' => 0,         // Socket Writing errors
         'read' => 0           // Socket Reading errors
         // 'except' => 0
      ];
      // Packages
      $this->writes = 0;       // Socket Write count
      $this->reads = 0;        // Socket Read count
      $this->written = 0;      // Socket Writes in bytes
      $this->read = 0;         // Socket Reads in bytes
   }

   // Open connection with server / Connect with server
   public function connect (): bool
   {
      $Client = $this->Client;
      if ($Client === null) {
         $this->errors['connection']++;
         return false;
      }
      // ! By value: the client's slot is overwritten by the next dial, and a
      //   reference here would follow it (use-after-overwrite)
      $Socket = $Client->Socket;

      $Client->Event->del($Socket, $Client->Event::EVENT_CONNECT);

      try {
         // @ Set blocking
         stream_set_blocking($Socket, $this->blocking);

         // @ Set Buffer sizes
         stream_set_read_buffer($Socket, 0);
         #stream_set_write_buffer($Socket, 65535);

         // @ Set Chunk size
         #stream_set_chunk_size($Socket, 65535);

         // @ Import stream
         #if (function_exists('socket_import_stream') === true) {
         #   $Socket = socket_import_stream($Socket);

         #   socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);
         #   socket_set_option($Socket, SOL_TCP, TCP_NODELAY, 1);
         #}
      }
      catch (\Throwable) {
         $Socket = false;
      }

      if ($Socket === false || is_resource($Socket) === false) {
         $this->Logger->log(error: 'Socket connection is false or invalid!' . PHP_EOL);
         $this->errors['connection']++;
         return false;
      }

      // ! ASYNC_CONNECT only creates the socket. Do not construct a logical
      // connection (or begin TLS) until TCP is writable and the peer name is
      // available. One absolute deadline covers this wait and the handshake.
      $now = microtime(true);
      $nowMonotonic = (int) hrtime(true);
      $deadline = $Client->deadline;
      $monotonicDeadline = $Client->monotonicDeadline;
      if ($Client->connectTimeout > 0) {
         $connectDeadline = $now + $Client->connectTimeout;
         $connectMonotonicDeadline = $nowMonotonic
            + (int) ($Client->connectTimeout * 1_000_000_000);
         $deadline = $deadline === null
            ? $connectDeadline
            : min($deadline, $connectDeadline);
         $monotonicDeadline = $monotonicDeadline === null
            ? $connectMonotonicDeadline
            : min($monotonicDeadline, $connectMonotonicDeadline);
      }
      if (
         ($deadline !== null && $deadline <= $now)
         || ($monotonicDeadline !== null && $monotonicDeadline <= $nowMonotonic)
      ) {
         fclose($Socket);
         $this->errors['connection']++;
         return false;
      }

      // @ Adopted reactor, owner Fiber: park on writability instead of blocking
      //   the worker (BG-13 S6). Reactor-stack code never dials (D4), so the
      //   blocking branch below is the self-driving client's own
      if ($Client->Wait !== null && $Client->owned === false && Fiber::getCurrent() !== null) {
         $selected = $this->parking($Socket, $Client->Wait, $deadline, $monotonicDeadline) ? 1 : 0;
      }
      else {
         do {
            $read = [];
            $write = [$Socket];
            $except = null;
            if ($deadline === null && $monotonicDeadline === null) {
               $selected = @stream_select($read, $write, $except, null);
            }
            else {
               $remaining = $deadline === null
                  ? INF
                  : max(0.0, $deadline - microtime(true));
               if ($monotonicDeadline !== null) {
                  $remaining = min(
                     $remaining,
                     max(0.0, ($monotonicDeadline - (int) hrtime(true)) / 1_000_000_000)
                  );
               }
               $seconds = (int) $remaining;
               $microseconds = (int) (($remaining - $seconds) * 1_000_000);
               $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
            }
            // SIGALRM parent watchdogs legitimately interrupt select. Retry only
            // while a finite caller deadline still bounds a persistent failure.
         } while (
            $selected === false
            && ($deadline === null || microtime(true) < $deadline)
            && ($monotonicDeadline === null || (int) hrtime(true) < $monotonicDeadline)
         );
      }
      if (
         $selected !== 1
         || @stream_socket_get_name($Socket, true) === false
      ) {
         fclose($Socket);
         $this->errors['connection']++;
         return false;
      }

      // @ Instance new connection
      $secure = $Client->secure !== null;
      $Connection = new Connection(
         $Socket,
         $secure,
         $Client,
         $deadline,
         $monotonicDeadline
      );
      if ($Connection->status !== Connection::STATUS_ESTABLISHED || ($secure && $Connection->encrypted === false)) {
         $this->errors['connection']++;
         return false;
      }

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      $this->Connections[(int) $Socket] = $Connection;

      return true;
   }

   /**
    * Park the owner Fiber until a dialing socket is writable (adopted reactor).
    *
    * Probe first, then park in finite slices: every wake re-probes the socket
    * before re-parking, and the dial deadline is re-proven at the top of
    * every slice. The socket is registered nowhere yet, so an unwind mid-dial
    * (the deferred context was cancelled and its Fiber collected) closes it
    * here.
    *
    * @param resource $Socket
    * @param Closure(Readiness):mixed $Wait
    *
    * @return bool Whether the socket became writable before the deadline.
    */
   private function parking ($Socket, Closure $Wait, null|float $deadline, null|int $monotonicDeadline): bool
   {
      $Readiness = Readiness::write($Socket, microtime(true));
      $writable = false;
      $settled = false;
      // ! Non-suspending-wait tripwire (same bound as the drain episode)
      $stalled = 0;

      try {
         // ? Already writable: the dial resolved before it could park — do not
         //   spend a reactor round-trip proving it
         $read = [];
         $write = [$Socket];
         $except = null;
         $writable = @stream_select($read, $write, $except, 0, 0) === 1;

         // @@ Park until writable or expired
         while ($writable === false) {
            $now = microtime(true);
            if (
               ($deadline !== null && $now >= $deadline)
               || ($monotonicDeadline !== null && (int) hrtime(true) >= $monotonicDeadline)
            ) {
               break;
            }

            // ! Parked waits always carry a finite deadline; both dial clocks
            //   are re-proven at the top of every slice
            $slice = $deadline === null ? $now + 1.0 : min($deadline, $now + 1.0);
            if ($monotonicDeadline !== null) {
               $slice = min($slice, $now + max(0.0, ($monotonicDeadline - (int) hrtime(true)) / 1_000_000_000));
            }
            $before = hrtime(true);
            try {
               $Wait($Readiness->renew($slice));
            }
            catch (RuntimeException $Rejection) {
               // ? Anything but the selector admission rejection is not ours
               if (strpos($Rejection->getMessage(), 'selector admission') === false) {
                  throw $Rejection;
               }

               // ? The reactor refused the socket (fd budget): the dial fails
               //   deterministically
               $this->Logger->log(
                  warning: 'Parked dial aborted: selector admission rejected the socket.@\;'
               );
               break;
            }

            // @ Re-probe: writable means connected or refused — the caller
            //   tells them apart by the peer name
            $read = [];
            $write = [$Socket];
            $except = null;
            $writable = @stream_select($read, $write, $except, 0, 0) === 1;

            // ? Non-suspending-wait tripwire: a fast wake with nothing to
            //   show is usually a revoked bridge, but an ordinary reactor
            //   release (a del() on this socket) looks the same — the
            //   consecutive count is the margin that separates them
            // ! 8 consecutive: no reachable client path releases a parked
            //   socket more than once (connect() dels before parking;
            //   close()/expire() del then fclose) — fail the dial, never
            //   hot-spin
            if ($writable === false && (int) hrtime(true) - $before < 100_000) {
               if (++$stalled >= 8) {
                  $this->Logger->log(
                     warning: 'Parked dial aborted: the wait bridge stopped suspending.@\;'
                  );
                  break;
               }
            }
            else {
               $stalled = 0;
            }
         }

         $settled = true;
      }
      finally {
         // ? Left without settling — an unwind or a foreign exception: the
         //   caller never sees this socket again
         if ($settled === false && is_resource($Socket)) {
            @fclose($Socket);
         }
      }

      // :
      return $writable;
   }

   /**
    * Close connection with server / Disconnect from server
    * 
    * @param resource $Connection
    * 
    * @return bool
    */
   public function close ($Connection): bool
   {
      // @ Close all Connections
      #if ($Connection === null) {
      #   foreach(self::$Connections as $Connection) {
      #      $Connection->close();
      #   }

      #   return true;
      #}

      $connection = (int) $Connection;

      // @ Close specific Connection
      if ( isSet($this->Connections[$connection]) ) {
         $closed = $this->Connections[$connection]->close();
      }
      else {
         $closed = false;
      }

      // @ On success
      if ($closed) {
         // Remove closed connection from @peers
         #unset(self::$Connections[$connection]);

         return true;
      }

      return false;
   }
}
