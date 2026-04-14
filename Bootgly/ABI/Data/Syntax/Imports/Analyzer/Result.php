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


use function count;


class Result
{
   /**
    * @param string $file
    * @param string $source
    * @param string $namespace
    * @param array<int,array{symbol:string,kind:string,global:bool,line:int,alias:string}> $imports
    * @param array{start:int,end:int} $importRange Byte offsets of the import block in source
    * @param array<string,array{kind:string,lines:array<int>}> $symbols
    * @param array<int,Issue> $issues
    */
   public function __construct (
      public readonly string $file,
      public readonly string $source,
      public readonly string $namespace,
      public readonly array $imports,
      public readonly array $importRange,
      public readonly array $symbols,
      public readonly array $issues
   )
   {}

   public function failed (): bool
   {
      return count($this->issues) > 0;
   }
}
