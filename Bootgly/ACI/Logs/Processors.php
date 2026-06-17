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


class Processors
{
   // * Data
   /** @var array<int,Processor> */
   public protected(set) array $Processors = [];


   /**
    * Append a processor to the chain.
    *
    * @param Processor $Processor The processor to add.
    * @return self
    */
   public function push (Processor $Processor): self
   {
      $this->Processors[] = $Processor;

      return $this;
   }

   /**
    * Run a record through every processor in order, returning the enriched record.
    *
    * @param Record $Record The record to enrich.
    * @return Record The enriched record.
    */
   public function process (Record $Record): Record
   {
      foreach ($this->Processors as $Processor) {
         $Record = $Processor->process($Record);
      }

      return $Record;
   }
}
