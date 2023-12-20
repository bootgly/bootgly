<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Header;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Header;


final class Cookie
{
   public Header $Header;


   // * Config
   // ...

   // * Data
   private array $cookies;

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

   public function __get (string $name)
   {
      switch ($name) {
         case 'cookies':
            $this->build();

            return $this->cookies;
         default:
            $this->build();

            return $this->cookies[$name] ?? '';
      }
   }

   public function build ()
   {
      if ( ! empty($this->cookies) ) {
         return false;
      }

      $replaced = preg_replace('/; ?/', '&', $this->Header->get('Cookie'));

      $cookies = &$this->cookies;

      foreach ($replaced as $cookie) {
         parse_str($cookie, $value);

         $cookies[] = $value;
      }

      return true;
   }

   public function get (string $name) : string
   {
      return $this->cookies[$name] ?? '';
   }
}
