<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;


use function is_callable;
use function is_object;
use function method_exists;
use function property_exists;


/**
 * Shared helpers for HTTP authentication failure responses.
 */
class Challenge
{
   /**
    * Mark a response-like object as `401 Unauthorized` when possible.
    */
   public static function mark (object $Response): object
   {
      if (method_exists($Response, 'code')) {
         $Result = $Response->code(401);
         return is_object($Result) ? $Result : $Response;
      }

      if (property_exists($Response, 'code')) {
         $Response->code = 401;
      }

      return $Response;
   }

   /**
    * Produce a generic unauthorized response fallback.
    */
   public static function reject (object $Response): object
   {
      if (is_callable($Response)) {
         $Result = $Response(401, [], 'Unauthorized');
         return is_object($Result) ? $Result : $Response;
      }

      return self::mark($Response);
   }

   /**
    * Write a `WWW-Authenticate` challenge on a response-like object.
    */
   public static function announce (object $Response, string $challenge): object
   {
      self::mark($Response);

      $Header = $Response->Header ?? null;
      if (is_object($Header) && method_exists($Header, 'set')) {
         $Header->set('WWW-Authenticate', $challenge);
         return $Response;
      }

      if (is_callable($Response)) {
         $Result = $Response(401, ['WWW-Authenticate' => $challenge], 'Unauthorized');

         if (is_object($Result)) {
            return $Result;
         }
      }

      return self::reject($Response);
   }
}