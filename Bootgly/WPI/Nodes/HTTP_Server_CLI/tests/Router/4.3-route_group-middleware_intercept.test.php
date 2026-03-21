<?php

use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should apply group middlewares from intercept() to nested routes',

   request: function () {
      return "GET /api/users HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/', function ($Request, $Response) {
         return $Response(body: 'Home');
      }, GET);

      yield $Router->route('/api/:*', function () use ($Router) {
         // @ Group-level middleware
         $Router->intercept(new RequestId);

         yield $Router->route('users', function ($Request, $Response) {
            return $Response(body: 'Users List');
         }, GET);

         yield $Router->route('health', function ($Request, $Response) {
            return $Response(body: 'OK');
         }, GET);
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) {
      // @ Assert X-Request-Id header present (from group middleware)
      if (str_contains($response, 'X-Request-Id: ') === false) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'X-Request-Id header not found — group intercept() middleware did not apply';
      }

      // @ Assert response body
      if (str_contains($response, 'Users List') === false) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Expected "Users List" body from nested route';
      }

      // @ Assert 200 OK
      if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Expected 200 OK status';
      }

      return true;
   }
);
