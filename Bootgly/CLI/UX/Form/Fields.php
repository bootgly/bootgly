<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Form;


use function count;

use Bootgly\CLI\UX\Form\Field;


class Fields
{
   // * Data
   /** @var array<int,Field> */
   public private(set) array $Fields;

   // * Metadata
   public int $count {
      get => count($this->Fields);
   }


   public function __construct ()
   {
      // * Data
      $this->Fields = [];
   }

   /**
    * Adds a Field to the collection.
    *
    * @param Field $Field The Field to add.
    *
    * @return Field
    */
   public function add (Field $Field): Field
   {
      $this->Fields[] = $Field;

      // :
      return $Field;
   }
}
