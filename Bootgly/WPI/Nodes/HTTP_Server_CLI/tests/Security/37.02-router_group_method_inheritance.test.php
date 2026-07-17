<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression M4 — group restrictions must propagate through
 * methodless children, nested groups and explicit child narrowing.
 */
return new Specification(
   description: 'Route-group methods must inherit, intersect and narrow consistently',
   Separator: new Separator(line: true),

   requests: [
      fn (): string => "GET /m4/inverse/read HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn (): string => "POST /m4/inverse/read HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n",
      fn (): string => "POST /m4/nested/write/execute HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n",
      fn (): string => "GET /m4/nested/write/execute HTTP/1.1\r\nHost: localhost\r\n\r\n",
      fn (): string => "POST /m4/narrow/execute HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n",
      fn (): string => "GET /m4/narrow/execute HTTP/1.1\r\nHost: localhost\r\n\r\n",
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m4/inverse/:*', function () use ($Router) {
         yield $Router->route('read', function (Request $Request, Response $Response) {
            return $Response(body: 'M4-INHERITED-GET');
         });
      }, GET);

      yield $Router->route('/m4/nested/:*', function () use ($Router) {
         yield $Router->route('write/:*', function () use ($Router) {
            yield $Router->route('execute', function (Request $Request, Response $Response) {
               return $Response(body: 'M4-NESTED-POST');
            });
         }, POST);
      }, [GET, POST]);

      yield $Router->route('/m4/narrow/:*', function () use ($Router) {
         yield $Router->route('execute', function (Request $Request, Response $Response) {
            return $Response(body: 'M4-NARROWED-POST');
         }, POST);
      }, [GET, POST]);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'M4-METHOD-REJECTED');
      });
   },

   test: function (array $responses): bool|string {
      $expected = [
         ['HTTP/1.1 200 OK', 'M4-INHERITED-GET', 'GET-group control'],
         ['HTTP/1.1 404 Not Found', 'M4-METHOD-REJECTED', 'GET-group POST rejection'],
         ['HTTP/1.1 200 OK', 'M4-NESTED-POST', 'nested POST control'],
         ['HTTP/1.1 404 Not Found', 'M4-METHOD-REJECTED', 'nested GET rejection'],
         ['HTTP/1.1 200 OK', 'M4-NARROWED-POST', 'narrowed POST control'],
         ['HTTP/1.1 404 Not Found', 'M4-METHOD-REJECTED', 'narrowed GET rejection'],
      ];

      if (count($responses) !== count($expected)) {
         return 'M4 inheritance regression did not receive every control and attack response.';
      }

      foreach ($expected as $index => [$status, $body, $label]) {
         $response = $responses[$index];
         if (! str_contains($response, $status) || ! str_contains($response, $body)) {
            Vars::$labels = ["M4 {$label} response"];
            dump(json_encode($response));

            return "M4 route-group method regression failed at {$label}.";
         }
      }

      return true;
   },
);
