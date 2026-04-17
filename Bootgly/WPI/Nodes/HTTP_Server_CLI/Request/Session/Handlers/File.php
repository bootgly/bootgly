<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers;


use const BOOTGLY_WORKING_DIR;
use const DIRECTORY_SEPARATOR;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function glob;
use function hash_equals;
use function hash_hmac;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function strlen;
use function substr;
use function time;
use function touch;
use function uniqid;
use function unlink;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handling;


class File implements Handling
{
   // * Config
   protected static string $path = '';
   protected static string $prefix = 'session_';

   // # HMAC
   /**
    * SHA-256 hex digest length (64 chars) used as the MAC prefix on every
    * persisted session file. Keep as a const — reused on every read/write.
    */
   private const int MAC_LENGTH = 64;
   /**
    * Per-install HMAC secret, lazily loaded on first read/write. The secret
    * is auto-generated into `<sessionsDir>/.secret` with 0600 perms the first
    * time it is needed; subsequent boots reuse it.
    */
   private static string $secret = '';


   public static function init (): void
   {
      $savePath = BOOTGLY_WORKING_DIR . 'workdata/sessions'; // TODO: use workdata dir constant
      static::prepare($savePath);
   }

   /** @param array<string,mixed> $config */
   public function __construct (array $config = [])
   {
      if (isset($config['save_path']) && is_string($config['save_path'])) {
         static::prepare($config['save_path']);
      } else if (static::$path === '') {
         static::init();
      }
   }

   public function read (string $sessionId): string|false
   {
      $file = static::resolve($sessionId);

      clearstatcache();

      if (is_file($file)) {
         if (time() - filemtime($file) > Session::$lifetime) {
            unlink($file);
            return false;
         }

         $data = file_get_contents($file);

         if ($data === false || strlen($data) < self::MAC_LENGTH) {
            return false;
         }

         // ! RFC-style authenticated read — reject any file that does not
         //   carry a valid HMAC written by File::write(). Without this,
         //   anyone with a write primitive into the sessions dir (shared
         //   mount, backup restore, upload tempfile leftover on the same
         //   FS) could forge arbitrary Session data or ship a POP gadget
         //   payload straight into `unserialize()`.
         //   See tests/Security/4.01-session_file_unserialize_forgery.test.php
         $mac      = substr($data, 0, self::MAC_LENGTH);
         $payload  = substr($data, self::MAC_LENGTH);
         $expected = hash_hmac('sha256', $payload, static::secret());

         if ( ! hash_equals($expected, $mac)) {
            return false;
         }

         return $payload ?: false;
      }

      return false;
   }

   public function write (string $sessionId, string $sessionData): bool
   {
      $target = static::resolve($sessionId);

      // ! Invalid session ID (path traversal guard)
      if ($target === '') {
         return false;
      }

      // @ Prefix payload with an HMAC so a later read() can reject any
      //   unsigned/attacker-dropped file at near-zero cost (one SHA-256).
      $mac = hash_hmac('sha256', $sessionData, static::secret());

      // @ Tempfile name begins with the session prefix so purge()'s glob
      //   reclaims orphaned temps too; the `.tmp.` segment guarantees the
      //   filename can never match the [a-f0-9]{32,64} resolve() regex,
      //   so a crashed rename cannot leave a file that would be read as
      //   a session.
      $tempFile = static::$path . static::$prefix . '.tmp.'
         . uniqid(bin2hex(random_bytes(8)), true);

      if (file_put_contents($tempFile, $mac . $sessionData) === false) {
         return false;
      }

      return rename($tempFile, $target);
   }

   public function touch (string $sessionId): bool
   {
      $file = static::resolve($sessionId);

      if (!file_exists($file)) {
         return false;
      }

      // set file modify time to current time
      $setModifyTime = touch($file);
      // clear file stat cache
      clearstatcache();

      return $setModifyTime;
   }

   public function destroy (string $sessionId): bool
   {
      $file = static::resolve($sessionId);

      if (is_file($file)) {
         unlink($file);
      }

      return true;
   }

   public function purge (int $maxLifetime): bool
   {
      $timeNow = time();

      $files = glob(static::$path . static::$prefix . '*');

      foreach ($files ?: [] as $file) {
         if (is_file($file) && $timeNow - filemtime($file) > $maxLifetime) {
            unlink($file);
         }
      }

      return true;
   }

   /**
    * Resolve session file path.
    *
    * @param string $sessionId
    * @return string Empty string if session ID is invalid (path traversal guard).
    */
   protected static function resolve (string $sessionId): string
   {
      // ! Session ID must be a hex string between 32 and 64 characters (path traversal guard)
      if (preg_match('/^[a-f0-9]{32,64}$/', $sessionId) !== 1) {
         return '';
      }

      // :
      return static::$path . static::$prefix . $sessionId;
   }

   /**
    * Prepare session save path (ensure directory exists).
    *
    * @param string $path
    * @return void
    */
   protected static function prepare (string $path): void
   {
      // ?
      if (!$path) {
         return;
      }

      // @
      if ($path[strlen($path) - 1] !== DIRECTORY_SEPARATOR) {
         $path .= DIRECTORY_SEPARATOR;
      }

      static::$path = $path;

      if (!is_dir($path)) {
         mkdir($path, 0700, true);
      }
   }

   /**
    * Lazily load (or auto-generate) the per-install HMAC secret used to
    * authenticate session files. Stored at `<sessionsDir>/.secret` with
    * 0600 perms. A single read hit caches the value in a static for the
    * rest of the worker lifetime.
    *
    * @return string Raw secret bytes (64 hex chars / 256 bits of entropy).
    */
   protected static function secret (): string
   {
      if (self::$secret !== '') {
         return self::$secret;
      }

      $secretFile = static::$path . '.secret';

      if (is_file($secretFile)) {
         $stored = file_get_contents($secretFile);
         if (is_string($stored) && strlen($stored) >= 32) {
            self::$secret = $stored;
            return self::$secret;
         }
      }

      // @ Generate a fresh secret (64 hex = 256-bit). Write atomically so
      //   concurrent workers racing on first boot converge to a single
      //   value (last-writer-wins is harmless — both values are equally
      //   strong and no persisted sessions exist yet on a fresh install).
      $secret = bin2hex(random_bytes(32));
      @file_put_contents($secretFile, $secret);
      @chmod($secretFile, 0600);

      self::$secret = $secret;

      return self::$secret;
   }
}
