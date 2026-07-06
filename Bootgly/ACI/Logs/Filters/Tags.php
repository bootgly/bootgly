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


use function array_intersect;
use function count;
use function in_array;
use function is_array;

use Bootgly\ACI\Logs\Filter;
use Bootgly\ACI\Logs\Data\Record;


class Tags extends Filter
{
   // * Config
   /** @var array<int,string> */
   public array $tags;
   public bool $all;


   /**
    * Pass records carrying matching tags (read from `extra['tags']` or `context['tags']`).
    *
    * @param array<int,string> $tags Tags to match against.
    * @param bool $all Require all tags (true) or any tag (false).
    */
   public function __construct (array $tags, bool $all = false)
   {
      // * Config
      $this->tags = $tags;
      $this->all = $all;
   }

   /**
    * Check whether the record's tags satisfy the match mode.
    *
    * @param Record $Record The record under evaluation.
    * @return bool True when the tags match.
    */
   public function check (Record $Record): bool
   {
      // ? No tags configured — nothing to enforce
      if ($this->tags === []) {
         return true;
      }

      // @ Resolve record tags
      $tags = $Record->extra['tags'] ?? $Record->context['tags'] ?? [];
      if (is_array($tags) === false) {
         return false;
      }

      // @ Match
      if ($this->all === true) {
         return count(array_intersect($this->tags, $tags)) === count($this->tags);
      }

      foreach ($this->tags as $tag) {
         if (in_array($tag, $tags, true) === true) {
            return true;
         }
      }

      // :
      return false;
   }
}
