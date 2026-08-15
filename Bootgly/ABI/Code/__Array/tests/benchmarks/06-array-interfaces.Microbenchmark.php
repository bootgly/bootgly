<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Microbenchmark;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays\Shapes;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Comparison;


// ! Prototype — the array interfaces PHP offers, implemented in userland
final class Wrapped implements ArrayAccess, IteratorAggregate, Countable
{
   /** @var array<mixed> */
   public array $array;

   /**
    * @param array<mixed> $array
    */
   public function __construct (array $array)
   {
      $this->array = $array;
   }

   public function offsetExists (mixed $offset): bool
   {
      return isSet($this->array[$offset]);
   }
   public function offsetGet (mixed $offset): mixed
   {
      return $this->array[$offset] ?? null;
   }
   public function offsetSet (mixed $offset, mixed $value): void
   {
      if ($offset === null) {
         $this->array[] = $value;

         return;
      }

      $this->array[$offset] = $value;
   }
   public function offsetUnset (mixed $offset): void
   {
      unset($this->array[$offset]);
   }

   public function count (): int
   {
      return count($this->array);
   }

   /**
    * Yielding beats returning an ArrayIterator — no decorator object per read.
    */
   public function getIterator (): Generator
   {
      yield from $this->array;
   }
}

// ! Prototype — the hand-rolled Iterator, the most expensive shape
final class Cursored implements Iterator
{
   /** @var array<mixed> */
   private array $array;
   private int $position = 0;
   /** @var array<int,int|string> */
   private array $keys;

   /**
    * @param array<mixed> $array
    */
   public function __construct (array $array)
   {
      $this->array = $array;
      $this->keys = array_keys($array);
   }

   public function current (): mixed
   {
      return $this->array[$this->keys[$this->position]];
   }
   public function key (): mixed
   {
      return $this->keys[$this->position];
   }
   public function next (): void
   {
      $this->position++;
   }
   public function rewind (): void
   {
      $this->position = 0;
   }
   public function valid (): bool
   {
      return isSet($this->keys[$this->position]);
   }
}


return new Microbenchmark(
   title: 'STUDY — PHP array interfaces on an object vs the native array',

   description: <<<'TEXT'
   __Array today is a plain object holding an array: it cannot be iterated,
   counted or indexed like one. This measures what implementing PHP's array
   interfaces would cost — ArrayAccess for $obj[$k], Countable for count($obj),
   IteratorAggregate/Iterator for foreach — against doing it on the array
   itself, plus the built-in ArrayObject and ArrayIterator.

   The question is not "is it nicer" but "does any of it ever WIN", because the
   codebase default is the native array and only a measured gain justifies
   changing that.
   TEXT,

   inputs: [
      'size' => 100,
   ],

   Comparisons: static function (array $inputs): array {
      $array = Arrays::build(Shapes::Sequence, $inputs['size']);

      $Wrapped = new Wrapped($array);
      $Cursored = new Cursored($array);
      $ArrayObject = new ArrayObject($array);
      $Fixed = SplFixedArray::fromArray($array);

      $key = (int) ($inputs['size'] / 2);

      return [
         new Comparison(
            name: 'iterate + sum',
            Cases: [
               'native foreach' => static function () use ($array) {
                  $sum = 0;
                  foreach ($array as $value) {
                     $sum += $value;
                  }

                  return $sum;
               },
               'IteratorAggregate (yield from)' => static function () use ($Wrapped) {
                  $sum = 0;
                  foreach ($Wrapped as $value) {
                     $sum += $value;
                  }

                  return $sum;
               },
               'Iterator (hand-rolled cursor)' => static function () use ($Cursored) {
                  $sum = 0;
                  foreach ($Cursored as $value) {
                     $sum += $value;
                  }

                  return $sum;
               },
               'ArrayObject (built-in)' => static function () use ($ArrayObject) {
                  $sum = 0;
                  foreach ($ArrayObject as $value) {
                     $sum += $value;
                  }

                  return $sum;
               },
               'SplFixedArray (built-in)' => static function () use ($Fixed) {
                  $sum = 0;
                  foreach ($Fixed as $value) {
                     $sum += $value;
                  }

                  return $sum;
               },
            ],
            baseline: 'native foreach',
            iterations: 20000,
            recommendation: 'native foreach — iterating an object is never cheaper than iterating the array it holds',
            verdict: 'Every object shape pays dispatch per element that a native foreach does not. '
               . 'yield from is the cheapest of them, which makes IteratorAggregate the right '
               . 'choice IF the interface is wanted for ergonomics — never for speed.',
         ),
         new Comparison(
            name: 'random access $a[$k]',
            Cases: [
               'native $array[$key]' => static fn () => $array[$key],
               'ArrayAccess (userland)' => static fn () => $Wrapped[$key],
               'ArrayObject (built-in)' => static fn () => $ArrayObject[$key],
               'public property + index' => static fn () => $Wrapped->array[$key],
            ],
            baseline: 'native $array[$key]',
            recommendation: 'native $array[$key]; if the array is behind an object, index the public property directly',
            verdict: 'ArrayAccess routes a native opcode through a method call. Exposing the array '
               . 'as a public property and indexing it stays far closer to native than '
               . 'implementing the interface does.',
         ),
         new Comparison(
            name: 'count',
            Cases: [
               'native count($array)' => static fn () => count($array),
               'Countable (userland)' => static fn () => count($Wrapped),
               'ArrayObject (built-in)' => static fn () => count($ArrayObject),
            ],
            baseline: 'native count($array)',
            recommendation: 'native count($array) — Countable only relays the same call',
            verdict: 'count() on a Countable dispatches into userland to run the very count() it '
               . 'was asked to replace.',
         ),
      ];
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   Implementing PHP's array interfaces buys ergonomics, never speed: every one
   of them inserts a userland dispatch in front of an opcode or a C function.

   If __Array ever adopts them, adopt IteratorAggregate with `yield from` (the
   cheapest shape measured) and keep the array reachable as a public property
   so hot callers can bypass the interface entirely.
   TEXT,
);
