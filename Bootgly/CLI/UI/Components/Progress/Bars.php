<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Progress;


use function count;

use Bootgly\CLI\UI\Components\Progress;
use Bootgly\CLI\UI\Components\Progress\Bar;


/**
 * Multi-bar track collection — each Bar added here is an independent track
 * (own current/total/percent/description) rendered in the same Progress frame.
 */
class Bars
{
   private Progress $Progress;

   // * Data
   /** @var array<int,Bar> */
   public private(set) array $Bars;

   // * Metadata
   public int $count {
      get => count($this->Bars);
   }


   public function __construct (Progress &$Progress)
   {
      $this->Progress = $Progress;

      // * Data
      $this->Bars = [];
   }

   /**
    * Adds an independent track Bar to the Progress frame.
    *
    * @param string $description The track description.
    *
    * @return Bar
    */
   public function add (string $description = ''): Bar
   {
      $Bar = new Bar($this->Progress);
      // * Config
      $Bar->units = 20;
      // * Data
      $Bar->description = $description;

      $this->Bars[] = $Bar;

      // :
      return $Bar;
   }
}
