<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M4 — a methodless child must inherit its route-group method.
 *
 * The POST request is the positive control. The following GET targets the
 * same child on the same warmed Router: secure behavior reaches the 404
 * fallback, while the vulnerable flattening path dispatches the POST-only
 * handler from the method-agnostic static-route bucket.
 */
return new Specification(
   description: 'Route-group methods must constrain methodless child routes',
   Separator: new Separator(line: true),

   requests: [
      function (): string {
         return "POST /m4/actions/execute HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n";
      },
      function (): string {
         return "GET /m4/actions/execute HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m4/actions/:*', function () use ($Router) {
         // ! Intentionally methodless: it must inherit POST from the group.
         yield $Router->route('execute', function (Request $Request, Response $Response) {
            return $Response(body: 'M4-POST-ONLY-HANDLER-EXECUTED');
         });
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'M4-METHOD-REJECTED');
      });
   },

   test: function (array $responses): bool|string {
      if (count($responses) !== 2) {
         return 'M4 probe did not receive both the POST control and GET attack responses.';
      }

      [$POSTResponse, $GETResponse] = $responses;

      if (
         ! str_contains($POSTResponse, 'HTTP/1.1 200 OK')
         || ! str_contains($POSTResponse, 'M4-POST-ONLY-HANDLER-EXECUTED')
      ) {
         Vars::$labels = ['M4 POST control response'];
         dump(json_encode($POSTResponse));

         return 'M4 POST control failed: the group child was not reachable under its declared method.';
      }

      if (
         str_contains($GETResponse, 'HTTP/1.1 200 OK')
         && str_contains($GETResponse, 'M4-POST-ONLY-HANDLER-EXECUTED')
      ) {
         Vars::$labels = ['M4 GET bypass response'];
         dump(json_encode($GETResponse));

         return 'CONFIRMED M4: GET executed a methodless child declared inside a POST-only route group.';
      }

      if (
         ! str_contains($GETResponse, 'HTTP/1.1 404 Not Found')
         || ! str_contains($GETResponse, 'M4-METHOD-REJECTED')
      ) {
         Vars::$labels = ['M4 unexpected GET response'];
         dump(json_encode($GETResponse));

         return 'M4 GET request did not execute the handler, but also did not reach the rejection control.';
      }

      return true;
   },
);
