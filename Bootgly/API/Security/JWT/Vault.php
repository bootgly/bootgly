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
use const LOCK_UN;
use function bin2hex;
use function chmod;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function glob;
use function hash;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rename;
use function strlen;
use function substr;
use function time;
use function unlink;
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

      // * Config
      $this->path = $this->prepare($path ?? BOOTGLY_WORKING_DIR . 'workdata/security/jwt');
      $this->prefix = $prefix;
   }

   /**
    * Read a non-expired value.
    */
   public function read (string $key): null|string
   {
      $Lock = $this->lock();
      try {
         return $this->load($this->resolve($key));
      }
      finally {
         $this->free($Lock);
      }
   }

   /**
    * Write a value with a positive TTL.
    */
   public function write (string $key, string $value, int $ttl): bool
   {
      $this->guard($ttl);

      $Lock = $this->lock();
      try {
         return $this->put($this->resolve($key), $value, $ttl);
      }
      finally {
         $this->free($Lock);
      }
   }

   /**
    * Write only when the key does not already hold a non-expired value.
    */
   public function claim (string $key, string $value, int $ttl): bool
   {
      $this->guard($ttl);

      $Lock = $this->lock();
      try {
         $file = $this->resolve($key);
         if ($this->load($file) !== null) {
            return false;
         }

         return $this->put($file, $value, $ttl);
      }
      finally {
         $this->free($Lock);
      }
   }

   /**
    * Atomically read and delete a non-expired value.
    */
   public function take (string $key): null|string
   {
      $Lock = $this->lock();
      try {
         $file = $this->resolve($key);
         $value = $this->load($file);
         if ($value !== null && is_file($file)) {
            unlink($file);
         }

         return $value;
      }
      finally {
         $this->free($Lock);
      }
   }

   /**
    * Delete a value.
    */
   public function delete (string $key): bool
   {
      $Lock = $this->lock();
      try {
         $file = $this->resolve($key);
         if (is_file($file)) {
            unlink($file);
         }

         return true;
      }
      finally {
         $this->free($Lock);
      }
   }

   /**
    * Purge expired values.
    */
   public function purge (): bool
   {
      $Lock = $this->lock();
      try {
         foreach (glob($this->path . $this->prefix . '*') ?: [] as $file) {
            if (is_file($file)) {
               $this->load($file);
            }
         }

         return true;
      }
      finally {
         $this->free($Lock);
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
         unlink($file);
         return null;
      }

      $mac = substr($data, 0, self::MAC_LENGTH);
      $payload = substr($data, self::MAC_LENGTH);
      $expected = hash_hmac('sha256', $payload, $this->derive());
      if (hash_equals($expected, $mac) === false) {
         unlink($file);
         return null;
      }

      try {
         $Record = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
      }
      catch (JsonException) {
         unlink($file);
         return null;
      }

      if (
         is_array($Record) === false
         || is_int($Record['expires'] ?? null) === false
         || is_string($Record['value'] ?? null) === false
      ) {
         unlink($file);
         return null;
      }

      if ($Record['expires'] <= time()) {
         unlink($file);
         return null;
      }

      return $Record['value'];
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

      return rename($temp, $file);
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
    * @return resource
    */
   private function lock ()
   {
      $Lock = fopen($this->path . '.lock', 'c');
      if ($Lock === false) {
         throw new RuntimeException('JWT cache lock could not be acquired.');
      }
      if (flock($Lock, LOCK_EX) === false) {
         fclose($Lock);
         throw new RuntimeException('JWT cache lock could not be acquired.');
      }

      return $Lock;
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

      $file = $this->path . '.secret';
      if (is_file($file)) {
         $secret = file_get_contents($file);
         if (is_string($secret) && strlen($secret) >= 32) {
            $this->secret = $secret;
            return $this->secret;
         }
      }

      $this->secret = bin2hex(random_bytes(32));
      file_put_contents($file, $this->secret);
      chmod($file, 0600);

      return $this->secret;
   }
}
