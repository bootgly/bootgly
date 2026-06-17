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


use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;


class Handlers
{
   // * Data
   /** @var array<int,Handler> */
   public protected(set) array $Handlers = [];


   /**
    * Append a handler, optionally overriding its minimum severity level.
    *
    * @param Handler $Handler The handler to add.
    * @param null|Levels $Level When given, sets the handler's minimum level.
    * @return self
    */
   public function push (Handler $Handler, null|Levels $Level = null): self
   {
      if ($Level !== null) {
         $Handler->Level = $Level;
      }

      $this->Handlers[] = $Handler;

      return $this;
   }

   /**
    * Dispatch a record to every registered handler.
    *
    * @param Record $Record The record to dispatch.
    */
   public function handle (Record $Record): void
   {
      foreach ($this->Handlers as $Handler) {
         $Handler->handle($Record);
      }
   }
}
