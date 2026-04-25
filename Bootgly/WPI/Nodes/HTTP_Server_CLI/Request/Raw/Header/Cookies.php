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


use const PREG_SPLIT_NO_EMPTY;
use function array_pad;
use function explode;
use function is_array;
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
   public protected(set) array $cookies {
      get {
         if (isSet($this->cookies) === false) {
            $this->cookies = $this->build();
         }

         return $this->cookies;
      }
   }

   // * Metadata
   // ...


   public function __construct (Header $Header)
   {
      $this->Header = $Header;


      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // ...
   }

   public function get (string $name): string
   {
      foreach ($this->cookies as $cookie) {
         if ( isSet($cookie[$name]) ) {
            return $cookie[$name];
         }
      }

      return '';
   }

   /**
    * @return array<int, array<string, string>>
    */
   private function build (): array
   {
      // @ Header keys are normalized to lowercase at parse time.
      $fields = $this->Header->fields;
      $rawCookies = $fields['cookie'] ?? null;

      if ($rawCookies === null) {
         return [];
      }

      $cookieLines = is_array($rawCookies) ? $rawCookies : [$rawCookies];
      $cookies = [];

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

      return $cookies;
   }
}
