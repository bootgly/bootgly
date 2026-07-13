<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


/**
 * One HTTP/2 stream (RFC 9113 §5) on a client `Session` connection.
 *
 * Assembles the response head + body and carries both flow-control windows
 * until END_STREAM, when the stream is moved into `Session::$done` as a
 * completion record.
 */
final class Stream
{
   // * Data
   // # Response head
   /** `:status` code of the final (non-interim) response head. */
   public int $code = 0;
   /** Synthesized `name: value\r\n` lines from the non-pseudo response fields. */
   public string $head = '';
   // # Response body
   /** DATA payloads accumulate here until END_STREAM. */
   public string $body = '';

   // * Metadata
   public readonly int $id;
   // # Flow control
   /** Outbound (send) window — spent by request DATA, raised by peer credit. */
   public int $window;
   /** Inbound (receive) window we granted the peer (RFC 9113 §6.9). */
   public int $supply;
   /** Inbound octets pending replenishment (since the last stream WINDOW_UPDATE). */
   public int $pending = 0;
   /** Outbound request DATA blocked by an exhausted window, awaiting credit. */
   public string $backlog = '';
   // # State
   /** Declared `content-length` (`null` = absent). */
   public null|int $length = null;
   /** HEAD request — a content-length mismatch is not an error (RFC 9113 §8.1.1). */
   public bool $headless = false;
   /** Final (non-interim) response head received. */
   public bool $headed = false;
   /** Remote side closed (END_STREAM received). */
   public bool $ended = false;


   public function __construct (int $id, int $window, int $supply)
   {
      // * Metadata
      $this->id = $id;
      $this->window = $window;
      $this->supply = $supply;
   }
}
