<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\Syntax\Imports\Analyzer;


class Issue
{
   public function __construct (
      public readonly string $type,
      public readonly string $symbol,
      public readonly string $kind,
      public readonly int $line,
      public readonly string $message,
      public readonly int $offset = -1
   )
   {}
}
