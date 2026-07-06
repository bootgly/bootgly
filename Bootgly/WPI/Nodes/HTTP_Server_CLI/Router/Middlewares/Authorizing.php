<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function array_values;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;


/**
 * Ordered authorization gate collection.
 */
class Authorizing
{
   // * Config
   // ...

   // * Data
   /**
    * Gates evaluated by the authorization middleware.
    *
    * @var array<int,Gate>
    */
   public private(set) array $Gates;

   // * Metadata
   // ...


   /**
    * Create an ordered authorization strategy.
    */
   public function __construct (Gate ...$Gates)
   {
      // * Data
      $this->Gates = array_values($Gates);
   }

   /**
    * Append a gate to the authorization strategy.
    */
   public function add (Gate $Gate): self
   {
      // @
      $this->Gates[] = $Gate;

      return $this;
   }
}
