<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;


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
   // # State
   // @ Declared `content-length` (mismatch at END_STREAM is a stream error)
   public null|int $length;
   // @ Remote side closed (END_STREAM received)
   public bool $ended;
   // @ Local side responded (response frames fully emitted)
   public bool $responded;


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
      $this->length = null;
      $this->ended = false;
      $this->responded = false;
   }
}
