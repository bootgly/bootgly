<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function is_string;
use function json_encode;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Releasable;


/**
 * Built-in JSON response formatter.
 */
class JSON extends Resource
{
   use Releasable;


   // * Config
   // ...

   // * Data
   protected Response $Response;

   // * Metadata
   // ...


   public function __construct (Response $Response)
   {
      parent::__construct(persistent: true);

      // * Data
      $this->Response = $Response;
   }

   /**
    * Let the response go when the deferred job it served ends (Releasable).
    */
   public function clean (): void
   {
      $this->release();
   }

   /**
    * Send JSON content through the canonical Response sender.
    */
   public function send (mixed $body = null, int $flags = 0): Response
   {
      $Response = $this->Response ?? $this->resolve(); // @phpstan-ignore nullCoalesce.initializedProperty

      // ! Set the default media type instead of a header field — leaves fields/prepared
      //   empty so build() keeps its fast path + the Raw wire-cache (no per-request
      //   header array, no validation regex). An explicit Content-Type still wins.
      $Response->Header->type = 'application/json';

      if (is_string($body) && $body !== '') {
         return $Response->send($body);
      }

      $encoded = json_encode($body, $flags);

      return $Response->send($encoded === false ? 'null' : $encoded);
   }
}
