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


interface Formatter
{
   /**
    * Render a record into its final string representation for a handler.
    *
    * @param Record $Record The record to format.
    * @return string The formatted output.
    */
   public function format (Record $Record): string;
}
