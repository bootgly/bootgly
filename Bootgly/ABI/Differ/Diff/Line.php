<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Diff;


final class Line
{
   public const int ADDED     = 1;
   public const int REMOVED   = 2;
   public const int UNCHANGED = 3;

   // * Data
   public private(set) int $type;
   public private(set) string $content;

   // * Metadata
   // ! Type
   public bool $added {
      get => $this->type === self::ADDED;
   }
   public bool $removed {
      get => $this->type === self::REMOVED;
   }
   public bool $unchanged {
      get => $this->type === self::UNCHANGED;
   }


   public function __construct (int $type = self::UNCHANGED, string $content = '')
   {
      $this->type    = $type;
      $this->content = $content;
   }
}
