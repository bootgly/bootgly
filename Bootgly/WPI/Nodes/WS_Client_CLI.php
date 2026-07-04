<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use function implode;
use function min;
use function preg_match;
use function strpos;
use function substr;
use BackedEnum;
use Closure;
use InvalidArgumentException;

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI\Event;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Modules\WS\Client;
use Bootgly\WPI\Nodes\WS_Client_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\WS_Client_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;
use Bootgly\WPI\Nodes\WS_Client_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * Native WebSocket client (RFC 6455 / RFC 7692), wire-compatible with
 * `WS_Server_CLI`. Connects over the TCP client transport, performs the upgrade
 * handshake, then exchanges masked frames with permessage-deflate, streaming
 * UTF-8 validation, fragmentation, ping/pong, and wss/TLS.
 *
 * ```php
 * $Client = new WS_Client_CLI();
 * $Client->configure(host: '127.0.0.1', port: 8083);
 * $Client
 *    ->on(Events::Connected, fn (Session $S) => $S->send('hello'))
 *    ->on(Events::MessageReceived, fn (Session $S, Message $M) => print($M->payload))
 *    ->on(Events::Disconnected, fn (Session $S) => print('closed'));
 * $Client->connect('/');   // blocks: runs the event loop until the connection closes
 * ```
 */
class WS_Client_CLI extends TCP_Client_CLI implements WS, Client
{
   // * Config
   // ...inherited from TCP_Client_CLI

   // * Data
   protected bool $compression = true;
   // # Session policy (set by configure(); read by Session + the frame decoder).
   //   Instance-scoped so multiple clients in one process do not share policy.
   public protected(set) int $heartbeatInterval = 0;
   public protected(set) int $maxFrameSize = 1048576;
   public protected(set) int $maxMessageSize = 8388608;
   // # Hooks (set by on(); fired by Session + the read loop). Instance-scoped so
   //   registering a handler on one client never clobbers another's.
   public private(set) null|Closure $onConnected = null;
   public private(set) null|Closure $onMessageReceived = null;
   public private(set) null|Closure $onDisconnected = null;
   // # Handshake policy
   protected int $handshakeTimeout = 10;    // seconds to receive + verify the 101 (0 = unbounded)
   // # Reconnect policy
   protected bool $reconnect = false;
   protected int $reconnectAttempts = 0;    // 0 = unlimited
   protected int $reconnectDelay = 1;       // base backoff (seconds)
   protected int $reconnectMaxDelay = 30;   // backoff cap (seconds)
   public null|Session $Session = null;
   protected Decoder_ $Decoder;
   protected Decoder_Framing $Framing;
   // # Handshake (armed by connect(), re-used on every re-dial)
   protected string $key = '';
   protected string $request = '';          // the encoded upgrade GET
   protected string $URI = '/';
   /** @var array<string,string> */
   protected array $headers = [];
   /** @var array<string> */
   protected array $subprotocols = [];      // offered subprotocols (validate the server's choice)

   // * Metadata
   protected int $attempt = 0;              // consecutive reconnect attempts (reset on connect)
   protected bool $wired = false;
   // # Concurrency (open()/run() — many clients on one shared loop)
   protected static bool $multi = false;    // concurrent mode active (set by open(), cleared by connect()/run())
   protected static int $open = 0;          // live concurrently-opened connections


   public function __construct (int $mode = self::MODE_DEFAULT)
   {
      // \
      parent::__construct($mode);

      // @ Configure Logger
      $this->Logger = new Logger(channel: 'WS.Client.CLI');

      // . Decoders (handshake response, then frame stream)
      $this->Decoder = new Decoder_;
      $this->Framing = new Decoder_Framing;
   }

   /**
    * Configure the WebSocket Client.
    *
    * @param int $workers Inherited from the transport, kept for signature compatibility
    *   with `TCP_Client_CLI::configure()`. The WS client opens a single blocking
    *   connection via `connect()` and does not fork, so this has no effect.
    * @param array<string,mixed>|null $secure Secure SSL/TLS Stream Context options (enables wss://).
    * @param bool $reconnect Auto re-dial after an abrupt drop (EOF / transport error). Off by default.
    * @param int $reconnectAttempts Max reconnect attempts before giving up (0 = unlimited).
    * @param int $reconnectDelay Base backoff in seconds (doubles each attempt, capped).
    * @param int $reconnectMaxDelay Backoff cap in seconds.
    * @param int $handshakeTimeout Seconds to receive + verify the 101 after dialing (0 = unbounded).
    *
    * @return self The WebSocket Client instance, for chaining.
    */
   public function configure (
      string $host, int $port, int $workers = 0,
      null|array $secure = null,
      int $heartbeatInterval = 0,
      int $maxFrameSize = 1048576,
      int $maxMessageSize = 8388608,
      bool $compression = true,
      bool $reconnect = false,
      int $reconnectAttempts = 0,
      int $reconnectDelay = 1,
      int $reconnectMaxDelay = 30,
      int $handshakeTimeout = 10
   ): self
   {
      // @ Auto-set peer_name for hostname verification if secure transport is enabled.
      if ($secure !== null && ! isSet($secure['peer_name'])) {
         $secure['peer_name'] = $host;
      }

      parent::configure($host, $port, $workers, $secure);

      // @ permessage-deflate offer toggle.
      $this->compression = $compression;
      // @ Handshake policy — bound the wait for the server's 101 so a peer that
      //   accepts TCP but never answers the upgrade cannot stall the loop forever.
      $this->handshakeTimeout = $handshakeTimeout;
      // @ Reconnect policy — auto re-dial with capped exponential backoff after an
      //   abrupt drop. Graceful closes (user/server close, fault) never reconnect.
      $this->reconnect = $reconnect;
      $this->reconnectAttempts = $reconnectAttempts;
      $this->reconnectDelay = $reconnectDelay;
      $this->reconnectMaxDelay = $reconnectMaxDelay;

      // @ Session policy (instance-scoped)
      $this->heartbeatInterval = $heartbeatInterval;
      $this->maxFrameSize = $maxFrameSize;
      $this->maxMessageSize = $maxMessageSize;

      return $this;
   }

   /**
    * Register an event handler for the WebSocket Client.
    *
    * @param Event&BackedEnum $Event The event to listen to.
    * @param Closure $Callback The event callback.
    *
    * @return self The WebSocket Client instance, for chaining.
    */
   public function on (
      Event & BackedEnum $Event,
      Closure $Callback
   ): self
   {
      if ($Event instanceof Events === false) {
         throw new InvalidArgumentException('Invalid WebSocket Client event.');
      }

      if (isSet($this->Events[$Event->value])) {
         throw new InvalidArgumentException("The event '{$Event->value}' is already registered.");
      }
      $this->Events[$Event->value] = true;

      match ($Event) {
         Events::Connected => $this->onConnected = $Callback,
         Events::MessageReceived => $this->onMessageReceived = $Callback,
         Events::Disconnected => $this->onDisconnected = $Callback,
      };

      // :
      return $this;
   }

   /**
    * Open a WebSocket connection: arm the upgrade request, dial the server, and
    * run the event loop until the connection closes (blocking).
    *
    * Overrides the inherited arg-less TCP `connect()` — the added parameters are
    * all optional, so the override stays substitutable.
    *
    * @param array<string,string> $headers Extra request headers (e.g. `Origin`, `Authorization`).
    * @param array<string> $subprotocols Subprotocols to offer, in preference order.
    *
    * @return resource|false The connected socket, or false on dial failure.
    */
   public function connect (string $URI = '/', array $headers = [], array $subprotocols = [])
   {
      // ! Single blocking connection — an abrupt drop reconnects (if enabled) and a
      //   graceful close tears down the whole loop so this call returns.
      self::$multi = false;

      // ! Arm the handshake template — re-used on every (re)dial.
      $this->URI = $URI;
      $this->headers = $headers;
      $this->subprotocols = $subprotocols;
      $this->attempt = 0;
      $this->wire();

      // @ Install the SIGALRM handler so the liveness supervisor + reconnect
      //   backoff timers can run under a direct connect() (no fork installs it);
      //   without it an alarm would terminate the process with signal 14.
      Timer::init(function () { Timer::tick(); });

      // @ Dial. On failure, retry with backoff when reconnect is enabled, else bail.
      $Socket = $this->dial();
      if ($Socket === false) {
         if ($this->reconnect === false) {
            return false;
         }
         $this->retry();
      }

      // @@ Re-enable + run the event loop until the connection closes (and, when
      //   reconnect is on, until the attempt budget is exhausted). A prior
      //   connection on this shared loop may have stopped it via destroy().
      self::$Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
      self::$Event->loop();

      // :
      return $Socket;
   }

   /**
    * Open a WebSocket connection WITHOUT running the event loop (non-blocking) — for
    * driving SEVERAL clients concurrently on one shared loop. Usage: construct every
    * client, call open() on each, then run() once. Reconnect does not apply here; an
    * abrupt drop simply removes the client from the shared loop.
    *
    * @param array<string,string> $headers Extra request headers (e.g. `Origin`, `Authorization`).
    * @param array<string> $subprotocols Subprotocols to offer, in preference order.
    *
    * @return resource|false The connected socket, or false on dial failure.
    */
   public function open (string $URI = '/', array $headers = [], array $subprotocols = [])
   {
      // ! Concurrent mode — the shared loop stops only when the LAST connection
      //   closes (see the onClientDisconnect router).
      self::$multi = true;

      // ! Arm the handshake template.
      $this->URI = $URI;
      $this->headers = $headers;
      $this->subprotocols = $subprotocols;
      $this->attempt = 0;
      $this->wire();

      // @ Install the SIGALRM handler for the per-session liveness supervisors.
      Timer::init(function () { Timer::tick(); });

      // @ Dial without running the loop; count the live connection for run().
      $Socket = $this->dial();
      if ($Socket !== false) {
         self::$open++;
      }

      // :
      return $Socket;
   }

   /**
    * Run the shared event loop until every concurrently-opened connection (open())
    * has closed. Pairs with open(); returns once the last one is gone.
    */
   public static function run (): void
   {
      // ? Nothing opened — nothing to run.
      if (self::$open <= 0) {
         return;
      }

      self::$Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
      self::$Event->loop();

      self::$multi = false;
   }

   /**
    * Generate a fresh nonce, build the upgrade request, and dial the server.
    * Re-used by connect() and the reconnect backoff timer.
    *
    * @return resource|false The connected socket, or false on dial failure.
    */
   private function dial ()
   {
      $this->key = Handshake::generate();
      $this->request = $this->build($this->URI, $this->headers, $this->subprotocols);

      // @ parent::connect() creates the Connection, which fires onClientConnect
      //   (queues the upgrade GET) inside its constructor.
      $Socket = parent::connect();

      // @ Arm the handshake deadline — a peer that accepts TCP but never answers
      //   the upgrade must not stall the event loop forever. Expiring counts as a
      //   handshake reject (graceful close), so it never triggers a reconnect.
      if ($Socket !== false && $this->handshakeTimeout > 0) {
         $Session = $this->Session;
         Timer::add(
            interval: $this->handshakeTimeout,
            handler: function () use ($Session) {
               if ($Session === null || $Session->established || $Session->disconnected) {
                  return;
               }

               $Session->closing = true;
               $Session->disconnect();
               $Session->Connection->close();
            },
            persistent: false
         );
      }

      // :
      return $Socket;
   }

   /**
    * Schedule an auto-reconnect with capped exponential backoff, or stop the
    * event loop once the attempt budget is exhausted.
    */
   protected function retry (): void
   {
      // ? Budget exhausted — give up and end the loop.
      if ($this->reconnectAttempts > 0 && $this->attempt >= $this->reconnectAttempts) {
         self::$Event->destroy();
         return;
      }

      // @ Capped exponential backoff, scheduled on the event loop (>= 1s, the
      //   SIGALRM granularity).
      $delay = (int) min(
         $this->reconnectMaxDelay,
         $this->reconnectDelay * (2 ** min($this->attempt, 16))
      );
      if ($delay < 1) {
         $delay = 1;
      }
      $this->attempt++;
      $this->Logger->log(debug: "Reconnecting (attempt {$this->attempt}) in {$delay}s...@\\;");

      Timer::add(
         interval: $delay,
         handler: function () {
            if ($this->dial() === false) {
               $this->retry();
            }
         },
         persistent: false
      );
   }

   /**
    * Build the RFC 6455 upgrade GET request.
    *
    * @param array<string,string> $headers
    * @param array<string> $subprotocols
    */
   private function build (string $URI, array $headers, array $subprotocols): string
   {
      // ? Reject CR/LF injection and malformed tokens — fail locally with a clear
      //   error rather than emit a corrupt or attacker-shaped request head (the
      //   demo feeds the URI/headers from environment variables).
      $token = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';
      if (preg_match('/[\r\n]/', $URI) === 1 || strpos($URI, ' ') !== false) {
         throw new InvalidArgumentException('Invalid request URI: CR/LF and spaces are not allowed.');
      }
      foreach ($headers as $name => $value) {
         if (preg_match($token, $name) !== 1) {
            throw new InvalidArgumentException("Invalid header name: '{$name}'.");
         }
         if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException("Invalid value for header '{$name}': CR/LF is not allowed.");
         }
      }
      foreach ($subprotocols as $subprotocol) {
         if (preg_match($token, $subprotocol) !== 1) {
            throw new InvalidArgumentException("Invalid subprotocol token: '{$subprotocol}'.");
         }
      }

      // @ Host header: include the port unless it is the scheme default.
      $host = (string) $this->host;
      $port = (int) $this->port;
      $defaultPort = $this->secure !== null
         ? 443
         : 80;
      $authority = $port === $defaultPort
         ? $host
         : "{$host}:{$port}";

      $request = "GET {$URI} HTTP/1.1\r\n"
         . "Host: {$authority}\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Key: {$this->key}\r\n"
         . "Sec-WebSocket-Version: 13\r\n";

      // @ Subprotocol offer.
      if ($subprotocols !== []) {
         $request .= 'Sec-WebSocket-Protocol: ' . implode(', ', $subprotocols) . "\r\n";
      }
      // @ permessage-deflate offer.
      if ($this->compression) {
         $request .= 'Sec-WebSocket-Extensions: ' . Handshake::offer() . "\r\n";
      }
      // @ Caller-supplied headers (Origin, Authorization, ...).
      foreach ($headers as $name => $value) {
         $request .= "{$name}: {$value}\r\n";
      }

      // :
      return "{$request}\r\n";
   }

   /**
    * Wire the connect/write/read/disconnect callbacks on the event loop.
    * Idempotent — only wires once.
    */
   private function wire (): void
   {
      if ($this->wired) {
         return;
      }
      $this->wired = true;

      // @ Instance-agnostic routers — each callback resolves the owning client from
      //   the Connection back-ref, so ONE shared closure serves N concurrent
      //   connections (each WS_Client_CLI owns exactly one connection + Session).

      // @ On TCP connect: create the session and queue the upgrade GET.
      self::$onClientConnect = function ($Socket, $Connection) {
         if ($Connection->Client instanceof self === false) {
            return;
         }
         $Client = $Connection->Client;

         $Session = new Session($Connection, $Client->key, $Client);
         // @ Carry what we offered so the decoder can reject unoffered choices.
         $Session->offeredSubprotocols = $Client->subprotocols;
         $Session->offeredCompression = $Client->compression;
         $Client->Session = $Session;
         $Connection->output = $Client->request;
         self::$Event->add($Socket, self::$Event::EVENT_WRITE, $Connection);
      };

      // @ After the request flushes, switch the socket to reading the response.
      self::$onDataWrite = function ($Socket, $Connection) {
         self::$Event->del($Socket, self::$Event::EVENT_WRITE);
         self::$Event->add($Socket, self::$Event::EVENT_READ, $Connection);
      };

      // @ On read: drive the handshake → framing decode loop.
      self::$onDataRead = function ($Socket, $Connection) {
         if ($Connection->Client instanceof self === false) {
            return;
         }
         $Client = $Connection->Client;

         $Session = $Client->Session;
         if ($Session === null || $Session->disconnected) {
            return;
         }

         // @ Prepend any carried partial-frame bytes from a previous read.
         $buffer = $Session->carry . $Connection->input;
         $Session->carry = '';

         while ($buffer !== '') {
            $Decoder = $Session->established
               ? $Client->Framing
               : $Client->Decoder;
            $result = $Decoder->decode($Session, $buffer);

            // ? Incomplete — buffer and wait for more bytes.
            if ($result === null) {
               $Session->carry = $buffer;
               break;
            }

            $consumed = $result['consumed'];

            // ? Handshake response invalid — drop the TCP connection (no reconnect).
            if (isSet($result['fail'])) {
               $Session->closing = true;
               $Session->disconnect();
               $Connection->close();
               return;
            }

            // ? Close received/sent or protocol fault — tear down.
            if (isSet($result['stop'])) {
               $Connection->close();
               return;
            }

            // ? 101 verified — establish (fires Connected), then decode any
            //   frames coalesced into this same read with the frame decoder.
            if (isSet($result['established'])) {
               $buffer = substr($buffer, $consumed);
               $Client->attempt = 0;   // reset the backoff after a successful (re)connection
               $Session->establish();
               continue;
            }

            // ? A complete message — surface it to the handler.
            if (isSet($result['message'])) {
               $buffer = substr($buffer, $consumed);
               $Message = $Session->Message;
               $Session->Message = null;
               if ($Client->onMessageReceived !== null && $Message !== null) {
                  ($Client->onMessageReceived)($Session, $Message);
               }
               continue;
            }

            // ? Control handled / intermediate fragment — advance and continue.
            if ($consumed <= 0) {
               $Session->carry = $buffer;
               break;
            }
            $buffer = substr($buffer, $consumed);
         }
      };

      // @ On TCP disconnect: fire the Disconnected hook, then either schedule a
      //   reconnect (abrupt drop + reconnect enabled) or stop the event loop so
      //   the blocking connect() returns.
      self::$onClientDisconnect = function ($Connection) {
         if ($Connection->Client instanceof self === false) {
            return;
         }
         $Client = $Connection->Client;

         $Session = $Client->Session;
         if ($Session !== null) {
            $Session->disconnect();
         }

         // ? Concurrent mode (open()/run()): drop this connection and stop the
         //   shared loop only when the LAST one closes. No per-client reconnect.
         if (self::$multi) {
            self::$open--;
            if (self::$open <= 0) {
               self::$Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
            }
            return;
         }

         // ? Single blocking connect(): reconnect only on an abrupt transport drop —
         //   not after a graceful close (user/server close frame, protocol fault, or
         //   handshake reject, which set Session->closing).
         if ($Client->reconnect && ($Session === null || $Session->closing === false)) {
            $Client->retry();
         }
         else {
            self::$Event->destroy();
         }
      };
   }

   /**
    * Tear down this client for reuse / test isolation: drop the current session
    * and clear the (process-wide static) transport callbacks this client wired.
    * Instance hooks (`on*`) and policy persist — a subsequent `connect()` re-wires.
    */
   public function reset (): void
   {
      $this->Session = null;
      $this->wired = false;

      self::$onClientConnect = null;
      self::$onClientDisconnect = null;
      self::$onDataRead = null;
      self::$onDataWrite = null;
   }
}
