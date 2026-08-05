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
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_UN;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fstat;
use function fsync;
use function function_exists;
use function fwrite;
use function glob;
use function hash;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_dir;
use function is_link;
use function is_string;
use function lstat;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function random_bytes;
use function realpath;
use function rename;
use function rtrim;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;
use function time;
use function touch;
use function trim;
use function umask;
use function unlink;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Committing;


class File implements Committing
{
   // * Config
   protected static string $path = '';
   protected static string $prefix = 'session_';

   // * Data
   /** Canonical, validated directory pinned to this handler instance. */
   private string $directory;

   // # HMAC
   private const int MAC_LENGTH = 64;
   private const string RECORD_VERSION = 'BGS1';
   private const int REVISION_LENGTH = 64;
   /** Cached generated/imported 64-byte hexadecimal HMAC secret. */
   private static string $secret = '';
   /** Filesystem path to which the static secret cache belongs. */
   private static string $secretPath = '';


   public static function init (): void
   {
      static::prepare(BOOTGLY_STORAGE_DIR . 'sessions');
   }

   /** @param array<string,mixed> $config */
   public function __construct (array $config = [])
   {
      if (isset($config['save_path'])) {
         if (is_string($config['save_path']) === false) {
            throw new InvalidArgumentException('File session save paths must be strings.');
         }
         $savePath = $config['save_path'];
      }
      else {
         if (static::$path === '') {
            static::init();
         }
         $savePath = static::$path;
      }

      static::prepare($savePath);
      $this->directory = self::configure(static::$path);
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
      $file = $this->locate($sessionId);
      if ($file === '') {
         return false;
      }

      $record = $this->load($file, true);
      if ($record === false) {
         return false;
      }

      return $this->unpack($record, $revision);
   }

   public function write (string $sessionId, string $sessionData): bool
   {
      $revision = null;

      return $this->store($sessionId, $sessionData, $revision, false);
   }

   public function commit (
      string $sessionId,
      string $sessionData,
      null|string &$revision,
   ): bool
   {
      return $this->store($sessionId, $sessionData, $revision, true);
   }

   private function store (
      string $sessionId,
      string $sessionData,
      null|string &$revision,
      bool $conditional,
   ): bool
   {
      $target = $this->locate($sessionId);
      if ($target === '') {
         return false;
      }

      $Lock = self::lock($this->directory . '.records.lock');
      $locked = false;
      try {
         $locked = @flock($Lock, LOCK_EX);
         if ($locked === false) {
            throw new RuntimeException('Failed to lock the File session records.');
         }

         if ($conditional) {
            $current = $this->load($target, false);
            if ($revision === null) {
               if ($current !== false) {
                  return false;
               }
            }
            elseif ($current === false) {
               return false;
            }
            else {
               $currentRevision = null;
               if (
                  $this->unpack($current, $currentRevision) === false
                  || is_string($currentRevision) === false
                  || hash_equals($revision, $currentRevision) === false
               ) {
                  return false;
               }
            }
         }

         $nextRevision = bin2hex(random_bytes(32));
         $record = self::pack($sessionData, $nextRevision);
         $mac = hash_hmac('sha256', $record, $this->sign());
         $temporary = $this->directory . static::$prefix . '.tmp.'
            . bin2hex(random_bytes(16));
         $Handle = self::create($temporary);
         $persisted = false;

         try {
            $persisted = self::persist($Handle, $mac . $record);
         }
         finally {
            @fclose($Handle);
         }
         if ($persisted === false) {
            @unlink($temporary);
            throw new RuntimeException('Failed to persist a File session temporary record.');
         }
         if (@rename($temporary, $target) === false) {
            @unlink($temporary);
            throw new RuntimeException('Failed to publish a File session record atomically.');
         }

         $revision = $nextRevision;

         return true;
      }
      finally {
         if ($locked) {
            @flock($Lock, LOCK_UN);
         }
         @fclose($Lock);
      }
   }

   /** Encode one revision inside the HMAC-authenticated record body. */
   private static function pack (string $sessionData, string $revision): string
   {
      return self::RECORD_VERSION . $revision . $sessionData;
   }

   /** Decode a versioned record, or derive a stable revision for a legacy one. */
   private function unpack (
      string $record,
      null|string &$revision = null,
   ): string|false
   {
      $revision = null;
      $headerLength = strlen(self::RECORD_VERSION) + self::REVISION_LENGTH;
      if (
         strlen($record) >= $headerLength
         && substr($record, 0, strlen(self::RECORD_VERSION)) === self::RECORD_VERSION
      ) {
         $candidate = substr(
            $record,
            strlen(self::RECORD_VERSION),
            self::REVISION_LENGTH,
         );
         if (preg_match('/^[a-f0-9]{64}$/D', $candidate) !== 1) {
            return false;
         }

         $payload = substr($record, $headerLength);
         if ($payload === '') {
            return false;
         }

         $revision = $candidate;

         return $payload;
      }

      // ! Existing HMAC-protected records remain readable. The first
      // successful conditional commit migrates them to the versioned format.
      if ($record === '') {
         return false;
      }
      $revision = 'legacy:' . hash('sha256', $record);

      return $record;
   }

   /** Read and authenticate one opened record; optionally remove expiry. */
   private function load (string $file, bool $remove): string|false
   {

      // ! Owner-owned legacy 0644 files inside the already verified 0700
      // directory are migrated through the opened inode before their contents
      // are trusted. Symlinks, hardlinks and foreign-owned files remain rejected.
      $Handle = self::open($file, repair: true);
      if ($Handle === false) {
         return false;
      }

      $state = @fstat($Handle);
      if (
         is_array($state) === false
         || time() - (int) $state['mtime'] > Session::$lifetime
      ) {
         @fclose($Handle);
         if ($remove && is_array($state) && self::compare($file, $state)) {
            @unlink($file);
         }

         return false;
      }

      try {
         $data = @stream_get_contents($Handle);
      }
      finally {
         @fclose($Handle);
      }
      if (is_string($data) === false || strlen($data) < self::MAC_LENGTH) {
         return false;
      }

      $mac = substr($data, 0, self::MAC_LENGTH);
      $payload = substr($data, self::MAC_LENGTH);
      $expected = hash_hmac('sha256', $payload, $this->sign());

      if (hash_equals($expected, $mac) === false) {
         return false;
      }

      return $payload !== '' ? $payload : false;
   }

   public function touch (string $sessionId): bool
   {
      $file = $this->locate($sessionId);
      if ($file === '') {
         return false;
      }

      $Lock = self::lock($this->directory . '.records.lock');
      $locked = false;
      try {
         $locked = @flock($Lock, LOCK_EX);
         if ($locked === false) {
            throw new RuntimeException('Failed to lock the File session records.');
         }
         if ($this->load($file, false) === false) {
            return false;
         }

         return @touch($file);
      }
      finally {
         if ($locked) {
            @flock($Lock, LOCK_UN);
         }
         @fclose($Lock);
         clearstatcache(true, $file);
      }
   }

   public function revoke (string $sessionId, string $revision): bool
   {
      $file = $this->locate($sessionId);
      if ($file === '') {
         return false;
      }

      $Lock = self::lock($this->directory . '.records.lock');
      $locked = false;
      try {
         $locked = @flock($Lock, LOCK_EX);
         if ($locked === false) {
            throw new RuntimeException('Failed to lock the File session records.');
         }
         $record = $this->load($file, false);
         if ($record === false) {
            return false;
         }
         $currentRevision = null;
         if (
            $this->unpack($record, $currentRevision) === false
            || is_string($currentRevision) === false
            || hash_equals($revision, $currentRevision) === false
         ) {
            return false;
         }

         if (@unlink($file) === false) {
            throw new RuntimeException('Failed to revoke the File session record.');
         }

         return true;
      }
      finally {
         if ($locked) {
            @flock($Lock, LOCK_UN);
         }
         @fclose($Lock);
      }
   }

   public function destroy (string $sessionId): bool
   {
      $file = $this->locate($sessionId);
      if ($file === '') {
         return true;
      }

      $Lock = self::lock($this->directory . '.records.lock');
      $locked = false;
      try {
         $locked = @flock($Lock, LOCK_EX);
         if ($locked === false) {
            throw new RuntimeException('Failed to lock the File session records.');
         }

         clearstatcache(true, $file);
         $state = @lstat($file);
         if (is_array($state) === false) {
            return true;
         }
         if (((int) $state['mode'] & 0170000) === 0040000) {
            return false;
         }

         return @unlink($file);
      }
      finally {
         if ($locked) {
            @flock($Lock, LOCK_UN);
         }
         @fclose($Lock);
      }
   }

   public function purge (int $maxLifetime): bool
   {
      $Lock = self::lock($this->directory . '.records.lock');
      $locked = false;
      try {
         $locked = @flock($Lock, LOCK_EX);
         if ($locked === false) {
            throw new RuntimeException('Failed to lock the File session records.');
         }

         $timeNow = time();
         $files = glob($this->directory . static::$prefix . '*');

         foreach ($files ?: [] as $file) {
            $state = @lstat($file);
            if (
               is_array($state)
               && ((int) $state['mode'] & 0170000) !== 0040000
               && $timeNow - (int) $state['mtime'] > $maxLifetime
            ) {
               @unlink($file);
            }
         }

         return true;
      }
      finally {
         if ($locked) {
            @flock($Lock, LOCK_UN);
         }
         @fclose($Lock);
      }
   }

   // ---

   /** Resolve a validated session ID against the current static configuration. */
   protected static function resolve (string $sessionId): string
   {
      if (preg_match('/^[a-f0-9]{32,64}$/', $sessionId) !== 1) {
         return '';
      }

      return static::$path . static::$prefix . $sessionId;
   }

   /**
    * Preserve the protected extension contract while hardening the configured path.
    */
   protected static function prepare (string $path): void
   {
      $directory = self::configure($path);
      if (static::$path !== $directory) {
         static::$path = $directory;
         self::$secret = '';
         self::$secretPath = '';
      }
   }

   /** Resolve a validated session ID inside this handler's pinned directory. */
   private function locate (string $sessionId): string
   {
      $this->activate();

      return static::resolve($sessionId);
   }

   /** Create or validate a canonical owner-only save directory. */
   private static function configure (string $path): string
   {
      if ($path === '' || str_contains($path, "\0")) {
         throw new InvalidArgumentException(
            'File session save paths must be non-empty and contain no NUL bytes.'
         );
      }

      $directory = rtrim($path, DIRECTORY_SEPARATOR);
      if ($directory === '' || $directory[0] !== DIRECTORY_SEPARATOR) {
         throw new InvalidArgumentException('File session save paths must be absolute.');
      }
      self::guard($directory);

      if (is_dir($directory) === false) {
         $mask = umask(0077);
         try {
            $created = @mkdir($directory, 0700, true);
         }
         finally {
            umask($mask);
         }
         if ($created === false && is_dir($directory) === false) {
            throw new RuntimeException('Failed to create the File session save directory.');
         }
      }

      // ! Revalidate the requested lexical chain after recursive creation.
      // Once every ancestor is non-replaceable, realpath must not redirect
      // this path to a different filesystem location.
      clearstatcache(true, $directory);
      self::guard($directory);
      self::secure($directory);
      $canonical = realpath($directory);
      if (is_string($canonical) === false || $canonical !== $directory) {
         throw new RuntimeException('Failed to canonicalize the File session save directory.');
      }
      self::guard($canonical);
      self::secure($canonical);

      return $canonical . DIRECTORY_SEPARATOR;
   }

   /** Preserve the original protected secret accessor for compatible subclasses. */
   protected static function secret (): string
   {
      return self::obtain(self::configure(static::$path));
   }

   /** Activate this instance's pinned directory for legacy protected hooks. */
   private function activate (): void
   {
      static::$path = $this->directory;
   }

   /** Dispatch the legacy protected secret hook for this pinned instance. */
   private function sign (): string
   {
      $this->activate();

      return static::secret();
   }

   /** Load or create path-bound HMAC material under a stable owner-only lock. */
   private static function obtain (string $directory): string
   {
      $path = $directory . '.secret';
      if (self::$secret !== '' && self::$secretPath === $path) {
         return self::$secret;
      }

      self::secure(rtrim($directory, DIRECTORY_SEPARATOR));
      $Lock = self::lock($directory . '.secret.lock');
      $locked = false;

      try {
         $locked = @flock($Lock, LOCK_EX);
         if ($locked === false) {
            throw new RuntimeException('Failed to lock the File session secret.');
         }

         clearstatcache(true, $path);
         if (is_link($path)) {
            throw new RuntimeException('File session secret path must not be a symbolic link.');
         }
         $state = @lstat($path);
         $material = is_array($state)
            ? self::import($path)
            : self::publish($path);

         self::$secret = $material;
         self::$secretPath = $path;

         return self::$secret;
      }
      finally {
         if ($locked) {
            @flock($Lock, LOCK_UN);
         }
         @fclose($Lock);
      }
   }

   /** Import an exact, owner-only, single-link hexadecimal secret. */
   private static function import (string $path): string
   {
      $Handle = self::open($path);
      if ($Handle === false) {
         throw new RuntimeException('File session secret has unsafe metadata.');
      }

      try {
         $material = @stream_get_contents($Handle);
      }
      finally {
         @fclose($Handle);
      }
      if (
         is_string($material) === false
         || strlen($material) !== 64
         || preg_match('/^[a-f0-9]{64}$/D', $material) !== 1
      ) {
         throw new RuntimeException('File session secret contents are invalid.');
      }

      return $material;
   }

   /** Publish a complete secret atomically while holding the stable lock. */
   private static function publish (string $path): string
   {
      $material = bin2hex(random_bytes(32));
      $temporary = $path . '.tmp.' . bin2hex(random_bytes(16));
      $Handle = self::create($temporary);
      $persisted = false;

      try {
         $persisted = self::persist($Handle, $material);
      }
      finally {
         @fclose($Handle);
      }
      if ($persisted === false) {
         @unlink($temporary);
         throw new RuntimeException('Failed to persist a File session secret.');
      }

      if (@rename($temporary, $path) === false) {
         @unlink($temporary);
         throw new RuntimeException('Failed to publish the File session secret atomically.');
      }

      return self::import($path);
   }

   /**
    * Open or exclusively create the stable secret lock and validate its inode.
    *
    * @return resource
    */
   private static function lock (string $path): mixed
   {
      $mask = umask(0077);
      try {
         $Handle = @fopen($path, 'x+b');
      }
      finally {
         umask($mask);
      }

      // ! O_EXCL safely distinguishes the creator. A losing worker validates
      // and adopts the winner's permanent inode; the pathname is never removed.
      if ($Handle === false) {
         $before = self::inspect($path, 0600);
         if ($before === false) {
            throw new RuntimeException('File session secret lock has unsafe metadata.');
         }
         $Handle = @fopen($path, 'r+b');
         if (
            $Handle === false
            || self::verify($path, $Handle, 0600, $before) === false
         ) {
            if ($Handle !== false) {
               @fclose($Handle);
            }
            throw new RuntimeException('File session secret lock has unsafe metadata.');
         }

         return $Handle;
      }

      if (
         @chmod($path, 0600) === false
         || self::verify($path, $Handle, 0600) === false
      ) {
         @fclose($Handle);
         // ! Never unlink a visible lock inode: another worker may already
         // have it open, and replacing it would split synchronization.
         throw new RuntimeException('File session secret lock has unsafe metadata.');
      }

      return $Handle;
   }

   /**
    * Create one exclusive owner-only regular file and return its handle.
    *
    * @return resource
    */
   private static function create (string $path): mixed
   {
      $mask = umask(0077);
      try {
         $Handle = @fopen($path, 'x+b');
      }
      finally {
         umask($mask);
      }
      if ($Handle === false) {
         throw new RuntimeException('Failed to create an exclusive File session file.');
      }
      if (
         @chmod($path, 0600) === false
         || self::verify($path, $Handle, 0600) === false
      ) {
         $state = @fstat($Handle);
         @fclose($Handle);
         if (is_array($state) && self::compare($path, $state)) {
            @unlink($path);
         }
         throw new RuntimeException('Failed to protect an exclusive File session file.');
      }

      return $Handle;
   }

   /**
    * Persist every byte and flush it to stable storage.
    *
    * @param resource $Handle
    */
   private static function persist (mixed $Handle, string $data): bool
   {
      $length = strlen($data);
      $offset = 0;
      while ($offset < $length) {
         $written = @fwrite($Handle, substr($data, $offset));
         if ($written === false || $written === 0) {
            return false;
         }
         $offset += $written;
      }

      return @fflush($Handle) === true
         && (function_exists('fsync') === false || @fsync($Handle) === true);
   }

   /**
    * Open one safe regular file, optionally migrating owner-owned legacy mode.
    *
    * @return resource|false
    */
   private static function open (string $path, bool $repair = false): mixed
   {
      $before = self::inspect($path, $repair ? null : 0600);
      if ($before === false) {
         return false;
      }

      $Handle = @fopen($path, 'rb');
      if (
         $Handle === false
         || self::verify($path, $Handle, null, $before) === false
      ) {
         if ($Handle !== false) {
            @fclose($Handle);
         }

         return false;
      }

      $state = @fstat($Handle);
      if (is_array($state) === false) {
         @fclose($Handle);
         return false;
      }
      $currentMode = (int) $state['mode'] & 0777;
      if ($currentMode !== 0600) {
         if (
            $repair === false
            || $currentMode !== 0644
            || @chmod($path, 0600) === false
            || self::verify($path, $Handle, 0600) === false
         ) {
            @fclose($Handle);
            return false;
         }
      }

      return $Handle;
   }

   /**
    * Inspect pathname metadata before any potentially blocking open call.
    *
    * @return array<int|string,int>|false
    */
   private static function inspect (string $path, null|int $mode): array|false
   {
      clearstatcache(true, $path);
      $state = @lstat($path);
      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;

      if (
         is_array($state)
         && ((int) $state['mode'] & 0170000) === 0100000
         && (int) $state['nlink'] === 1
         && ($EUID === null || (int) $state['uid'] === $EUID)
         && ($mode === null || ((int) $state['mode'] & 0777) === $mode)
      ) {
         return $state;
      }

      return false;
   }

   /**
    * Verify that a pathname and opened handle identify one safe regular inode.
    *
    * @param resource $Handle
    * @param array<int|string,int>|null $before
    */
   private static function verify (
      string $path,
      mixed $Handle,
      null|int $mode,
      null|array $before = null
   ): bool
   {
      clearstatcache(true, $path);
      $pathState = @lstat($path);
      $handleState = @fstat($Handle);
      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;

      return is_array($pathState)
         && is_array($handleState)
         && ((int) $pathState['mode'] & 0170000) === 0100000
         && ((int) $handleState['mode'] & 0170000) === 0100000
         && (int) $pathState['nlink'] === 1
         && (int) $handleState['nlink'] === 1
         && (int) $pathState['dev'] === (int) $handleState['dev']
         && (int) $pathState['ino'] === (int) $handleState['ino']
         && ($before === null || (
            (int) $before['dev'] === (int) $pathState['dev']
            && (int) $before['ino'] === (int) $pathState['ino']
         ))
         && ($EUID === null || (int) $handleState['uid'] === $EUID)
         && ($mode === null || (
            ((int) $pathState['mode'] & 0777) === $mode
            && ((int) $handleState['mode'] & 0777) === $mode
         ));
   }

   /**
    * Compare a pathname with an already opened inode before pathname mutation.
    *
    * @param array<int|string,int> $state
    */
   private static function compare (string $path, array $state): bool
   {
      clearstatcache(true, $path);
      $current = @lstat($path);

      return is_array($current)
         && ((int) $current['mode'] & 0170000) === 0100000
         && (int) $current['dev'] === (int) $state['dev']
         && (int) $current['ino'] === (int) $state['ino'];
   }

   /** Reject symlinks and traversal components in an absolute directory path. */
   private static function guard (string $path): void
   {
      $current = DIRECTORY_SEPARATOR;
      foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $component) {
         if ($component === '' || $component === '.' || $component === '..') {
            throw new InvalidArgumentException(
               'File session save paths must not contain empty or traversal components.'
            );
         }

         $current = $current === DIRECTORY_SEPARATOR
            ? $current . $component
            : $current . DIRECTORY_SEPARATOR . $component;
         if (is_link($current)) {
            throw new RuntimeException('File session save paths must not contain symbolic links.');
         }

         $state = @lstat($current);
         if (
            is_array($state)
            && ((int) $state['mode'] & 0170000) !== 0040000
         ) {
            throw new RuntimeException('File session save path components must be directories.');
         }
      }
   }

   /** Validate final-directory confidentiality and ancestor replacement safety. */
   private static function secure (string $directory): void
   {
      $state = @lstat($directory);
      $rootState = @lstat(DIRECTORY_SEPARATOR);
      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;
      $rootUID = is_array($rootState) ? (int) $rootState['uid'] : 0;
      if (
         is_array($state) === false
         || ((int) $state['mode'] & 0170000) !== 0040000
         || ((int) $state['mode'] & 0777) !== 0700
         || ($EUID !== null && (int) $state['uid'] !== $EUID)
      ) {
         throw new RuntimeException('File session save directory has unsafe metadata.');
      }

      $ancestor = dirname($directory);
      while (true) {
         $state = @lstat($ancestor);
         if (
            is_array($state) === false
            || ((int) $state['mode'] & 0170000) !== 0040000
         ) {
            throw new RuntimeException('File session save path ancestor is unsafe.');
         }

         $mode = (int) $state['mode'];
         $writable = ($mode & 0022) !== 0;
         $sticky = ($mode & 01000) !== 0;
         $owner = (int) $state['uid'];
         if (
            ($EUID !== null && $owner !== $rootUID && $owner !== $EUID)
            || ($writable && $sticky === false)
         ) {
            throw new RuntimeException('File session save path ancestor is replaceable.');
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
