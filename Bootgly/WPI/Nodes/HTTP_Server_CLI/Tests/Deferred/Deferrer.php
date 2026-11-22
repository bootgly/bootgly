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

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;


/**
 * Global middleware fixture that defers ahead of `$next` — the shape of a
 * "look the token up in the database" middleware: everything after it,
 * routing included, runs inside the deferral.
 *
 * Test mode gives every request a fresh Router; a spec that needs the
 * production shape (one Router for the worker's life) passes it here, and
 * it is installed as the ambient Router before anything defers — as the
 * production encoder has it.
 */
final class Deferrer implements Middleware
{
   // * Config
   /** Defer only requests whose URI starts with this prefix. */
   public readonly string $prefix;
   /** The persistent Router installed as the ambient one (null: keep the harness's). */
   public readonly null|Router $Router;
   /** Holds a deferral sent with an `X-Park` header until the spec releases it. */
   public readonly null|Barrier $Barrier;


   public function __construct (string $prefix, null|Router $Router = null, null|Barrier $Barrier = null)
   {
      // * Config
      $this->prefix = $prefix;
      $this->Router = $Router;
      $this->Barrier = $Barrier;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $Next): object
   {
      // ! The production encoder routes every request on one Router
      if ($this->Router !== null) {
         $WPI = WPI;
         $WPI->Router = $this->Router;
      }

      // ? Out of scope: straight through, synchronously
      if (str_starts_with($Request->URI, $this->prefix) === false) {
         return $Next($Request, $Response);
      }

      // @
      $Barrier = $this->Barrier;
      return $Response->defer(static function (Response $Response, Request $Snapshot) use ($Next, $Barrier): void {
         if ($Barrier !== null && $Snapshot->Header->get('X-Park') !== null) {
            $Barrier->hold($Response);
         }

         $Next($Snapshot, $Response);
      });
   }
}
