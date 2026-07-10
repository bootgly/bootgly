<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should answer health probes before the middleware pipeline (K8s probe-proof)',

   request: function () {
      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   // ! A middleware that rejects every request — a probe must never see it
   middlewares: [
      new class implements Middleware {
         public function process (object $Request, object $Response, Closure $next): object
         {
            /** @var Response $Response */
            return $Response(code: 401, body: 'blocked by middleware');
         }
      }
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler');
   },

   test: function ($response) {
      // @ Assert — the guard dispatches before SAPI::$Middlewares->process
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, '"status":"ok"') === false
         || str_contains($response, '401')
         || str_contains($response, 'blocked by middleware')
      ) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Middleware broke the health probe';
      }

      return true;
   }
);
