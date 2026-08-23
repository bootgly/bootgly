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


use function get_object_vars;
use function is_array;
use function is_object;
use function preg_match_all;
use function spl_object_id;
use function strcasecmp;
use function unserialize;
use __PHP_Incomplete_Class;
use ErrorException;
use TypeError;

use Bootgly\ABI\Resources\Cache\Config;


/**
 * Cache driver contract.
 *
 * Concrete drivers (File, APCu, Shared-memory, Redis) implement one blocking
 * backend each. Keys arriving here are already namespaced by the Cache facade.
 */
abstract class Driver
{
   /**
    * Maximum nesting accepted from a stored record.
    *
    * Bounds the recursion a tampered blob can force independently of the
    * `unserialize_max_depth` ini an application is free to raise. 256 sits far
    * past any real cached graph and far below the depth that exhausts the
    * stack, so it never turns a legitimate value into a miss.
    */
   protected const int DEPTH = 256;
   /**
    * Classes this driver's own record format requires — always permitted.
    *
    * A driver that wraps values in a record of its own declares that wrapper
    * here; one that stores the bare value declares nothing and so reconstructs
    * nothing the application did not ask for.
    *
    * @var array<int,string>
    */
   protected const array WRAPPERS = [];

   // * Config
   public Config $Config;

   // * Metadata
   /** @var array{allowed_classes: array<int,string>, max_depth: int} */
   private array $options;


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;

      // * Metadata
      // ! Deserialization is fail-closed: this driver's own record wrapper plus
      //   whatever the application declared, and nothing else — so a tampered
      //   store can never run an object-injection gadget. Built once here
      //   because decode() sits on every read path.
      $this->options = [
         'allowed_classes' => [...static::WRAPPERS, ...$Config->classes],
         'max_depth' => self::DEPTH,
      ];
   }

   /**
    * Read a value; null on miss or expiry.
    */
   abstract public function fetch (string $key): mixed;
   /**
    * Write a value with an optional TTL (seconds, 0 = forever) and tags.
    *
    * @param array<int,string> $tags
    */
   abstract public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool;
   /**
    * Atomically create a value only when no live key exists.
    *
    * Custom drivers fail closed until they provide a backend-native override.
    *
    * @param array<int,string> $tags
    */
   public function create (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      return false;
   }
   /**
    * Atomically replace a live value only when it exactly matches the expected value.
    *
    * Custom drivers fail closed until they provide a backend-native override.
    *
    * @param array<int,string> $tags
    */
   public function swap (
      string $key,
      mixed $expected,
      mixed $value,
      int $TTL = 0,
      array $tags = [],
   ): bool
   {
      return false;
   }
   /**
    * Atomically remove a live key only when it exactly matches the expected value.
    * Custom drivers fail closed until they provide a backend-native override.
    */
   public function evict (string $key, mixed $expected): bool
   {
      return false;
   }
   /**
    * Atomically renew a live key's TTL without rewriting its value.
    * Custom drivers fail closed until they provide a backend-native override.
    */
   public function renew (string $key, int $TTL = 0): bool
   {
      return false;
   }
   /**
    * Remove one key.
    */
   abstract public function delete (string $key): bool;
   /**
    * Flush every key owned by this driver.
    */
   abstract public function clear (): bool;
   /**
    * Whether a key exists and is not expired.
    */
   abstract public function check (string $key): bool;
   /**
    * Atomically increase an integer counter, creating it at 0 when absent.
    *
    * A positive $TTL sets the entry's expiry only when the counter is first
    * created; existing counters keep their expiry (fixed-window friendly,
    * matching Redis INCR + one-time EXPIRE).
    */
   abstract public function increment (string $key, int $by = 1, int $TTL = 0): int;
   /**
    * Remaining time-to-live in seconds.
    *
    * Mirrors Redis: -2 when the key is missing or expired, -1 when it exists
    * without expiry, otherwise the seconds left.
    */
   abstract public function remain (string $key): int;
   /**
    * Drop every key carrying the given tag.
    */
   abstract public function invalidate (string $tag): bool;
   /**
    * Evict expired entries; returns the number removed.
    */
   abstract public function purge (): int;

   /**
    * Atomically decrease an integer counter.
    */
   public function decrement (string $key, int $by = 1): int
   {
      return $this->increment($key, -$by);
   }

   // ---

   /**
    * Decode a stored record under this driver's deserialization allow-list.
    *
    * Answers `false` — `unserialize()`'s own failure signal — for any blob that
    * cannot be decoded, including one naming a class the allow-list refused
    * anywhere in its graph, so a tampered store reads as a miss instead of
    * raising or handing back a half-built object.
    *
    * This is the gate for drivers that serialize in PHP: `File` and `Redis`.
    * `Shared` and `APCu` never reach it — `shm_get_var()` and `apcu_fetch()`
    * deserialize inside the extension, which accepts no options — so on those
    * two the allow-list does not apply at all.
    *
    * @param string $bytes The serialized record bytes.
    */
   protected function decode (string $bytes): mixed
   {
      try {
         $decoded = @unserialize($bytes, $this->options);
      }
      catch (ErrorException | TypeError) {
         // ! `@` hides the warning malformed or over-deep bytes raise, but not
         //   the TypeError a forged record throws when it assigns a value a
         //   declared property cannot hold — nor a warning an application error
         //   handler already promoted into an exception. Deliberately narrow:
         //   a ParseError in a declared class's own file, an autoloader that
         //   throws, or a `__wakeup()` that raises are the application's bugs,
         //   and swallowing those would turn them into a cache that silently
         //   never hits.
         return false;
      }

      // ---

      // ? `unserialize()` downgrades a refused class to an inert placeholder
      //   WHEREVER it sits in the graph, not only at the top. Answering with a
      //   record poisoned one level down would be worse than a miss: the caller
      //   sees a hit, and the placeholder raises on the first property read.
      //   The scan only decides whether the walk is worth its cost — it can
      //   cost one needless walk, never a wrong answer — so the walk stays the
      //   thing that actually decides.
      $Seen = [];
      if ($decoded !== false && $this->suspect($bytes) === true
          && $this->screen($decoded, $Seen) === true) {
         return false;
      }

      // :
      return $decoded;
   }

   /**
    * Whether a payload names a class this driver would refuse to reconstruct.
    *
    * Reading the names out of the serialized bytes is one pass over a string
    * already in hand; walking the decoded graph is a recursion over every node
    * it holds. Only a payload that names something unexpected can be carrying a
    * placeholder, so this decides whether that walk happens at all — and it
    * errs toward walking: a plain string that merely looks like a class marker
    * costs one wasted walk, and a refusal is never decided here.
    *
    * @param string $bytes The serialized record bytes.
    */
   private function suspect (string $bytes): bool
   {
      // ? Every object-creating form carries its class name in quotes — `O:` for
      //   an ordinary object and `C:` for a Serializable one. `E:` enums are not
      //   matched on purpose: PHP restores them outside the allow-list, so they
      //   can never leave a placeholder behind
      if (preg_match_all('/[OC]:\d+:"([^"]*)"/', $bytes, $matches) === 0) {
         return false;
      }

      // @@
      foreach ($matches[1] as $class) {
         foreach ($this->options['allowed_classes'] as $allowed) {
            // ! PHP folds class-name case when it matches the allow-list
            if (strcasecmp($class, $allowed) === 0) {
               continue 2;
            }
         }

         // :
         return true;
      }

      // :
      return false;
   }

   /**
    * Whether a decoded graph carries a class the allow-list refused.
    *
    * @param array<int,true> $Seen Ids of the objects already visited.
    */
   private function screen (mixed $value, array &$Seen, int $depth = 0): bool
   {
      // ? `unserialize()` already bounded the decoded nesting at DEPTH, so going
      //   past it here means the graph loops back through a reference rather
      //   than actually being deeper. Everything a loop can reach was inspected
      //   on the way down, so stopping is an answer, not a surrender — refusing
      //   instead would turn a legitimately recursive array into a miss.
      if ($depth > self::DEPTH) {
         return false;
      }

      if (is_object($value) === true) {
         // ?: The placeholder a refused class leaves behind
         if ($value instanceof __PHP_Incomplete_Class) {
            return true;
         }

         // ? A serialized graph can cycle back on itself through a reference;
         //   visiting each object once keeps the walk finite
         $id = spl_object_id($value);
         if (isSet($Seen[$id]) === true) {
            return false;
         }
         $Seen[$id] = true;

         $value = get_object_vars($value);
      }

      // ?
      if (is_array($value) === false) {
         return false;
      }

      // @@
      foreach ($value as $item) {
         if ($this->screen($item, $Seen, $depth + 1) === true) {
            return true;
         }
      }

      // :
      return false;
   }
}
