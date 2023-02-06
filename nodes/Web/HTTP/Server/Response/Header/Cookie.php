<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Response\Header;


use Bootgly\Web\HTTP\Server\Response\Header;


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

   public function append
   ($name, $value = '', $expiration = null, $path = '', $domain = '', $secure = false, $httpOnly = false, $sameSite  = false)
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
