<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache\Drivers;


use const APC_ITER_CTIME;
use const APC_ITER_KEY;
use const APC_ITER_TTL;
use function apcu_add;
use function apcu_delete;
use function apcu_exists;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function array_values;
use function extension_loaded;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_quote;
use function serialize;
use function substr;
use function time;
use APCUIterator;
use RuntimeException;

use Bootgly\ABI\Resources\Cache\Driver;


/**
 * APCu cache driver.
 *
 * Process-local shared memory — fastest local backend, but each PHP worker has
 * its own APCu segment in CLI/forked deployments. Not suitable as the shared
 * cross-worker backend (use Shared-memory or Redis for that). TTL is native;
 * tag membership is tracked in a companion set entry.
 *
 * **Deserialization caveat.** Values are stored behind a marker byte so this
 * driver's own writes carry no object graph, and reads are decoded under
 * `Config::$classes`. `apcu_fetch()` still reconstructs whatever the store
 * holds before that check runs, so a process able to write the store can fire a
 * planted `__wakeup`/`__destruct`. APCu memory is shared across the SAPI: the
 * boundary is who can execute in that pool.
 */
class APCu extends Driver
{
   /**
    * Current stored-value format. Bump it whenever the representation changes:
    * everything under this cache's prefix is dropped when the store carries
    * anything else.
    */
   private const int FORMAT = 2;

   // * Metadata
   /** Whether this instance has already reconciled the store's format. */
   private bool $reconciled = false;


   public function fetch (string $key): mixed
   {
      $this->guard();

      $raw = apcu_fetch($key, $success);

      // :
      return $success === true ? $this->unpack($raw) : null;
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $this->guard();

      // @ Store the value with native TTL (0 = forever)
      if (apcu_store($key, $this->pack($value), $TTL) === false) {
         return false;
      }

      // @ Track tag membership
      foreach ($tags as $tag) {
         $this->tag($tag, $key, $TTL);
      }

      return true;
   }

   /** @param array<int,string> $tags */
   public function create (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $this->guard();

      if (apcu_add($key, $this->pack($value), $TTL) === false) {
         return false;
      }

      foreach ($tags as $tag) {
         $this->tag($tag, $key, $TTL);
      }

      return true;
   }

   public function delete (string $key): bool
   {
      $this->guard();

      return apcu_delete($key) === true;
   }

   public function clear (): bool
   {
      $this->guard();

      $prefix = $this->Config->prefix;

      // @ Scope the flush to this cache's prefix (whole cache when prefix is empty)
      $pattern = '/^' . preg_quote($prefix, '/') . '/';
      apcu_delete(new APCUIterator($pattern, APC_ITER_KEY));

      return true;
   }

   public function check (string $key): bool
   {
      $this->guard();

      return apcu_exists($key) === true;
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      $this->guard();

      // @ apcu_inc creates the counter at $by (with $TTL) when absent
      $value = apcu_inc($key, $by, $success, $TTL);

      // :
      return $success === true && $value !== false ? $value : 0;
   }

   public function remain (string $key): int
   {
      $this->guard();

      // ?
      if (apcu_exists($key) === false) {
         return -2;
      }

      $now = $this->Config->clock === null ? time() : (int) ($this->Config->clock)();
      $pattern = '/^' . preg_quote($key, '/') . '$/';

      foreach (new APCUIterator($pattern, APC_ITER_TTL | APC_ITER_CTIME) as $info) {
         if (is_array($info) === false) {
            continue;
         }

         $TTL = $info['ttl'] ?? 0;
         $TTL = is_int($TTL) === true ? $TTL : 0;
         // ?: No expiry
         if ($TTL === 0) {
            return -1;
         }

         $created = $info['creation_time'] ?? 0;
         $created = is_int($created) === true ? $created : 0;
         $remaining = $created + $TTL - $now;

         // :
         return $remaining > 0 ? $remaining : -2;
      }

      // :
      return -2;
   }

   public function invalidate (string $tag): bool
   {
      $this->guard();

      $members = $this->unpack(apcu_fetch($this->index($tag), $success));
      if ($success === true && is_array($members) === true) {
         foreach ($members as $member) {
            if (is_string($member) === true) {
               apcu_delete($member);
            }
         }
      }

      apcu_delete($this->index($tag));

      return true;
   }

   public function purge (): int
   {
      $this->guard();

      // APCu evicts expired entries natively; nothing to scan.
      return 0;
   }

   // ---

   /**
    * Ensure the APCu extension is available before any operation.
    */
   private function guard (): void
   {
      if (extension_loaded('apcu') === false) {
         throw new RuntimeException('The APCu cache driver requires ext-apcu.');
      }

      $this->reconcile();
   }

   /**
    * Drop everything under this cache's prefix when the store predates the
    * current value format.
    *
    * `apcu_fetch()` takes no options, so it reconstructs whatever the store
    * holds before this driver can refuse it — which is why values are kept as
    * opaque strings now. Anything left by the older format is a live object
    * waiting to be built, and dropping it is the only answer that does not
    * involve constructing it first. The iterator yields KEYS only, so nothing
    * is reconstructed on the way out, and the sweep is scoped to this cache's
    * prefix rather than the whole store, which other applications share.
    */
   private function reconcile (): void
   {
      // ?: Already reconciled by this instance
      if ($this->reconciled === true) {
         return;
      }
      // ! Set before the work: clear() below calls guard(), and a second pass
      //   would drop the marker this one is about to write
      $this->reconciled = true;

      // ! Outside the user keyspace on purpose: built from the prefix but not
      //   under it, so clear() cannot delete the marker it depends on and an
      //   application key can never collide with it. The version is part of the
      //   name, so recognising the format never reads a value
      $marker = "\0bootgly-cache-format-" . self::FORMAT . ":{$this->Config->prefix}";

      // ?: The store is already ours
      if (apcu_exists($marker) === true) {
         return;
      }

      $prefix = $this->Config->prefix;

      // ? An empty prefix owns no keyspace of its own, so there is no sweep that
      //   reaches this cache's records without also reaching every other
      //   tenant's — APCu memory is shared across the whole SAPI. Refusing to
      //   sweep leaves older records unreadable rather than destroying data
      //   this driver does not own
      if ($prefix !== '') {
         // ! Keys only: materializing values here would reconstruct the very
         //   objects this sweep exists to get rid of
         apcu_delete(new APCUIterator('/^' . preg_quote($prefix, '/') . '/', APC_ITER_KEY));
      }

      apcu_store($marker, self::FORMAT);
   }

   /**
    * Encode a value for storage: integers stay raw so `apcu_inc()` keeps
    * working on them natively, everything else is serialized behind a marker
    * byte so the extension has no object graph to rebuild on the way out.
    */
   private function pack (mixed $value): mixed
   {
      if (is_int($value) === true) {
         return $value;
      }

      return "\x01" . serialize($value);
   }

   /**
    * Decode a stored value through this driver's deserialization allow-list.
    *
    * A value that is neither a raw counter nor a marked payload was written by
    * an older format, and reads as a miss rather than being trusted.
    */
   private function unpack (mixed $raw): mixed
   {
      // ?: Raw counter
      if (is_int($raw) === true) {
         return $raw;
      }

      // ?
      if (is_string($raw) === false || ($raw[0] ?? '') !== "\x01") {
         return null;
      }

      $payload = substr($raw, 1);
      $value = $this->decode($payload);

      // ? Undecodable bytes. `false` is also a legitimate stored value, so the
      //   one payload that encodes it is the only `false` accepted here
      if ($value === false && $payload !== 'b:0;') {
         return null;
      }

      // :
      return $value;
   }

   /**
    * Build the companion set key for a tag.
    */
   private function index (string $tag): string
   {
      return "{$this->Config->prefix}@tag:{$tag}";
   }

   /**
    * Append a key to a tag's member set.
    */
   private function tag (string $tag, string $key, int $TTL): void
   {
      $index = $this->index($tag);

      $members = $this->unpack(apcu_fetch($index, $success));
      if ($success !== true || is_array($members) === false) {
         $members = [];
      }

      // ?: Already a member
      if (in_array($key, $members, true) === true) {
         return;
      }

      $members[] = $key;
      apcu_store($index, $this->pack(array_values($members)), $TTL);
   }
}
