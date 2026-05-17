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
 * Text faker that emits words from a small built-in lexicon.
 */
final class Text extends Faker
{
   /**
    * Number of words generated per text value.
    */
   public int $words = 5;

   /**
    * @var array<int, string>
    */
   public array $lexicon = [
      'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur',
      'adipiscing', 'elit', 'sed', 'do', 'eiusmod', 'tempor',
      'incididunt', 'ut', 'labore', 'magna', 'aliqua', 'enim',
   ];


   /**
    * Generate one fake text snippet.
    */
   public function generate (): string
   {
      $count = count($this->lexicon) - 1;
      $out = '';

      for ($i = 0; $i < $this->words; $i++) {
         if ($i > 0) {
            $out .= ' ';
         }

         $out .= $this->lexicon[$this->Randomizer->getInt(0, $count)];
      }

      return $out;
   }
}
