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
   private string $raw;
   // * Meta
   private int $length;


   public function __construct ()
   {
      // * Config
      // * Data
      $this->raw = '';
      // * Meta
      $this->length = 0;
   }
   public function __get ($name)
   {
      switch ($name) {
         case 'raw':
            return $this->raw;
         case 'length':
            return $this->length;
      }
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         case 'raw':
            $this->raw = $value;
            break;
         case 'length':
            $this->length = $value;
            break;
      }
   }
}
