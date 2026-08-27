<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use const BOOTGLY_STORAGE_DIR;
use const DIRECTORY_SEPARATOR;
use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
use function bin2hex;
use function chmod;
use function fclose;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function hash;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function is_writable;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;
use function time;
use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

use Bootgly\ABI\Resources\Cache as Storage;
use Bootgly\ABI\Resources\Cache\Atomic;


/**
 * JWT cache backed by the Bootgly Cache facade.
 *
 * Records keep Vault's HMAC-SHA256 envelope
 * (`mac . {"expires","value","nonce"}`), so
 * the store never holds plain trusted state: a record altered in the storage
 * backend fails MAC verification and reads as a miss. The default backend is
 * the `file` driver rooted at the Vault path (same-host worker sharing, as
 * before); passing a Redis-backed Cache enables fleet-wide token revocation —
 * inject a shared `$secret` in that case, since the default secret is derived
 * per host. `claim()` and `take()` elect their winners through the backend's
 * atomic create/compare-and-evict primitives. `lock()` remains deliberately
 * host-local: it serializes compound sections within one host, but is not a
 * distributed multi-key transaction.
 */
class Vault implements Cache
{
   // * Config
   public private(set) string $path;
   public private(set) string $prefix;
   public private(set) Storage $Storage;

   // * Data
   private string $secret = '';
   /**
    * Active transaction lock.
    *
    * @var null|resource
    */
   private mixed $Lock = null;

   // * Metadata
   private const int MAC_LENGTH = 64;
   private const int CLAIM_ATTEMPTS = 3;


   /**
    * Create a JWT cache on a storage backend.
    *
    * @param null|string|Storage $storage Directory for the default file
    * backend, or a prepared Cache facade whose driver implements the atomic
    * create/swap/evict contract.
    * @param string $prefix Storage key prefix.
    * @param null|string $secret Shared HMAC secret (>= 32 bytes); null derives
    * a per-host secret file.
    */
   public function __construct (
      null|string|Storage $storage = null,
      string $prefix = 'jwt_',
      null|string $secret = null
   )
   {
      // ?
      if ($prefix === '') {
         throw new InvalidArgumentException('JWT cache file prefix must not be empty.');
      }
      if (str_contains($prefix, DIRECTORY_SEPARATOR)) {
         throw new InvalidArgumentException('JWT cache file prefix must not contain directory separators.');
      }
      if ($secret !== null && strlen($secret) < 32) {
         throw new InvalidArgumentException('JWT cache secret must have at least 32 bytes.');
      }

      // * Config
      $this->path = $this->prepare(
         is_string($storage)
            ? $storage
            : BOOTGLY_STORAGE_DIR . 'security/jwt'
      );
      $this->prefix = $prefix;
      $this->Storage = $storage instanceof Storage
         ? $storage
         : new Storage([
            'driver' => 'file',
            'path' => $this->path
         ]);
      if ($this->Storage->Driver instanceof Atomic === false) {
         throw new InvalidArgumentException(
            'JWT cache storage driver must provide atomic create, swap and evict operations.'
         );
      }

      // * Data
      $this->secret = $secret ?? '';
   }

   /**
    * Run a critical cache section under an exclusive lock.
    */
   public function lock (Closure $Closure): mixed
   {
      if ($this->Lock !== null) {
         return $Closure();
      }

      $Lock = $this->open(LOCK_EX);
      $this->Lock = $Lock;
      try {
         return $Closure();
      }
      finally {
         $this->Lock = null;
         $this->free($Lock);
      }
   }

   /**
    * Read a non-expired value.
    */
   public function read (string $key): null|string
   {
      $Lock = $this->share(LOCK_SH);
      try {
         return $this->load($key);
      }
      finally {
         $this->release($Lock);
      }
   }

   /**
    * Write a value with a positive TTL.
    */
   public function write (string $key, string $value, int $ttl): bool
   {
      $this->guard($ttl);

      $Lock = $this->share(LOCK_EX);
      try {
         return $this->put($key, $value, $ttl);
      }
      finally {
         $this->release($Lock);
      }
   }

   /**
    * Write only when the key does not already hold a non-expired value.
    */
   public function claim (string $key, string $value, int $ttl): bool
   {
      $this->guard($ttl);

      $Lock = $this->share(LOCK_EX);
      try {
         $record = $this->seal($value, $ttl);
         if ($record === null) {
            return false;
         }
         $resolved = $this->resolve($key);

         // @ The backend, not the host-local lock, elects the fleet winner.
         for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            if ($this->Storage->create($resolved, $record, $ttl)) {
               return true;
            }

            // ? A live authentic record owns the claim. Invalid/tampered
            //   records fail closed instead of being overwritten blindly.
            $stored = $this->Storage->fetch($resolved);
            if ($stored === null) {
               continue;
            }
            $expired = false;
            if ($this->decode($stored, $expired) !== null || $expired === false) {
               return false;
            }

            // @ The protected expiry can precede a backend TTL under clock
            //   skew. Replace only the exact authentic expired envelope.
            if ($this->Storage->swap($resolved, $stored, $record, $ttl)) {
               return true;
            }
         }

         return false;
      }
      finally {
         $this->release($Lock);
      }
   }

   /**
    * Atomically read and delete a non-expired value.
    */
   public function take (string $key): null|string
   {
      $Lock = $this->share(LOCK_EX);
      try {
         $stored = null;
         $value = $this->load($key, $stored);
         if (
            $value === null
            || $stored === null
            || $this->Storage->evict($this->resolve($key), $stored) === false
         ) {
            return null;
         }

         return $value;
      }
      finally {
         $this->release($Lock);
      }
   }

   /**
    * Delete a value.
    */
   public function delete (string $key): bool
   {
      $Lock = $this->share(LOCK_EX);
      try {
         return $this->Storage->delete($this->resolve($key));
      }
      finally {
         $this->release($Lock);
      }
   }

   /**
    * Purge expired values.
    */
   public function purge (): bool
   {
      $Lock = $this->share(LOCK_EX);
      try {
         $this->Storage->purge();

         return true;
      }
      finally {
         $this->release($Lock);
      }
   }

   /**
    * Validate a positive TTL.
    */
   private function guard (int $ttl): void
   {
      if ($ttl < 1) {
         throw new InvalidArgumentException('JWT cache ttl must be positive.');
      }
   }

   /**
    * Read and validate a stored record.
    */
   private function load (string $key, mixed &$stored = null): null|string
   {
      $stored = $this->Storage->fetch($this->resolve($key));

      return $this->decode($stored);
   }

   /**
    * Verify and decode one raw Vault envelope.
    */
   private function decode (mixed $data, bool &$expired = false): null|string
   {
      $expired = false;
      if (is_string($data) === false || strlen($data) < self::MAC_LENGTH) {
         return null;
      }

      $mac = substr($data, 0, self::MAC_LENGTH);
      $payload = substr($data, self::MAC_LENGTH);
      $expected = hash_hmac('sha256', $payload, $this->derive());
      if (hash_equals($expected, $mac) === false) {
         return null;
      }

      try {
         $Record = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         return null;
      }

      if (
         is_array($Record) === false
         || is_int($Record['expires'] ?? null) === false
         || is_string($Record['value'] ?? null) === false
      ) {
         return null;
      }

      if ($Record['expires'] <= time()) {
         $expired = true;

         return null;
      }

      return $Record['value'];
   }

   /**
    * Persist a cache value.
    *
    * The expiry rides inside the HMAC-protected payload (the storage TTL alone
    * is not tamper-protected) and is also handed to the storage backend so
    * expired records are evicted natively.
    */
   private function put (string $key, string $value, int $ttl): bool
   {
      $record = $this->seal($value, $ttl);

      return $record !== null
         && $this->Storage->store($this->resolve($key), $record, $ttl);
   }

   /**
    * Seal one value in a unique authenticated Vault envelope.
    */
   private function seal (string $value, int $ttl): null|string
   {
      try {
         $payload = json_encode([
            'expires' => time() + $ttl,
            'value' => $value,
            // ! Unique per write so an old compare-and-evict cannot remove a
            //   later record recreated with the same value in the same second.
            'nonce' => bin2hex(random_bytes(16)),
         ], JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return null;
      }

      $mac = hash_hmac('sha256', $payload, $this->derive());

      return $mac . $payload;
   }

   /**
    * Build a safe storage key for a cache key.
    */
   private function resolve (string $key): string
   {
      $hash = hash('sha256', $key);

      return "{$this->prefix}{$hash}";
   }

   /**
    * Open and lock the cache lock file.
    *
      * @param int<0,7> $mode
      *
    * @return resource
    */
   private function open (int $mode)
   {
      $Lock = fopen("{$this->path}{$this->prefix}.lock", 'c');
      if ($Lock === false) {
         throw new RuntimeException('JWT cache lock could not be acquired.');
      }
      if (flock($Lock, $mode) === false) {
         fclose($Lock);
         throw new RuntimeException('JWT cache lock could not be acquired.');
      }

      return $Lock;
   }

   /**
    * Open a lock unless a transaction lock is already active.
    *
      * @param int<0,7> $mode
      *
    * @return null|resource
    */
   private function share (int $mode)
   {
      if ($this->Lock !== null) {
         return null;
      }

      return $this->open($mode);
   }

   /**
    * Release an optional cache lock.
    *
    * @param null|resource $Lock
    */
   private function release ($Lock): void
   {
      if ($Lock === null) {
         return;
      }

      $this->free($Lock);
   }

   /**
    * Release a cache lock.
    *
    * @param resource $Lock
    */
   private function free ($Lock): void
   {
      flock($Lock, LOCK_UN);
      fclose($Lock);
   }

   /**
    * Prepare a cache directory.
    */
   private function prepare (string $path): string
   {
      if ($path === '') {
         throw new InvalidArgumentException('JWT cache path must not be empty.');
      }

      if ($path[strlen($path) - 1] !== DIRECTORY_SEPARATOR) {
         $path .= DIRECTORY_SEPARATOR;
      }

      if (is_dir($path) === false) {
         mkdir($path, 0700, true);
      }

      if (is_dir($path) === false || is_writable($path) === false) {
         throw new RuntimeException('JWT cache path must be writable.');
      }

      return $path;
   }

   /**
    * Load or create the cache HMAC secret.
    */
   private function derive (): string
   {
      if ($this->secret !== '') {
         return $this->secret;
      }

      $file = "{$this->path}{$this->prefix}.secret";
      $Secret = fopen($file, 'c+');
      if ($Secret === false) {
         throw new RuntimeException('JWT cache secret could not be opened.');
      }

      try {
         if (flock($Secret, LOCK_EX) === false) {
            throw new RuntimeException('JWT cache secret could not be locked.');
         }

         $secret = stream_get_contents($Secret);
         if (is_string($secret) && strlen($secret) >= 32) {
            $this->secret = $secret;
            return $this->secret;
         }

         $this->secret = bin2hex(random_bytes(32));
         ftruncate($Secret, 0);
         rewind($Secret);
         fwrite($Secret, $this->secret);
         chmod($file, 0600);

         return $this->secret;
      }
      finally {
         flock($Secret, LOCK_UN);
         fclose($Secret);
      }
   }
}
