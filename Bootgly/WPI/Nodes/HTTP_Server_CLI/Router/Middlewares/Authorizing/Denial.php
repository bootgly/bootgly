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


use function is_callable;
use function is_object;
use function method_exists;
use function property_exists;


/**
 * Shared helpers for HTTP authorization failure responses.
 */
class Denial
{
   /**
    * Mark a response-like object as `403 Forbidden` when possible.
    */
   public static function mark (object $Response): object
   {
      if (method_exists($Response, 'code')) {
         $Result = $Response->code(403);
         return is_object($Result) ? $Result : $Response;
      }

      if (property_exists($Response, 'code')) {
         $Response->code = 403;
      }

      return $Response;
   }

   /**
    * Produce a generic forbidden response fallback.
    */
   public static function reject (object $Response): object
   {
      if (is_callable($Response)) {
         $Result = $Response(403, [], 'Forbidden');
         return is_object($Result) ? $Result : $Response;
      }

      return self::mark($Response);
   }
}
