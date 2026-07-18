<?php

use function is_string;
use function strlen;
use function preg_match;
use function str_repeat;
use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;


return new Specification(
   description: 'It should generate or preserve request IDs on the response',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };

      // @ Test 1: Generates ID when none provided
      [$Request, $Response] = $createMocks();
      $RequestId = new RequestId;
      $Result = $RequestId->process($Request, $Response, $passthrough);
      $generatedId = $Result->Header->get('X-Request-Id');
      yield new Assertion(
         description: 'Generated ID should be 36 characters (UUID format)',
      )
         ->expect($generatedId !== null && strlen($generatedId) === 36)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Generated ID should match UUID v4 pattern',
      )
         ->expect(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $generatedId) === 1) // @phpstan-ignore-line
         ->to->be(true)
         ->assert();

      // @ Test 2: Preserves existing request ID
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Request-Id' => 'my-custom-id-123']
      );
      $RequestId = new RequestId;
      $Result = $RequestId->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Existing request ID should be preserved',
      )
         ->expect($Result->Header->get('X-Request-Id'))
         ->to->be('my-custom-id-123')
         ->assert();

      // @ Test 3: Preserves the maximum accepted RFC-token length
      $boundaryID = str_repeat('a', 128);
      [$Request, $Response] = $createMocks(
         requestHeaders: ['X-Request-Id' => $boundaryID]
      );
      $RequestId = new RequestId;
      $Result = $RequestId->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'A 128-byte token request ID should be preserved',
      )
         ->expect($Result->Header->get('X-Request-Id'))
         ->to->be($boundaryID)
         ->assert();

      // @ Test 4: Replaces values outside the grammar/length contract
      $invalidIDs = [
         '129-byte token' => str_repeat('b', 129),
         'space' => 'contains space',
         'horizontal tab' => "contains\ttab",
         'control byte' => "contains\x1fcontrol",
         'Unicode' => "unicode-\u{00e9}",
         'CRLF' => "split\r\nheader",
      ];
      foreach ($invalidIDs as $label => $invalidID) {
         [$Request, $Response] = $createMocks(
            requestHeaders: ['X-Request-Id' => $invalidID]
         );
         $RequestId = new RequestId;
         $Result = $RequestId->process($Request, $Response, $passthrough);
         $generatedID = $Result->Header->get('X-Request-Id');

         yield new Assertion(
            description: "Invalid request ID ({$label}) should be replaced with a UUID v4",
         )
            ->expect(
               is_string($generatedID)
               && preg_match(
                  '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                  $generatedID,
               ) === 1
            )
            ->to->be(true)
            ->assert();
      }

      // @ Test 5: Custom header name
      [$Request, $Response] = $createMocks();
      $RequestId = new RequestId(header: 'X-Trace-Id');
      $Result = $RequestId->process($Request, $Response, $passthrough);
      yield new Assertion(
         description: 'Custom header name should be used',
      )
         ->expect($Result->Header->get('X-Trace-Id') !== null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Default header should not be set when custom name is used',
      )
         ->expect($Result->Header->get('X-Request-Id'))
         ->to->be(Type::Null)
         ->assert();
   })
);
