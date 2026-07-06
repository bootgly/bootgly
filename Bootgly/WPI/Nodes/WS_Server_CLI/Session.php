<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use const ZLIB_ENCODING_RAW;
use const ZLIB_SYNC_FLUSH;
use function deflate_add;
use function deflate_init;
use function feof;
use function inflate_add;
use function inflate_init;
use function is_int;
use function pack;
use function str_ends_with;
use function strlen;
use function substr;
use function time;
use Closure;
use DeflateContext;
use InflateContext;

use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Server_CLI\Channels;
use Bootgly\WPI\Nodes\WS_Server_CLI\Channels\Channel;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Relay;


class Session implements Disconnecting
{
   // * Config
   // @ Session policy defaults, set by WS_Server_CLI::configure().
   public static int $heartbeatInterval = 30;       // seconds between server pings (0 disables)
   public static null|int $idleTimeout = null;      // seconds of inbound silence before reaping
   public static int $maxFrameSize = 1048576;       // 1 MiB — close 1009 on exceed
   public static int $maxMessageSize = 8388608;     // 8 MiB — close 1009 on exceed
   // # Hooks (set by WS_Server_CLI::on()).
   public static null|Closure $onConnected = null;
   public static null|Closure $onDisconnected = null;


   // * Data
   public Connection $Connection;
   public string $ip;
   public int $port;
   // @ Negotiated
   public string $subprotocol = '';
   /** @var array<string, mixed> */
   public array $extensions = [];
   // @ Compression (permessage-deflate, RFC 7692)
   public null|InflateContext $Inflator = null;
   public null|DeflateContext $Deflator = null;
   public bool $clientNoContextTakeover = false;
   public bool $serverNoContextTakeover = false;
   public int $serverWindowBits = 15;
   // @ Authentication (resolved by handshake guards, if any)
   public mixed $identity = null;
   public mixed $claims = null;
   public mixed $tokenHeaders = null;
   // @ Rooms (channels this session has joined)
   /** @var array<string, Channel> */
   public array $Channels = [];

   // * Metadata
   public readonly int $id;
   // @ Lifecycle
   public string $handshake = '';                   // pending 101 bytes; '' once sent
   public bool $established = false;
   public bool $disconnected = false;
   // @ Framing
   public string $carry = '';                       // trailing partial-frame bytes between reads
   public string $reassembly = '';                  // concatenated fragment payloads
   public int $reassemblyOpcode = 0;                // opcode of the first fragment; 0 = idle
   public bool $reassemblyCompressed = false;       // RSV1 of the first fragment (Phase 4)
   public string $utf8Pending = '';                 // trailing incomplete UTF-8 bytes carried between text fragments
   public null|Message $Message = null;             // completed message surfaced to the encoder
   public string $outbox = '';                      // server-queued control frames (pong / close)
   // @ Liveness
   public int $lastActivity = 0;
   public bool $awaitingPong = false;
   public int $timer = 0;


   public function __construct (Connection $Connection)
   {
      // * Data
      $this->Connection = $Connection;
      $this->ip = $Connection->ip;
      $this->port = $Connection->port;

      // * Metadata
      $this->id = $Connection->id;
      $this->lastActivity = time();
   }

   /**
    * Mark the connection established, install the heartbeat supervisor, and
    * fire the Connected hook.
    */
   public function establish (): void
   {
      // ?
      if ($this->established) {
         return;
      }
      $this->established = true;
      $this->lastActivity = time();

      // @ Replace the transport's short idle reaper with the WS supervisor:
      //   the inherited connection timer assumes request/response traffic; a
      //   long-lived socket is governed by ping/pong + idle-timeout instead.
      // ! Install the supervisor BEFORE cancelling the inherited timer so the
      //   timer status set never empties — otherwise Timer::del() would disarm
      //   the worker's SIGALRM and Timer::add() would not re-arm it (a stale
      //   empty runtime bucket keeps `$tasks` non-empty).
      $interval = self::$heartbeatInterval > 0
         ? self::$heartbeatInterval
         : (self::$idleTimeout ?? 30);
      if ($interval < 1) {
         $interval = 1;
      }

      $inherited = $this->Connection->timers;

      $timer = Timer::add(interval: $interval, handler: [$this, 'supervise']);
      if ($timer !== false) {
         $this->timer = $timer;
         $this->Connection->timers = [$timer];
      }
      else {
         $this->Connection->timers = [];
      }

      foreach ($inherited as $id) {
         Timer::del($id);
      }

      // # Hook
      if (self::$onConnected !== null) {
         (self::$onConnected)($this);
      }
   }

   /**
    * Mark the connection disconnected, stop the supervisor, and fire the
    * Disconnected hook. Idempotent.
    */
   public function disconnect (): void
   {
      // ?
      if ($this->disconnected) {
         return;
      }
      $this->disconnected = true;

      // @ Stop the heartbeat supervisor.
      if ($this->timer !== 0) {
         Timer::del($this->timer);
         $this->timer = 0;
      }

      // @ Leave every joined channel (also breaks the Channel->Session cycle).
      foreach ($this->Channels as $name => $Channel) {
         $Channel->leave($this);
         if ($Channel->count() === 0) {
            Channels::drop($name);
         }
      }
      $this->Channels = [];

      // # Hook
      if (self::$onDisconnected !== null) {
         (self::$onDisconnected)($this);
      }
   }

   /**
    * Send a text (default) or binary message to this client.
    *
    * `$fragment` > 0 splits the (post-compression) payload into frames of at
    * most that many bytes — one text/binary frame (FIN=0) followed by
    * continuation frames, the last with FIN=1. RSV1 marks the message, so the
    * compression bit rides only on the first frame.
    */
   public function send (string $payload, bool $binary = false, int $fragment = 0): bool
   {
      $opcode = $binary
         ? WS::OPCODE_BINARY
         : WS::OPCODE_TEXT;

      $rsv1 = 0;
      if ($this->Deflator !== null) {
         [$payload, $rsv1] = $this->deflate($payload);
      }

      // ? Single frame (default).
      if ($fragment <= 0 || strlen($payload) <= $fragment) {
         return $this->deliver(Frame::encode($opcode, $payload, true, $rsv1));
      }

      // @ Fragmented: the first frame carries the opcode (+RSV1); the rest are
      //   continuation frames; only the final fragment sets FIN.
      $frames = '';
      $length = strlen($payload);
      $first = true;
      for ($offset = 0; $offset < $length; $offset += $fragment) {
         $chunk = substr($payload, $offset, $fragment);
         $fin = ($offset + $fragment) >= $length;
         $frames .= Frame::encode(
            $first ? $opcode : WS::OPCODE_CONTINUATION,
            $chunk,
            $fin,
            $first ? $rsv1 : 0
         );
         $first = false;
      }

      // :
      return $this->deliver($frames);
   }

   /**
    * Send a ping control frame and arm the pong-wait.
    */
   public function ping (string $payload = ''): bool
   {
      $this->awaitingPong = true;

      return $this->deliver(Frame::encode(WS::OPCODE_PING, $payload));
   }

   /**
    * Send a close frame and tear the connection down.
    */
   public function close (int $code = 1000, string $reason = ''): bool
   {
      $this->deliver(Frame::encode(WS::OPCODE_CLOSE, pack('n', $code) . $reason));
      $this->disconnect();

      // ? If the close frame deferred under backpressure, let it drain before
      //   closing the socket — Packages::writing() honors closeAfterDrain.
      $Connection = $this->Connection;
      if ($Connection->pendingBuffer !== '') {
         $Connection->closeAfterDrain = true;
         return true;
      }

      return $Connection->close();
   }

   /**
    * Write an already-encoded frame to this client's socket (Channels\Member).
    */
   public function deliver (string $frame): bool
   {
      return $this->Connection->writing($this->Connection->Socket, strlen($frame), $frame);
   }

   /**
    * Join a channel (room).
    */
   public function join (string $channel): Channel
   {
      $Channel = Channels::fetch($channel);
      $Channel->join($this);
      $this->Channels[$channel] = $Channel;

      return $Channel;
   }

   /**
    * Leave a channel (room).
    */
   public function leave (string $channel): void
   {
      $Channel = $this->Channels[$channel] ?? null;
      if ($Channel === null) {
         return;
      }

      $Channel->leave($this);
      if ($Channel->count() === 0) {
         Channels::drop($channel);
      }
      unSet($this->Channels[$channel]);
   }

   /**
    * Broadcast a message to every member of a channel.
    *
    * @return int The number of members the message was sent to.
    */
   public function broadcast (string $channel, string $payload, bool $binary = false, bool $self = false): int
   {
      $opcode = $binary
         ? WS::OPCODE_BINARY
         : WS::OPCODE_TEXT;

      // @ Encode once, uncompressed — per-session deflate contexts cannot be
      //   shared across members, so fan-out frames are sent without RSV1.
      $frame = Frame::encode($opcode, $payload);

      // @ Cross-worker fan-out: peer workers deliver to their own members.
      Relay::publish($channel, $frame);

      // ? No-create local lookup: a missing room here is a no-op, never a
      //   phantom channel in the registry.
      $Channel = Channels::find($channel);
      if ($Channel === null) {
         return 0;
      }

      // : Local recipient count (cross-worker recipients are not counted).
      return $Channel->broadcast($frame, $self ? null : $this);
   }

   /**
    * Enable permessage-deflate for this session from the negotiated params.
    *
    * @param array<string, mixed> $params
    */
   public function compress (array $params): void
   {
      // * Config
      $this->extensions = $params;
      $this->serverNoContextTakeover = (bool) ($params['server_no_context_takeover'] ?? false);
      $this->clientNoContextTakeover = (bool) ($params['client_no_context_takeover'] ?? false);
      $bits = $params['server_max_window_bits'] ?? 15;
      $this->serverWindowBits = is_int($bits) ? $bits : 15;

      // @ The inflater always uses a full window so it can decode any client
      //   compressor window <= 15; the deflater honors the negotiated bound.
      $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
      $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => $this->serverWindowBits, 'level' => -1]);

      // ? Disable compression entirely if either context could not be created.
      if ($Inflator === false || $Deflator === false) {
         $this->Inflator = null;
         $this->Deflator = null;
         return;
      }
      $this->Inflator = $Inflator;
      $this->Deflator = $Deflator;
   }

   /**
    * Inflate a compressed inbound message payload (RSV1). Returns `false` when
    * the compressed data is invalid (the caller closes with 1007).
    */
   public function inflate (string $payload): string|false
   {
      // ?
      if ($this->Inflator === null) {
         return $payload;
      }

      // @ Append the RFC 7692 §7.2.2 tail before inflating.
      $out = inflate_add($this->Inflator, "{$payload}\x00\x00\xff\xff");
      // : `false` signals invalid compressed data — the decoder closes 1007.
      if ($out === false) {
         return false;
      }

      // @ Per-message context reset when no-context-takeover was negotiated.
      if ($this->clientNoContextTakeover) {
         $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
         $this->Inflator = $Inflator !== false
            ? $Inflator
            : null;
      }

      // :
      return $out;
   }

   /**
    * Deflate an outbound message payload.
    *
    * @return array{string, int} The compressed payload and the RSV1 bit (0x40).
    */
   public function deflate (string $payload): array
   {
      // ?
      if ($this->Deflator === null) {
         return [$payload, 0];
      }

      $out = deflate_add($this->Deflator, $payload, ZLIB_SYNC_FLUSH);
      if ($out === false) {
         return [$payload, 0];
      }

      // @ Strip the RFC 7692 §7.2.1 trailing empty block.
      if (str_ends_with($out, "\x00\x00\xff\xff")) {
         $out = (string) substr($out, 0, -4);
      }

      // @ Per-message context reset when no-context-takeover was negotiated.
      if ($this->serverNoContextTakeover) {
         $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => $this->serverWindowBits, 'level' => -1]);
         $this->Deflator = $Deflator !== false
            ? $Deflator
            : null;
      }

      // :
      return [$out, 0x40];
   }

   /**
    * Heartbeat / liveness supervisor (periodic). Pings only an idle peer and
    * reaps it on a missed pong, peer EOF, or idle-timeout.
    */
   public function supervise (): void
   {
      $Connection = $this->Connection;

      // ? Already closing/closed.
      if ($this->disconnected || $Connection->status > Connections::STATUS_ESTABLISHED) {
         $this->disconnect();
         return;
      }

      // ? Peer closed the socket.
      if (@feof($Connection->Socket)) {
         $this->disconnect();
         $Connection->close();
         return;
      }

      // # Heartbeat mode
      if (self::$heartbeatInterval > 0) {
         // ? Recent inbound activity — peer is alive, reset the pong-wait.
         if ((time() - $this->lastActivity) < self::$heartbeatInterval) {
            $this->awaitingPong = false;
            return;
         }
         // ? A prior ping went unanswered and the peer is still silent — dead.
         if ($this->awaitingPong) {
            $this->disconnect();
            $Connection->close();
            return;
         }
         // @ Probe the idle peer.
         $this->ping();
         return;
      }

      // # Idle-timeout mode (heartbeat disabled).
      $idle = self::$idleTimeout;
      if ($idle !== null && $idle > 0 && (time() - $this->lastActivity) >= $idle) {
         $this->disconnect();
         $Connection->close();
      }
   }
}
