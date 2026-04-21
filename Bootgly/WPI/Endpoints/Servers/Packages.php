<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
