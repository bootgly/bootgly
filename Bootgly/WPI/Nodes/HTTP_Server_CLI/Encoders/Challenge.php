<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use function strlen;
use function strpos;
use function substr;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * Built-in ACME HTTP-01 challenge responder (Auto-TLS).
 *
 * Dispatched by the encoders BEFORE the middleware pipeline when the request
 * path starts with `/.well-known/acme-challenge/` and the challenge-token
 * directory is enabled — user middlewares or router config can never break a
 * certificate validation (the health-probe precedent).
 *
 * The path is ACME-reserved (RFC 8555 §8.3): unknown tokens answer 404 with
 * no fall-through to user routes.
 */
abstract class Challenge
{
   public const string PREFIX = '/.well-known/acme-challenge/';


   /**
    * Respond one HTTP-01 key-authorization probe.
    */
   public static function respond (Request $Request, Response $Response): Response
   {
      // ! Token — the URI segment after the prefix, query stripped
      $token = substr($Request->URI, strlen(self::PREFIX));
      $mark = strpos($token, '?');
      if ($mark !== false) {
         $token = substr($token, 0, $mark);
      }

      // @ Head — validation responses must never be cached
      $Response->Header->type = 'text/plain';
      $Response->Header->set('Cache-Control', 'no-store');

      $authorization = Challenges::load($token);

      // ? Unknown token — the path is ACME-reserved, no fall-through
      if ($authorization === null) {
         return $Response->code(404)->send('');
      }

      // :
      return $Response->send($authorization);
   }
}
