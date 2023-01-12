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
   public array $cookies;


   public function __construct (Header $Header)
   {
      $this->Header = $Header;

      // * Data
      $this->cookies = $_COOKIE;
   }

   public function __get (string $name)
   {
      switch ($name) {
         default:
            return @$this->cookies[$name];
      }
   }
}
