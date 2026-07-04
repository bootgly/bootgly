<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Configs;


use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function intval;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function preg_match;
use function range;
use function str_contains;
use RuntimeException;


/**
 * Case option schema — the single source of truth for case-local CLI options.
 *
 * Each benchmark case declares its options in an `options.php` schema file:
 *
 * ```php
 * return [
 *    'server-workers' => [
 *       'type' => 'int',
 *       'default' => null,   // auto
 *       'vary' => true,      // value accepts sweep syntax
 *       'description' => 'Number of server workers (default: auto)',
 *    ],
 * ];
 * ```
 *
 * Options with `vary: true` accept sweep values — `8`, `1..24`, `1..24:4`
 * or `1,2,4,8` — expanded into execution rounds (one benchmark run each).
 */
class Options
{
   /** @var array<string> Valid schema entry types. */
   public const array TYPES = ['bool', 'int', 'string'];
   /** Sanity cap on expanded values per swept option (typo guard, e.g. `1..100000`). */
   public const int LIMIT = 256;

   // * Config
   /** @var array<string,array{type:string,default:null|scalar,vary:bool,description:string}> Normalized schema. */
   public protected(set) array $schema;

   // * Data
   /** @var array<string,scalar> Resolved static values (defaults + single CLI values). */
   public protected(set) array $values = [];

   // * Metadata
   /** @var array<string,array<int,int>> Swept option name => expanded values (only when > 1 value). */
   public protected(set) array $sweeps = [];
   /** @var array<int,array<string,scalar>> One complete value map per execution round. */
   public protected(set) array $rounds = [[]];


   /**
    * @param array<string,array{type:string,default:null|scalar,vary:bool,description:string}> $schema
    */
   private function __construct (array $schema)
   {
      // * Config
      $this->schema = $schema;
   }

   /**
    * Load and validate a case `options.php` schema file.
    *
    * @param string $file Absolute path to the schema file.
    *
    * @throws RuntimeException On invalid schema.
    */
   public static function load (string $file): self
   {
      // ? Cases without case-local options simply ship no schema file
      if (is_file($file) === false) {
         return new self([]);
      }

      $schema = include $file;

      // : Normalized, validated schema
      return new self(self::validate($schema, $file));
   }

   /**
    * Normalize and validate a raw schema.
    *
    * @param mixed $schema The value returned by the schema file.
    * @param string $file Schema file path (for error messages).
    *
    * @return array<string,array{type:string,default:null|scalar,vary:bool,description:string}>
    *
    * @throws RuntimeException On invalid schema.
    */
   private static function validate (mixed $schema, string $file): array
   {
      // ? Schema file must return an array
      if (is_array($schema) === false) {
         throw new RuntimeException(
            "Benchmark options schema must return an array: {$file}"
         );
      }

      $normalized = [];

      // @@ Validate each entry
      foreach ($schema as $name => $entry) {
         // ? Legacy help-text map (`'--flag=N' => 'description'`) is no longer supported
         if (is_string($entry)) {
            throw new RuntimeException(
               "Legacy options.php format for '{$name}' in {$file} — migrate to a schema entry: ['type' => ..., 'default' => ..., 'vary' => ..., 'description' => ...]."
            );
         }
         // ? Option names are lowercase kebab-case, no `--` prefix
         if (is_string($name) === false || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new RuntimeException(
               "Invalid benchmark option name '{$name}' in {$file} — use lowercase kebab-case without the `--` prefix."
            );
         }
         if (is_array($entry) === false) {
            throw new RuntimeException(
               "Benchmark option '{$name}' in {$file} must be a schema array."
            );
         }

         // ? Only known schema keys
         foreach (array_keys($entry) as $key) {
            if (in_array($key, ['type', 'default', 'vary', 'description'], true) === false) {
               throw new RuntimeException(
                  "Unknown schema key '{$key}' for benchmark option '{$name}' in {$file}."
               );
            }
         }
         // ? `type` is required and must be valid
         $type = $entry['type'] ?? null;
         if (is_string($type) === false || in_array($type, self::TYPES, true) === false) {
            $types = implode(', ', self::TYPES);
            throw new RuntimeException(
               "Benchmark option '{$name}' in {$file} requires a valid 'type' ({$types})."
            );
         }
         // ? `vary` requires an int type (sweeps are numeric series)
         $vary = $entry['vary'] ?? false;
         if (is_bool($vary) === false) {
            throw new RuntimeException(
               "Benchmark option '{$name}' in {$file}: 'vary' must be a bool."
            );
         }
         if ($vary === true && $type !== 'int') {
            throw new RuntimeException(
               "Benchmark option '{$name}' in {$file}: 'vary' requires type 'int'."
            );
         }

         $normalized[$name] = [
            'type' => $type,
            'default' => $entry['default'] ?? null,
            'vary' => $vary,
            'description' => (string) ($entry['description'] ?? ''),
         ];
      }

      // : Normalized schema
      return $normalized;
   }

   /**
    * Resolve CLI options against the schema: coerce types, expand sweeps
    * and build the execution rounds.
    *
    * @param array<string,bool|int|string> $options Parsed CLI options.
    *
    * @throws RuntimeException On invalid option values.
    */
   public function parse (array $options): void
   {
      // ! Reset resolution state
      $this->values = [];
      $this->sweeps = [];

      // @@ Resolve each schema entry
      foreach ($this->schema as $name => $entry) {
         // # Absent option — apply the default (null default = auto, omitted)
         if (array_key_exists($name, $options) === false) {
            if ($entry['default'] !== null) {
               $this->values[$name] = $entry['default'];
            }
            continue;
         }

         $raw = $options[$name];

         // # bool — bare flag only
         if ($entry['type'] === 'bool') {
            // ? `--flag=value` is invalid for bool options
            if ($raw !== true) {
               throw new RuntimeException(
                  "Benchmark option --{$name} is a flag and does not accept a value."
               );
            }

            $this->values[$name] = true;
            continue;
         }

         // ? Valued options require `--name=value`
         if (is_bool($raw)) {
            throw new RuntimeException(
               "Benchmark option --{$name} requires a value."
            );
         }

         // # string — kept verbatim
         if ($entry['type'] === 'string') {
            $this->values[$name] = (string) $raw;
            continue;
         }

         // # int — single value or sweep
         $value = (string) $raw;

         if ($entry['vary'] === false) {
            // ? Sweep syntax on a non-vary option
            if (str_contains($value, '..') || str_contains($value, ',')) {
               throw new RuntimeException(
                  "Benchmark option --{$name} does not accept sweeps: '{$value}'."
               );
            }
            if (preg_match('/^\d+$/', $value) !== 1) {
               throw new RuntimeException(
                  "Benchmark option --{$name} expects an integer: '{$value}'."
               );
            }

            $this->values[$name] = (int) $value;
            continue;
         }

         $expanded = self::expand($value);

         if (count($expanded) === 1) {
            $this->values[$name] = $expanded[0];
         }
         else {
            $this->sweeps[$name] = $expanded;
         }
      }

      // @ Build rounds — cartesian product of sweeps (schema order) over static values
      $rounds = [$this->values];
      foreach ($this->sweeps as $name => $expanded) {
         $product = [];
         foreach ($rounds as $round) {
            foreach ($expanded as $value) {
               $round[$name] = $value;
               $product[] = $round;
            }
         }
         $rounds = $product;
      }

      $this->rounds = $rounds;
   }

   /**
    * Expand a sweep value into its integer series.
    *
    * Grammar: `8` | `1..24` | `1..24:4` | `1,2,4,8`
    *
    * @param string $value Raw CLI value.
    *
    * @return array<int,int>
    *
    * @throws RuntimeException On invalid sweep syntax.
    */
   public static function expand (string $value): array
   {
      // # Range — `A..B` or `A..B:S`
      if (preg_match('/^(\d+)\.\.(\d+)(?::(\d+))?$/', $value, $matches) === 1) {
         $start = (int) $matches[1];
         $end = (int) $matches[2];
         $step = (int) ($matches[3] ?? 1);

         // ? Range must be ascending
         if ($start > $end) {
            throw new RuntimeException(
               "Invalid sweep range '{$value}' — start must be <= end."
            );
         }
         // ? Step must be positive
         if ($step < 1) {
            throw new RuntimeException(
               "Invalid sweep step in '{$value}' — step must be >= 1."
            );
         }

         // ?: A step wider than the span keeps only the start point
         //    (PHP range() rejects step > span with a ValueError)
         $expanded = $step > $end - $start
            ? [$start]
            : range($start, $end, $step);
      }
      // # List — `N,N[,N...]`
      else if (preg_match('/^\d+(?:,\d+)+$/', $value) === 1) {
         $expanded = array_map(intval(...), explode(',', $value));
         $expanded = array_values(array_unique($expanded));
      }
      // # Single value — `N`
      else if (preg_match('/^\d+$/', $value) === 1) {
         $expanded = [(int) $value];
      }
      // ? Anything else is invalid
      else {
         throw new RuntimeException(
            "Invalid sweep value '{$value}' — use N, A..B, A..B:S or N,N,..."
         );
      }

      // ? Cap the series length (typo guard)
      if (count($expanded) > self::LIMIT) {
         $limit = self::LIMIT;
         throw new RuntimeException(
            "Sweep '{$value}' expands to more than {$limit} values."
         );
      }

      // : Expanded integer series
      return $expanded;
   }

   /**
    * Render help lines from the schema.
    *
    * @return array<string,string> Flag => description, e.g.
    *   `['--server-workers=N|A..B|A..B:S|N,N' => 'Number of server workers (default: auto)']`
    */
   public function render (): array
   {
      $lines = [];

      // @@ One flag per schema entry
      foreach ($this->schema as $name => $entry) {
         $flag = match (true) {
            $entry['type'] === 'bool' => "--{$name}",
            $entry['vary'] === true => "--{$name}=N|A..B|A..B:S|N,N",
            $entry['type'] === 'int' => "--{$name}=N",
            default => "--{$name}=VALUE",
         };

         $lines[$flag] = $entry['description'];
      }

      // : Help lines
      return $lines;
   }
}
