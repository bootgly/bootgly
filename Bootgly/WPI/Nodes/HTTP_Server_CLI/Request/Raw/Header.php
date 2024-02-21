<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw;


use Bootgly\WPI\Modules\HTTP\Server\Request\Heading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;


class Header
{
   use Heading;


   // * Config
   // ...

   // * Data
   public readonly string $raw;
   protected array $fields;

   // * Metadata
   private bool $built;
   public readonly null|int|false $length;

   public Cookies $Cookies;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // public $raw (readonly)
      $this->fields = [];

      // * Metadata
      $this->built = false;
      // $this->length = null;


      $this->Cookies = new Cookies($this);
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Config
         // ..

         // * Data
         // public $raw (readonly)
         case 'fields':
            if ($this->built === false) {
               $this->build();
            }

            return $this->fields;

         // * Metadata
         // public length (readonly)
      }
   }

   public function set (string $raw) : void
   {
      $this->raw ??= $raw;

      $this->length = \strlen($raw);
   }
}
