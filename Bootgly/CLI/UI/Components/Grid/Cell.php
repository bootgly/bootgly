<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Grid;


use Bootgly\CLI\UI\Components\Frame;


/**
 * Cell — a Frame placement over the Grid tracks: the anchor track (1-based)
 * and the spanned track counts. The Frame is writable, so stacked designs
 * (tabs, overlays) can retarget the cell later.
 */
class Cell
{
   // * Config
   /** The placed Frame */
   public Frame $Frame;
   /** Anchor row track (1-based) */
   public int $row;
   /** Anchor column track (1-based) */
   public int $column;
   /** Row tracks spanned */
   public int $rowspan;
   /** Column tracks spanned */
   public int $colspan;


   public function __construct (
      Frame $Frame,
      int $row,
      int $column,
      int $rowspan = 1,
      int $colspan = 1
   )
   {
      // * Config
      $this->Frame = $Frame;
      $this->row = $row;
      $this->column = $column;
      $this->rowspan = $rowspan;
      $this->colspan = $colspan;
   }
}
