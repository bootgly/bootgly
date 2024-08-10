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


use function file_get_contents;

use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;


class Body extends Raw\Body
{
   // * Config
   // ... inherited

   // * Data
   // ... inherited

   // * Metadata
   // ... inherited


   public function __construct ()
   {
      // * Config
      // ... inherited

      // * Data
      $this->raw = '';
      $this->input = (string) file_get_contents('php://input');

      // * Metadata
      $this->length = null;
      $this->position = null;
      $this->downloaded = null;
   }
}
