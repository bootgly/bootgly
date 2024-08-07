<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


class Environment
{
   public const CI_CD = 1;

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
    * Load environment variables from a file.
    *
    * @param string $file
    * @return bool
    */
   public static function load ($file): bool
   {
      if (file_exists($file) === false) {
         return false;
      }

      $sections = false;
      $variables = parse_ini_file($file, $sections, INI_SCANNER_RAW);

      if ($variables !== false) {
         foreach ($variables as $key => $value) {
            self::put($key, $value);
         }
      }

      return true;
   }

   /**
    * Save the environment variables to a file.
    *
    * @param string $file
    * @return bool
    */
   public static function save (string $file): bool
   {
      $envContent = '';
      foreach (self::get() as $key => $value) {
         $envContent .= "$key=$value\n";
      }

      return file_put_contents($file, $envContent) !== false;
   }

   // ! Variable
   /**
    * Get the value of an environment variable.
    *
    * @param string|null $key
    * @param mixed $default
    * @return array<string>|string|false|null
    */
   public static function get (? string $key = null, mixed $default = null): array|string|false|null
   {
      $key = self::$prefix . $key . self::$suffix;

      $value = getenv($key);

      if ($value === false) {
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
         default => false
      };
   }

   /**
    * Set the value of an environment variable.
    *
    * @param string $key
    * @param string|int $value
    * @return bool
    */
   public static function put (string $key, string|int $value): bool
   {
      $key = self::$prefix . $key . self::$suffix;

      return putenv("$key=$value");
   }

   /**
    * Clear the value of an environment variable.
    *
    * @param string $key
    * @return bool
    */
   public static function del (string $key): bool
   {
      return putenv($key);
   }
}
