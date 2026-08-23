<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


/**
 * One cache entry: value plus expiry and tags metadata.
 *
 * The File driver, which has no native TTL/tag support, serializes this record
 * so a single stored blob carries everything needed to evaluate expiry and tag
 * membership.
 */
class Item
{
   // * Data
   // ! Every property carries a default: a forged record naming this class with
   //   no properties at all hydrates fine, and reading an uninitialized typed
   //   property would raise instead of reporting the record as a miss.
   public mixed $value = null;
   /**
    * Unix timestamp when the entry expires; 0 means it never expires.
    */
   public int $expiry = 0;
   /** @var array<int,string> */
   public array $tags = [];


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
