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


use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\Line;


abstract class Handler
{
   // * Config
   public Levels $Level;

   // * Data
   public Formatter $Formatter;
   public Filters $Filters;


   /**
    * @param null|Formatter $Formatter Output formatter (defaults to a Line formatter).
    * @param Levels $Level Minimum severity this handler accepts (defaults to Debug — everything).
    */
   public function __construct (null|Formatter $Formatter = null, Levels $Level = Levels::Debug)
   {
      // * Config
      $this->Level = $Level;

      // * Data
      $this->Formatter = $Formatter ?? new Line;
      $this->Filters = new Filters;
   }

   /**
    * Process a record: severity threshold → filters → format → write.
    *
    * @param Record $Record The record to handle.
    * @return bool True when handled (including when skipped by threshold/filters).
    */
   public function handle (Record $Record): bool
   {
      // ? Below threshold (lower backing value = more severe)
      if ($Record->Level->value > $this->Level->value) {
         return true;
      }
      // ? Dropped by filters
      if ($this->Filters->check($Record) === false) {
         return true;
      }

      // @ Format + write
      return $this->write($this->Formatter->format($Record), $Record);
   }

   /**
    * Write the formatted output to the handler's destination.
    *
    * @param string $formatted The formatted record.
    * @param Record $Record The source record (for handlers needing its metadata).
    * @return bool True on success.
    */
   abstract protected function write (string $formatted, Record $Record): bool;
}
