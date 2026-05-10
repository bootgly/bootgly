<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * Ordered authentication guard collection.
 *
 * `Authenticating` is the strategy object consumed by the `Authentication`
 * middleware. It keeps concrete mechanisms outside the middleware executor and
 * allows routes to compose Basic, Bearer, JWT, Session, and future guards in a
 * deterministic order.
 */
class Authenticating
{
   // * Config
   // ...

   // * Data
   /**
    * Guards evaluated by the authentication middleware.
    *
    * @var array<int,Guard>
    */
   public private(set) array $Guards;

   // * Metadata
   // ...


   /**
    * Create an ordered guard strategy.
    */
   public function __construct (Guard ...$Guards)
   {
      // * Data
      $Ordered = [];
      foreach ($Guards as $Guard) {
         $Ordered[] = $Guard;
      }

      $this->Guards = $Ordered;
   }

   /**
    * Append a guard to the authentication strategy.
    */
   public function add (Guard $Guard): self
   {
      // @
      $this->Guards[] = $Guard;

      return $this;
   }
}