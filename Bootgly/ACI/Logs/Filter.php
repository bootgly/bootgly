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


abstract class Filter
{
   /**
    * Decide whether a record passes this filter.
    *
    * @param Record $Record The record under evaluation.
    * @return bool True to let the record through, false to drop it.
    */
   abstract public function check (Record $Record): bool;
}
