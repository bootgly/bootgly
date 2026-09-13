<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Imports\Analyzer;


use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result as Analysis;


/**
 * The imports analyzer's result: the shared file/source/issues plus what the
 * formatter needs to rewrite the import block.
 */
class Result extends Analysis
{
   // * Config
   /** The file's namespace, or '' when it declares none */
   public readonly string $namespace;
   /** @var array<int,array{symbol:string,kind:string,global:bool,line:int,alias:string}> */
   public readonly array $imports;
   /** @var array{start:int,end:int} Byte offsets of the import block in source */
   public readonly array $importRange;
   /** @var array<string,array{kind:string,lines:array<int>}> */
   public readonly array $symbols;


   /**
    * @param string $file
    * @param string $source
    * @param string $namespace
    * @param array<int,array{symbol:string,kind:string,global:bool,line:int,alias:string}> $imports
    * @param array{start:int,end:int} $importRange Byte offsets of the import block in source
    * @param array<string,array{kind:string,lines:array<int>}> $symbols
    * @param array<int,Issue> $issues
    * @param null|string $notice Why the file was not analyzed, null when it was
    */
   public function __construct (
      string $file,
      string $source,
      string $namespace,
      array $imports,
      array $importRange,
      array $symbols,
      array $issues,
      null|string $notice = null
   )
   {
      parent::__construct($file, $source, $issues, $notice);

      // * Config
      $this->namespace = $namespace;
      $this->imports = $imports;
      $this->importRange = $importRange;
      $this->symbols = $symbols;
   }
}
