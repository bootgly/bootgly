<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


use const INI_SCANNER_RAW;
use function basename;
use function clearstatcache;
use function dirname;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;
use function parse_ini_string;
use function putenv;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strpbrk;
use function substr;

use Bootgly\ABI\IO\FS\File;
use Bootgly\API\Environment\Agent;


class Environment
{
   public const int CI_CD = 1;
   public const int AI_AGENT = 2;

   // * Config
   /**
    * Prefix for environment variable keys.
    *
    * @var string|null
    */
   public static $prefix = '';
   /**
    * Suffix for environment variable keys.
    *
    * @var string|null
    */
   public static $suffix = '';


   /**
    * Load environment variables from an INI-style file.
    *
    * Every pair is exported through `put()`, so the prefix and suffix apply.
    * Returns `false`, applying nothing, when the file is missing, is not a
    * regular file, cannot be read or parsed, holds a NUL byte (the INI parser
    * would stop there and drop the pairs after it) or holds an array key
    * (`KEY[]=`), and when the prefix or suffix holds `=` or NUL (`put()` would
    * refuse every pair).
    *
    * @deprecated 1.1.0 `.env` files belong to `Environment\Configs` (scoped,
    *             validated, never exported); export a value a child process
    *             needs with `put()`. Removed in 2.0.0.
    *
    * @param string $file
    *
    * @return bool
    */
   public static function load (string $file): bool
   {
      // ? Missing or not a regular file (a directory, a FIFO): nothing is applied
      if (is_file($file) === false) {
         return false;
      }
      // ? Unreadable, or a NUL byte — where the INI parser stops, silently dropping the rest
      $contents = @file_get_contents($file);
      if ($contents === false || str_contains($contents, "\0")) {
         return false;
      }
      // ? Not INI
      $variables = @parse_ini_string($contents, false, INI_SCANNER_RAW);
      if ($variables === false) {
         return false;
      }

      // ? All or nothing: an `ARR[]=` key parses to an array `put()` cannot carry, and a
      //   prefix or suffix holding `=` or NUL makes `put()` refuse every pair
      if (strpbrk(self::compose(''), "=\0") !== false) {
         return false;
      }
      foreach ($variables as $value) {
         if (is_string($value) === false) {
            return false;
         }
      }

      // @@
      foreach ($variables as $key => $value) {
         self::put((string) $key, $value);
      }

      // :
      return true;
   }

   /**
    * Save the environment variables to a file.
    *
    * Writes the whole process environment as `KEY=value` lines, quoted only
    * when the bare line would not read back (`;`, edge spaces, a value wrapped
    * in quotes), each verified to read back identically through `load()`. The
    * file is created `0600` beside `$file` and renamed over it: a link at
    * `$file` is replaced, never written through. Returns `false`, leaving
    * `$file` untouched, for a path that is not local (a stream wrapper, a
    * `file://` form without an absolute path) or names no file (a trailing
    * separator, a backslash), when a variable has no such line (a line break,
    * a name INI cannot carry) or the write fails.
    *
    * @deprecated 1.1.0 Persisting the whole process environment is not
    *             supported: write the values a deployment needs to a file
    *             you own (0600) and read them through a scope `.env`.
    *             Removed in 2.0.0.
    *
    * @param string $file
    *
    * @return bool
    */
   public static function save (string $file): bool
   {
      // ? A NUL byte never reaches the filesystem; a stream wrapper cannot rename() or
      //   unlink() the temporary it opened
      if (str_contains($file, "\0") || (str_contains($file, '://') && str_starts_with($file, 'file://') === false)) {
         return false;
      }

      // !
      $lines = '';

      // @@ Every variable, each line verified before anything is written
      foreach (getenv() as $key => $value) {
         $line = self::encode((string) $key, $value);
         if ($line === null) {
            return false;
         }

         $lines .= "{$line}\n";
      }

      // :
      return self::write($file, $lines);
   }

   // ! Variable
   /**
    * Get the list of environment variables.
    *
    * @deprecated 1.1.0 Use `getenv()`. Removed in 2.0.0.
    *
    * @return array<string,string>
    */
   public static function list (): array
   {
      /** @var array<string,string> $list */
      $list = getenv();

      return $list;
   }
   /**
    * Get the value of an environment variable.
    *
    * @param string $key
    * @param null|string|false $default
    *
    * @return false|string
    */
   public static function get (string $key, null|string|false $default = null): false|string
   {
      $value = getenv(self::compose($key));

      if ($value === false && $default !== null) {
         return $default;
      }

      return $value;
   }
   public static function match (int $type): bool
   {
      return match ($type) {
         Environment::CI_CD => (
            Environment::get('GITHUB_ACTIONS')
            || Environment::get('TRAVIS')
            || Environment::get('CIRCLECI')
            || Environment::get('GITLAB_CI')
         ),
         Environment::AI_AGENT => Agent::detect()->detected,
         default => false
      };
   }

   /**
    * Set the value of an environment variable.
    *
    * Returns `false` for an empty key, a name (prefix, key and suffix) holding
    * `=` or NUL, or a value holding NUL: `putenv()` would retarget or cut them.
    *
    * @param string $key
    * @param string|int $value
    *
    * @return bool
    */
   public static function put (string $key, string|int $value): bool
   {
      $name = self::compose($key);
      // ? A name `putenv()` would misread, or a value it would cut at NUL
      if ($key === '' || strpbrk($name, "=\0") !== false || str_contains((string) $value, "\0")) {
         return false;
      }

      // :
      return putenv("{$name}={$value}");
   }

   /**
    * Clear the value of an environment variable.
    *
    * The key is composed with the prefix and suffix, like `get()` and `put()`.
    * Returns `false` for an empty key or a name (prefix, key and suffix)
    * holding `=` or NUL.
    *
    * @param string $key
    *
    * @return bool
    */
   public static function del (string $key): bool
   {
      $name = self::compose($key);
      // ? A name `putenv()` would misread: `NAME=value` SETS, NUL truncates
      if ($key === '' || strpbrk($name, "=\0") !== false) {
         return false;
      }

      // :
      return putenv($name);
   }

   /**
    * Compose the real variable name: prefix, key, suffix.
    */
   private static function compose (string $key): string
   {
      $prefix = (string) self::$prefix;
      $suffix = (string) self::$suffix;

      return "{$prefix}{$key}{$suffix}";
   }

   /**
    * Encode one variable as the INI line `load()` reads back identically.
    *
    * Bare first — the bytes a plain `KEY=value` reader (`docker --env-file`)
    * expects — and quoted only when the bare line does not read back. Returns
    * `null` when neither does: a CR, LF or NUL (a split record, a planted
    * variable), a name INI cannot carry.
    */
   private static function encode (string $key, string $value): null|string
   {
      // @@
      foreach (["{$key}={$value}", "{$key}=\"{$value}\""] as $line) {
         // ?: Only a line that parses back to exactly this pair
         if (@parse_ini_string($line, false, INI_SCANNER_RAW) === [$key => $value]) {
            return $line;
         }
      }

      // :
      return null;
   }

   /**
    * Replace `$file` atomically with a private (0600) file holding `$data`.
    *
    * Through `File->replace()`: the bytes go to a random sibling made 0600
    * before the first byte (a default ACL on the directory overrides the
    * umask), are flushed and synced, then `rename()` swaps the directory entry
    * itself: a link at `$file` is replaced, never opened, and in a sticky
    * directory a link another account owns makes the swap fail instead.
    */
   private static function write (string $file, string $data): bool
   {
      // ? `file:///` names a local path; any other `file://` form names none `rename()` can swap
      if (str_starts_with($file, 'file://')) {
         if (str_starts_with($file, 'file:///') === false) {
            return false;
         }
         $file = substr($file, 7);
      }
      // ? No entry to name: a trailing separator asks for a directory a file cannot replace
      if ($file === '' || str_ends_with($file, '/')) {
         return false;
      }

      // ! The parent as the kernel's rename() resolves it, not as an earlier lookup cached
      //   it — `File` collapses `..` by name, which a link in the path would contradict
      clearstatcache(true);
      $parent = realpath(dirname($file));
      if ($parent === false) {
         return false;
      }
      $parent = rtrim($parent, '/');
      $name = basename($file);
      $target = "{$parent}/{$name}";
      // ? A backslash `File` would read as a separator: refused, never written elsewhere
      if (str_contains($target, '\\')) {
         return false;
      }
      $File = new File($target);
      $File->convert = false;

      // :
      return $File->replace($data, 0600);
   }
}
