<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\protocols;


use Bootgly\Web\protocols\HTTP\Content;
use Bootgly\Web\protocols\HTTP\Header;


trait HTTP
{
   private string $user;            // slayer
   private string $password;        // tech
   private string $protocol;        // HTTP/1.1
   private string $method;          // GET, POST, ...
   private string $raw;

   private Header $Header;
   private Content $Content;
}
