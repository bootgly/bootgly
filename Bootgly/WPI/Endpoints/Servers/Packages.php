<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


abstract class Packages
{
   // # Endpoints
   // @ Per-connection decoder instance (stateful).
   // When null, the shared default `Server::$Decoder` is used.
   public null|Decoder $Decoder = null;
   // @ Per-connection encoder instance (stateful).
   // When null, the shared default `Server::$Encoder` is used.
   // Note: `Encoder::encode()` is static, so it is invoked as
   // `$Encoder::encode(...)` on whichever instance (or class) is active.
   public null|Encoder $Encoder = null;

   // * Config
   public bool $cache;

   // * Data
   public bool $changed;
   // # IO
   public string $input;
   public string $output;

   // * Metadata
   // # Handler
   /** @var array<string> */
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
