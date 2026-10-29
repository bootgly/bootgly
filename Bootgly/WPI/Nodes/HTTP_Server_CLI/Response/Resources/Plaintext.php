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


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Releasable;


/**
 * Built-in plain-text response formatter.
 */
class Plaintext extends Resource
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
    * Send plain-text content through the canonical Response sender.
    */
   public function send (string $body = ''): Response
   {
      $Response = $this->Response ?? $this->resolve(); // @phpstan-ignore nullCoalesce.initializedProperty

      // ! Set the default media type instead of an explicit header field: this leaves
      //   `fields`/`prepared` empty, so build() keeps its cheapest branch and the Raw
      //   wire-cache stays valid — no CRLF/RFC-9110 regex, no header array churn.
      $Response->Header->type = 'text/plain';

      // :
      return $Response->send($body);
   }
}
