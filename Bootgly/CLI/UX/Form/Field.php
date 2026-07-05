<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Form;


use Closure;

use Bootgly\CLI\UX\Form\Controls;


class Field
{
   // * Config
   public string $label;
   public Controls $Control;
   public string $default;
   public bool $required;
   public null|Closure $Validator;
   /** @var array<string> Select choices */
   public array $options;
   /** Mask self-echoed per typed character (Secret input) — null echoes as typed */
   public null|string $mask;

   // * Metadata
   public private(set) string $answer;
   public private(set) bool $answered;


   public function __construct (string $label)
   {
      // * Config
      $this->label = $label;
      $this->Control = Controls::Text;
      $this->default = '';
      $this->required = false;
      $this->Validator = null;
      $this->options = [];
      $this->mask = null;

      // * Metadata
      $this->answer = '';
      $this->answered = false;
   }

   /**
    * Updates the field answer.
    *
    * @param string $answer The answer captured by the field editor.
    *
    * @return self
    */
   public function update (string $answer): self
   {
      // * Metadata
      $this->answer = $answer;
      $this->answered = true;

      // :
      return $this;
   }
}
