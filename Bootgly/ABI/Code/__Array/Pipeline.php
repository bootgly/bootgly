<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__Array;


use function count;


/**
 * A recorded chain of array operations, run in a single pass.
 *
 * This is the one place a PHP-level array abstraction is measurably worth it.
 * The idiomatic chain — `array_values(array_filter(array_map($f, $a), $g))` —
 * pays twice: an intermediate array per stage, and the full callback dispatch a
 * C array function performs per element (~37 ns, against ~19 ns for the same
 * callback invoked from a JIT-compiled userland loop). A pipeline pays neither:
 * it records the stages and applies all of them to each element once.
 *
 * Measured against the native chain (PHP 8.4.23, opcache+JIT, best of 5 rounds
 * across 5 processes):
 *
 *     n =    5    0.91x        n =  100    0.35x
 *     n =   20    0.49x        n = 1000    0.31x
 *
 * That lands at parity (within 4%) with the same loop written out by hand — the
 * abstraction is free, the chain is what costs. Only hand-inlining the transform
 * so no callable is invoked at all goes faster, and nothing expressible as an API
 * can reach that.
 *
 * The early-exit terminals are the bigger win: find() and check() stop at the
 * first survivor, so they beat the native chain by 3x to 52x, and still beat
 * PHP 8.4's own `array_find()`/`array_any()` by roughly 2x from n >= 100 up.
 *
 * Nothing here is lazy in the caller's sense — no generators, no iterators. The
 * stages are recorded and the terminal runs them; that is what keeps the
 * per-element cost at one dispatch instead of one object per element.
 */
final class Pipeline
{
   // # Stage kinds — recorded as ints so the terminal can dispatch on shape
   private const MAP = 0;
   private const FILTER = 1;

   // * Data
   /**
    * The source, snapshotted when the chain starts.
    *
    * Nothing is copied: PHP arrays are copy-on-write, so this only raises the
    * refcount. It does mean a chain does not observe writes made to the caller's
    * variable after the chain was started — including through an `__Array`
    * binding. Build the chain where you run it.
    *
    * @var array<mixed>
    */
   private array $array;
   /**
    * The recorded stages, in order, as `[kind, callable]`.
    *
    * @var array<int,array{0:int,1:callable}>
    */
   private array $Ops = [];


   /**
    * Open a pipeline over an array.
    *
    * The source is optional: a pipeline built without one is a reusable program
    * — record the stages once, then run them over many arrays with apply(). That
    * is what makes the abstraction pay on small arrays, where constructing per
    * call would otherwise dominate (2.5x faster than the native chain at n=5,
    * where a per-call pipeline manages only 1.1x).
    *
    * @param array<mixed> $array The source to run the stages over.
    */
   public function __construct (array $array = [])
   {
      // * Data
      $this->array = $array;
   }

   // # Stages — recorded, never run here

   /**
    * Record a transform applied to every element.
    *
    * @param callable $Op Receives one element, returns its replacement.
    */
   public function map (callable $Op): static
   {
      // * Data
      $this->Ops[] = [self::MAP, $Op];

      // :
      return $this;
   }
   /**
    * Record a test every element must pass to survive.
    *
    * @param callable $Op Receives one element, returns whether it survives.
    */
   public function filter (callable $Op): static
   {
      // * Data
      $this->Ops[] = [self::FILTER, $Op];

      // :
      return $this;
   }

   // # Terminals — each runs every recorded stage in ONE pass

   /**
    * Run the stages over the source and materialize the survivors.
    *
    * Always a list: survivors are appended, so the result is re-indexed from 0
    * without the `array_values()` the native `array_filter()` idiom needs. Keys
    * from the source are not carried.
    *
    * @return array<int,mixed>
    */
   public function collect (): array
   {
      // :
      return $this->run($this->array);
   }

   /**
    * Run the stages over another array — the reusable form.
    *
    * The recorded stages are not consumed, so one pipeline can be built at boot
    * and applied per request. This is the only shape that wins on the small
    * arrays a framework hot path actually handles.
    *
    * @param array<mixed> $array
    * @return array<int,mixed>
    */
   public function apply (array $array): array
   {
      // :
      return $this->run($array);
   }

   /**
    * The first survivor, or `null` when there is none.
    *
    * Stops at the first one — the whole point. Since `null` is also a legitimate
    * survivor, use check() when the distinction matters, exactly as with PHP's
    * own `array_find()`.
    */
   public function find (): mixed
   {
      // ! Specialized: map -> filter, the shape a search chain almost always has
      if ( count($this->Ops) === 2 && $this->Ops[0][0] === self::MAP && $this->Ops[1][0] === self::FILTER ) {
         $Map = $this->Ops[0][1];
         $Test = $this->Ops[1][1];

         // @@
         foreach ($this->array as $value) {
            $mapped = $Map($value);

            // ?:
            if ( $Test($mapped) ) {
               return $mapped;
            }
         }

         // :
         return null;
      }

      // ---

      // @@
      foreach ($this->array as $value) {
         foreach ($this->Ops as [$kind, $Op]) {
            // ?
            if ($kind === self::MAP) {
               $value = $Op($value);

               continue;
            }

            // ?
            if ( ! $Op($value) ) {
               continue 2;
            }
         }

         // :
         return $value;
      }

      // :
      return null;
   }

   /**
    * Whether any element survives every stage.
    *
    * Stops at the first survivor, so a hit near the front costs almost nothing —
    * 50x faster than materializing the filtered array to ask whether it is empty.
    */
   public function check (): bool
   {
      // ! Specialized: map -> filter
      if ( count($this->Ops) === 2 && $this->Ops[0][0] === self::MAP && $this->Ops[1][0] === self::FILTER ) {
         $Map = $this->Ops[0][1];
         $Test = $this->Ops[1][1];

         // @@
         foreach ($this->array as $value) {
            // ! Through a temporary rather than $Test($Map($value)): the nested
            //   form measured 6-7% slower AND made the JIT bimodal across
            //   processes (cross-process spread 1.07x against 1.00x)
            $mapped = $Map($value);

            // ?:
            if ( $Test($mapped) ) {
               return true;
            }
         }

         // :
         return false;
      }

      // ---

      // @@
      foreach ($this->array as $value) {
         foreach ($this->Ops as [$kind, $Op]) {
            // ?
            if ($kind === self::MAP) {
               $value = $Op($value);

               continue;
            }

            // ?
            if ( ! $Op($value) ) {
               continue 2;
            }
         }

         // :
         return true;
      }

      // :
      return false;
   }

   /**
    * How many elements survive every stage.
    *
    * Counts as it goes and never materializes, which is where the ~1.8x over
    * `count(array_filter(array_map(...)))` comes from.
    */
   public function count (): int
   {
      // !
      $count = 0;

      // ! Specialized: map -> filter
      if ( count($this->Ops) === 2 && $this->Ops[0][0] === self::MAP && $this->Ops[1][0] === self::FILTER ) {
         $Map = $this->Ops[0][1];
         $Test = $this->Ops[1][1];

         // @@
         foreach ($this->array as $value) {
            $mapped = $Map($value);

            if ( $Test($mapped) ) {
               $count++;
            }
         }

         // :
         return $count;
      }

      // ---

      // @@
      foreach ($this->array as $value) {
         foreach ($this->Ops as [$kind, $Op]) {
            // ?
            if ($kind === self::MAP) {
               $value = $Op($value);

               continue;
            }

            // ?
            if ( ! $Op($value) ) {
               continue 2;
            }
         }

         $count++;
      }

      // :
      return $count;
   }

   /**
    * Fold the survivors into a single value, inside the same pass.
    *
    * @param callable $Op Receives the carry and one survivor, returns the new carry.
    * @param mixed $initial The carry the fold starts from.
    */
   public function reduce (callable $Op, mixed $initial = null): mixed
   {
      // !
      $carry = $initial;

      // ! Specialized: map -> filter
      if ( count($this->Ops) === 2 && $this->Ops[0][0] === self::MAP && $this->Ops[1][0] === self::FILTER ) {
         $Map = $this->Ops[0][1];
         $Test = $this->Ops[1][1];

         // @@
         foreach ($this->array as $value) {
            $mapped = $Map($value);

            if ( $Test($mapped) ) {
               $carry = $Op($carry, $mapped);
            }
         }

         // :
         return $carry;
      }

      // ---

      // @@
      foreach ($this->array as $value) {
         foreach ($this->Ops as [$kind, $Stage]) {
            // ?
            if ($kind === self::MAP) {
               $value = $Stage($value);

               continue;
            }

            // ?
            if ( ! $Stage($value) ) {
               continue 2;
            }
         }

         $carry = $Op($carry, $value);
      }

      // :
      return $carry;
   }

   // ---

   /**
    * Materialize an array through the recorded stages.
    *
    * Dispatches ONCE on the recorded shape rather than per element. The inner
    * `foreach ($this->Ops)` a generic implementation runs for every element
    * costs 1.34x to 1.57x more than these dedicated loops, which is the whole
    * difference between the abstraction winning and losing.
    *
    * @param array<mixed> $array
    * @return array<int,mixed>
    */
   private function run (array $array): array
   {
      // !
      $output = [];

      // @ One stage
      if ( count($this->Ops) === 1 ) {
         [$kind, $Op] = $this->Ops[0];

         if ($kind === self::MAP) {
            foreach ($array as $value) {
               $output[] = $Op($value);
            }

            // :
            return $output;
         }

         foreach ($array as $value) {
            if ( $Op($value) ) {
               $output[] = $value;
            }
         }

         // :
         return $output;
      }

      // @ Two stages
      if ( count($this->Ops) === 2 ) {
         [$first, $Head] = $this->Ops[0];
         [$second, $Tail] = $this->Ops[1];

         if ($first === self::MAP && $second === self::FILTER) {
            foreach ($array as $value) {
               $mapped = $Head($value);

               if ( $Tail($mapped) ) {
                  $output[] = $mapped;
               }
            }

            // :
            return $output;
         }

         if ($first === self::FILTER && $second === self::MAP) {
            foreach ($array as $value) {
               if ( $Head($value) ) {
                  $output[] = $Tail($value);
               }
            }

            // :
            return $output;
         }
      }

      // ---

      // @@ Any other shape — one dispatch per element, still one pass
      foreach ($array as $value) {
         foreach ($this->Ops as [$kind, $Op]) {
            // ?
            if ($kind === self::MAP) {
               $value = $Op($value);

               continue;
            }

            // ?
            if ( ! $Op($value) ) {
               continue 2;
            }
         }

         $output[] = $value;
      }

      // :
      return $output;
   }
}
