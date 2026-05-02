<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Diff;


use ArrayIterator;
use IteratorAggregate;
use Traversable;


/**
 * @template-implements IteratorAggregate<int, Line>
 */
final class Chunk implements IteratorAggregate
{
   // * Data
   public private(set) int $start;
   public private(set) int $startRange;
   public private(set) int $end;
   public private(set) int $endRange;
   /** @var list<Line> */
   public private(set) array $lines;


   /**
    * @param list<Line> $lines
    */
   public function __construct (
      int $start = 0,
      int $startRange = 1,
      int $end = 0,
      int $endRange = 1,
      array $lines = []
   ) {
      $this->start      = $start;
      $this->startRange = $startRange;
      $this->end        = $end;
      $this->endRange   = $endRange;
      $this->lines      = $lines;
   }

   /**
    * Replace this chunk's lines.
    *
    * @param list<Line> $lines
    */
   public function update (array $lines): void
   {
      $this->lines = $lines;
   }

   public function getIterator (): Traversable
   {
      return new ArrayIterator($this->lines);
   }
}
