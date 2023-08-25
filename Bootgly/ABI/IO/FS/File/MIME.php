<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\FS\File;


class MIME
{
   // * Data
   public readonly string $type;
   // * Meta
   public readonly string $format;
   public readonly string $subtype;


   public function __construct (string $filename)
   {
      $mime_content_type = mime_content_type($filename) ?? '';

      [$format, $subtype] = explode('/', $mime_content_type);

      // * Data
      $this->type = $mime_content_type;
      // * Meta
      $this->format = $format;
      $this->subtype = $subtype;
   }
   public function __toString ()
   {
      return $this->type;
   }
}
