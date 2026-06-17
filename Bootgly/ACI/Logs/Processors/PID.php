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


use function getmypid;

use Bootgly\ACI\Logs\Processor;
use Bootgly\ACI\Logs\Data\Record;


class PID extends Processor
{
   /**
    * Add the current process id to the record under `extra['pid']`.
    *
    * @param Record $Record The record to enrich.
    * @return Record The enriched record.
    */
   public function process (Record $Record): Record
   {
      $Record->extra['pid'] = getmypid();

      return $Record;
   }
}
