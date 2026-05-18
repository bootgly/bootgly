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


use function is_array;
use function is_string;
use function json_encode;
use function preg_match;

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in JSONP response formatter.
 */
class JSONP extends Resource
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
    * Send JSONP content through the canonical Response sender.
    */
   public function send (mixed $body = null): Response
   {
      $this->Response->Header->set('Content-Type', 'application/json');

      $callbackSource = WPI->Request->queries['callback'] ?? null;

      if (is_array($callbackSource)) {
         $callbackSource = $callbackSource[0] ?? null;
      }

      $callback = is_string($callbackSource)
         && $callbackSource !== ''
         && preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$.]*$/', $callbackSource) === 1
            ? $callbackSource
            : 'callback';

      $json = json_encode($body);

      return $this->Response->send($callback . '(' . ($json === false ? 'null' : $json) . ')');
   }
}
