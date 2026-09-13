<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Analyzers;


/**
 * One style violation an analyzer found in a file.
 */
class Issue
{
   // * Config
   /** The issue type — a submodule-specific token such as `missing_import` or `nullable_shorthand` */
   public readonly string $type;
   /** What the issue is about — a symbol, a type, a parameter or a method name */
   public readonly string $symbol;
   /** The category of `$symbol` — `function`, `class`, `parameter`, `return`, `method`, ... */
   public readonly string $kind;
   /** The 1-based source line */
   public readonly int $line;
   /** The human-readable report line */
   public readonly string $message;
   /** The byte offset a formatter rewrites at, or -1 when the issue is report-only */
   public readonly int $offset;


   /**
    * @param string $type The issue type
    * @param string $symbol What the issue is about
    * @param string $kind The category of `$symbol`
    * @param int $line The 1-based source line
    * @param string $message The human-readable report line
    * @param int $offset The byte offset a formatter rewrites at, -1 when report-only
    */
   public function __construct (
      string $type,
      string $symbol,
      string $kind,
      int $line,
      string $message,
      int $offset = -1
   )
   {
      // * Config
      $this->type = $type;
      $this->symbol = $symbol;
      $this->kind = $kind;
      $this->line = $line;
      $this->message = $message;
      $this->offset = $offset;
   }
}
