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


class Cookie
{
   // * Config
   private string $name;
   private string $value;

   // * Data
   private ? int $expiration;
   private string $path;
   private string $domain;
   private bool $secure;
   private bool $HTTP_only;
   private string $same_site;

   // * Metadata
   // ...

   public function __construct (string $name, string $value)
   {
      // * Config
      $this->name = $name;
      $this->value = $value;

      // * Data
      $this->expiration = null;
      $this->path = '';
      $this->domain = '';
      $this->secure = false;
      $this->HTTP_only = false;
      $this->same_site = '';
   }

   public function build ()
   {
      $cookie = $this->name . '=' . \rawurlencode($this->value)
      . (empty($this->domain) ? '' : '; Domain=' . $this->domain)
      . ($this->expiration === null ? '' : '; Max-Age=' . (string) $this->expiration)
      . (empty($this->path) ? '' : '; Path=' . $this->path)
      . (! $this->secure ? '' : '; Secure')
      . (! $this->HTTP_only ? '' : '; HttpOnly')
      . (empty($this->same_site) ? '' : '; SameSite=' . $this->same_site);

      return $cookie;
   }
}
