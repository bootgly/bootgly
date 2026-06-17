<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs;


use Bootgly\ACI\Logs\Data\Record;


class Filters
{
   // * Data
   /** @var array<int,Filter> */
   public protected(set) array $Filters = [];


   /**
    * Append a filter to the chain.
    *
    * @param Filter $Filter The filter to add.
    * @return self
    */
   public function push (Filter $Filter): self
   {
      $this->Filters[] = $Filter;

      return $this;
   }

   /**
    * Check a record against every filter; passes only when all of them pass.
    *
    * @param Record $Record The record under evaluation.
    * @return bool True when the record passes all filters.
    */
   public function check (Record $Record): bool
   {
      foreach ($this->Filters as $Filter) {
         if ($Filter->check($Record) === false) {
            return false;
         }
      }

      return true;
   }
}
