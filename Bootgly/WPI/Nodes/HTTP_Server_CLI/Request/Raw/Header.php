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


   private Cookies $Cookies;

   // * Config
   // ...

   // * Data
   public readonly string $raw;
   protected array $fields;

   // * Metadata
   private bool $built;
   public readonly null|int|false $length;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      #$this->raw = $raw;
      $this->fields = [];

      // * Metadata
      $this->built = false;
      #$this->length = \strlen($raw);
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'Cookies':
            return $this->Cookies ??= new Cookies($this);

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
      // * Data
      $this->raw ??= $raw;
      // * Metadata
      $this->length = \strlen($raw);
   }
}
