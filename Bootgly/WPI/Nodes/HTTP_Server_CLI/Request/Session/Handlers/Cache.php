<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers;


use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_WORKING_DIR;
use const DIRECTORY_SEPARATOR;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chmod;
use function count;
use function dirname;
use function fclose;
use function fflush;
use function file_get_contents;
use function fopen;
use function fsync;
use function function_exists;
use function fwrite;
use function hash;
use function hash_equals;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Committing;


/**
 * Cache-backed session handler.
 *
 * Stores sessions in any `ABI\Resources\Cache` backend: `file` (default),
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
class Cache implements Committing
{
   private const int RECORD_VERSION = 1;

   // * Data
   private CacheResource $Cache;
   private Encrypter $Encrypter;
   private string $context;


   /**
    * @param array<string,mixed>|CacheResource $config Cache config array or
    *        a prepared Cache instance. Array-only security options:
    *        `secret` (at least 32 bytes) and `secret_path`. Every other key
    *        must be a `Cache\Config` option: the array reaches it with those
    *        two removed (the `shared` driver also forces `permissions` to
    *        0600), and an unknown key is refused by name.
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
      // ! `file`, not `shared`. Both are shared across workers, but ext-sysvshm
      //   deserializes a record inside `shm_get_var()` before any Bootgly code
      //   can refuse it, so a process able to write the segment can fire a
      //   planted destructor in the session worker. The File driver hands its
      //   bytes to the allow-list first, so nothing is built until the record
      //   has been accepted. A deployment that wants the shared-memory speed
      //   can still ask for it explicitly.
      $cache['driver'] = is_string($cache['driver'] ?? null)
         ? $cache['driver']
         : 'file';
      $cache['prefix'] = is_string($cache['prefix'] ?? null)
         ? $cache['prefix']
         : "session:{$this->context}:";
      // ! The records live beside the key, under `storage/sessions`: the one
      //   directory a root launch hands to the runtime identity the workers run
      //   as. Never the shared `storage/cache`, which root-run code reads too.
      //   Checked like the key directory, whatever the key source: a process
      //   that does not own the tree (root, over a runtime-owned one) refuses
      //   it instead of following what its owner planted there.
      if ($cache['driver'] === 'file' && is_string($cache['path'] ?? null) === false) {
         $cache['path'] = self::store(BOOTGLY_STORAGE_DIR . 'sessions');
      }

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
      $revision = null;

      return $this->fetch($sessionId, $revision);
   }

   public function fetch (
      string $sessionId,
      null|string &$revision = null,
   ): string|false
   {
      $revision = null;
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $record = $this->Cache->fetch($sessionId);

      // : Expired entries vanish natively (TTL set on write)
      return $this->open($record, $sessionId, $revision);
   }

   public function write (string $sessionId, string $sessionData): bool
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $revision = bin2hex(random_bytes(32));
      $record = $this->seal($sessionData, $sessionId, $revision);

      // @ TTL = session lifetime — the backend expires the entry natively
      return $this->Cache->store($sessionId, $record, Session::$lifetime);
   }

   public function commit (
      string $sessionId,
      string $sessionData,
      null|string &$revision,
   ): bool
   {
      if (self::validate($sessionId) === false) {
         return false;
      }

      $nextRevision = bin2hex(random_bytes(32));
      $next = $this->seal($sessionData, $sessionId, $nextRevision);
      if ($revision === null) {
         $committed = $this->Cache->create(
            $sessionId,
            $next,
            Session::$lifetime,
         );
         if ($committed) {
            $revision = $nextRevision;

            return true;
         }

         if ($this->Cache->fetch($sessionId) !== null) {
            return false;
         }

         throw new RuntimeException('The Cache driver failed its atomic Session create.');
      }

      $current = $this->Cache->fetch($sessionId);
      $currentRevision = null;
      if (
         $this->open($current, $sessionId, $currentRevision) === false
         || is_string($currentRevision) === false
         || hash_equals($revision, $currentRevision) === false
      ) {
         return false;
      }

      $committed = $this->Cache->swap(
         $sessionId,
         $current,
         $next,
         Session::$lifetime,
      );
      if ($committed) {
         $revision = $nextRevision;

         return true;
      }

      $latest = $this->Cache->fetch($sessionId);
      $latestRevision = null;
      if (
         $this->open($latest, $sessionId, $latestRevision) === false
         || is_string($latestRevision) === false
         || hash_equals($revision, $latestRevision) === false
      ) {
         return false;
      }

      throw new RuntimeException('The Cache driver failed its atomic Session commit.');
   }

   public function revoke (string $sessionId, string $revision): bool
   {
      if (self::validate($sessionId) === false) {
         return false;
      }

      $current = $this->Cache->fetch($sessionId);
      $currentRevision = null;
      if (
         $this->open($current, $sessionId, $currentRevision) === false
         || is_string($currentRevision) === false
         || hash_equals($revision, $currentRevision) === false
      ) {
         return false;
      }

      if ($this->Cache->evict($sessionId, $current)) {
         return true;
      }

      $latest = $this->Cache->fetch($sessionId);
      $latestRevision = null;
      if (
         $this->open($latest, $sessionId, $latestRevision) === false
         || is_string($latestRevision) === false
         || hash_equals($revision, $latestRevision) === false
      ) {
         return false;
      }

      throw new RuntimeException('The Cache driver failed its atomic Session revocation.');
   }

   public function touch (string $sessionId): bool
   {
      // ?
      if (self::validate($sessionId) === false) {
         return false;
      }

      $record = $this->Cache->fetch($sessionId);
      $revision = null;
      if ($this->open($record, $sessionId, $revision) === false) {
         return false;
      }

      // @ Backend-native TTL renewal never writes the fetched snapshot, so a
      // concurrent logout/downgrade cannot be rolled back by a stale reader.
      return $this->Cache->renew($sessionId, Session::$lifetime);
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

   /** @return array{version:int,revision:string,sealed:string} */
   private function seal (
      string $sessionData,
      string $sessionId,
      string $revision,
   ): array
   {
      return [
         'version' => self::RECORD_VERSION,
         'revision' => $revision,
         'sealed' => $this->Encrypter->encrypt(
            $sessionData,
            "{$this->context}:{$sessionId}",
         ),
      ];
   }

   /** Decode one versioned record, retaining compatibility with legacy ciphertext. */
   private function open (
      mixed $record,
      string $sessionId,
      null|string &$revision = null,
   ): string|false
   {
      $revision = null;
      if (is_string($record)) {
         $sealed = $record;
         $revision = 'legacy:' . hash('sha256', $sealed);
      }
      elseif (
         is_array($record)
         && count($record) === 3
         && ($record['version'] ?? null) === self::RECORD_VERSION
         && is_string($record['revision'] ?? null)
         && preg_match('/^[a-f0-9]{64}$/D', $record['revision']) === 1
         && is_string($record['sealed'] ?? null)
      ) {
         $revision = $record['revision'];
         $sealed = $record['sealed'];
      }
      else {
         return false;
      }

      $data = $this->Encrypter->decrypt(
         $sealed,
         "{$this->context}:{$sessionId}",
      );
      if (is_string($data) === false) {
         $revision = null;

         return false;
      }

      return $data;
   }

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

      // ! One look at the path: two checks (a file? then: anything?) let a
      //   concurrent worker publish the key between them, refusing a key that
      //   is a regular file by then
      $state = @lstat($path);
      // ?: No key yet — create it (a worker that loses the publication race
      //   imports the winner's)
      if ($state === false) {
         return self::create($path);
      }
      $type = (int) $state['mode'] & 0170000;
      if ($type === 0120000) {
         throw new RuntimeException('Session Cache key path must not be a symbolic link.');
      }
      if ($type !== 0100000) {
         throw new RuntimeException('Session Cache key path must be a regular file.');
      }

      // :
      return self::import($path);
   }

   /**
    * Create the owner-only sessions directory when absent, and validate it and
    * its ancestors for this process — never through a link.
    */
   private static function establish (string $directory): void
   {
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
   }

   /**
    * Prepare the default records directory inside the sessions directory and
    * return it.
    *
    * Both directories are checked for what they are, never through their
    * ancestors (that walk guards the key's multi-step creation, and an
    * explicit `secret` creates no key): each must be a plain directory, not a
    * link, writable by its owner only, and owned by this process — the
    * sessions directory may also be root's. A process that does not own the
    * tree (root, over the directory a root launch handed to the workers)
    * refuses it instead of reading or writing through what its owner put there.
    */
   private static function store (string $directory): string
   {
      $records = "{$directory}/cache";
      foreach ([$directory => true, $records => false] as $path => $rooted) {
         // ! Created owner-only here, not 0775 by the File driver on first write
         if (@lstat($path) === false) {
            $mask = umask(0077);
            try {
               @mkdir($path, 0700);
            }
            finally {
               umask($mask);
            }
         }

         self::vet($path, $rooted);
      }

      // :
      return $records;
   }

   /**
    * Refuse a session store directory that is not this process's own — with
    * the reason and its remedy.
    *
    * @param string $path The directory.
    * @param bool $rooted Whether root may own it too.
    */
   private static function vet (string $path, bool $rooted): void
   {
      $state = @lstat($path);
      if (is_array($state) === false) {
         throw new RuntimeException("Session Cache store directory `{$path}` could not be created.");
      }
      $type = (int) $state['mode'] & 0170000;
      if ($type === 0120000) {
         throw new RuntimeException("Session Cache store directory `{$path}` must be a plain directory, not a link.");
      }
      if ($type !== 0040000) {
         throw new RuntimeException("Session Cache store directory `{$path}` must be a directory.");
      }

      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;
      $owner = (int) $state['uid'];
      if ($EUID !== null && $owner !== $EUID && ($rooted === false || $owner !== 0)) {
         throw new RuntimeException("Session Cache store directory `{$path}` must be owned by the current user (uid {$EUID}), not uid {$owner}.");
      }
      if (((int) $state['mode'] & 0022) !== 0) {
         throw new RuntimeException("Session Cache store directory `{$path}` must not be group- or world-writable: `chmod 700 {$path}`.");
      }
   }

   /** Atomically create the default key without exposing partial contents. */
   private static function create (string $path): string
   {
      self::establish(dirname($path));

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

      // ---

      // @ Walk the ancestors too. Key creation and import
      //   perform separate pathname-based lstat/link/read operations, so a
      //   replaceable ancestor lets another local UID swap a component between
      //   them — validating only the immediate directory leaves that window
      //   open. An ancestor is acceptable only while it is a directory owned by
      //   root or by this process, and is not world/group-writable without the
      //   sticky bit. Mirrors the File handler, which already walks its chain.
      // ! Root is uid 0 — and whoever owns `/` (a user namespace shows the
      //   host's root as an overflow uid). `Demotable::inspect()` applies the
      //   same rule at a root launch, to warn before any worker runs into it.
      $rootState = @lstat(DIRECTORY_SEPARATOR);
      $rootUID = is_array($rootState) ? (int) $rootState['uid'] : 0;

      $ancestor = dirname($directory);
      while (true) {
         $state = @lstat($ancestor);
         if (
            is_array($state) === false
            || ((int) $state['mode'] & 0170000) !== 0040000
         ) {
            throw new RuntimeException('Session Cache key path ancestor is unsafe.');
         }

         $mode = (int) $state['mode'];
         $owner = (int) $state['uid'];
         if (
            ($EUID !== null && $owner !== 0 && $owner !== $rootUID && $owner !== $EUID)
            || (($mode & 0022) !== 0 && ($mode & 01000) === 0)
         ) {
            throw new RuntimeException('Session Cache key path ancestor is replaceable.');
         }

         if ($ancestor === DIRECTORY_SEPARATOR) {
            break;
         }

         $parent = dirname($ancestor);
         if ($parent === $ancestor) {
            break;
         }

         $ancestor = $parent;
      }
   }
}
