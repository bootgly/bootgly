<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;


use const INF;
use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
use function fclose;
use function hrtime;
use function is_resource;
use function max;
use function microtime;
use function min;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function strpos;
use function time;
use Fiber;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Events;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Packages;


class Connection extends Packages
{
   /** @var resource */
   public $Socket;


   // * Config
   /** @var array<false|int> */
   public array $timers;
   public int $expiration;

   // * Data
   // # Owner (the TCP/WS client that opened this connection — dispatch back-ref).
   public null|Client $Client = null;
   /** Reactor this connection registered in — deregistration must target it. */
   private null|(Events&Loops&Scheduler) $Event = null;
   // # Remote
   public string $address;
   public int $port;

   // * Metadata
   public int $id;
   public bool $encrypted;
   /** True only when the remote peer, rather than a local abort, ended the stream. */
   public bool $peerEOF;
   // # Status
   public const int STATUS_INITIAL = 0;
   public const int STATUS_CONNECTING = 1;
   public const int STATUS_ESTABLISHED = 2;
   public const int STATUS_CLOSING = 4;
   public const int STATUS_CLOSED = 8;
   public int $status;
   // # State
   public int $started;
   public int $used;
   // # Stats
   #public int $reads;
   public int $writes;


   /**
    * @param resource $Socket
   * @param bool $secure Whether secure SSL/TLS handshake is required
    */
   public function __construct (
      &$Socket,
      bool $secure = false,
      null|Client $Client = null,
      null|float $deadline = null,
      null|int $monotonicDeadline = null
   )
   {
      $this->Socket = $Socket;
      $this->Client = $Client;
      // ! Stamp the reactor at construction: the owner may adopt another one
      //   later, but THIS socket lives in the reactor it was registered with
      $this->Event = $Client?->Event;


      // * Config
      $this->timers = [];
      $this->expiration = 10;

      // * Data
      // ... dynamicaly

      // * Metadata
      $this->id = (int) $Socket;
      $this->encrypted = false;
      $this->peerEOF = false;
      // # Status
      $this->status = self::STATUS_ESTABLISHED;
      // # Handler
      $this->started = time();
      $this->used = time();
      // # Stats
      $this->writes = 0;
      $this->reads = 0;


      // @ Set Remote Data if possible
      // IP:port
      $peer = stream_socket_get_name($Socket, false);
      if ($peer === false) {
         $this->close();
         return;
      }
      // * Data
      // @ Remote
      [$this->address, $this->port] = Peer::parse($peer);


      parent::__construct($this);

      // @ Call handshake if secure transport is enabled
      if ($secure && $this->handshake($deadline, $monotonicDeadline) === false) {
         return;
      }

      // @ Call On Connection connect
      if ($Client?->onClientConnect !== null) {
         ($Client->onClientConnect)($Socket, $this);
      }
   }

   public function close (): true
   {
      if ($this->status > self::STATUS_ESTABLISHED) {
         return true;
      }

      $this->status = self::STATUS_CLOSING;

      $Client = $this->Client;
      $Event = $this->Event;
      if ($Event !== null) {
         $Event->del($this->Socket, $Event::EVENT_WRITE);
         $Event->del($this->Socket, $Event::EVENT_READ);
      }

      try {
         @fclose($this->Socket);
      }
      catch (Throwable) {
         // ...
      }

      $this->status = self::STATUS_CLOSED;

      if ($Client?->onClientDisconnect !== null) {
         ($Client->onClientDisconnect)($this);
      }

      // @ Destroy itself
      // ! Local handle: PHP rejects this array write when chained through the
      //   client's protected(set) $Connections ("Cannot indirectly modify...",
      //   verified on 8.4/8.5 against this class); a local read sidesteps it
      if ($Client !== null) {
         $Connections = $Client->Connections;
         unset($Connections->Connections[$this->id]);
      }

      return true;
   }

   public function handshake (
      null|float $deadline = null,
      null|int $monotonicDeadline = null
   ): bool|int
   {
      // ! Adopted reactor, owner Fiber: the handshake parks instead of
      //   blocking the worker (BG-13 S6)
      $Client = $this->Client;
      $Wait = $Client?->Wait;
      $parked = $Client?->owned === false && Fiber::getCurrent() !== null;
      $Readiness = null;
      // ! A foreign bridge exception is never laundered into a TLS failure
      $Foreign = null;
      // ! Non-suspending-wait tripwire (same bound as the drain episode)
      $stalled = 0;
      $negotiation = false;
      $settled = false;

      try {
         stream_set_blocking($this->Socket, false);
         do {
            if (
               ($deadline !== null && microtime(true) >= $deadline)
               || ($monotonicDeadline !== null && (int) hrtime(true) >= $monotonicDeadline)
            ) {
               $negotiation = false;
               break;
            }

            $negotiation = @stream_socket_enable_crypto(
               $this->Socket,
               true,
               STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($negotiation === true || $negotiation === false) {
               break;
            }

            // @ Park until readable, then negotiate again (both deadlines
            //   are re-proven at the top of the loop)
            if ($Wait !== null && $parked) {
               $now = microtime(true);
               $slice = $deadline === null ? $now + 1.0 : min($deadline, $now + 1.0);
               if ($monotonicDeadline !== null) {
                  $slice = min($slice, $now + max(0.0, ($monotonicDeadline - (int) hrtime(true)) / 1_000_000_000));
               }
               $Readiness ??= Readiness::read($this->Socket, $slice);
               $before = hrtime(true);
               try {
                  $Wait($Readiness->renew($slice));
               }
               catch (Throwable $Rejection) {
                  // ? Anything but the selector admission rejection is not ours
                  if (
                     $Rejection instanceof RuntimeException
                     && strpos($Rejection->getMessage(), 'selector admission') !== false
                  ) {
                     $this->Logger->log(
                        warning: 'Parked handshake aborted: selector admission rejected the socket.@\;'
                     );
                  }
                  else {
                     $Foreign = $Rejection;
                  }

                  $negotiation = false;
                  break;
               }

               // ? Non-suspending-wait tripwire: a fast wake with nothing to
               //   read is usually a revoked bridge, but an ordinary reactor
               //   release (a del() on this socket) looks the same — the
               //   consecutive count is the margin that separates them
               // ! 8 consecutive: no reachable client path releases a parked
               //   socket more than once (connect() dels before parking;
               //   close()/expire() del then fclose) — fail the handshake,
               //   never hot-spin
               $read = [$this->Socket];
               $write = [];
               $except = null;
               if (
                  (int) hrtime(true) - $before < 100_000
                  && @stream_select($read, $write, $except, 0, 0) !== 1
               ) {
                  if (++$stalled >= 8) {
                     $this->Logger->log(
                        warning: 'Parked handshake aborted: the wait bridge stopped suspending.@\;'
                     );
                     $negotiation = false;
                     break;
                  }
               }
               else {
                  $stalled = 0;
               }

               continue;
            }

            do {
               $read = [$this->Socket];
               $write = [];
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
            } while (
               $selected === false
               && ($deadline === null || microtime(true) < $deadline)
               && ($monotonicDeadline === null || (int) hrtime(true) < $monotonicDeadline)
            );
            if ($selected !== 1) {
               $negotiation = false;
               break;
            }
         } while (true);

         $settled = true;
      }
      catch (Throwable) {
         $negotiation = false;
         $settled = true;
      }
      finally {
         // ? Left mid-negotiation — an unwind (the deferred context was
         //   cancelled and its Fiber collected): the socket is registered
         //   nowhere yet. The unwind is not an exception the catch above
         //   sees, which is what static analysis cannot know here
         /** @phpstan-ignore booleanAnd.alwaysFalse, identical.alwaysFalse */
         if ($settled === false && is_resource($this->Socket)) {
            @fclose($this->Socket);
            $this->status = self::STATUS_CLOSED;
         }
      }

      // ? Not ours — the caller sees it, not a fabricated TLS failure
      if ($Foreign !== null) {
         $this->close();

         throw $Foreign;
      }

      // @ Check negotiation
      if ($negotiation === false) {
         $this->close();
         return false;
      }
      else if ($negotiation === 0) {
         return 0;
      }

      $this->encrypted = true;

      return true;
   }

   public function __destruct ()
   {
      foreach ($this->timers as $id) {
         if ($id === false) {
            continue;
         }

         Timer::del($id);
      }
   }
}
