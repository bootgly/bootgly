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
use function array_unique;
use function array_values;
use function count;
use function explode;
use function intval;
use function preg_replace;
use function strtolower;
use function trim;


class Configs
{
   /** @var null|array<string> Competitor names to include (null = all). */
   public protected(set) null|array $competitors;
   /** @var null|string Runner name (lowercase). */
   public protected(set) null|string $runner;
   /** @var null|array<int> 1-based scenario indices to include (null = all). */
   public protected(set) null|array $scenarios;
   /** @var array<string,int> Variation parameters (e.g. ['workers' => 2]). */
   public protected(set) array $vary;


   /**
    * @param null|array<string> $competitors
    * @param null|array<int> $scenarios
    * @param array<string,int> $vary
    */
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
         $competitors = [];

         foreach (explode(',', (string) $options['competitors']) as $competitor) {
            $normalized = self::normalize($competitor);
            if ($normalized !== '') {
               $competitors[] = $normalized;
            }
         }

         $competitors = $competitors !== [] ? array_values(array_unique($competitors)) : null;
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

   /**
    * Normalize a competitor name to a slug for comparison.
    * e.g. "Swoole (Base)" → "swoole-base", "Workerman" → "workerman"
    */
   public static function slug (string $name): string
   {
      $name = strtolower($name);
      $name = preg_replace('/[()]+/', '', $name);

      if ($name === null) {
         return '';
      }

      $name = preg_replace('/[\s_]+/', '-', trim($name));

      if ($name === null) {
         return '';
      }

      return trim($name, '-');
   }

   /**
    * Normalize competitor filter values passed via CLI.
    * Accepts known aliases/typos to avoid silent skips.
    */
   public static function normalize (string $competitor): string
   {
      $slug = self::slug($competitor);
      if ($slug === '') {
         return '';
      }

      /** @var array<string, string> $aliases */
      $aliases = [];
      return $aliases[$slug] ?? $slug;
   }
}
