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


use Bootgly\CLI\UI\Components\Timeline\States;


class Step
{
   // * Config
   public string $label;

   // * Metadata
   public private(set) States $State;
   public private(set) string $note;


   public function __construct (string $label)
   {
      // * Config
      $this->label = $label;

      // * Metadata
      $this->State = States::Pending;
      $this->note = '';
   }

   /**
    * Updates the step state (optionally annotating it).
    *
    * @param States $State The new state.
    * @param string $note A short annotation rendered beside the label.
    *
    * @return self
    */
   public function update (States $State, string $note = ''): self
   {
      // * Metadata
      $this->State = $State;

      if ($note !== '') {
         $this->note = $note;
      }

      // :
      return $this;
   }
}
