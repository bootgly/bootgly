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


use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function time;
use function touch;
use function unlink;
use function bin2hex;
use function file_exists;
use function is_string;
use function random_bytes;
use function strlen;
use function uniqid;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handling;


class File implements Handling
{
   // * Config
   protected static string $path = '';
   protected static string $prefix = 'session_';


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

         return $data ?: false;
      }

      return false;
   }

   public function write (string $sessionId, string $sessionData): bool
   {
      $tempFile = static::$path . uniqid(bin2hex(random_bytes(8)), true);

      if (!file_put_contents($tempFile, $sessionData)) {
         return false;
      }

      return rename($tempFile, static::resolve($sessionId));
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
    * @return string
    */
   protected static function resolve (string $sessionId): string
   {
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
         mkdir($path, 0777, true);
      }
   }
}
