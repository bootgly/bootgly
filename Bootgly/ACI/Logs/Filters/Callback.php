<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Filters;


use Closure;

use Bootgly\ACI\Logs\Filter;
use Bootgly\ACI\Logs\Data\Record;


class Callback extends Filter
{
   // * Config
   /** @var Closure(Record):bool */
   public Closure $Closure;


   /**
    * Pass records according to a user-supplied predicate.
    *
    * @param Closure(Record):bool $Closure Predicate returning true to keep the record.
    */
   public function __construct (Closure $Closure)
   {
      // * Config
      $this->Closure = $Closure;
   }

   /**
    * Evaluate the predicate against the record.
    *
    * @param Record $Record The record under evaluation.
    * @return bool The predicate result.
    */
   public function check (Record $Record): bool
   {
      // :
      return ($this->Closure)($Record);
   }
}
