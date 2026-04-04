<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;


return new Specification(
   description: 'It should set CORS headers and handle preflight/origin validation',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ Test 1: Wildcard origin (default) sets ACAO (Access-Control-Allow-Origin): *
      [$Request, $Response] = $createMocks();
      $CORS = new CORS;
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Wildcard origin should set Access-Control-Allow-Origin to *',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Origin'))
         ->to->be('*')
         ->assert();

      // @ Test 2: Allowed specific origin is reflected in ACAO
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'http://example.com']
      );
      $CORS = new CORS(origins: ['http://example.com']);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Allowed origin should be reflected in ACAO header',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Origin'))
         ->to->be('http://example.com')
         ->assert();

      // @ Test 3: Rejected origin returns 403
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'http://evil.com']
      );
      $CORS = new CORS(origins: ['http://example.com']);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Rejected origin should return 403',
      )
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      // @ Test 4: Preflight OPTIONS returns 204
      [$Request, $Response] = $createMocks(
         requestProps: ['method' => 'OPTIONS']
      );
      $CORS = new CORS;
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'OPTIONS preflight should return 204',
      )
         ->expect($Result->code)
         ->to->be(204)
         ->assert();

      // @ Test 5: Credentials enabled sets ACAC header
      [$Request, $Response] = $createMocks();
      $CORS = new CORS(credentials: true);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Credentials should set Access-Control-Allow-Credentials header',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Credentials'))
         ->to->be('true')
         ->assert();

      // @ Test 6: Methods and headers are listed
      [$Request, $Response] = $createMocks();
      $CORS = new CORS;
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Allow-Methods header should be set',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Methods') !== null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Allow-Headers header should be set',
      )
         ->expect($Result->Header->get('Access-Control-Allow-Headers') !== null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Max-Age header should default to 86400',
      )
         ->expect($Result->Header->get('Access-Control-Max-Age'))
         ->to->be('86400')
         ->assert();
   })
);
