<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use const BOOTGLY_WORKING_DIR;
use const DIRECTORY_SEPARATOR;
use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
use function bin2hex;
use function chmod;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function glob;
use function hash;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function is_writable;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rename;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;
use function time;
use function unlink;
use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;


/**
 * File-backed JWT cache shared by workers on the same filesystem.
 */
class Vault implements Cache
{
   // * Config
   public private(set) string $path;
   public private(set) string $prefix;

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


   /**
    * Create a file-backed JWT cache.
    */
   public function __construct (null|string $path = null, string $prefix = 'jwt_')
   {
      if ($prefix === '') {
         throw new InvalidArgumentException('JWT cache file prefix must not be empty.');
      }
      if (str_contains($prefix, DIRECTORY_SEPARATOR)) {
         throw new InvalidArgumentException('JWT cache file prefix must not contain directory separators.');
      }

      // * Config
      $this->path = $this->prepare($path ?? BOOTGLY_WORKING_DIR . 'workdata/security/jwt');
      $this->prefix = $prefix;
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
         return $this->load($this->resolve($key));
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
         return $this->put($this->resolve($key), $value, $ttl);
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
         $file = $this->resolve($key);
         if ($this->load($file) !== null) {
            return false;
         }

         return $this->put($file, $value, $ttl);
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
         $file = $this->resolve($key);
         $value = $this->load($file);
         if (is_file($file) && unlink($file) === false) {
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
         $file = $this->resolve($key);
         if (is_file($file)) {
            return unlink($file);
         }

         return true;
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
         $files = glob($this->path . $this->prefix . '[a-f0-9]*');
         if ($files === false) {
            return false;
         }
         foreach ($files as $file) {
            if ($this->sweep($file) === false) {
               return false;
            }
         }

         $temps = glob($this->path . $this->prefix . '.tmp.*');
         if ($temps === false) {
            return false;
         }
         foreach ($temps as $file) {
            if (is_file($file) && unlink($file) === false) {
               return false;
            }
         }

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
    * Read and validate a cache file.
    */
   private function load (string $file): null|string
   {
      if (is_file($file) === false) {
         return null;
      }

      $data = file_get_contents($file);
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
         return null;
      }

      return $Record['value'];
   }

   /**
    * Remove an invalid or expired cache file.
    */
   private function sweep (string $file): bool
   {
      if (is_file($file) === false || $this->load($file) !== null) {
         return true;
      }

      return unlink($file);
   }

   /**
    * Persist a cache value.
    */
   private function put (string $file, string $value, int $ttl): bool
   {
      try {
         $payload = json_encode([
            'expires' => time() + $ttl,
            'value' => $value,
         ], JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return false;
      }

      $mac = hash_hmac('sha256', $payload, $this->derive());
      $temp = $this->path . $this->prefix . '.tmp.' . bin2hex(random_bytes(8));

      if (file_put_contents($temp, $mac . $payload) === false) {
         return false;
      }
      chmod($temp, 0600);

      if (rename($temp, $file) === false) {
         if (is_file($temp)) {
            unlink($temp);
         }

         return false;
      }

      return true;
   }

   /**
    * Build a safe filename for a cache key.
    */
   private function resolve (string $key): string
   {
      return $this->path . $this->prefix . hash('sha256', $key);
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
      $Lock = fopen($this->path . $this->prefix . '.lock', 'c');
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

      $file = $this->path . $this->prefix . '.secret';
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
