<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use function array_map;
use function count;
use function explode;
use function intval;
use function strtolower;


class Configs
{
   public protected(set) null|array $competitors;
   /** @var null|array<int> 1-based scenario indices to include (null = all). */
   /** @var null|string Runner name (lowercase). */
   public protected(set) null|string $runner;
   /** @var null|array<string> Competitor names to include (null = all). */
   public protected(set) null|array $scenarios;
   /** @var array<string,int> Variation parameters (e.g. ['workers' => 2]). */
   public protected(set) array $vary;


   private function __construct (
      null|array $competitors = null,
      null|string $runner = null,
      null|array $scenarios = null,
      array $vary = [],
   )
   {
      $this->competitors = $competitors;
      $this->runner = $runner;
      $this->scenarios = $scenarios;
      $this->vary = $vary;
   }

   /**
    * Parse CLI options into a Configs instance.
    *
    * @param array<string, bool|int|string> $options
    */
   public static function parse (array $options): self
   {
      $competitors = null;
      $runner = null;
      $scenarios = null;
      $vary = [];

      if (isset($options['competitors'])) {
         $competitors = explode(',', (string) $options['competitors']);
      }

      if (isset($options['runner'])) {
         $runner = strtolower((string) $options['runner']);
      }

      if (isset($options['scenarios'])) {
         $scenarios = array_map(intval(...), explode(',', (string) $options['scenarios']));
      }

      if (isset($options['vary'])) {
         foreach (explode(',', (string) $options['vary']) as $part) {
            $kv = explode(':', $part, 2);
            if (count($kv) === 2) {
               $vary[$kv[0]] = (int) $kv[1];
            }
         }
      }

      return new self($competitors, $runner, $scenarios, $vary);
   }
}
