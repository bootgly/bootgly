<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function strlen;
use InvalidArgumentException;

use Bootgly\ABI\Data\Language;
use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Path;


/**
 * Validate a source array against rule objects, on construction.
 *
 * A rule key is a path (`email`, `address.city`, `items.*.id`; see
 * `Validation\Path`): errors are keyed by the concrete path checked
 * (`items.3.id`). A present value that is not an array where the path needs
 * a container fails with `{field} must be an array.` at that container, and
 * a wildcard rule that would visit more than `limit` entries — or build
 * more than `limit` × `BYTES` bytes of concrete paths, since client keys are
 * copied into every path below them — is not run: it fails once with
 * `{field} has too many items.` at its first wildcard's container.
 */
class Validation
{
   /**
    * Concrete-path bytes one rule key may build per entry of `limit`.
    */
   public const int BYTES = 256;

   // * Config
   /**
    * The most entries the wildcards of one rule key may visit.
    */
   public private(set) int $limit;

   // * Data
   /**
    * @var array<string,mixed>
    */
   private array $source;
   /**
    * @var array<string,Condition|array<int,Condition>>
    */
   private array $rules;

   // * Metadata
   /**
    * The error messages, keyed by the concrete path that failed.
    *
    * @var array<string,array<int,string>>
    */
   public private(set) array $errors = [];
   /**
    * Whether every rule passed.
    */
   public bool $valid {
      get => $this->errors === [];
   }
   /**
    * Entries the wildcards of the current rule key visited.
    */
   private int $visits = 0;
   /**
    * Bytes of the concrete paths (targets and containers) the current rule
    * key built.
    */
   private int $bytes = 0;


   /**
    * @param array<string,mixed> $source The data to validate.
    * @param array<string,Condition|array<int,Condition>> $rules The rules, keyed by path.
    * @param int $limit The most entries the wildcards of one rule key may visit (at least 1).
    *
    * @throws InvalidArgumentException When a rule is not a Condition or the limit is below 1.
    */
   public function __construct (array $source, array $rules, int $limit = 1000)
   {
      // ?
      if ($limit < 1) {
         throw new InvalidArgumentException('Validation limit must be at least 1.');
      }

      // * Config
      $this->limit = $limit;

      // * Data
      $this->source = $source;
      $this->rules = $rules;

      // * Metadata
      $this->errors = [];

      // @
      $this->execute();
   }

   private function execute (): void
   {
      foreach ($this->rules as $field => $conditions) {
         $field = (string) $field;
         /** @var mixed $raw */
         $raw = $conditions;

         if ($raw instanceof Condition) {
            $raw = [$raw];
         }

         if (is_array($raw) === false) {
            throw new InvalidArgumentException("Validation rules for {$field} must be Condition objects.");
         }

         foreach ($raw as $Condition) {
            if ($Condition instanceof Condition === false) {
               throw new InvalidArgumentException("Validation rule for {$field} must be a Condition object.");
            }
         }
         /** @var array<int,Condition> $raw */

         // ! Concrete targets of the path
         $Path = new Path($field);
         /** @var array<int,array{0:string,1:bool,2:mixed}> $targets */
         $targets = [];
         // ! Container errors, kept until the rule key is known to fit the limit
         /** @var array<string,string> $containers */
         $containers = [];
         $this->visits = 0;
         $this->bytes = 0;

         // ? Flat key: one lookup, named as written
         if ($Path->wildcard === false && count($Path->segments) === 1) {
            $key = (string) $Path->segments[0];
            $exists = array_key_exists($key, $this->source);
            $targets[] = [$field, $exists, $exists ? $this->source[$key] : null];
         }
         else if ($this->expand($Path, 0, $this->source, true, '', $targets, $containers) === false) {
            // ? Too many entries: the rule is not run
            $this->fail($Path->prefix !== '' ? $Path->prefix : $field, '{field} has too many items.');

            continue;
         }
         // ? A path without wildcards keeps the key as written
         else if ($Path->wildcard === false && $targets !== []) {
            $targets[0][0] = $field;
         }

         // @ Container errors of a rule that fit the limit
         foreach ($containers as $container => $message) {
            $this->fail($container, $message);
         }

         // @@ Run the conditions on every target
         foreach ($targets as [$target, $exists, $value]) {
            $blank = $value === null || $value === '' || (is_array($value) && $value === []);

            foreach ($raw as $Condition) {
               // ? Optional empty fields are ignored unless the rule is implicit.
               if ($blank && $Condition->implicit === false) {
                  continue;
               }

               if ($Condition->validate($target, $value, $this->source) === false) {
                  $this->errors[$target][] = $Condition->format($target);
               }
            }
         }
      }
   }

   /**
    * Collect the concrete targets of a path below one node.
    *
    * @param array<int,array{0:string,1:bool,2:mixed}> $targets
    * @param array<string,string> $containers
    *
    * @return bool `false` when the wildcards visited more than `limit` entries
    *              or the concrete paths outgrew `limit` × `BYTES` bytes.
    */
   private function expand (Path $Path, int $index, mixed $node, bool $exists, string $field, array &$targets, array &$containers): bool
   {
      // ?: Leaf — one concrete target
      if ($index === count($Path->segments)) {
         // ? Client keys repeat in every path below a wildcard: bound the bytes
         if ($Path->wildcard) {
            $this->bytes += strlen($field);
            if ($this->bytes > $this->limit * self::BYTES) {
               return false;
            }
         }

         $targets[] = [$field, $exists, $exists ? $node : null];

         return true;
      }

      $segment = $Path->segments[$index];

      // ? Missing or blank container: a named segment goes on missing,
      //   a wildcard has no entries
      if ($exists === false || $node === null || $node === '') {
         if ($segment === null) {
            return true;
         }

         $escaped = Path::escape($segment);
         $child = $field === '' ? $escaped : "{$field}.{$escaped}";

         return $this->expand($Path, $index + 1, null, false, $child, $targets, $containers);
      }

      // ? Present but not a container: fail closed at the container
      if (is_array($node) === false) {
         if ($Path->wildcard) {
            $this->bytes += strlen($field);
            if ($this->bytes > $this->limit * self::BYTES) {
               return false;
            }
         }

         $containers[$field] = '{field} must be an array.';

         return true;
      }

      // @@ Wildcard: every entry, within the limit
      if ($segment === null) {
         foreach ($node as $key => $value) {
            $this->visits++;

            if ($this->visits > $this->limit) {
               return false;
            }

            $escaped = Path::escape($key);
            $child = $field === '' ? $escaped : "{$field}.{$escaped}";

            if ($this->expand($Path, $index + 1, $value, true, $child, $targets, $containers) === false) {
               return false;
            }
         }

         return true;
      }

      // @ Named segment
      $escaped = Path::escape($segment);
      $child = $field === '' ? $escaped : "{$field}.{$escaped}";
      $exists = array_key_exists($segment, $node);

      // :
      return $this->expand($Path, $index + 1, $exists ? $node[$segment] : null, $exists, $child, $targets, $containers);
   }

   /**
    * Record one structural error for a path, once.
    */
   private function fail (string $field, string $template): void
   {
      $message = Language::translate($template, ['field' => $field], domain: 'validation');

      // ? Several rule keys may share a container: one message each
      if (in_array($message, $this->errors[$field] ?? [], true)) {
         return;
      }

      // @
      $this->errors[$field][] = $message;
   }
}
