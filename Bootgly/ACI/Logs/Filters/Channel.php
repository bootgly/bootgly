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


use function in_array;

use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Filter;


class Channel extends Filter
{
   // * Config
   /** @var array<int,string> */
   public array $allowed;
   /** @var array<int,string> */
   public array $denied;


   /**
    * Pass records by channel allow/deny lists. Deny takes precedence over allow.
    *
    * @param array<int,string> $allowed When non-empty, only these channels pass.
    * @param array<int,string> $denied These channels never pass.
    */
   public function __construct (array $allowed = [], array $denied = [])
   {
      // * Config
      $this->allowed = $allowed;
      $this->denied = $denied;
   }

   /**
    * Check the record's channel against the deny then allow lists.
    *
    * @param Record $Record The record under evaluation.
    * @return bool True when the channel is permitted.
    */
   public function check (Record $Record): bool
   {
      // ? Denied wins
      if ($this->denied !== [] && in_array($Record->channel, $this->denied, true) === true) {
         return false;
      }
      // ? Restricted allow list
      if ($this->allowed !== [] && in_array($Record->channel, $this->allowed, true) === false) {
         return false;
      }

      // :
      return true;
   }
}
