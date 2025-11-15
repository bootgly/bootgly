<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;

use function array_pad;
use function explode;
use function is_array;
use function is_string;
use function preg_split;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;


final class Cookies
{
   private Header $Header;

   // * Config
   // ...

   // * Data
   /** @var array<int, array<string, string>> */
   protected array $cookies;

   // * Metadata
   // ...


   public function __construct (Header $Header)
   {
      $this->Header = $Header;


      // * Config
      // ...

      // * Data
      $this->cookies = [];

      // * Metadata
      // ...
   }

   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         case 'cookies':
            $this->build();

            return $this->cookies;

         // * Metadata
         // ...
         default:
            return $this->cookies[$name] ?? '';
      }
   }

   public function build (): bool
   {
      if ( ! empty($this->cookies) ) {
         return false;
      }

      $fields = $this->Header->fields;
      $rawCookies = $fields['Cookie'] ?? $fields['cookie'] ?? null;

      if ($rawCookies === null) {
         return false;
      }

      $cookieLines = is_array($rawCookies) ? $rawCookies : [$rawCookies];
      $cookies = &$this->cookies;

      foreach ($cookieLines as $line) {
         $line = trim((string) $line);
         if ($line === '') {
            continue;
         }

         $segments = preg_split('/;\s*/', $line, flags: PREG_SPLIT_NO_EMPTY);
         if ($segments === false) {
            continue;
         }

         $cookie = [];

         foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
               continue;
            }

            [$key, $value] = array_pad(explode('=', $segment, 2), 2, '');
            $key = trim($key);

            if ($key === '') {
               continue;
            }

            $cookie[$key] = trim($value);
         }

         if ($cookie !== []) {
            $cookies[] = $cookie;
         }
      }

      return true;
   }
}
