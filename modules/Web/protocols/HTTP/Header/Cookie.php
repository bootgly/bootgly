<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\protocols\HTTP\Header;


class Cookie
{
   private array $cookies;


   public function __construct (? array $cookies = null)
   {
      $this->cookies = $cookies !== null ? $cookies : $_COOKIE;
   }

   public function __get (string $name)
   {
      switch ($name) {
         case 'cookies':
            return $this->cookies;
         default:
            return @$this->cookies[$name];
      }
   }
}
