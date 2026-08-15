<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code;


use function array_is_list;
use function array_key_first;
use function array_key_last;
use function array_search;
use function is_array;

use Bootgly\ABI\Code\__Array\Pipeline;


/**
 * Array vocabulary — the boundary entries as `{key, value}` pairs and the shape
 * checks PHP has no single call for, plus the entry point to a fused chain.
 *
 * It deliberately does NOT wrap the native array functions one by one: a
 * PHP-level wrapper's floor is the native call it hides plus the call overhead,
 * so mirroring `array_keys()`/`array_values()` here could only ever cost. For a
 * single operation, call PHP directly.
 *
 * Chains are the exception, and the reason this class earns its place. Starting
 * one with map()/filter() records the stages and runs them in a single pass,
 * which beats the idiomatic `array_values(array_filter(array_map(...)))` by
 * roughly 3x — and the early-exit terminals beat it by up to 52x. See
 * `__Array\Pipeline` for the measurements.
 */
class __Array
{
   // * Data
   /**
    * The wrapped array.
    *
    * Public and writable on purpose: indexing it directly (`$Array->array[$k]`)
    * measures at parity with a native array access, while routing the same read
    * through `ArrayAccess` costs ~8x. Hot callers should reach straight for it.
    *
    * Native functions that mutate in place work on it as-is and copy nothing —
    * `sort($Array->array)` is a true in-place sort.
    *
    * @var array<mixed>
    */
   public array $array;

   // * Metadata
   // # Boundary — the entry plus the key it sits at, in one read
   /**
    * The first entry as `{key, value}`, in a single read.
    *
    * Natively this is two calls (`array_key_first()` plus an index); this reads
    * once at the cost of an object. Prefer the native pair in hot paths.
    *
    * Empty array: `{key: null, value: null}`.
    */
   public object $First {
      get {
         $key = array_key_first($this->array);

         return (object) [
            'key'   => $key,
            'value' => $key === null ? null : $this->array[$key]
         ];
      }
   }
   /**
    * The last entry as `{key, value}`, in a single read.
    *
    * Natively this is two calls (`array_key_last()` plus an index); this reads
    * once at the cost of an object. Prefer the native pair in hot paths.
    *
    * Empty array: `{key: null, value: null}`.
    */
   public object $Last {
      get {
         $key = array_key_last($this->array);

         return (object) [
            'key'   => $key,
            'value' => $key === null ? null : $this->array[$key]
         ];
      }
   }
   // # Shape
   /**
    * Whether any direct value is itself an array.
    *
    * Shallow by design — depth 1 only. PHP has no native equivalent, so the
    * baseline is the `foreach` you would otherwise write inline; the work being
    * a loop is what keeps the dispatch from dominating here.
    */
   public bool $multidimensional {
      get {
         // @@
         foreach ($this->array as $value) {
            // ?
            if ( is_array($value) ) {
               return true;
            }
         }

         // :
         return false;
      }
   }


   /**
    * Wrap an array the instance then owns.
    *
    * Nothing is copied here: PHP arrays are copy-on-write, so passing even a
    * multi-megabyte array only raises its refcount. The copy is deferred to the
    * first write, and happens only while another holder still references it.
    *
    * Accepting by value is what allows literals and expressions —
    * `new __Array([1, 2, 3])`, `new __Array(explode(',', $csv))`. A by-reference
    * parameter would reject both with a fatal error. When aliasing the caller's
    * variable is what you want, use bind() instead.
    *
    * Final: bind() constructs through it, so the signature is a fixed contract.
    *
    * @param array<mixed> $array The array to wrap.
    */
   final public function __construct (array $array)
   {
      // * Data
      $this->array = $array;
   }

   /**
    * Wrap a variable's array by reference — the instance aliases it.
    *
    * Where the constructor takes ownership, this shares: writes through the
    * instance are visible in the caller's variable and vice versa, and the
    * copy-on-write separation never happens, so mutating a large array costs
    * no memory at all.
    *
    * That is the difference the two forms exist for — owning versus aliasing,
    * not two ways to do one thing:
    *
    *     $Array = new __Array([1, 2, 3]);   // owns its own array
    *     $Array = __Array::bind($data);     // operates on $data itself
    *
    * Only a variable can be bound; a literal or a call result is a fatal error,
    * which is exactly why the constructor stays by value.
    *
    * @param array<mixed> $array The variable to alias.
    */
   public static function bind (array &$array): static
   {
      $Array = new static([]);

      // ! Rebind the property onto the caller's variable — assigning would copy
      //   the value and lose the alias
      $Array->array = &$array;

      // :
      return $Array;
   }

   // # Chain — the operations worth routing through this class

   /**
    * Start a chain with a transform applied to every element.
    *
    * The stages are recorded, not run: nothing happens until a terminal
    * (`collect()`, `find()`, `check()`, `count()`, `reduce()`) asks for it, and
    * then all of them run in a single pass over the array.
    *
    *     $Array->map($Double)->filter($Even)->collect();
    *
    * The array is snapshotted here (copy-on-write, nothing is copied), so a
    * chain does not observe later writes — including through a bind() alias.
    *
    * @param callable $Op Receives one element, returns its replacement.
    */
   public function map (callable $Op): Pipeline
   {
      // :
      return (new Pipeline($this->array))->map($Op);
   }
   /**
    * Start a chain with a test every element must pass to survive.
    *
    * Pairs with find() and check(), which stop at the first survivor instead of
    * building the filtered array the native idiom has to materialize before it
    * can answer:
    *
    *     $Array->filter($Active)->find();    // up to 52x faster than the chain
    *
    * @param callable $Op Receives one element, returns whether it survives.
    */
   public function filter (callable $Op): Pipeline
   {
      // :
      return (new Pipeline($this->array))->filter($Op);
   }

   /**
    * Open a chain with no source — a reusable program.
    *
    * Record the stages once, then run them over many arrays with `apply()`. This
    * is the form that pays on the small arrays a hot path actually handles: the
    * per-call construction a chain otherwise pays is what erases the win below
    * about twenty elements.
    *
    *     $Pipeline = __Array::pipe()->map($Normalize)->filter($Allowed);
    *     // ... per request:
    *     $Pipeline->apply($headers);
    */
   public static function pipe (): Pipeline
   {
      // :
      return new Pipeline();
   }

   /**
    * Search for a value in an array.
    *
    * Returns the first needle that matches, as `{key, value, found}`. When
    * nothing matches, `key` is `false` and `value` is `null` — read `found`
    * rather than `value`, since `false`/`null` are themselves searchable.
    *
    * @param array<mixed> $haystack
    * @param mixed $needle A single value, or a list of values tried in order.
    * @param bool $strict Compare with `===` instead of `==`.
    *
    * @return object
    */
   public static function search (array $haystack, mixed $needle, bool $strict = false): object
   {
      // !
      $needles = (array) $needle;

      $key = false;
      $value = null;

      // @@
      foreach ($needles as $searched) {
         $key = array_search($searched, $haystack, $strict);

         // ?
         if ($key !== false) {
            $value = $haystack[$key];

            break;
         }
      }

      // :
      return (object) [
         'key'   => $key,
         'value' => $value,
         'found' => $key !== false
      ];
   }
}
