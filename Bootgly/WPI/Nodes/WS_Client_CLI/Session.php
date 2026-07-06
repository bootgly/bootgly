<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


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
use DeflateContext;
use InflateContext;

use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame;


/**
 * Per-connection client state: the negotiated session, the framing buffers, the
 * permessage-deflate contexts, and the outbound API (send / ping / close).
 *
 * Compression mirrors the server with the roles swapped: the client deflates
 * its OUTBOUND messages with the negotiated client window and inflates the
 * server's INBOUND messages with a full window.
 */
class Session
{
   // * Data
   public WS_Client_CLI $Client;                    // owning client (instance hooks + policy)
   public Connection $Connection;
   public string $key;                              // the Sec-WebSocket-Key we sent (verifies accept)
   // @ Negotiated
   public string $subprotocol = '';
   /** @var array<string, mixed> */
   public array $extensions = [];
   // @ Offered — what the client sent, used to validate the server's response.
   /** @var array<string> */
   public array $offeredSubprotocols = [];
   public bool $offeredCompression = false;
   // @ Compression (permessage-deflate, RFC 7692 — client roles)
   public null|InflateContext $Inflator = null;
   public null|DeflateContext $Deflator = null;
   public bool $clientNoContextTakeover = false;
   public bool $serverNoContextTakeover = false;
   public int $clientWindowBits = 15;

   // * Metadata
   // @ Lifecycle
   public bool $established = false;
   public bool $disconnected = false;
   public bool $closing = false;        // a graceful close was initiated (suppresses client reconnect)
   // @ Framing
   public string $carry = '';                       // trailing partial-frame bytes between reads
   public string $reassembly = '';                  // concatenated fragment payloads
   public int $reassemblyOpcode = 0;                // opcode of the first fragment; 0 = idle
   public bool $reassemblyCompressed = false;       // RSV1 of the first fragment
   public string $utf8Pending = '';                 // trailing incomplete UTF-8 bytes
   public null|Message $Message = null;             // completed message surfaced to the node
   // @ Liveness
   public int $lastActivity = 0;
   public bool $awaitingPong = false;
   public int $timer = 0;


   public function __construct (Connection $Connection, string $key, WS_Client_CLI $Client)
   {
      // * Data
      $this->Client = $Client;
      $this->Connection = $Connection;
      $this->key = $key;

      // * Metadata
      $this->lastActivity = time();
   }

   /**
    * Mark the connection established (the 101 was verified), install the
    * optional heartbeat supervisor, and fire the Connected hook.
    */
   public function establish (): void
   {
      // ?
      if ($this->established) {
         return;
      }
      $this->established = true;
      $this->lastActivity = time();

      // @ Liveness supervisor — always armed so an abrupt TCP EOF (a peer reset
      //   with no WS close frame) is reaped and `Disconnected` fires instead of
      //   the loop hanging. When heartbeat is enabled it also pings idle peers.
      //   Interval = the heartbeat cadence, or 1s as a pure EOF reaper.
      $interval = $this->Client->heartbeatInterval > 0
         ? $this->Client->heartbeatInterval
         : 1;
      $timer = Timer::add(interval: $interval, handler: [$this, 'supervise']);
      if ($timer !== false) {
         $this->timer = $timer;
         $this->Connection->timers[] = $timer;
      }

      // # Hook
      if ($this->Client->onConnected !== null) {
         ($this->Client->onConnected)($this);
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

      // # Hook
      if ($this->Client->onDisconnected !== null) {
         ($this->Client->onDisconnected)($this);
      }
   }

   /**
    * Send a text (default) or binary message to the server.
    *
    * `$fragment` > 0 splits the (post-compression) payload into frames of at
    * most that many bytes — one text/binary frame (FIN=0) followed by
    * continuation frames, the last with FIN=1.
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
      $this->closing = true;
      $this->deliver(Frame::encode(WS::OPCODE_CLOSE, pack('n', $code) . $reason));
      $this->disconnect();

      return $this->Connection->close();
   }

   /**
    * Write an already-encoded frame to the server's socket.
    */
   public function deliver (string $frame): bool
   {
      $this->Connection->output = $frame;

      return $this->Connection->writing($this->Connection->Socket, strlen($frame));
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
      $bits = $params['client_max_window_bits'] ?? 15;
      $this->clientWindowBits = is_int($bits) ? $bits : 15;

      // @ The inflater always uses a full window so it can decode any server
      //   compressor window <= 15; the deflater honors the negotiated bound.
      $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
      $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => $this->clientWindowBits, 'level' => -1]);

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
    * Inflate a compressed inbound (server) message payload (RSV1). Returns
    * `false` when the compressed data is invalid (the caller closes with 1007).
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

      // @ Per-message context reset when the server negotiated no-context-takeover.
      if ($this->serverNoContextTakeover) {
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

      // @ Per-message context reset when the client negotiated no-context-takeover.
      if ($this->clientNoContextTakeover) {
         $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => $this->clientWindowBits, 'level' => -1]);
         $this->Deflator = $Deflator !== false
            ? $Deflator
            : null;
      }

      // :
      return [$out, 0x40];
   }

   /**
    * Heartbeat / liveness supervisor (periodic). Pings only an idle peer and
    * reaps it on a missed pong or peer EOF.
    */
   public function supervise (): void
   {
      $Connection = $this->Connection;

      // ? Already closing/closed.
      if ($this->disconnected) {
         return;
      }

      // ? Peer closed the socket (EOF) — reap an abrupt TCP close that sent no
      //   WS close frame, firing `Disconnected` and ending the loop.
      if (@feof($Connection->Socket)) {
         $this->disconnect();
         $Connection->close();
         return;
      }

      // ? Heartbeat disabled — the supervisor is a pure EOF reaper; never ping.
      if ($this->Client->heartbeatInterval <= 0) {
         return;
      }

      // ? Recent inbound activity — peer is alive, reset the pong-wait.
      if ((time() - $this->lastActivity) < $this->Client->heartbeatInterval) {
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
   }
}
