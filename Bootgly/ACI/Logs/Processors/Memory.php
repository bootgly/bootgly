<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Processors;


use function memory_get_peak_usage;
use function memory_get_usage;

use Bootgly\ACI\Logs\Processor;
use Bootgly\ACI\Logs\Data\Record;


class Memory extends Processor
{
   // * Config
   public bool $real;


   /**
    * @param bool $real Report real (system-allocated) memory instead of emalloc usage.
    */
   public function __construct (bool $real = true)
   {
      // * Config
      $this->real = $real;
   }

   /**
    * Add current and peak memory usage to the record under `extra['memory']` and `extra['memory_peak']`.
    *
    * @param Record $Record The record to enrich.
    * @return Record The enriched record.
    */
   public function process (Record $Record): Record
   {
      $Record->extra['memory'] = memory_get_usage($this->real);
      $Record->extra['memory_peak'] = memory_get_peak_usage($this->real);

      return $Record;
   }
}
