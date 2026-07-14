<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;


use function array_key_exists;
use ArrayIterator;
use IteratorAggregate;
use Traversable;


/**
 * @implements IteratorAggregate<string,string|int|array<int,string>|null>
 */
class Params implements IteratorAggregate
{
   /** @var array<string,string|int|array<int,string>|null> */
   private array $params = [];

   /** @return string|int|array<int,string>|null */
   public function &__get (string $name)
   {
      if (array_key_exists($name, $this->params) === false) {
         $this->params[$name] = null;
      }

      return $this->params[$name];
   }
   /**
    * @param string $param
    * @param string|int|array<int,string> $value
    */
   public function __set (string $param, string|int|array $value): void
   {
      $this->params[$param] = $value;
   }
   /**
    * Replace all params at once (batch assignment).
    *
    * @param array<string,string|array<int,string>> $params
    */
   public function set (array $params): void
   {
      $this->params = $params;
   }

   /** @return ArrayIterator<string,string|int|array<int,string>|null> */
   public function getIterator (): Traversable
   {
      return new ArrayIterator($this->params);
   }
}
