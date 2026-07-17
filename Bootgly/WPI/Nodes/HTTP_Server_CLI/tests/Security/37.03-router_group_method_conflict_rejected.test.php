<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression M4 — contradictory group/child method declarations
 * must fail during route-cache warmup instead of broadening either policy.
 */
return new Specification(
   description: 'Disjoint route-group and child methods must be rejected',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /m4/conflict/execute HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m4/conflict/:*', function () use ($Router) {
         yield $Router->route('execute', function (Request $Request, Response $Response) {
            return $Response(body: 'M4-CONFLICT-HANDLER-EXECUTED');
         }, GET);
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'M4-METHOD-REJECTED');
      });
   },

   test: function (string $response): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 500')
         && ! str_contains($response, 'M4-CONFLICT-HANDLER-EXECUTED')
      ) {
         return true;
      }

      Vars::$labels = ['M4 disjoint group/child response'];
      dump(json_encode($response));

      return 'M4 disjoint group and child method declarations were not rejected at warmup.';
   },
);
