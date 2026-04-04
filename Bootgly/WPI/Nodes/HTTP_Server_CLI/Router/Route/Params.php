<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;


use function array_key_exists;
use ArrayIterator;
use IteratorAggregate;
use Traversable;


/**
 * @implements IteratorAggregate<string,string|int|array<int>|null>
 */
class Params implements IteratorAggregate
{
   /** @var array<string,string|int|array<int>|null> */
   private array $params = [];

   /** @return string|int|array<int>|null */
   public function &__get (string $name)
   {
      if (array_key_exists($name, $this->params) === false) {
         $this->params[$name] = null;
      }

      return $this->params[$name];
   }
   /**
    * @param string $param
    * @param string|int|array<int> $value
    */
   public function __set (string $param, string|int|array $value): void
   {
      $this->params[$param] = $value;
   }

   /** @return ArrayIterator<string,string|int|array<int>|null> */
   public function getIterator (): Traversable
   {
      return new ArrayIterator($this->params);
   }
}
