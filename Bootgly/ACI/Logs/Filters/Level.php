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


use Bootgly\ACI\Logs\Filter;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;


class Level extends Filter
{
   // * Config
   public Levels $Min;
   public Levels $Max;


   /**
    * Pass records whose severity sits within an inclusive range.
    *
    * Remember: lower backing value = more severe.
    *
    * @param Levels $Min Least severe level allowed (defaults to Debug — the floor).
    * @param Levels $Max Most severe level allowed (defaults to Emergency — the ceiling).
    */
   public function __construct (Levels $Min = Levels::Debug, Levels $Max = Levels::Emergency)
   {
      // * Config
      $this->Min = $Min;
      $this->Max = $Max;
   }

   /**
    * Check whether the record's level is within `[Max, Min]` by severity.
    *
    * @param Record $Record The record under evaluation.
    * @return bool True when the level is in range.
    */
   public function check (Record $Record): bool
   {
      $value = $Record->Level->value;

      // :
      return $value <= $this->Min->value && $value >= $this->Max->value;
   }
}
