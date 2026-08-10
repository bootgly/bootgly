<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// M1 parity regression for Encoder_Testing: raw route-cache wire is ineligible
// whenever a global response lifecycle is active. Both current RequestId values
// and both handler bodies must therefore execute instead of replaying request 1.

$handlerRuns = 0;

return new Test(
   Separator: new Separator(left: 'Route response cache lifecycle'),
   description: 'It should decline route-cache replay and storage under global middleware',

   requests: [
      static fn (): string => "GET /cached/security-m1-global HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Request-Id: m1-testing-primer\r\n\r\n",
      static fn (): string => "GET /cached/security-m1-global HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Request-Id: m1-testing-current\r\n\r\n",
   ],

   middlewares: [new RequestId],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router
   ) use (&$handlerRuns) {
      yield $Router->route('/cached/security-m1-global', static function (
         Request $Request,
         Response $Response
      ) use (&$handlerRuns): Response {
         return $Response(body: 'M1-TESTING:handler=' . ++$handlerRuns);
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      [$primer, $current] = $responses;

      $Header = static function (string $wire): null|string {
         if (
            preg_match('/^X-Request-Id:\s*([^\r\n]+)\r?$/mi', $wire, $matches)
            !== 1
         ) {
            return null;
         }

         return $matches[1];
      };

      $evidence = [
         'primer_id' => $Header($primer),
         'current_id' => $Header($current),
         'primer_handler' => str_contains($primer, 'M1-TESTING:handler=1'),
         'current_handler' => str_contains($current, 'M1-TESTING:handler=2'),
      ];

      if (
         $evidence['primer_id'] !== 'm1-testing-primer'
         || $evidence['current_id'] !== 'm1-testing-current'
         || $evidence['primer_handler'] !== true
         || $evidence['current_handler'] !== true
      ) {
         Vars::$labels = ['M1 Encoder_Testing lifecycle evidence'];
         dump(json_encode($responses), json_encode($evidence));

         return 'M1 reproduced: global middleware output entered or consumed shared route-cache wire.';
      }

      return true;
   }
);
