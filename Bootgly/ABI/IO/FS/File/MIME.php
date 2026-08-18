<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\FS\File;


use function count;
use function explode;
use function mime_content_type;


class MIME
{
   // * Data
   public readonly string $type;
   // * Metadata
   public readonly string $format;
   public readonly string $subtype;


   public function __construct (string $filename)
   {
      // ! `mime_content_type()` warns on a path it cannot open and throws on an empty
      //   one — and a type it fails to determine carries no `format/subtype` to split
      $type = ($filename !== '')
         ? @mime_content_type($filename) ?: ''
         : '';

      $parts = explode('/', $type, 2);

      // ? An undeterminable type leaves every part unknown
      if (count($parts) !== 2) {
         $type = '';
         $parts = ['', ''];
      }

      // * Data
      $this->type = $type;
      // * Metadata
      $this->format = $parts[0];
      $this->subtype = $parts[1];
   }
   public function __toString ()
   {
      return $this->type;
   }
}
