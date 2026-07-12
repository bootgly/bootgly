<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Filters;


use function stripos;

use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Filter;


class Search extends Filter
{
   // * Config
   public string $term;


   /**
    * Pass records whose message contains a substring (case-insensitive). For incremental find.
    *
    * @param string $term Substring to match; an empty term passes everything.
    */
   public function __construct (string $term = '')
   {
      // * Config
      $this->term = $term;
   }

   /**
    * Check whether the record's message contains the search term.
    *
    * @param Record $Record The record under evaluation.
    * @return bool True when the term is empty or found in the message.
    */
   public function check (Record $Record): bool
   {
      // ? Empty term — nothing to match
      if ($this->term === '') {
         return true;
      }

      // :
      return stripos($Record->message, $this->term) !== false;
   }
}
