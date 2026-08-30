<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred;


use function str_starts_with;
use Closure;
use RuntimeException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;


/**
 * Sealing fixture for the deferred-work specs.
 *
 * `process()` mirrors the shipped sealing middlewares — `$next()`, then the
 * same `seal()` — so a spec can hold a synchronous and a deferred route to
 * one chain and compare wires. The stamp carries the status code the seal
 * saw: proof the pass ran against the REAL outcome, not the placeholder the
 * onion returned with.
 */
final class Sealer implements Middleware, Sealing
{
   // * Config
   /** Identity: stamps `X-Sealed-{name}`. */
   public readonly string $name;
   /** `stamp` (header with the status seen) or `throw`. */
   public readonly string $mode;
   /** Seal only requests whose URI starts with this prefix (`''` = all). */
   public readonly string $prefix;

   // * Data
   /** Times `seal()` ran. */
   public int $seals = 0;


   public function __construct (
      string $name,
      string $mode = 'stamp',
      string $prefix = ''
   )
   {
      $this->name = $name;
      $this->mode = $mode;
      $this->prefix = $prefix;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      $Result = $next($Request, $Response);

      // @ One sealing pass serves both cycles, as the shipped middlewares do
      $this->seal($Request, $Result);

      return $Result;
   }

   public function seal (Request $Request, Response $Response): void
   {
      // ? Global-pipeline marker: stay inert for every other request
      if ($this->prefix !== '' && str_starts_with($Request->URI, $this->prefix) === false) {
         return;
      }

      $this->seals++;

      if ($this->mode === 'throw') {
         throw new RuntimeException("seal-throw:{$this->name}");
      }

      $Response->Header->set("X-Sealed-{$this->name}", (string) $Response->code);
   }
}
