<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function is_string;
use function json_encode;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in JSON response formatter.
 */
class JSON extends Resource
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
    * Send JSON content through the canonical Response sender.
    */
   public function send (mixed $body = null, int $flags = 0): Response
   {
      // ! Set the default media type instead of a header field — leaves fields/prepared
      //   empty so build() keeps its fast path + the Raw wire-cache (no per-request
      //   header array, no validation regex). An explicit Content-Type still wins.
      $this->Response->Header->type = 'application/json';

      if (is_string($body) && $body !== '') {
         return $this->Response->send($body);
      }

      $encoded = json_encode($body, $flags);

      return $this->Response->send($encoded === false ? 'null' : $encoded);
   }
}
