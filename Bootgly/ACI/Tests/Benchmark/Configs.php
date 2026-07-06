<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function intval;
use function preg_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;


class Configs
{
   /** @var null|array<string> Opponent names to include (null = all). */
   public protected(set) null|array $opponents;
   /** @var null|string Runner name (lowercase). */
   public protected(set) null|string $runner;
   /** @var null|string Load set name (e.g. 'techempower'); null = not specified. */
   public protected(set) null|string $loadSet;
   /** @var null|array<int> 1-based load indices to include (null = all in the set). */
   public protected(set) null|array $loads;
   /** @var null|string Output style: 'full' | 'compact' (null = auto). */
   public protected(set) null|string $output;
   /** @var string Results serialization: 'text' | 'json'. */
   public protected(set) string $format;
   /** @var string Generated artifacts: 'marks' | 'report' | 'charts'. */
   public protected(set) string $results;


   /**
    * @param null|array<string> $opponents
    * @param null|array<int> $loads
    */
   private function __construct (
      null|array $opponents = null,
      null|string $runner = null,
      null|string $loadSet = null,
      null|array $loads = null,
      null|string $output = null,
      string $format = 'text',
      string $results = 'marks',
   )
   {
      $this->opponents = $opponents;
      $this->runner = $runner;
      $this->loadSet = $loadSet;
      $this->loads = $loads;
      $this->output = $output;
      $this->format = $format;
      $this->results = $results;
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
      $loadSet = null;
      $loads = null;

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

      // # Load selection — `--loads=<set>:<indexes>` (e.g. `techempower:1,2` or
      //   `benchmark:*`). The set names the load group; `*` runs all of it, a
      //   comma list filters to those 1-based indices. A bare `--loads=1,2`
      //   (no set) leaves $loadSet null — the run path rejects it, since the
      //   <set>:<indexes> form is required.
      if (isset($options['loads'])) {
         $raw = (string) $options['loads'];

         $colon = strpos($raw, ':');
         if ($colon !== false) {
            $set = substr($raw, 0, $colon);
            $loadSet = $set === '' ? null : strtolower($set);

            $indexes = trim(substr($raw, $colon + 1));
            if ($indexes === '*') {
               $loads = null;             // all loads of the set
            } else if ($indexes === '') {
               $loads = [];               // set with no indexes → invalid (caller reports)
            } else {
               $loads = array_map(intval(...), explode(',', $indexes));
            }
         } else {
            $loads = array_map(intval(...), explode(',', $raw));
         }
      }

      // # Output style — 'full' | 'compact' (null = auto: compact when sweeping)
      $output = isset($options['output'])
         ? strtolower((string) $options['output'])
         : null;
      // # Results serialization — 'text' | 'json'
      $format = isset($options['format'])
         ? strtolower((string) $options['format'])
         : 'text';
      // # Generated artifacts — 'marks' | 'report' | 'charts' (inclusive levels)
      $results = isset($options['results'])
         ? strtolower((string) $options['results'])
         : 'marks';

      return new self($opponents, $runner, $loadSet, $loads, $output, $format, $results);
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
