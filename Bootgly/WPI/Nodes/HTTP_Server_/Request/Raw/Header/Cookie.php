<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;


use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header;


final class Cookie
{
   public Header $Header;

   // * Data
   private array $cookies;


   public function __construct (Header $Header)
   {
      $this->Header = $Header;

      // * Data
      $this->cookies = $_COOKIE;
   }

   public function __get (string $name)
   {
      switch ($name) {
         case 'cookies':
            return $this->cookies;
         default:
            return $this->cookies[$name] ?? '';
      }
   }

   public function get (string $name) : string
   {
      return $this->cookies[$name] ?? '';
   }
}
