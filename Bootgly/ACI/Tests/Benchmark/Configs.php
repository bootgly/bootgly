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
   /** @var null|array<string> Opponent names to include (null = all). */
   public protected(set) null|array $opponents;
   /** @var null|string Runner name (lowercase). */
   public protected(set) null|string $runner;
   /** @var null|array<int> 1-based load indices to include (null = all). */
   public protected(set) null|array $loads;
   /** @var array<string,int> Variation parameters (e.g. ['workers' => 2]). */
   public protected(set) array $vary;


   /**
    * @param null|array<string> $opponents
    * @param null|array<int> $loads
    * @param array<string,int> $vary
    */
   private function __construct (
      null|array $opponents = null,
      null|string $runner = null,
      null|array $loads = null,
      array $vary = [],
   )
   {
      $this->opponents = $opponents;
      $this->runner = $runner;
      $this->loads = $loads;
      $this->vary = $vary;
   }

   /**
    * Parse CLI options into a Configs instance.
    *
    * @param array<string, bool|int|string> $options
    */
   public static function parse (array $options): self
   {
      $opponents = null;
      $runner = null;
      $loads = null;
      $vary = [];

      if (isset($options['opponents'])) {
         $opponents = [];

         foreach (explode(',', (string) $options['opponents']) as $opponent) {
            $normalized = self::normalize($opponent);
            if ($normalized !== '') {
               $opponents[] = $normalized;
            }
         }

         $opponents = $opponents !== [] ? array_values(array_unique($opponents)) : null;
      }

      if (isset($options['runner'])) {
         $runner = strtolower((string) $options['runner']);
      }

      if (isset($options['loads'])) {
         $loads = array_map(intval(...), explode(',', (string) $options['loads']));
      }

      if (isset($options['vary'])) {
         foreach (explode(',', (string) $options['vary']) as $part) {
            $kv = explode(':', $part, 2);
            if (count($kv) === 2) {
               $vary[$kv[0]] = (int) $kv[1];
            }
         }
      }

      return new self($opponents, $runner, $loads, $vary);
   }

   /**
    * Normalize a opponent name to a slug for comparison.
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
    * Normalize opponent filter values passed via CLI.
    * Accepts known aliases/typos to avoid silent skips.
    */
   public static function normalize (string $opponent): string
   {
      $slug = self::slug($opponent);
      if ($slug === '') {
         return '';
      }

      /** @var array<string, string> $aliases */
      $aliases = [];
      return $aliases[$slug] ?? $slug;
   }
}
