<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_;


class Content
{
   // * Data
   public string $raw;
   public string $input;
   // * Meta
   public ? int $length;
   public null|int|false $position;


   public function __construct ()
   {
      // * Data
      $this->raw = '';
      $this->input = '';
      // * Meta
      $this->length = null;
      $this->position = null;


      if (\PHP_SAPI !== 'cli') {
         $this->input = file_get_contents('php://input');
      }
   }
}
