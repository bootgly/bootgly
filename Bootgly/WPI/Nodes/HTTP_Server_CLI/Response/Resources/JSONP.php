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
use function strlen;

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


/**
 * Built-in JSONP response formatter.
 */
class JSONP extends Resource
{
   // * Config
   //   Maximum accepted JSONP callback name length in bytes (audit F-7):
   //   bounds the attacker-shaped response prefix. Over-length names fall back
   //   to the safe default identifier.
   private const int CALLBACK_MAX_LENGTH = 64;

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
      // @ JSONP emits JavaScript (`callback(...)`), not JSON — label it
      //   honestly as `text/javascript` and forbid content sniffing (audit
      //   F-7). The wrong `application/json` label both misrepresents the
      //   payload and lets a directly-navigated response be sniffed into
      //   another type. JSONP is, by construction, a cross-origin read; never
      //   expose session/identity-scoped data through it.
      $this->Response->Header->set('Content-Type', 'text/javascript');
      $this->Response->Header->set('X-Content-Type-Options', 'nosniff');

      $callbackSource = WPI->Request->queries['callback'] ?? null;

      if (is_array($callbackSource)) {
         $callbackSource = $callbackSource[0] ?? null;
      }

      // ? Callback name: a JS identifier (dotted member paths allowed) bounded
      //   to CALLBACK_MAX_LENGTH bytes (audit F-7 — bounds the attacker-shaped
      //   response prefix). Anything else falls back to the safe default.
      $callback = is_string($callbackSource)
         && $callbackSource !== ''
         && strlen($callbackSource) <= self::CALLBACK_MAX_LENGTH
         && preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$.]*$/', $callbackSource) === 1
            ? $callbackSource
            : 'callback';

      $json = json_encode($body);

      return $this->Response->send($callback . '(' . ($json === false ? 'null' : $json) . ')');
   }
}
