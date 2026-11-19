<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Unit;


use function array_diff;
use function array_keys;
use function assert;
use function bin2hex;
use function implode;
use function random_bytes;
use function str_contains;
use RuntimeException;

use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environments;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * The development debug page lists the request headers. Every credential
 * field (`HTTP::SECRET_HEADERS`: Authorization, Proxy-Authorization, Cookie,
 * Set-Cookie, X-CSRF-Token, X-API-Key, X-Auth-Token) shows as the dump mask, never its value, while the other
 * fields still show.
 *
 * The values carry a random suffix: the page also shows a source excerpt of
 * the file that created the throwable — this one — so a fixed value would be
 * found in the excerpt, not in the header list.
 */
return new Test(
   description: 'Catcher: the development debug page masks every credential header field',
   test: function () {
      // ! SYNTH credentials — never real ones
      $suffix = bin2hex(random_bytes(6));
      $values = [
         'authorization' => "SYNTH-PAGE-AUTHORIZATION-{$suffix}",
         'proxy-authorization' => "SYNTH-PAGE-PROXY-AUTHORIZATION-{$suffix}",
         'cookie' => "SYNTH-PAGE-COOKIE-{$suffix}",
         'set-cookie' => "SYNTH-PAGE-SET-COOKIE-{$suffix}",
         'x-csrf-token' => "SYNTH-PAGE-CSRF-{$suffix}",
         'x-api-key' => "SYNTH-PAGE-API-KEY-{$suffix}",
         'x-auth-token' => "SYNTH-PAGE-AUTH-TOKEN-{$suffix}",
      ];
      $visible = "visible-page-field-{$suffix}";

      // ! A request carrying every credential field
      $Request = new Request;
      $Request->Header->define(implode("\r\n", [
         "Authorization: Bearer {$values['authorization']}",
         "Proxy-Authorization: Basic {$values['proxy-authorization']}",
         "Cookie: PHPSID={$values['cookie']}",
         "Set-Cookie: remember={$values['set-cookie']}",
         "X-CSRF-Token: {$values['x-csrf-token']}",
         "X-Api-Key: {$values['x-api-key']}",
         "X-AUTH-TOKEN: {$values['x-auth-token']}",
         'Accept: text/html',
         "X-Visible: {$visible}",
         ''
      ]));
      $Request->Header->build();

      // @ The development page for a route that threw
      $previous = Catcher::$Environment;
      try {
         Catcher::$Environment = Environments::Development;
         $Response = Catcher::respond($Request, new Response, new RuntimeException('The route failed.'));
      }
      finally {
         Catcher::$Environment = $previous;
      }
      $page = $Response->Body->raw;

      // @@ Every credential field
      $shown = [];
      foreach ($values as $name => $value) {
         if (str_contains($page, $value) === true) {
            $shown[] = $name;
         }
      }
      $listed = implode(', ', $shown);
      // ! Every credential field the framework knows is sent
      $uncovered = implode(', ', array_diff(HTTP::SECRET_HEADERS, array_keys($values)));
      yield assert(
         assertion: $shown === [] && $uncovered === '',
         description: "The page shows no credential header value (shown: [{$listed}], not sent: [{$uncovered}])"
      );

      yield assert(
         assertion: str_contains($page, Secret::MASK) === true && str_contains($page, $visible) === true,
         description: 'The page shows the mask in their place, and every other field as it is'
      );

      // @ The page never changes the request
      yield assert(
         assertion: $Request->Header->get('Authorization') === "Bearer {$values['authorization']}"
            && $Request->Header->get('Proxy-Authorization') === "Basic {$values['proxy-authorization']}",
         description: 'The live request fields are untouched by the page'
      );
   }
);
