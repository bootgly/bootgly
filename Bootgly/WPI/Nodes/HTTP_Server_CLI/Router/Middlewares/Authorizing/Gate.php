<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;


use Bootgly\API\Security\Identity;


/**
 * Base contract for HTTP authorization gates.
 */
abstract class Gate
{
   /**
    * Authorize the request.
    */
   abstract public function authorize (object $Request): bool;

   /**
    * Build the forbidden response for this gate.
    */
   public function deny (object $Response): object
   {
      return $this->reject($Response);
   }

   /**
    * Resolve a typed Identity from a request-like object.
    */
   protected function resolve (object $Request): null|Identity
   {
      // @
      $Identity = $Request->identity ?? null;

      // :
      return $Identity instanceof Identity ? $Identity : null;
   }

   /**
    * Produce a generic forbidden response fallback.
    */
   protected function reject (object $Response): object
   {
      return Denial::reject($Response);
   }
}
