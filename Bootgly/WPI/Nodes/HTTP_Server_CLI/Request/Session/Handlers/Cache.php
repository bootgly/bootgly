<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers;


use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_WORKING_DIR;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chmod;
use function dirname;
use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function fopen;
use function fsync;
use function function_exists;
use function fwrite;
use function hash;
use function hash_hmac;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_scalar;
use function is_string;
use function link;
use function lstat;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function random_bytes;
use function str_contains;
use function strlen;
use function substr;
use function trim;
use function umask;
use function unlink;
use function unpack;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\API\Security\Encrypter;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handling;


/**
 * Cache-backed session handler.
 *
 * Stores sessions in any `ABI\Resources\Cache` backend: `shared` (default)
 * for single-host multi-worker servers, `redis` for multi-host fleets, or
 * `apcu`/`file` where their scopes fit. Expiry is native — every write sets
 * the entry TTL to `Session::$lifetime`, so reads never return stale data
 * and purge() is only a storage-reclaim pass.
 *
 * Every payload is encrypted and authenticated before reaching the backend.
 * The session ID and per-application context are Additional Authenticated
 * Data, preventing ciphertext replay under another chosen ID. Default key
 * material is generated once under the protected sessions directory. Redis
 * fleets spanning multiple hosts must provision the same explicit `secret`
 * on every host.
 */
class Cache implements Handling
{
   // * Data
   private CacheResource $Cache;
   private Encrypter $Encrypter;
   private string $context;


   /**
    * @param array<string,mixed>|CacheResource $config Cache config array or
    *        a prepared Cache instance. Array-only security options:
    *        `secret` (at least 32 bytes) and `secret_path`.
    */
   public function __construct (array|CacheResource $config = [])
   {
      $settings = is_array($config) ? $config : [];
      $material = self::load($settings);
      $digest = hash_hmac(
         'sha256',
         "bootgly.session\0" . BOOTGLY_WORKING_DIR,
         $material
      );

      // * Data
      $this->Encrypter = new Encrypter($material);
      $this->context = substr($digest, 0, 32);

      if ($config instanceof CacheResource) {
         $this->Cache = $config;
         return;
      }

      $cache = $config;
      unset($cache['secret'], $cache['secret_path']);
      $cache['driver'] = is_string($cache['driver'] ?? null)
         ? $cache['driver']
         : 'shared';
      $cache['prefix'] = is_string($cache['prefix'] ?? null)
         ? $cache['prefix']
         : "session:{$this->context}:";

      if ($cache['driver'] === 'shared') {
         // ! Session IPC is always owner-only. An explicit prepared
         //   CacheResource remains possible for advanced deployments, while
         //   authenticated encryption still protects its payloads.
         $cache['permissions'] = 0600;

         $configuredSegment = $cache['segment'] ?? 0;
         if (
            is_scalar($configuredSegment) === false
            || (int) $configuredSegment === 0
         ) {
            $bytes = hash_hmac(
               'sha256',
               "bootgly.session.segment\0" . BOOTGLY_WORKING_DIR,
               $material,
               true
            );
            $parts = unpack('Nsegment', substr($bytes, 0, 4));
            $segment = is_array($parts)
               ? ((int) $parts['segment'] & 0x7fffffff)
               : 0;
            $cache['segment'] = $segment > 0 ? $segment : 1;
         }
      }

      $this->Cache = new CacheResource($cache);
   }

   public function read (string $sessionId): string|false
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $sealed = $this->Cache->fetch($sessionId);
      if (is_string($sealed) === false) {
         return false;
      }

      $data = $this->Encrypter->decrypt(
         $sealed,
         "{$this->context}:{$sessionId}"
      );

      // : Expired entries vanish natively (TTL set on write)
      return is_string($data) === true ? $data : false;
   }

   public function write (string $sessionId, string $sessionData): bool
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $sealed = $this->Encrypter->encrypt(
         $sessionData,
         "{$this->context}:{$sessionId}"
      );

      // @ TTL = session lifetime — the backend expires the entry natively
      return $this->Cache->store($sessionId, $sealed, Session::$lifetime);
   }

   public function touch (string $sessionId): bool
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $sealed = $this->Cache->fetch($sessionId);
      if (
         is_string($sealed) === false
         || $this->Encrypter->decrypt(
            $sealed,
            "{$this->context}:{$sessionId}"
         ) === null
      ) {
         return false;
      }

      // @ Re-store to renew the TTL (sliding expiration)
      return $this->Cache->store($sessionId, $sealed, Session::$lifetime);
   }

   public function destroy (string $sessionId): bool
   {
      // ? Invalid ID — nothing to destroy
      if (self::validate($sessionId) === false) {
         return true;
      }

      return $this->Cache->delete($sessionId);
   }

   public function purge (int $maxLifetime): bool
   {
      // @ Entries expire natively via per-write TTL; this pass only reclaims
      //   storage on drivers that keep expired records around (File, Shared).
      $this->Cache->purge();

      return true;
   }

   // ---

   /**
    * Validate the session ID shape (mirrors the File handler guard).
    *
    * Keys reach shared backends verbatim after the prefix, so the same hex
    * hygiene prevents key-namespace injection via attacker-supplied IDs.
    */
   private static function validate (string $sessionId): bool
   {
      return preg_match('/^[a-f0-9]{32,64}$/', $sessionId) === 1;
   }

   /**
    * Load managed or explicitly configured 256-bit session key material.
    *
    * @param array<string,mixed> $config
    */
   private static function load (array $config): string
   {
      $secret = $config['secret'] ?? null;
      if ($secret !== null) {
         if (is_string($secret) === false || strlen($secret) < 32) {
            throw new InvalidArgumentException(
               'Session Cache secrets must contain at least 32 bytes.'
            );
         }

         return hash('sha256', $secret, true);
      }

      $path = is_string($config['secret_path'] ?? null)
         ? $config['secret_path']
         : BOOTGLY_STORAGE_DIR . 'sessions/.cache.key';
      if ($path === '' || str_contains($path, "\0")) {
         throw new InvalidArgumentException(
            'Session Cache key paths must be non-empty and contain no NUL bytes.'
         );
      }

      if (is_link($path)) {
         throw new RuntimeException('Session Cache key path must not be a symbolic link.');
      }
      if (is_file($path)) {
         return self::import($path);
      }
      if (file_exists($path)) {
         throw new RuntimeException('Session Cache key path must be a regular file.');
      }

      return self::create($path);
   }

   /** Atomically create the default key without exposing partial contents. */
   private static function create (string $path): string
   {
      $directory = dirname($path);
      if (is_link($directory)) {
         throw new RuntimeException('Session Cache key directory must not be a symbolic link.');
      }
      $created = false;
      if (is_dir($directory) === false) {
         $mask = umask(0077);
         try {
            $created = @mkdir($directory, 0700, true);
         }
         finally {
            umask($mask);
         }
         if ($created === false && is_dir($directory) === false) {
            throw new RuntimeException('Failed to create the Session Cache key directory.');
         }
      }
      self::secure($directory, $created);

      $material = random_bytes(32);
      $encoded = base64_encode($material);
      $temporary = "{$path}.tmp." . bin2hex(random_bytes(16));
      $mask = umask(0077);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($mask);
      }
      if ($Handle === false) {
         throw new RuntimeException('Failed to create a temporary Session Cache key.');
      }

      $persisted = false;
      try {
         $persisted = @chmod($temporary, 0600) === true
            && @fwrite($Handle, $encoded) === strlen($encoded)
            && @fflush($Handle) === true
            && (function_exists('fsync') === false || @fsync($Handle) === true);
      }
      finally {
         @fclose($Handle);
      }
      if ($persisted === false) {
         @unlink($temporary);
         throw new RuntimeException('Failed to persist the Session Cache key.');
      }

      // ! link() publishes a complete inode only when the destination is
      //   absent. Concurrent workers that lose this race import the winner.
      $published = @link($temporary, $path);
      @unlink($temporary);
      if ($published === false && is_file($path) === false) {
         throw new RuntimeException('Failed to publish the Session Cache key atomically.');
      }

      return self::import($path);
   }

   /** Import a complete owner-only key file and reject unsafe metadata. */
   private static function import (string $path): string
   {
      if (is_link($path)) {
         throw new RuntimeException('Session Cache key path must not be a symbolic link.');
      }
      self::secure(dirname($path));

      $state = @lstat($path);
      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;
      if (
         is_array($state) === false
         || ((int) $state['mode'] & 0170000) !== 0100000
         || ((int) $state['mode'] & 0777) !== 0600
         || ($EUID !== null && (int) $state['uid'] !== $EUID)
      ) {
         throw new RuntimeException('Session Cache key file has unsafe metadata.');
      }

      $encoded = @file_get_contents($path);
      $material = is_string($encoded)
         ? base64_decode(trim($encoded), true)
         : false;
      if (is_string($material) === false || strlen($material) !== 32) {
         throw new RuntimeException('Session Cache key file is invalid.');
      }

      return $material;
   }

   /** Validate the key directory without mutating a caller-owned directory. */
   private static function secure (string $directory, bool $created = false): void
   {
      if (is_link($directory)) {
         throw new RuntimeException('Session Cache key directory must not be a symbolic link.');
      }
      if ($created && @chmod($directory, 0700) === false) {
         throw new RuntimeException('Failed to protect the Session Cache key directory.');
      }

      $state = @lstat($directory);
      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;
      if (
         is_array($state) === false
         || ((int) $state['mode'] & 0170000) !== 0040000
         || ((int) $state['mode'] & 0022) !== 0
         || (
            $EUID !== null
            && (int) $state['uid'] !== $EUID
            && (int) $state['uid'] !== 0
         )
      ) {
         throw new RuntimeException('Session Cache key directory has unsafe metadata.');
      }
   }
}
