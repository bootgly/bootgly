<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;


use function fclose;
use function is_resource;
use function is_string;
use function strlen;
use Throwable;

use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;


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
   public Bodies $Bodies;
   // @ Worker-wide reservation for the persistent decoded request head.
   //   Kept separate from outbound Buffers because response encoders resize
   //   that token absolutely as flow-controlled tails grow and drain.
   public Buffers $HeadBuffers;

   // * Metadata
   public readonly int $id;
   // @ Absolute monotonic deadline for receiving END_STREAM. Zero means the
   //   remote side already ended while the opening HEADERS was resolved.
   public int $deadline;
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
   // @ Worker-wide reservation for this stream's in-memory outbound tail.
   public Buffers $Buffers;
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
   private bool $closed;
   // @ Teardown owner of a sustained stream (e.g. the SSE resource) —
   //   notified exactly once by close(), on every release path (RST_STREAM,
   //   GOAWAY, connection teardown, graceful end)
   public null|Disconnecting $Owner;


   public function __construct (
      int $id,
      int $window,
      int $supply,
      Bodies $Bodies,
      int $deadline = 0
   )
   {
      // * Data
      $this->method = '';
      $this->target = '';
      $this->scheme = '';
      $this->authority = '';
      $this->fields = [];
      $this->body = '';
      $this->Bodies = $Bodies;
      $this->HeadBuffers = new Buffers;

      // * Metadata
      $this->id = $id;
      $this->deadline = $deadline;
      $this->window = $window;
      $this->supply = $supply;
      $this->pending = 0;
      $this->backlog = '';
      $this->Buffers = new Buffers;
      $this->drained = 0;
      $this->chunks = [];
      $this->chunk = 0;
      $this->length = null;
      $this->ended = false;
      $this->responded = false;
      $this->sustained = false;
      $this->closed = false;
      $this->Owner = null;
   }

   public function close (): void
   {
      if ($this->closed) {
         return;
      }

      // ! Commit before callbacks: re-entrant close is a no-op and owners
      //   attached from a teardown callback observe closure immediately.
      $this->closed = true;

      // ! Capture and clear the primary owner before invoking registry
      //   callbacks. Re-entrant close cannot consume or replace this owner.
      $Owner = $this->Owner;
      $this->Owner = null;

      // @ Additional stream-scoped work observes closure before any primary
      //   owner callback can re-enter and attach more work.
      Ownership::close($this);

      // @ Release actual request-head owners before their logical budget.
      //   Clearing matters when a sustained-stream resource still references
      //   this object after decoder removal.
      $this->method = '';
      $this->target = '';
      $this->scheme = '';
      $this->authority = '';
      $this->fields = [];
      $this->deadline = 0;
      $this->HeadBuffers->release();

      // @ Body accounting is stream-owned and idempotent: every normal,
      //   reset, denial, GOAWAY, and transport teardown already converges
      //   here before removing the stream from its decoder.
      $this->Bodies->release(strlen($this->body));
      $this->body = '';

      foreach ($this->chunks as $chunk) {
         $Handler = $chunk['handler'] ?? null;
         if (is_resource($Handler)) {
            @fclose($Handler);
         }
      }

      $this->chunks = [];
      $this->chunk = 0;
      $this->backlog = '';
      $this->Buffers->release();

      // # Notify the owning unit exactly once (disconnect() is idempotent)
      try {
         $Owner?->disconnect();
      }
      catch (Throwable) {
         // One owner cannot suppress the remaining stream teardown.
      }

   }

   /**
    * Measure the stream's persistent in-memory outbound tail.
    *
    * File ranges remain disk-backed and are intentionally excluded. Only
    * unread raw backlog and in-memory pad segments are charged.
    */
   public function measure (): int
   {
      $bytes = strlen($this->backlog);

      foreach ($this->chunks as $index => $segment) {
         if ($index < $this->chunk || ! is_string($segment['data'] ?? null)) {
            continue;
         }

         // ! `position` is a send cursor, not a memory cursor: substr() used
         //   by drain() does not compact this source string. Charge the whole
         //   allocation until drain() clears `data` on complete consumption.
         $bytes += strlen($segment['data']);
      }

      return $bytes;
   }
}
