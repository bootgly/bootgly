<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;


/**
 * The wire bytes of a connection — what it read, what it writes and the last
 * request head it keyed — carry its credential headers, so a debug dump masks them.
 */
abstract class Packages
{
   use Redactable;


   // * Config
   public bool $cache;

   // * Data
   // # Endpoints
   // @ Per-connection decoder instance (stateful).
   // When null, the shared default `Server::$Decoder` is used.
   public null|Decoder $Decoder = null;
   // @ Per-connection encoder instance (stateful).
   // When null, the shared default `Server::$Encoder` is used.
   // Note: `Encoder::encode()` is static, so it is invoked as
   // `$Encoder::encode(...)` on whichever instance (or class) is active.
   public null|Encoder $Encoder = null;

   public bool $changed;
   // # IO
   #[Secret]
   public string $input;
   #[Secret]
   public string $output;

   // * Metadata
   // # Handler
   /**
    * Its first slot is a reference to `$input`, so it carries the same bytes.
    *
    * @var array<string>
    */
   #[Secret]
   public array $callbacks;
   // # Expiration
   public bool $expired;
   // # Decoder outcome
   /**
    * Bytes consumed by the most recent `Decoder::decode()` call. Replaces
    * the integer return value of `decode()`; reset to 0 at the start of
    * every read cycle.
    */
   public int $consumed = 0;
   /**
    * `true` once `reject()` has been called for this read cycle. Lets the
    * read loop distinguish a `States::Rejected` outcome from a
    * `States::Incomplete` one when no bytes are consumed. Reset to `false`
    * at the start of every read cycle.
    */
   public bool $rejected = false;
   /**
    * Protocol-layer decoded unit, owned by the registered Decoder.
    * For HTTP_Server_CLI: the per-connection `Request` instance reused
    * across keep-alive requests (see `Request::assume()`).
    */
   public null|object $decoded = null;
   /**
    * Whether the current receive event was reassembled from a previous
    * event's retained carry (see `TCP_Server_CLI\Packages::$carry`).
    * Decoders may consult it — e.g. to exclude reassembled reads from
    * byte-keyed caches — but only the transport writes it.
    */
   public protected(set) bool $carried = false;
   // # L0 — per-connection consecutive-repeat template
   /**
    * The exact input bytes the entry decoder last keyed on this connection
    * (bounded at 2,048 bytes — empty when the event was larger). Decoder-
    * owned, like `$decoded`; the endpoint layer does not name the decoded
    * type.
    */
   #[Secret]
   public string $known = '';
   /**
    * The decoded template for `$known` — set on the second consecutive
    * sighting of the same bytes, or aliased from the shared byte-keyed
    * cache on its hits; `null` while the connection's traffic keeps
    * changing. Invariant: non-null implies `$known` holds exactly the
    * bytes whose decode produced it, and that decode consumed
    * `strlen($known)`.
    */
   public null|object $Template = null;


   public function __construct ()
   {
      // * Config
      $this->cache = true;

      // * Data
      $this->changed = true;
      // # IO
      $this->input = '';
      $this->output = '';

      // * Metadata
      // # Handler
      $this->callbacks = [&$this->input];
      // # Expiration
      $this->expired = false;
   }
}
