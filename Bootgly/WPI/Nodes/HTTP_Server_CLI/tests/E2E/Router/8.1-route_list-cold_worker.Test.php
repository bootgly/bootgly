<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   description: 'It should list every registered route from inside a handler on a cold worker',

   request: function () {
      return "GET /routes HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ The first request of this Router: the handler below runs after the
      //   warm-up, so it sees the routes registered after it and the group's
      yield $Router->route('/routes', function ($Request, $Response) use ($Router) {
         $lines = [];
         foreach ($Router->Routes as $Registration) {
            $methods = $Registration->methods === null
               ? '*'
               : implode(',', $Registration->methods);
            $middlewares = count($Registration->Middlewares);
            $parameters = [];
            foreach ($Registration->parameters as $parameter) {
               $parameters[] = "{$parameter['name']}:{$parameter['constraint']}";
            }
            $parameters = implode(',', $parameters);

            $lines[] = "{$Registration->Kind->name} [$methods] {$Registration->path} m=$middlewares p=$parameters";
         }
         $cached = $Router->cached ? 'warm' : 'cold';

         return $Response(body: "$cached\n" . implode("\n", $lines));
      }, GET);

      yield $Router->serve('/health', 'OK', GET);

      yield $Router->route('/api/:*', function () use ($Router) {
         $Router->intercept(new RequestId);

         yield $Router->route('users', function ($Request, $Response) {
            return $Response(body: 'Users');
         });
         yield $Router->route('users/:id<int>', function ($Request, $Response) {
            return $Response(body: 'User');
         }, [GET, PUT]);
      }, [GET, POST, PUT]);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) {
      $expected = implode("\n", [
         'warm',
         'Static [GET] /routes m=0 p=',
         'Body [GET] /health m=0 p=',
         'CatchAll [*] /* m=0 p=',
         'Static [GET,POST,PUT] /api/users m=1 p=',
         'Dynamic [GET,PUT] /api/users/:id<int> m=1 p=id:int',
      ]);

      // @ Assert 200 OK
      if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Expected 200 OK status';
      }

      // @ Assert the whole list, in registration order
      if (str_ends_with($response, "\r\n\r\n$expected") === false) {
         Vars::$labels = ['HTTP Response:', 'Expected body:'];
         dump($response, $expected);
         return 'The route list read inside the handler does not match the registered routes';
      }

      return true;
   }
);
