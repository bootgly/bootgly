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


use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Processor;


class RequestID extends Processor
{
   // * Config
   public string $key;

   // * Data
   // @ Correlation id shared by the current process/request — set by higher layers (e.g. WPI middleware).
   public static null|string $id = null;


   /**
    * @param string $key Record `extra` key under which the id is stored.
    */
   public function __construct (string $key = 'request_id')
   {
      // * Config
      $this->key = $key;
   }

   /**
    * Add the current correlation id (when set) to the record under `extra[$key]`.
    *
    * @param Record $Record The record to enrich.
    * @return Record The enriched record.
    */
   public function process (Record $Record): Record
   {
      // ? No id set for this process/request
      if (self::$id === null) {
         return $Record;
      }

      $Record->extra[$this->key] = self::$id;

      return $Record;
   }
}
