<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Mock;


use function array_filter;
use function array_values;
use function count;


/**
 * Collection of recorded Call entries.
 */
class Calls
{
   /**
    * @var array<int, Call>
    */
   public array $list = [];


   /**
    * Append one recorded invocation to the collection.
    */
   public function push (Call $Call): void
   {
      $this->list[] = $Call;
   }

   /**
    * Remove every recorded invocation.
    */
   public function reset (): void
   {
      $this->list = [];
   }

   /**
    * Total recorded calls, or per-method count when $method is given.
    */
   public function count (null|string $method = null): int
   {
      if ($method === null) {
         return count($this->list);
      }

      return count(
         array_filter($this->list, static fn (Call $Call): bool => $Call->method === $method)
      );
   }

   /**
    * @return array<int, Call>
    */
   public function filter (string $method): array
   {
      return array_values(
         array_filter($this->list, static fn (Call $Call): bool => $Call->method === $method)
      );
   }
}