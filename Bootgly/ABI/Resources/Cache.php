<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources;


use function spl_object_id;
use Closure;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ABI\Resources\Cache\Driver;
use Bootgly\ABI\Resources\Cache\Drivers;
use Bootgly\ABI\Resources\Cache\Events;


/**
 * Cache facade.
 *
 * Single entry point for the cache layer: selects the active blocking driver,
 * applies the configured key prefix, and adds get-or-compute via resolve().
 * Async, event-loop-aware Redis lives separately under ADI/Databases/KV.
 */
class Cache
{
   // * Config
   public Config $Config;

   // * Data
   public Drivers $Drivers;
   public protected(set) Driver $Driver;

   // * Metadata
   protected string $prefix;


   /**
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $this->Config = $config instanceof Config
         ? $config
         : new Config($config);

      // * Data
      $this->Drivers = new Drivers($this->Config);
      $this->Driver = $this->Drivers->fetch($this->Config->driver);

      // * Metadata
      $this->prefix = $this->Config->prefix;
   }

   public function fetch (string $key): mixed
   {
      $value = $this->Driver->fetch("{$this->prefix}{$key}");

      // @ Events — guarded so a no-listener fetch stays zero-allocation.
      // ! Direct Listeners read instead of check(): the call frame plus a
      //   repeated spl_object_id of process-stable enum cases is measurable
      //   on per-request cache reads (same pattern as Encoder_::encode).
      static $hit = null, $miss = null;
      $hit ??= spl_object_id(Events::Hit);
      $miss ??= spl_object_id(Events::Miss);

      $Emitter = Emitter::$Instance;
      if ($value !== null) {
         isSet($Emitter->Listeners[$hit]) && $Emitter->emit(Events::Hit, $key, $value);
      }
      else {
         isSet($Emitter->Listeners[$miss]) && $Emitter->emit(Events::Miss, $key);
      }

      // :
      return $value;
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      if ($TTL === 0) {
         $TTL = $this->Config->TTL;
      }

      return $this->Driver->store("{$this->prefix}{$key}", $value, $TTL, $tags);
   }

   public function delete (string $key): bool
   {
      $deleted = $this->Driver->delete("{$this->prefix}{$key}");

      // @ Events — guarded so a no-listener delete stays zero-allocation
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Evict) && $Emitter->emit(Events::Evict, $key, $deleted);

      // :
      return $deleted;
   }

   public function clear (): bool
   {
      return $this->Driver->clear();
   }

   public function check (string $key): bool
   {
      return $this->Driver->check("{$this->prefix}{$key}");
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      return $this->Driver->increment("{$this->prefix}{$key}", $by, $TTL);
   }

   public function decrement (string $key, int $by = 1): int
   {
      return $this->Driver->decrement("{$this->prefix}{$key}", $by);
   }

   public function remain (string $key): int
   {
      return $this->Driver->remain("{$this->prefix}{$key}");
   }

   public function invalidate (string $tag): bool
   {
      return $this->Driver->invalidate($tag);
   }

   public function purge (): int
   {
      return $this->Driver->purge();
   }

   /**
    * Fetch a key, computing and storing it on miss.
    *
    * A single fetch() decides hit/miss, so each resolve costs one driver read
    * (one round-trip on Redis). A stored `null` is indistinguishable from a
    * miss and is recomputed — do not cache `null` values.
    *
    * @param array<int,string> $tags
    */
   public function resolve (string $key, int $TTL, Closure $compute, array $tags = []): mixed
   {
      // ?: Cache hit (null means miss — see docblock)
      $value = $this->fetch($key);
      if ($value !== null) {
         return $value;
      }

      // @ Compute and store on miss
      $value = $compute();
      $this->store($key, $value, $TTL, $tags);

      // :
      return $value;
   }
}
