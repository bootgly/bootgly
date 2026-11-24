<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\SecureHeaders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * `SecureHeaders` decorates after `$next()`, and a deferred answer is a copy
 * the onion never unwound over: it seals that copy at settlement. A global
 * `SecureHeaders` in front of the deferring mode of `Authentication` sets
 * its headers on the authenticated answer, the refusal and the bound's 503
 * exactly as on the synchronous 401 of a request without credentials — and
 * on any deferred route, authentication or not.
 */
$Mode = new Authentication(new Authenticating(new Bearer(
   Resolver: static function (string $token, Request $Request): mixed {
      $WPI = WPI;
      Routes::park($WPI->Response, $token === 'tok-slow' ? 2.0 : 0.05);

      return $token === 'tok-alice' ? 'alice' : null;
   }
)), deferred: true, timeout: 0.5);
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new SecureHeaders],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $requests = [
         'authenticated' => ['/am6/who', 'tok-alice'],
         'refused' => ['/am6/who', 'tok-nobody'],
         'bounded' => ['/am6/who', 'tok-slow'],
         'none' => ['/am6/who', ''],
         'plain' => ['/am6/plain', '']
      ];
      try {
         foreach ($requests as $key => [$path, $token]) {
            $headers = $token === '' ? [] : ['Authorization' => "Bearer {$token}"];
            $Socket = Client::open($hostPort);
            $Probe->answers[$key] = Client::send($Socket, Client::request($path, $testIndex, $headers), 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Mode): Generator {
      yield $Router->route('/am6/who', static function (Request $Request, Response $Response) {
         return $Response(body: (string) $Request->identity);
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am6/plain', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 0.05);
            $Response(body: 'plain');
         });
      }, GET);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      $secured = static function (string $key) use ($A): array {
         $head = (string) ($A[$key]['head'] ?? '');

         return [
            $A[$key]['code'] ?? 0,
            stripos($head, "\r\nX-Frame-Options: SAMEORIGIN") !== false,
            stripos($head, "\r\nContent-Security-Policy: default-src 'self'") !== false,
            stripos($head, "\r\nStrict-Transport-Security: max-age=31536000; includeSubDomains") !== false
         ];
      };

      foreach ([
         'authenticated' => [200, 'The authenticated (deferred) answer'],
         'refused' => [401, 'The refusal of a bogus token (deferred)'],
         'bounded' => [503, 'The 503 of the guard bound (deferred)'],
         'none' => [401, 'The 401 of a request without credentials (synchronous)'],
         'plain' => [200, 'A plain deferred answer']
      ] as $key => [$code, $answer]) {
         yield new Assertion(
            description: "{$answer} carries the security headers",
            fallback: "The security headers were dropped from the {$key} answer: {$evidence}"
         )
            ->expect($secured($key))
            ->to->be([$code, true, true, true])
            ->assert();
      }
   })
);
