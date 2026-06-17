<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in plain-text response formatter.
 */
class Plaintext extends Resource
{
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
    * Send plain-text content through the canonical Response sender.
    */
   public function send (string $body = ''): Response
   {
      // ! Set the default media type instead of an explicit header field: this leaves
      //   `fields`/`prepared` empty, so build() keeps its cheapest branch and the Raw
      //   wire-cache stays valid — no CRLF/RFC-9110 regex, no header array churn.
      $this->Response->Header->type = 'text/plain';

      // :
      return $this->Response->send($body);
   }
}
