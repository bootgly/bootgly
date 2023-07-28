<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\modules\HTTP\Server\Request;


class Content
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public string $input;

   // * Meta
   public ? int $length;
   public null|int|false $position;
   public ? int $downloaded;


   public function __construct ()
   {
      // * Config
      // ..

      // * Data
      $this->raw = '';
      $this->input = file_get_contents('php://input');

      // * Meta
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
   }
}
