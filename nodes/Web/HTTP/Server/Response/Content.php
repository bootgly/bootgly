<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Response;


class Content
{
   // * Config
   // * Data
   public string $raw;
   // * Meta
   public int $length;


   public function __construct ()
   {
      // * Config
      // * Data
      $this->raw = '';
      // * Meta
      $this->length = 0;
   }
}
