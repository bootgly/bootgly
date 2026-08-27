<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


use function fclose;
use function hrtime;
use function max;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_socket_enable_crypto;
use function time;
use Throwable;

use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;


class Connection extends Packages
{
   /** @var resource */
   public $Socket;

   // * Config
   /** @var array<int> */
   public array $timers;
   public int $expiration;

   // * Data
   // @ Remote
   public string $ip;
   public int $port;

   // * Metadata
   public readonly int $id;
   public bool $encrypted;
   public bool $handshaking = false;
   public int $handshakeTimer = 0;
   public int $status;
   // @ State
   public int $started;
   public int $used;
   // @ Stats
   #public int $reads;
   public int $writes;
   // Last `writes` value observed by `expire()` (activity detection)
   protected int $expiredWrites;


   /**
    * @param resource $Socket
    */
   public function __construct (&$Socket, string $IP, int $port)
   {
      $this->Socket = $Socket;
      $this->ip = $IP;
      $this->port = $port;

      // * Config
      $this->timers = [];
      // ! Seeded from the server knob; a subclass may still overwrite it
      //   before guard() arms the timer on the TLS path
      $this->expiration = Server::$connectionIdleTimeout;

      // * Metadata
      $this->id = (int) $Socket;
      $this->encrypted = false;
      $this->handshaking = isSet(Server::$context['ssl']);
      $this->handshakeTimer = 0;
      $this->status = $this->handshaking
         ? Connections::STATUS_CONNECTING
         : Connections::STATUS_ESTABLISHED;
      // @ State
      $this->started = time();
      $this->used = time();
      // @ Stats
      #$this->reads = 0;
      $this->writes = 0;
      $this->expiredWrites = 0;

      parent::__construct($this);

      if ($this->handshaking) {
         // ! Defense in depth: Connections::connect() already makes accepted
         //   sockets nonblocking. Preserve the invariant for any other caller
         //   before the first readiness-driven crypto step.
         stream_set_blocking($this->Socket, false);
         $this->arm();
      }
      else {
         $this->guard();
      }
   }

   /** Arm the absolute monotonic TLS-handshake deadline. */
   private function arm (): void
   {
      $timeoutNS = (int) (max(0.001, Server::$handshakeTimeout) * 1_000_000_000);
      $deadlineNS = (int) hrtime(true) + $timeoutNS;

      $this->handshakeTimer = Server::$Event->defer(
         $deadlineNS,
         function (): void {
            if (
               $this->handshaking
               && $this->status <= Connections::STATUS_ESTABLISHED
            ) {
               Connections::$errors['connection']++;
               $this->close();
            }
         }
      );
   }

   /** Install idle expiration only after transport establishment. */
   private function guard (): void
   {
      // ! Idle expiration is gated by its own config, NOT by the stats
      //   flag: reaping idle connections is a resource-protection concern
      //   and must work with stats collection disabled (the default).
      if ($this->expiration > 0) {
         $timer = Timer::add(
            interval: $this->expiration,
            handler: [$this, 'expire'],
            args: [$this->expiration]
         );

         // @ Set Connection timeout expiration
         if ($timer) {
            $this->timers[] = $timer;
         }

         /*
         // @ Set Connection limit
         $this->timers[] = Timer::add(
            interval: 5,
            handler: [$this, 'limit'],
            args: [1000]
         );
         */
      }
   }

   public function handshake (): bool|int
   {
      if ($this->handshaking === false) {
         return true;
      }

      try {
         $negotiation = @stream_socket_enable_crypto(
            $this->Socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
         );
      }
      catch (Throwable) {
         $negotiation = false;
      }

      // @ Check negotiation
      if ($negotiation === false) {
         $this->close();
         return false;
      }
      else if ($negotiation === 0) {
         return 0;
      }
      else {
         if (
            isSet(Connections::$Connections[$this->id])
            && Connections::$pendingHandshakes > 0
         ) {
            Connections::$pendingHandshakes--;
         }

         $this->handshaking = false;
         $this->encrypted = true;
         $this->status = Connections::STATUS_ESTABLISHED;

         if ($this->handshakeTimer > 0) {
            Server::$Event->cancel($this->handshakeTimer);
            $this->handshakeTimer = 0;
         }

         // @ ALPN: hand the connection to the negotiated application
         //   protocol's installer (e.g. 'h2' → HTTP/2 decoder). Registered
         //   by nodes via `Server::$Protocols`; TLS-only cost.
         if (Server::$Protocols !== []) {
            $meta = stream_get_meta_data($this->Socket);
            $protocol = $meta['crypto']['alpn_protocol'] ?? '';

            if ($protocol !== '' && isSet(Server::$Protocols[$protocol])) {
               (Server::$Protocols[$protocol])($this);
            }
         }

         $this->guard();
      }

      return true;
   }

   public function check (): bool
   {
      // @ Check blacklist
      // Blocked IP
      if ( isSet(Connections::$blacklist[$this->ip]) ) {
         // TODO add timer to unblock
         return false;
      }

      return true;
   }
   public function expire (int $timeout): bool
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      // ! Per-instance snapshot (was a per-method `static` shared by every
      //   Connection in the worker — one busy connection masked the
      //   idleness of all others).
      // @ Activity since the previous tick: a completed write, or pending
      //   work still owned by this transport — a deferred exchange parked on
      //   the reactor writes nothing until it settles. Either renews the
      //   lease; neither is permanent: once the owner detaches and the reply
      //   is flushed, the following silent ticks reap it like any idle peer.
      if ($this->expiredWrites !== $this->writes || Ownership::check($this)) {
         $this->expiredWrites = $this->writes;
         $this->used = time();
      }

      if ((time() - $this->used) >= $timeout) {
         return $this->close();
      }

      return false;
   }
   public function limit (int $packages): bool
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      static $writes = 0;

      if (($this->writes - $writes) >= $packages) {
         Connections::$blacklist[$this->ip] = true;
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }

   public function close (): true
   {
      // ! A direct backpressured upload may retain its caller-supplied handler
      //   reference between EVENT_WRITE callbacks. Every transport-close path
      //   drops that cursor immediately instead of waiting for cycle collection.
      try {
         $this->release();
      }
      catch (Throwable) {
         // Transport teardown must still reach the descriptor and CLOSED state.
      }

      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      // ! Commit the terminal transition before invoking any owner. A
      //   re-entrant close becomes a no-op and a re-entrant/late attach is
      //   notified immediately instead of entering the fresh owner storage.
      $this->status = Connections::STATUS_CLOSING;

      // ! Keep the accepted descriptor independently of extension callbacks;
      //   public package state can be mutated during teardown, but the socket
      //   selected for this terminal transition must still be closed.
      $Socket = $this->Socket;

      // ! Capture and clear protocol slots before ANY ownership callback.
      //   Re-entrant close cannot rediscover either teardown target, while the
      //   local references guarantee both original targets are still notified.
      $Decoder = $this->Decoder;
      $this->Decoder = null;
      $Decoded = $this->decoded;
      $this->decoded = null;

      if ($this->handshakeTimer > 0) {
         $timer = $this->handshakeTimer;
         $this->handshakeTimer = 0;

         try {
            Server::$Event->cancel($timer);
         }
         catch (Throwable) {
            // A scheduler failure cannot suppress transport teardown.
         }
      }

      // ! Cancel per-connection timers on the first close transition. The
      //   persistent expire() task holds [$this, 'expire'] in the static
      //   Timer::$tasks map — a GC root — so __destruct() (which also dels)
      //   can never run while the timer lives: without this, every closed
      //   connection is retained for the worker lifetime and its timer keeps
      //   firing. Safe re-entrancy: tick() checks the task status only after
      //   the callback returns, so a del from inside expire()->close() still
      //   suppresses the requeue.
      $timers = $this->timers;
      $this->timers = [];
      foreach ($timers as $id) {
         try {
            Timer::del($id);
         }
         catch (Throwable) {
            // Continue closing even if a timer backend rejects cancellation.
         }
      }

      // @ Protocol-independent pending work (for example, an HTTP deferred
      //   exchange waiting on a database socket) must terminate even though it
      //   is not the active connection decoder and will not be resumed by
      //   removing this client socket from the selector.
      try {
         Ownership::close($this);
      }
      catch (Throwable) {
         // Registry teardown is subordinate to closing the client descriptor.
      }

      // @ Stateful decoder cleanup: an incomplete protocol body owns resources
      //   independently from the decoded request/session. Abort it first on
      //   every transport close path, including abrupt peer EOF.
      if ($Decoder instanceof Disconnecting) {
         try {
            $Decoder->disconnect();
         }
         catch (Throwable) {
            // A protocol cleanup failure cannot strand the closing socket.
         }
      }

      // @ Protocol-unit cleanup: a decoded session (e.g. a WebSocket Session)
      //   runs its teardown exactly once on any close path.
      if ($Decoded instanceof Disconnecting) {
         try {
            $Decoded->disconnect();
         }
         catch (Throwable) {
            // Continue to selector removal and fclose after extension failure.
         }
      }

      // @ Receive-carry hygiene: a closing connection never revisits its
      //   bytes — do not pin a retained fragment until object destruction.
      $this->carry = '';
      $this->carried = false;

      // @ Request-data hygiene: the Connection is a self-cycle (Packages
      //   stores back the very object that inherits it), so refcounting alone
      //   never frees this graph — the cycle collector does, later. What must
      //   not wait for it is request data: the decoded Request (its body), the
      //   L0 template and its key are released here, on every close path, so
      //   the cycle retains only an empty shell until collection.
      $this->Template = null;
      $this->known = '';

      /*
      if ( isSet(Server::$context['ssl'] ) {
         try {
            stream_set_blocking($Socket, true);
            stream_socket_enable_crypto($Socket, false);
            stream_set_blocking($Socket, false);
         }
         catch (\Throwable) {}
      }
      */

      $this->writeRegistered = false;

      try {
         Server::$Event->del($Socket, Server::$Event::EVENT_READ);
      }
      catch (Throwable) {
         // The write lane and descriptor still require independent cleanup.
      }
      try {
         Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);
      }
      catch (Throwable) {
         // Descriptor closure remains the authoritative teardown boundary.
      }

      try {
         @fclose($Socket);
      }
      catch (Throwable) {
         // ...
      }

      $this->status = Connections::STATUS_CLOSED;

      // @ Destroy itself + release its per-IP slot (audit F-2). The decrement
      //   is gated on membership so it stays balanced: a connection shed by the
      //   per-IP ceiling (closed before it was ever established) is not counted
      //   and therefore not decremented.
      if ( isSet(Connections::$Connections[$this->id]) ) {
         unset(Connections::$Connections[$this->id]);

         if ($this->handshaking && Connections::$pendingHandshakes > 0) {
            Connections::$pendingHandshakes--;
         }

         if ( isSet(Connections::$ipConnections[$this->ip]) ) {
            if ( --Connections::$ipConnections[$this->ip] <= 0 ) {
               unset(Connections::$ipConnections[$this->ip]);
            }
         }
      }

      return true;
   }

   public function __destruct ()
   {
      // @ Fallback for construction/test paths that let the object die
      //   without an explicit close transition.
      $this->release();

      // ? Half-constructed instances (constructor threw) have no timers yet
      if (isSet($this->timers) === false) { // @phpstan-ignore isset.initializedProperty
         return;
      }

      if ($this->handshakeTimer > 0) {
         Server::$Event->cancel($this->handshakeTimer);
      }

      foreach ($this->timers as $id) {
         Timer::del($id);
      }
   }
}
