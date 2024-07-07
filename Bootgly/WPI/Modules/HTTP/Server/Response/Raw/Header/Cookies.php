<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header;


use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;


abstract class Cookies
{
   public Header $Header;

   // * Config
   // ...

   // * Data
   /** @var array<string> */
   protected array $cookies;

   // * Metadata
   // ...


   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Data
         case 'cookies':
            return $this->cookies;
         default:
            return null;
      }
   }

   public function append (Cookie $Cookie): self
   {
      $cookie = $Cookie->build();

      $this->cookies[] = $cookie;

      $this->Header->queue('Set-Cookie', $cookie);

      return $this;
   }
}
