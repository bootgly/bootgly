<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Inputs\GitDiff;


/**
 * Render-ready hunk converted from a `git diff` unified-diff chunk.
 */
final class Hunk
{
   // * Data
   public private(set) string $fromFile;
   public private(set) string $toFile;
   public private(set) int $fromStart;
   public private(set) int $toStart;
   /** @var list<string> */
   public private(set) array $fromLines;
   /** @var list<string> */
   public private(set) array $toLines;


   /**
    * @param list<string> $fromLines
    * @param list<string> $toLines
    */
   public function __construct (
      string $fromFile,
      string $toFile,
      int $fromStart,
      int $toStart,
      array $fromLines,
      array $toLines
   ) {
      $this->fromFile  = $fromFile;
      $this->toFile    = $toFile;
      $this->fromStart = $fromStart;
      $this->toStart   = $toStart;
      $this->fromLines = $fromLines;
      $this->toLines   = $toLines;
   }
}
