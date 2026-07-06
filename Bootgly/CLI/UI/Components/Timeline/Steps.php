<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Timeline;


use function count;

use Bootgly\CLI\UI\Components\Timeline\States;
use Bootgly\CLI\UI\Components\Timeline\Step;


class Steps
{
   // * Data
   /** @var array<int,Step> */
   public private(set) array $Steps;

   // * Metadata
   /** Index of the Active step (-1 before start) */
   public private(set) int $current;
   public int $count {
      get => count($this->Steps);
   }


   public function __construct ()
   {
      // * Data
      $this->Steps = [];

      // * Metadata
      $this->current = -1;
   }

   /**
    * Adds a Step to the timeline.
    *
    * @param string $label The step label.
    *
    * @return Step
    */
   public function add (string $label): Step
   {
      $Step = new Step($label);

      $this->Steps[] = $Step;

      // :
      return $Step;
   }

   /**
    * Activates the next pending Step (the first one on start).
    *
    * @return null|Step The activated Step — null when none remain.
    */
   public function activate (): null|Step
   {
      $next = $this->current + 1;

      // ?: No steps remain
      if (isSet($this->Steps[$next]) === false) {
         return null;
      }

      $this->current = $next;

      $Step = $this->Steps[$next];
      $Step->update(States::Active);

      // :
      return $Step;
   }
}
