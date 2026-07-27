<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * Base response resource definition.
 */
abstract class Resource
{
   // * Config
   // ...

   // * Data
   public private(set) bool $persistent;
   /**
    * Whether this resource holds REQUEST-scoped state despite being a
    * persistent instance (audit 2026-07-27 M2). `Resources::reset()` calls
    * `clean()` on these between requests, so the instance survives while the
    * data it accumulated for one request does not.
    */
   public private(set) bool $scoped;

   // * Metadata
   public private(set) bool $async;


   public function __construct (bool $persistent = false, bool $scoped = false)
   {
      // * Data
      $this->persistent = $persistent;
      $this->scoped = $scoped;

      // * Metadata
      $this->async = $this instanceof Scheduling;
   }

   /**
    * Drop request-scoped state, keeping the instance.
    *
    * Called between requests for resources constructed with `scoped: true`.
    * A resource that holds nothing per-request needs no override.
    */
   public function clean (): void
   {
      // N/A
   }
}
