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


use function implode;
use function is_array;
use function is_object;
use function is_string;
use function method_exists;
use function property_exists;
use function stripos;
use function substr;
use function trim;
use stdClass;

use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication as Method;


/**
 * Base contract for HTTP authentication guards.
 *
 * Concrete guards implement one authentication mechanism and expose the same
 * lifecycle to `Authentication`: authenticate the request, then produce the
 * correct challenge when authentication fails. Shared helper methods keep
 * response fallback and per-request metadata exposure consistent.
 */
abstract class Guard
{
   // * Config
   // ...

   // * Data
   /**
    * Authentication protection space used by WWW-Authenticate challenges.
    */
   public private(set) string $realm;

   // * Metadata
   // ...


   /**
    * Configure the guard challenge realm.
    */
   public function __construct (string $realm = 'Protected area')
   {
      // * Data
      $this->realm = $realm;
   }

   /**
    * Attempt to authenticate the request.
    *
    * Implementations may expose successful authentication metadata on the
    * request object, commonly `identity` and/or `claims`.
    */
   abstract public function authenticate (object $Request): bool;

   /**
    * Build the unauthorized response for this guard.
    */
   abstract public function challenge (object $Response): object;

   /**
    * Apply a protocol-aware authentication challenge when supported.
    */
   protected function respond (object $Response, Method $Method): object
   {
      // @ Prefer protocol-aware Response authentication challenges.
      if (method_exists($Response, 'authenticate')) {
         $Result = $Response->authenticate($Method);
         return is_object($Result) ? $Result : $Response;
      }

      // : Generic 401 fallback.
      return $this->reject($Response);
   }

   /**
    * Write a `401 Unauthorized` challenge directly to a response-like object.
    *
    * Bearer authentication is middleware-owned, so guards can emit its
    * `WWW-Authenticate` challenge without depending on loose Response-layer
    * descriptors.
    */
   protected function announce (object $Response, string $challenge): object
   {
      return Challenge::announce($Response, $challenge);
   }

   /**
    * Build an HTTP authentication challenge value.
    *
    * @param array<string, string> $Attributes Non-empty challenge attributes.
    */
   protected function format (string $scheme, array $Attributes): string
   {
      $parameters = [];

      foreach ($Attributes as $name => $value) {
         if ($value === '') {
            continue;
         }

         $parameters[] = $name . '="' . $value . '"';
      }

      if ($parameters === []) {
         return $scheme;
      }

      return $scheme . ' ' . implode(', ', $parameters);
   }

   /**
    * Extract a Bearer token from a request-like object.
    *
    * Guards first honor an already-resolved `$token` property, then fall back
    * to the raw `Authorization` header. The parser stays here because Bearer
    * is an authentication middleware concern, not a generic request DTO.
    */
   protected function extract (object $Request): string
   {
      $token = $Request->token ?? '';
      if (is_string($token) && $token !== '') {
         return $token;
      }

      $authorization = '';
      $Header = $Request->Header ?? null;
      if (is_object($Header) && method_exists($Header, 'get')) {
         $authorization = $Header->get('Authorization');
      }

      if (! is_string($authorization) || $authorization === '') {
         $headers = $Request->headers ?? [];
         if (is_array($headers)) {
            $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
         }
      }

      if (! is_string($authorization) || stripos($authorization, 'Bearer ') !== 0) {
         return '';
      }

      return trim(substr($authorization, 7));
   }

   /**
    * Produce a generic unauthorized response fallback.
    */
   protected function reject (object $Response): object
   {
      return Challenge::reject($Response);
   }

   /**
    * Attach authentication metadata to a request-like object.
    */
   protected function expose (object $Request, string $name, mixed $value): void
   {
      // @ Real Request declares authentication metadata properties. stdClass
      //   remains supported for tests and lightweight request doubles.
      if (property_exists($Request, $name) || $Request instanceof stdClass) {
         $Request->{$name} = $value;
      }
   }
}
