<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_\Header;


use Bootgly\Web\HTTP\Server\_\Header;


final class Cookie
{
   public Header $Header;

   // * Data
   private array $cookies;


   public function __construct (Header $Header)
   {
      $this->Header = $Header;

      // * Data
      $this->cookies = \PHP_SAPI !== 'cli' ? $_COOKIE : [];
   }

   public function __get (string $name)
   {
      switch ($name) {
         case 'cookies':
            if (\PHP_SAPI === 'cli') {
               $this->build();
            }

            return $this->cookies;
         default:
            if (\PHP_SAPI === 'cli') {
               $this->build();
            }

            return $this->cookies[$name] ?? '';
      }
   }

   public function build ()
   {
      if ( empty($this->cookies) ) {
         $replaced = preg_replace('/; ?/', '&', $this->Header->get('Cookie'));

         parse_str($replaced, $this->cookies);

         return true;
      }

      return false;
   }

   public function get (string $name) : string
   {
      return $this->cookies[$name] ?? '';
   }
}
