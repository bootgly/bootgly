<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const PHP_EOL;
use const PHP_INT_MAX;
use function get_resource_type;
use function hrtime;
use function is_finite;
use function is_resource;
use function microtime;
use function min;
use function stream_select;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function strlen;
use function strpos;
use Fiber;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Outcomes;


/**
 * The datagrams of a client connection carry its credential headers, so a
 * debug dump masks them.
 */
class Packages implements WPI\Connections\Packages
{
   use Redactable;


   // * Config
   // ...

   // * Data
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }

   // # IO
   #[Secret]
   public string $output;
   #[Secret]
   public string $input;

   // * Metadata
   public int $written;
   public int $read;
   // # Stats
   public int $writes;
   public int $reads;
   /** @var array<string,int> */
   public array $errors;
   // # Expiration
   public bool $expired;

   public Connection $Connection;


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      // * Config
      // ...

      // * Data
      // # IO
      $this->output = '';
      $this->input = '';

      // * Metadata
      $this->written = 0;         // Output datagram length (bytes sent).
      $this->read = 0;            // Input datagram length (bytes received).
      // # Stats
      $this->writes = 0;          // Socket Write count
      $this->reads = 0;           // Socket Read count
      $this->errors['write'] = 0; // Socket Writing errors
      $this->errors['read'] = 0;  // Socket Reading errors
      // # Expiration
      $this->expired = false;
   }

   /**
    * Handle failed package operation.
    *
    * @param resource $Socket
    * @param string $operation
    * @param mixed $result
    *
    * @return bool
    */
   public function fail ($Socket, string $operation, mixed $result): bool
   {
      // UDP has no end-of-stream — we only close if the socket itself
      // has vanished (e.g. explicit fclose from somewhere else).
      if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
         $this->Logger->log(
            warning: 'Failed to ' . $operation . ' datagram: socket gone, closing connection...' . PHP_EOL
         );

         $this->Connection->close();
      }
      else {
         $this->Logger->log(
            warning: 'Failed to ' . $operation . ' datagram.' . PHP_EOL
         );
      }

      // ? Embedded: the flow counts its own errors
      if ($this->Connection->Client !== null) {
         $this->errors[$operation]++;

         return false;
      }

      Connections::$errors[$operation]++;

      return false;
   }
   /**
    * Send the queued datagram to the server.
    *
    * The output buffer is authoritative and datagrams are atomic: a
    * successful send consumes the whole buffer and drops the EVENT_WRITE
    * registration — arm it AFTER queueing `output`; one arm delivers one
    * datagram. A failed send keeps buffer and registration, so the next
    * write-ready wakeup retries.
    *
    * @param resource $Socket
    * @param null|int<0, max> $length
    *
    * @return bool
    */
   public function writing (&$Socket, null|int $length = null): bool
   {
      $buffer = $this->output;

      // ? Nothing queued — a spurious write-ready wakeup delivers nothing.
      //   Drop the stale registration: the reactor is level-triggered and a
      //   datagram socket is essentially always writable, so keeping it
      //   would redispatch this no-op forever.
      if ($buffer === '') {
         $this->disarm($Socket);

         return true;
      }

      $expected = strlen($buffer);

      try {
         // @ The client socket is "connected" (bound to remote peer),
         //   so sendto with no explicit address uses that peer.
         $sent = @stream_socket_sendto($Socket, $buffer);
      }
      catch (Throwable) {
         $sent = false;
      }

      // ? Failed — buffer and registration stay untouched for the retry.
      if ($sent === false || $sent < 0) {
         return $this->fail($Socket, 'write', $sent);
      }

      // @ UDP is lossy by design: short writes just get logged.
      if ($sent !== $expected) {
         $this->Logger->log(
            warning: "Short datagram send: {$sent} of {$expected} bytes." . PHP_EOL
         );
      }

      // @ Consume the datagram — atomic; the buffer never re-sends.
      $this->output = '';
      $this->written = $sent;

      $Client = $this->Connection->Client;

      // @ Set Stats
      // ? Embedded: the flow always counts its own
      if ($Client !== null) {
         $this->Connection->writes++;
      }
      else if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $sent;
         // Per client
         $this->Connection->writes++;
      }

      // ? One datagram per arm: drop the registration BEFORE the hook so a
      //   hook that re-arms is not silently disarmed right after.
      $this->disarm($Socket);

      // # Hook
      if ($Client !== null) {
         $Hook = $Client->Callbacks['datagramWrite'] ?? null;
         if ($Hook !== null) {
            $Hook($Socket, $this->Connection, $this);
         }
      }
      else if (Client::$onDatagramWrite) {
         (Client::$onDatagramWrite)($Socket, $this->Connection, $this);
      }

      return true;
   }
   /**
    * Receive one datagram from the server.
    *
    * @param resource $Socket
    * @param null|int<1,max> $length
    * @param null|int<0,max> $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      try {
         $buffer = @stream_socket_recvfrom($Socket, $length ?? 65535);
      }
      catch (Throwable) {
         $buffer = false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read', $buffer);
      }

      // @ Empty datagram: no data available right now.
      if ($buffer === '') {
         return false;
      }

      $this->accept($Socket, $buffer);

      return true;
   }

   /**
    * Wait for one datagram from the server, bounded by `$timeout` seconds.
    *
    * The wait never outlives its bound, measured on the monotonic clock. Probe
    * first; then, on a reactor adopted by an embedded client (`react()`),
    * park the calling Fiber through the client's wait bridge (`schedule()`)
    * in slices of at most one second — the host keeps serving its other
    * descriptors meanwhile; any other client blocks in `stream_select()`.
    *
    * A zero-byte datagram is a datagram (`Outcomes::Received`, `input` is
    * `''`). A wait cut short by an unwind (a cancelled Fiber, an exception
    * from the bridge) closes this flow before it propagates: an abandoned
    * wait never leaks its descriptor (a client dropped without `stop()`
    * keeps its flows open until the cycle collector runs: stop it in a
    * `finally`). Never combine it with a persistent `EVENT_READ`
    * registration of the same socket on the reactor: the wait reads the
    * datagram first, and the persistent reader then reports a failed read.
    *
    * @param float $timeout Seconds to wait (finite, `>= 0`; `0` probes once).
    *
    * @throws InvalidArgumentException On a negative or non-finite timeout, or
    *    one too large for the monotonic clock (about 290 years).
    * @throws LogicException On an adopted reactor without the wait bridge or
    *    outside a Fiber — waiting there would block the host's whole loop.
    *
    * @return Outcomes Why the wait ended.
    */
   public function receive (float $timeout): Outcomes
   {
      // ? A finite, non-negative bound the monotonic clock can hold
      $now = (int) hrtime(true);
      if (
         is_finite($timeout) === false || $timeout < 0.0
         || $timeout >= (PHP_INT_MAX - $now) / 1_000_000_000
      ) {
         throw new InvalidArgumentException(
            'A datagram wait needs a finite timeout of zero or more seconds, within the monotonic clock\'s range.'
         );
      }

      $Connection = $this->Connection;
      $Socket = $Connection->Socket;
      $Client = $Connection->Client;
      $Wait = $Client?->Wait;
      // ! Parked only on an adopted reactor; never block a host's loop
      $parked = $Client !== null && $Client->owned === false;
      if ($parked && ($Wait === null || Fiber::getCurrent() === null)) {
         throw new LogicException(
            'A datagram wait on an adopted reactor parks through the wait bridge: call schedule() and wait inside a Fiber.'
         );
      }

      // ! One monotonic bound for the whole wait
      $deadline = $now + (int) ($timeout * 1_000_000_000);
      $Readiness = null;
      // ! Non-suspending-wait tripwire (the TCP client's bound)
      $stalled = 0;
      $fast = false;
      $settled = false;
      $Outcome = Outcomes::Expired;

      try {
         // @@ Probe, then wait, until a datagram, a failure or the bound
         while (true) {
            // ? Closed under the wait (stop(), close(), another Fiber)
            if ($Connection->status !== Connection::STATUS_ESTABLISHED || is_resource($Socket) === false) {
               $Outcome = Outcomes::Closed;
               break;
            }

            // @ Probe without waiting
            $ready = $this->probe($Socket);
            if ($ready === null) {
               // ? The selector cannot watch this socket
               $Outcome = Outcomes::Rejected;
               break;
            }
            if ($ready) {
               $Outcome = $this->fetch($Socket);
               break;
            }

            // ? Non-suspending-wait tripwire: a fast wake with nothing to read
            //   is a revoked bridge (an ordinary release of this socket
            //   closes the flow, caught above) — fail, never hot-spin
            if ($fast) {
               if (++$stalled >= 8) {
                  $this->Logger->log(
                     warning: 'Datagram wait aborted: the wait bridge stopped suspending.@\;'
                  );
                  $Outcome = Outcomes::Stalled;
                  break;
               }
            }
            else {
               $stalled = 0;
            }

            // ? The bound
            $remaining = $deadline - (int) hrtime(true);
            if ($remaining <= 0) {
               $Outcome = Outcomes::Expired;
               break;
            }

            // @ Park in a slice of at most one second (wall-clock deadline for
            //   the reactor; the bound itself stays monotonic)
            if ($parked) {
               $slice = microtime(true) + min(1.0, $remaining / 1_000_000_000);
               $Readiness ??= Readiness::read($Socket, $slice);
               $before = (int) hrtime(true);
               try {
                  $Wait($Readiness->renew($slice));
               }
               catch (RuntimeException $Rejection) {
                  // ? Anything but the selector admission rejection is not ours
                  if (strpos($Rejection->getMessage(), 'selector admission') === false) {
                     throw $Rejection;
                  }

                  $this->Logger->log(
                     warning: 'Datagram wait aborted: selector admission rejected the socket.@\;'
                  );
                  $Outcome = Outcomes::Rejected;
                  break;
               }
               $fast = (int) hrtime(true) - $before < 100_000;

               continue;
            }

            // @ Block until readable or the bound (an interrupted select is
            //   re-proven by the probe above)
            $read = [$Socket];
            $write = null;
            $except = null;
            $seconds = (int) ($remaining / 1_000_000_000);
            $microseconds = (int) (($remaining % 1_000_000_000) / 1_000);
            try {
               @stream_select($read, $write, $except, $seconds, $microseconds);
            }
            catch (Throwable) {
               // ...
            }
            $fast = false;
         }

         $settled = true;
      }
      finally {
         // ? Left without settling — an unwind or a foreign exception: close
         //   the flow so the descriptor and its registration go together
         if ($settled === false) {
            $Connection->close();
         }
      }

      // :
      return $Outcome;
   }

   /**
    * Probe the socket for a pending datagram without waiting.
    *
    * @param resource $Socket
    *
    * @return null|bool Readable; `null` when the selector cannot watch it
    *    (twice: once more after a failed call, as an interrupted one
    *    succeeds the second time).
    */
   private function probe ($Socket): null|bool
   {
      // @@ At most two attempts
      for ($attempt = 0; $attempt < 2; $attempt++) {
         $read = [$Socket];
         $write = null;
         $except = null;
         try {
            $selected = @stream_select($read, $write, $except, 0, 0);
         }
         catch (Throwable) {
            $selected = false;
         }

         if ($selected !== false) {
            return $selected > 0;
         }
      }

      return null;
   }

   /**
    * Read the datagram a probe proved pending.
    *
    * A failed read of a readable socket is the peer refusing the flow (an
    * ICMP port unreachable surfaces here); an empty string is a zero-byte
    * datagram.
    *
    * @param resource $Socket
    */
   private function fetch ($Socket): Outcomes
   {
      try {
         $buffer = @stream_socket_recvfrom($Socket, 65535);
      }
      catch (Throwable) {
         $buffer = false;
      }

      if ($buffer === false) {
         $this->fail($Socket, 'read', $buffer);

         return Outcomes::Refused;
      }

      $this->accept($Socket, $buffer);

      return Outcomes::Received;
   }

   /**
    * Take one received datagram: input, counters and the read hook.
    *
    * @param resource $Socket
    */
   private function accept ($Socket, string $buffer): void
   {
      $received = strlen($buffer);

      // @ Set Input
      $this->input = $buffer;
      $this->read = $received;

      $Client = $this->Connection->Client;

      // ? Embedded: the flow always counts its own and fires its own hook
      if ($Client !== null) {
         $this->reads++;

         $Hook = $Client->Callbacks['datagramRead'] ?? null;
         if ($Hook !== null) {
            $Hook($Socket, $this->Connection, $this);
         }

         return;
      }

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $received;
      }

      // # Hook
      if (Client::$onDatagramRead) {
         (Client::$onDatagramRead)($Socket, $this->Connection, $this);
      }
   }

   /**
    * Drop the write registration of the socket (one datagram per arm).
    *
    * @param resource $Socket
    */
   private function disarm ($Socket): void
   {
      // ? Embedded: the reactor this flow lives in
      if ($this->Connection->Client !== null) {
         $Reactor = $this->Connection->Reactor;
         if ($Reactor !== null) {
            try {
               $Reactor->del($Socket, $Reactor::EVENT_WRITE);
            }
            catch (Throwable) {}
         }

         return;
      }

      if ( isSet(Client::$Event) ) {
         try {
            Client::$Event->del($Socket, Client::$Event::EVENT_WRITE);
         }
         catch (Throwable) {}
      }
   }

   public function write (&$Socket, null|int $length = null): bool
   {
      return false;
   }
   public function read (&$Socket): void
   {}
}
