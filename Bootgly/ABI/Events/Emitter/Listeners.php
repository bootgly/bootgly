<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\Emitter;


use function array_column;
use function usort;
use ArrayIterator;
use Closure;
use IteratorAggregate;
use Traversable;

use Bootgly\ABI\Events\Emitter\Listener;


/**
 * Priority-ordered listener collection for a single event.
 *
 * @implements IteratorAggregate<int,Listener|Closure>
 */
class Listeners implements IteratorAggregate
{
   // * Data
   /** @var array<int,array{0:int,1:Listener|Closure}> */
   protected array $entries = [];

   // * Metadata
   private bool $sorted = true;
   /** @var list<Listener|Closure> */
   private array $Sorted = [];


   /**
    * Register one listener with an optional priority (higher runs first).
    */
   public function add (Listener|Closure $Listener, int $priority = 0): void
   {
      $this->entries[] = [$priority, $Listener];
      $this->sorted = false;
   }

   /**
    * Iterate listeners in descending priority order.
    *
    * The order is computed once per registration batch and cached, so emit()
    * stays a flat iteration over the prepared list.
    *
    * @return Traversable<int,Listener|Closure>
    */
   public function getIterator (): Traversable
   {
      // ?
      if ($this->sorted === false) {
         usort($this->entries, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

         $this->Sorted = array_column($this->entries, 1);
         $this->sorted = true;
      }

      // :
      return new ArrayIterator($this->Sorted);
   }
}
