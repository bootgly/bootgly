<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;


use const LOCK_SH;
use const LOCK_UN;
use function array_unique;
use function array_values;
use function bin2hex;
use function chmod;
use function count;
use function explode;
use function fclose;
use function feof;
use function fflush;
use function flock;
use function fopen;
use function fread;
use function fsync;
use function fstat;
use function function_exists;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function lstat;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function unlink;
use function umask;
use InvalidArgumentException;


/**
 * HTTP-01 key-authorization files — the cross-process challenge contract.
 *
 * Paths are chartered by server-instance owner IDs. Responders search every
 * chartered path, while an issuer always supplies its exact path to save/drop;
 * two server objects therefore never steal one mutable process-global writer.
 * Every path is normalized once and every token update is an atomic rename over
 * the final name, so a pre-planted token symlink is replaced, never followed.
 */
class Challenges
{
   /** @var array<string,string> owner ID => normalized absolute directory */
   private static array $paths = [];


   /**
    * Register or replace one responder path.
    */
   public static function charter (string $owner, string $path): string
   {
      $path = self::normalize($path);
      self::$paths[$owner] = $path;

      return $path;
   }

   /**
    * Release only the path owned by this server instance.
    */
   public static function release (string $owner): void
   {
      unset(self::$paths[$owner]);
   }

   /**
    * Compatibility/test convenience for a single explicitly configured path.
    */
   public static function configure (null|string $path): void
   {
      if ($path === null) {
         self::$paths = [];
         return;
      }

      self::charter('default', $path);
   }

   /** @return array<int,string> */
   public static function collect (): array
   {
      return array_values(array_unique(self::$paths));
   }

   /**
    * Persist a token -> key-authorization file.
    *
    * `$path` is mandatory for production issuers; omitting it is supported only
    * when exactly one responder path is chartered (focused tests/manual use).
    */
   public static function save (
      string $token,
      string $authorization,
      null|string $path = null
   ): bool
   {
      $length = strlen($authorization);
      if ($length < 1 || $length > 8192) {
         return false;
      }
      $path = self::select($path);
      $file = $path !== null ? self::locate($token, $path) : null;
      if ($path === null || $file === null) {
         return false;
      }

      if (is_dir($path) === false) {
         if (mkdir($path, 0700, true) === false && is_dir($path) === false) {
            return false;
         }
      }
      // Recheck every component after mkdir: a concurrently inserted directory
      // symlink must not turn the following rename into an outside write.
      if (self::validate($path) === false || chmod($path, 0700) === false) {
         return false;
      }

      $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
      $previousMask = umask(0077);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }

      $written = false;
      try {
         // The controlled creation mask set 0600 before the first byte.
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($authorization, $offset));
            if ($bytes === false || $bytes === 0) {
               break;
            }
            $offset += $bytes;
         }
         $written = $offset === $length
            && fflush($Handle)
            && (!function_exists('fsync') || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($written === false) {
            @unlink($temporary);
         }
      }

      if ($written === false) {
         return false;
      }

      // rename() replaces a final symlink itself; it never opens its target.
      if (rename($temporary, $file) === false) {
         @unlink($temporary);
         return false;
      }

      return is_link($file) === false && is_file($file);
   }

   /**
    * Read a key authorization by token — null when unknown or unsafe.
    */
   public static function load (string $token): null|string
   {
      foreach (self::collect() as $path) {
         $file = self::locate($token, $path);
         if ($file === null || is_link($file) || is_file($file) === false) {
            continue;
         }

         $before = @lstat($file);
         $Handle = @fopen($file, 'rb');
         if ($Handle === false || flock($Handle, LOCK_SH) === false) {
            is_resource($Handle) && fclose($Handle);
            continue;
         }

         try {
            $opened = fstat($Handle);
            $after = @lstat($file);
            if (
               is_array($before) === false || is_array($opened) === false || is_array($after) === false
               || $before['dev'] !== $opened['dev']
               || $before['ino'] !== $opened['ino']
               || $after['dev'] !== $opened['dev']
               || $after['ino'] !== $opened['ino']
            ) {
               continue;
            }

            $authorization = '';
            while (strlen($authorization) <= 8192 && !feof($Handle)) {
               $chunk = fread($Handle, 8193 - strlen($authorization));
               if ($chunk === false) {
                  $authorization = '';
                  break;
               }
               $authorization .= $chunk;
            }
            return $authorization !== '' && strlen($authorization) <= 8192
               ? $authorization
               : null;
         }
         finally {
            flock($Handle, LOCK_UN);
            fclose($Handle);
         }
      }

      return null;
   }

   /**
    * Remove a token after validation. A final symlink is unlinked as a name;
    * its target is never touched.
    */
   public static function drop (string $token, null|string $path = null): bool
   {
      $path = self::select($path);
      $file = $path !== null ? self::locate($token, $path) : null;
      if ($file === null || (is_file($file) === false && is_link($file) === false)) {
         return false;
      }

      return unlink($file);
   }

   private static function select (null|string $path): null|string
   {
      if ($path !== null) {
         try {
            return self::normalize($path);
         }
         catch (InvalidArgumentException) {
            return null;
         }
      }

      $paths = self::collect();

      return count($paths) === 1 ? $paths[0] : null;
   }

   private static function normalize (string $path): string
   {
      $path = rtrim($path, '/') . '/';
      if (
         $path === '/'
         || str_starts_with($path, '/') === false
         || str_contains($path, '/../')
         || str_contains($path, '/./')
      ) {
         throw new InvalidArgumentException(
            "ACME challenge path `{$path}` must be a dedicated absolute directory without traversal."
         );
      }
      if (self::validate($path) === false) {
         throw new InvalidArgumentException(
            "ACME challenge path `{$path}` crosses a symbolic link."
         );
      }

      return $path;
   }

   private static function locate (string $token, string $path): null|string
   {
      if (preg_match('/^[A-Za-z0-9_\-]{1,256}$/', $token) !== 1) {
         return null;
      }
      if (self::validate($path) === false) {
         return null;
      }

      return $path . $token;
   }

   private static function validate (string $path): bool
   {
      $walk = '';
      foreach (explode('/', trim($path, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }
         $walk .= "/{$segment}";
         if (is_link($walk)) {
            return false;
         }
      }

      return true;
   }
}
