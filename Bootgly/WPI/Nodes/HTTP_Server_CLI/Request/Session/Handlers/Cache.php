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


use function is_string;
use function preg_match;

use Bootgly\ABI\Resources\Cache as CacheResource;
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
 * Trust model: unlike the File handler (which HMAC-signs files because any
 * process with filesystem access could drop forged payloads), this handler
 * trusts the configured backend. Keep the backend private to the
 * application: SysV shared memory is host-local; for Redis use AUTH and a
 * dedicated logical database.
 */
class Cache implements Handling
{
   // * Data
   private CacheResource $Cache;


   /**
    * @param array<string,mixed>|CacheResource $config Cache config array
    *        (driver defaults to 'shared', prefix to 'session:') or a
    *        prepared Cache instance.
    */
   public function __construct (array|CacheResource $config = [])
   {
      // * Data
      $this->Cache = $config instanceof CacheResource
         ? $config
         : new CacheResource($config + ['driver' => 'shared', 'prefix' => 'session:']);
   }

   public function read (string $sessionId): string|false
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $data = $this->Cache->fetch($sessionId);

      // : Expired entries vanish natively (TTL set on write)
      return is_string($data) === true ? $data : false;
   }

   public function write (string $sessionId, string $sessionData): bool
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      // @ TTL = session lifetime — the backend expires the entry natively
      return $this->Cache->store($sessionId, $sessionData, Session::$lifetime);
   }

   public function touch (string $sessionId): bool
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $data = $this->Cache->fetch($sessionId);
      // ?
      if (is_string($data) === false) {
         return false;
      }

      // @ Re-store to renew the TTL (sliding expiration)
      return $this->Cache->store($sessionId, $data, Session::$lifetime);
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
}
