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


use Bootgly\CLI\UI\Atoms\Boxing;


/**
 * Cell — a box placement over the Grid tracks: the anchor track (1-based)
 * and the spanned track counts. The box is writable, so overlay designs can
 * retarget the cell later.
 */
class Cell
{
   // * Config
   /** The placed box (Frame, Tabs, ...) */
   public Boxing $Box;
   /** Anchor row track (1-based) */
   public int $row;
   /** Anchor column track (1-based) */
   public int $column;
   /** Row tracks spanned */
   public int $rowspan;
   /** Column tracks spanned */
   public int $colspan;


   public function __construct (
      Boxing $Box,
      int $row,
      int $column,
      int $rowspan = 1,
      int $colspan = 1
   )
   {
      // * Config
      $this->Box = $Box;
      $this->row = $row;
      $this->column = $column;
      $this->rowspan = $rowspan;
      $this->colspan = $colspan;
   }
}
