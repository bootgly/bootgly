<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\HTTP\Server\Request\_\Header;


use Bootgly\CLI\HTTP\Server\Request\_\Header;


final class Cookie
{
   public Header $Header;

   // * Data
   private array $cookies;


   public function __construct (Header $Header)
   {
      $this->Header = $Header;

      // * Data
      $this->cookies = [];
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

      parse_str($replaced, $this->cookies);

      return true;
   }

   public function get (string $name) : string
   {
      return $this->cookies[$name] ?? '';
   }
}
