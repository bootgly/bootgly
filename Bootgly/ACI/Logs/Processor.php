<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs;


use Bootgly\ACI\Logs\Data\Record;


abstract class Processor
{
   /**
    * Enrich a record (typically its `extra` data) and return it.
    *
    * @param Record $Record The record to enrich.
    * @return Record The enriched record.
    */
   abstract public function process (Record $Record): Record;
}
