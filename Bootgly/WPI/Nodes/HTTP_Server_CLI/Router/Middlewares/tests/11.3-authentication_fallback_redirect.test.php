<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;


return new Specification(
   description: 'It should let redirecting fallbacks keep their 3xx while others normalize to 401',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         $Response->Body->raw = 'passed';
         return $Response;
      };

      // @ Redirecting fallback keeps its 3xx + Location.
      [$Request, $Response] = $createMocks();
      $Middleware = new Authentication(
         new Authenticating(new SessionGuard),
         Fallback: function (object $Request, object $Response): object {
            return $Response(303, ['Location' => '/login']);
         }
      );
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'A 303 + Location fallback should survive untouched')
         ->expect($Result->code === 303 && $Result->Header->get('Location') === '/login')
         ->to->be(true)
         ->assert();

      // @ Non-redirect fallback is still normalized to 401.
      [$Request, $Response] = $createMocks();
      $Middleware = new Authentication(
         new Authenticating(new SessionGuard),
         Fallback: function (object $Request, object $Response): object {
            return $Response(200, [], 'custom body');
         }
      );
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'A non-redirect fallback should be re-marked 401')
         ->expect($Result->code === 401 && $Result->Body->raw === 'custom body')
         ->to->be(true)
         ->assert();

      // @ 3xx WITHOUT Location does not count as a redirect.
      [$Request, $Response] = $createMocks();
      $Middleware = new Authentication(
         new Authenticating(new SessionGuard),
         Fallback: function (object $Request, object $Response): object {
            return $Response(304);
         }
      );
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'A 3xx without Location should still normalize to 401')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();
   })
);
