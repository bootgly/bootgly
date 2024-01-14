<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


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

   // TODO REFACTOR: This function has 8 parameters, which is greater than the 7 authorized.
   public function append(
      $name, $value = '', $expiration = null, $path = '', $domain = '', $secure = false, $httpOnly = false, $sameSite  = false
   )
   {
      $cookie = $name . '=' . \rawurlencode($value)
      . (empty($domain) ? '' : '; Domain=' . $domain)
      . ($expiration === null ? '' : '; Max-Age=' . $expiration)
      . (empty($path) ? '' : '; Path=' . $path)
      . (! $secure ? '' : '; Secure')
      . (! $httpOnly ? '' : '; HttpOnly')
      . (empty($sameSite) ? '' : '; SameSite=' . $sameSite);

      $this->Header->queue('Set-Cookie', $cookie);

      return $this;
   }
}
