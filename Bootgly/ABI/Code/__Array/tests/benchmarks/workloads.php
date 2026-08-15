<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays\Shapes;


/**
 * The operations under comparison, written once.
 *
 * Several cases measure the same `map -> filter -> materialize` work through
 * different mechanisms. Implementing it per case would make the files drift
 * apart silently — and a benchmark whose implementations drift is measuring
 * the drift. Everything comparable lives here, so `Workloads::chain()` in one
 * case is byte-for-byte the `Workloads::chain()` of another.
 *
 * The transforms are deliberately trivial (`*2`, `% 3`): the point is the
 * mechanism carrying them, not the arithmetic.
 */
final class Workloads
{
   // * Data
   /** The per-element transform every mechanism applies. */
   public static Closure $Transform;
   /** The per-element test every mechanism applies. */
   public static Closure $Test;


   /**
    * Bind the shared closures once — they are values, not behaviour, so they
    * are properties rather than methods that only return them.
    */
   public static function boot (): void
   {
      self::$Transform = static fn (int $value): int => $value * 2;
      self::$Test = static fn (int $value): bool => $value % 3 === 0;
   }

   /**
    * Idiomatic native chain — allocates one intermediate array per stage.
    *
    * @param array<int,int> $array
    * @return array<int,int>
    */
   public static function chain (array $array): array
   {
      return array_values(array_filter(array_map(self::$Transform, $array), self::$Test));
   }

   /**
    * Hand-written fused loop — one pass, zero intermediates.
    *
    * @param array<int,int> $array
    * @return array<int,int>
    */
   public static function fuse (array $array): array
   {
      $Transform = self::$Transform;
      $Predicate = self::$Test;

      $output = [];
      foreach ($array as $value) {
         $mapped = $Transform($value);

         if ( $Predicate($mapped) ) {
            $output[] = $mapped;
         }
      }

      return $output;
   }

   /**
    * Generator pipeline — a C-implemented coroutine, lazy, no intermediates.
    *
    * @param array<int,int> $array
    * @return array<int,int>
    */
   public static function generate (array $array): array
   {
      $Transform = self::$Transform;
      $Predicate = self::$Test;

      $Pipe = (static function () use ($array, $Transform, $Predicate) {
         foreach ($array as $value) {
            $mapped = $Transform($value);

            if ( $Predicate($mapped) ) {
               yield $mapped;
            }
         }
      })();

      return iterator_to_array($Pipe, false);
   }

   /**
    * SPL iterator decorators — `CallbackFilterIterator` is C-implemented, but
    * pays an object per element.
    *
    * @param array<int,int> $array
    * @return array<int,int>
    */
   public static function decorate (array $array): array
   {
      $Transform = self::$Transform;

      $Mapped = (static function () use ($array, $Transform) {
         foreach ($array as $value) {
            yield $Transform($value);
         }
      })();

      return iterator_to_array(new CallbackFilterIterator($Mapped, self::$Test), false);
   }

   /**
    * `SplFixedArray` — C-level fixed storage, no hashtable.
    *
    * @param array<int,int> $array
    * @return array<int,int>
    */
   public static function fix (array $array): array
   {
      $Transform = self::$Transform;
      $Predicate = self::$Test;

      $Source = SplFixedArray::fromArray($array);
      $Output = new SplFixedArray($Source->getSize());

      $size = 0;
      foreach ($Source as $value) {
         $mapped = $Transform($value);

         if ( $Predicate($mapped) ) {
            $Output[$size++] = $mapped;
         }
      }

      $Output->setSize($size);

      return $Output->toArray();
   }

   /**
    * Assert every mechanism agrees with the native chain on a sample.
    */
   public static function check (int $size = 30): bool
   {
      $Mechanisms = [
         'chain' => self::chain(...),
         'fuse' => self::fuse(...),
         'generate' => self::generate(...),
         'decorate' => self::decorate(...),
         'fix' => self::fix(...),
         'Pipeline' => static fn (array $array): array => new Pipeline($array)
            ->map(self::$Transform)
            ->filter(self::$Test)
            ->collect(),
         'Pipeline (reused)' => static fn (array $array): array => new Pipeline()
            ->map(self::$Transform)
            ->filter(self::$Test)
            ->apply($array),
         'Generic' => static fn (array $array): array => new Generic($array)
            ->map(self::$Transform)
            ->filter(self::$Test)
            ->collect(),
      ];

      $sample = Arrays::build(Shapes::Sequence, $size);
      $expected = self::chain($sample);

      foreach ($Mechanisms as $label => $Mechanism) {
         if ($Mechanism($sample) !== $expected) {
            fwrite(STDERR, "Mechanism '{$label}' disagrees with the native chain." . PHP_EOL);

            return false;
         }
      }

      return true;
   }
}

/**
 * Prototype — the naive way to run a recorded chain: one op-dispatch loop per
 * element.
 *
 * A measurement subject, never shipped code. It is what the real
 * `__Array\Pipeline` would be without shape dispatch, and it is kept precisely
 * to price that decision: the shipped entity picks a dedicated loop once, from
 * the recorded shape, instead of walking `$Ops` for every element.
 *
 * That single difference is worth 1.34x to 1.57x — the whole margin between the
 * abstraction beating the native chain and merely matching it.
 */
final class Generic
{
   public const MAP = 0;
   public const FILTER = 1;

   /** @var array<mixed> */
   private array $array;
   /** @var array<int,array{0:int,1:callable}> */
   private array $Ops = [];


   /**
    * @param array<mixed> $array
    */
   public function __construct (array $array)
   {
      $this->array = $array;
   }

   public function map (callable $Op): static
   {
      $this->Ops[] = [self::MAP, $Op];

      return $this;
   }
   public function filter (callable $Op): static
   {
      $this->Ops[] = [self::FILTER, $Op];

      return $this;
   }

   /**
    * Run every recorded op in ONE pass — no intermediate arrays.
    *
    * @return array<mixed>
    */
   public function collect (): array
   {
      $output = [];

      // @@
      foreach ($this->array as $value) {
         foreach ($this->Ops as [$kind, $Op]) {
            if ($kind === self::MAP) {
               $value = $Op($value);

               continue;
            }

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

Workloads::boot();
