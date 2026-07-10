<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;


use function fclose;
use function is_resource;

use Bootgly\WPI\Endpoints\Servers\Disconnecting;


/**
 * One HTTP/2 stream (RFC 9113 §5) on a `Decoder_HTTP2` connection.
 *
 * Holds the decoded request head (pseudo-header targets + regular fields)
 * and accumulates the request body until END_STREAM, when the stream is
 * dispatched as a Bootgly Request.
 */
class Stream
{
   // * Data
   // # Request head (from the HEADERS block pseudo-headers)
   public string $method;
   public string $target;
   public string $scheme;
   public string $authority;
   /** @var array<string, string|array<int, string>> lowercased regular fields */
   public array $fields;
   // # Request body (DATA frames accumulate here until END_STREAM)
   public string $body;

   // * Metadata
   public readonly int $id;
   // # Flow control
   // @ Outbound (send) window — decremented by DATA we send, raised by
   //   WINDOW_UPDATE / SETTINGS_INITIAL_WINDOW_SIZE deltas from the peer.
   public int $window;
   // @ Inbound (receive) window we granted the peer — decremented by every
   //   DATA payload, credited back by the WINDOW_UPDATEs we emit; a peer
   //   driving it negative gets RST_STREAM(FLOW_CONTROL_ERROR).
   public int $supply;
   // @ Inbound octets pending replenishment (since the last stream WINDOW_UPDATE).
   public int $pending;
   // @ Outbound DATA blocked by an exhausted window, waiting for credit.
   public string $backlog;
   // @ Flow-control progress clock: stamped when backlog bytes are actually
   //   CONSUMED by drain() and when a write first parks bytes into an empty
   //   backlog. A non-empty backlog whose clock stops advancing is a real
   //   stall — net-size sampling alone cannot tell progress from
   //   replenishment (SSE stall deadline reads this).
   public int $drained;
   /** @var array<int, array<string, mixed>> Outbound file/pad segments. */
   public array $chunks;
   // @ Current outbound file/pad segment index.
   public int $chunk;
   // # State
   // @ Declared `content-length` (mismatch at END_STREAM is a stream error)
   public null|int $length;
   // @ Remote side closed (END_STREAM received)
   public bool $ended;
   // @ Local side responded (response frames fully emitted)
   public bool $responded;
   // @ Long-lived local stream (e.g. SSE) — `drain()`/`pump()` never emit
   //   END_STREAM nor release the stream while set
   public bool $sustained;
   // @ Teardown owner of a sustained stream (e.g. the SSE resource) —
   //   notified exactly once by close(), on every release path (RST_STREAM,
   //   GOAWAY, connection teardown, graceful end)
   public null|Disconnecting $Owner;


   public function __construct (int $id, int $window, int $supply)
   {
      // * Data
      $this->method = '';
      $this->target = '';
      $this->scheme = '';
      $this->authority = '';
      $this->fields = [];
      $this->body = '';

      // * Metadata
      $this->id = $id;
      $this->window = $window;
      $this->supply = $supply;
      $this->pending = 0;
      $this->backlog = '';
      $this->drained = 0;
      $this->chunks = [];
      $this->chunk = 0;
      $this->length = null;
      $this->ended = false;
      $this->responded = false;
      $this->sustained = false;
      $this->Owner = null;
   }

   public function close (): void
   {
      foreach ($this->chunks as $chunk) {
         $Handler = $chunk['handler'] ?? null;
         if (is_resource($Handler)) {
            @fclose($Handler);
         }
      }

      $this->chunks = [];
      $this->chunk = 0;
      $this->backlog = '';

      // # Notify the owning unit exactly once (disconnect() is idempotent)
      $Owner = $this->Owner;
      $this->Owner = null;
      $Owner?->disconnect();
   }
}
