<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use const STREAM_PF_UNIX;
use const STREAM_SOCK_DGRAM;
use function stream_socket_pair;
use BackedEnum;
use Closure;
use InvalidArgumentException;

use Bootgly\ABI\Configs as Configuring;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Event;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Modules\WS\Server;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\WS_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Server_CLI\Relay;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


class WS_Server_CLI extends TCP_Server_CLI implements WS, Server
{
   // # Configs
   /** The Configs carrying the socket — host, port and workers. */
   protected const string TRANSPORT = WS_Server_CLI\Configs::class;
   /** @var array<int,class-string<Configuring>> Every Configs this node applies. */
   protected const array CONFIGS = [WS_Server_CLI\Configs::class];

   // * Config
   // ...inherited from TCP_Server_CLI

   // * Data
   // ...inherited from TCP_Server_CLI
   // # Hooks
   //   ServerStarted / ServerStopped are inherited from TCP_Server_CLI.
   //   Connected / Disconnected live on Session — they are fired from the
   //   decoder/encoder/session layer, which cannot depend on this node class.
   // # Cross-worker broadcast bus
   //   One datagram socketpair per worker, built on the master before fork so
   //   every worker (incl. SIGCHLD-recovered ones) inherits all ends.
   /** @var array<int, array<int, resource>> */
   protected array $buses = [];

   // * Metadata
   // ...inherited from TCP_Server_CLI
   // # Socket
   protected string $process = 'Bootgly_WS_Server';


   public function __construct (Modes $Mode = Modes::Daemon)
   {
      // \
      parent::__construct($Mode);

      // * Config
      $this->socket = $this->secure !== null
         ? 'wss'
         : 'ws';
      // @ Configure Logger
      $this->Logger = new Logger(channel: 'WS.Server.CLI', global: true);

      // . Decoders, Encoders
      self::$Decoder = new Decoder_;
      // @ Shared, stateless frame decoder swapped in per-connection after the
      //   handshake (all per-connection state lives on the Session).
      Decoders::$Framing = new Decoder_Framing;

      switch ($Mode) {
         case Modes::Test:
            self::$Encoder = new Encoder_Testing;
            break;
         default:
            self::$Encoder = new Encoder_;
      }
   }

   /**
    * Configure the WebSocket Server.
    *
    * Every concern arrives as its own Configs value object, in any order:
    * `configure(new WS_Server_CLI\Configs(host: '0.0.0.0', port: 8080, workers: 4))`.
    *
    * @param Configuring ...$Configs One Configs per concern.
    *
    * @return self The WebSocket Server instance, for chaining.
    */
   public function configure (Configuring ...$Configs): self
   {
      parent::configure(...$Configs);

      // :
      return $this;
   }
   /**
    * Apply one Configs to this server.
    *
    * @throws InvalidArgumentException On a Configs this node does not accept.
    */
   protected function adopt (Configuring $Config): void
   {
      // ? Anything else belongs to the transport — which also rejects it
      if ($Config instanceof WS_Server_CLI\Configs === false) {
         parent::adopt($Config);

         return;
      }

      parent::adopt($Config);

      if ($Config->host === '0.0.0.0') {
         $this->domain ??= 'localhost';
      }

      // * Config
      $this->socket = $this->secure !== null
         ? 'wss://'
         : 'ws://';

      // @ Session policy
      Session::$heartbeatInterval = $Config->heartbeatInterval;
      Session::$idleTimeout = $Config->idleTimeout;
      Session::$maxFrameSize = $Config->maxFrameSize;
      Session::$maxMessageSize = $Config->maxMessageSize;
      // @ Handshake policy
      Handshake::$subprotocols = $Config->subprotocols;
      Handshake::$compression = $Config->compression;
      Handshake::$Guards = $Config->Guards;
      // @ Clear any prior custom upgrade predicate; on(HandshakeRequested) re-sets it.
      Handshake::$predicate = null;
      // @ HTTP fallback for plain (non-upgrade) requests — e.g. the client page
      Handshake::$fallback = $Config->Fallback;
      // @ Connection-exhaustion caps
      if ($Config->maxConnections !== null) {
         self::$maxConnections = $Config->maxConnections;
      }
      if ($Config->maxConnectionsPerIP !== null) {
         self::$maxConnectionsPerIP = $Config->maxConnectionsPerIP;
      }
   }

   /**
    * Register an event handler for the WebSocket Server.
    *
    * @param Event&BackedEnum $Event The event to listen to.
    * @param Closure $Callback The event callback.
    *
    * @return self The WebSocket Server instance, for chaining.
    */
   public function on (
      Event & BackedEnum $Event,
      Closure $Callback
   ): self
   {
      if ($Event instanceof Events === false) {
         throw new InvalidArgumentException('Invalid WebSocket Server event.');
      }

      if (isset($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }
      $this->Events[$Event->value] = true;

      match ($Event) {
         Events::HandshakeRequested => Handshake::$predicate = $Callback,
         Events::Connected => Session::$onConnected = $Callback,
         Events::MessageReceived => $this->listen($Callback),
         Events::Disconnected => Session::$onDisconnected = $Callback,
         Events::ServerAdvertised => $this->onServerAdvertised = $Callback,
         Events::ServerStarted => $this->onServerStarted = $Callback,
         Events::ServerStopped => $this->onServerStopped = $Callback,
      };

      // :
      return $this;
   }

   private function listen (Closure $Callback): void
   {
      SAPI::$Handler = $Callback;
   }

   /**
    * Boot the Server API (honoring `Modes::Test`), or bail when no message
    * handler is wired. Overrides the base for the WS-specific error message.
    */
   protected function loading (): void
   {
      if (self::$Application) {
         self::$Application::boot(
            $this->Mode === Modes::Test
               ? Environments::Test
               : Environments::Production
         );
      }
      else if (isSet(SAPI::$Handler) === false) {
         $this->Logger->log(error: '@\;No message handler defined. Call on(Events::MessageReceived, ...) before start().@\;');
         exit(1);
      }
   }

   /**
    * Pre-fork setup: build the cross-worker broadcast bus — one datagram
    * socketpair per worker, created on the master before fork so every worker
    * inherits all ends. A single worker needs no bus.
    */
   protected function booting (): void
   {
      // ?
      if ($this->workers <= 1) {
         return;
      }

      // @
      for ($worker = 0; $worker < $this->workers; $worker++) {
         $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
         if ($pair === false) {
            $this->buses = [];
            break;
         }
         $this->buses[$worker] = $pair;
      }
   }

   /**
    * Per-worker wiring: attach this worker's cross-worker broadcast relay to the
    * event loop. No-op when running a single worker (no bus). Runs for both
    * initially-forked and SIGCHLD-recovered workers.
    */
   protected function wire (int $index): void
   {
      // ?
      if ($this->buses === []) {
         return;
      }

      // @
      Relay::$Instance = new Relay($this->buses, $index, $this->workers);
      self::$Event->add(
         Relay::$Instance->Socket,
         self::$Event::EVENT_READ,
         Relay::$Instance
      );
   }

   public static function boot (Environments $Environment): void
   {
      switch ($Environment) {
         case Environments::Test:
            // ! Test-suite wiring (Encoder_Testing dispatch + pretest) is implemented in Phase 7.
            self::$Encoder = new Encoder_Testing;
            break;
         default:
            self::$Encoder = new Encoder_;
      }
   }
}
