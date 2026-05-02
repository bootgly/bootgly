<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ;


use ArrayIterator;
use IteratorAggregate;
use Traversable;

use Bootgly\ABI\Differ\Diff\Chunk;


/**
 * @template-implements IteratorAggregate<int, Chunk>
 */
final class Diff implements IteratorAggregate
{
   // * Data
   public private(set) string $from;
   public private(set) string $to;
   /** @var list<Chunk> */
   public private(set) array $chunks;


   /**
    * @param list<Chunk> $chunks
    */
   public function __construct (string $from, string $to, array $chunks = [])
   {
      $this->from   = $from;
      $this->to     = $to;
      $this->chunks = $chunks;
   }

   /**
    * Replace the chunks of this diff.
    *
    * @param list<Chunk> $chunks
    */
   public function update (array $chunks): void
   {
      $this->chunks = $chunks;
   }

   public function getIterator (): Traversable
   {
      return new ArrayIterator($this->chunks);
   }
}
