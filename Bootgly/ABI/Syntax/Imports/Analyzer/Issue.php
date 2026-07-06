<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Imports\Analyzer;


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
