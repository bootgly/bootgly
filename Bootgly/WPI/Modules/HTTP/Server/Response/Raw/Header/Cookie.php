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


use function rawurlencode;


class Cookie
{
   // * Config
   public int|null $expiration;
   public string $path;
   public string $domain;
   public bool $secure;
   public bool $HTTP_only;
   public string $same_site;

   // * Data
   protected string $name;
   protected string $value;

   // * Metadata
   // ...

   public function __construct (string $name, string $value)
   {
      // * Config
      $this->name = $name;
      $this->value = $value;

      $this->expiration = null;
      $this->path = '';
      $this->domain = '';
      $this->secure = false;
      $this->HTTP_only = false;
      $this->same_site = '';
   }

   public function build (): string
   {
      $cookie = $this->name . '=' . rawurlencode($this->value)
      . (empty($this->domain) ? '' : '; Domain=' . $this->domain)
      . ($this->expiration === null ? '' : '; Max-Age=' . (string) $this->expiration)
      . (empty($this->path) ? '' : '; Path=' . $this->path)
      . (! $this->secure ? '' : '; Secure')
      . (! $this->HTTP_only ? '' : '; HttpOnly')
      . (empty($this->same_site) ? '' : '; SameSite=' . $this->same_site);

      return $cookie;
   }
}
