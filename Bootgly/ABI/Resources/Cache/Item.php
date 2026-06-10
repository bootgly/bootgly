<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


/**
 * One cache entry: value plus expiry and tags metadata.
 *
 * Drivers without native TTL/tag support (File, Shared-memory) serialize this
 * record so a single stored blob carries everything needed to evaluate expiry
 * and tag membership.
 */
class Item
{
   // * Data
   public mixed $value;
   /**
    * Unix timestamp when the entry expires; 0 means it never expires.
    */
   public int $expiry;
   /** @var array<int,string> */
   public array $tags;


   /**
    * @param array<int,string> $tags
    */
   public function __construct (mixed $value, int $expiry = 0, array $tags = [])
   {
      // * Data
      $this->value = $value;
      $this->expiry = $expiry;
      $this->tags = $tags;
   }
}
