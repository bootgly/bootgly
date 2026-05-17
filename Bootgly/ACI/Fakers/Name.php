<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Fakers;


use function count;

use Bootgly\ACI\Faker;


/**
 * Human-name faker using built-in first and last name samples.
 */
final class Name extends Faker
{
   /**
    * @var array<int, string>
    */
   public array $firsts = [
      'Alice', 'Bob', 'Carol', 'Dan', 'Eve', 'Frank', 'Grace', 'Henry',
      'Ivy', 'Jack', 'Kate', 'Leo', 'Mia', 'Nate', 'Olivia', 'Paul',
   ];
   /**
    * @var array<int, string>
    */
   public array $lasts = [
      'Adams', 'Brown', 'Clark', 'Davis', 'Evans', 'Foster', 'Green',
      'Hill', 'Irving', 'Jones', 'King', 'Lee', 'Moore', 'Nash',
   ];


   /**
    * Generate one fake full name.
    */
   public function generate (): string
   {
      $first = $this->firsts[$this->Randomizer->getInt(0, count($this->firsts) - 1)];
      $last = $this->lasts[$this->Randomizer->getInt(0, count($this->lasts) - 1)];

      return "{$first} {$last}";
   }
}
