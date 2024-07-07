<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Request\Raw\Header;


use Bootgly\WPI\Modules\HTTP\Server\Request\Raw\Header;


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


   public function get (string $name): string
   {
      return $this->cookies[$name] ?? '';
   }
}
