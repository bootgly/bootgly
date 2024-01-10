<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public string $input;

   // * Metadata
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

      // * Metadata
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
   }
}
