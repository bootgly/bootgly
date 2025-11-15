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

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;


final class Cookies
{
   private Header $Header;

   // * Config
   // ...

   // * Data
   /** @var array<string> */
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

      return null;
   }

   public function build (): bool
   {
      if ( ! empty($this->cookies) ) {
         return false;
      }

      $replaced = \preg_replace('/; ?/', '&', $this->Header->get('Cookie'));

      $cookies = &$this->cookies;

      foreach ($replaced as $cookie) {
         \parse_str($cookie, $value);

         $cookies[] = $value;
      }

      return true;
   }
}
