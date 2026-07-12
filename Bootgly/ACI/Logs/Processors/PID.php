<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Processors;


use function getmypid;

use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Processor;


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
