<?php

use Bootgly\API\Security\Authorization\Policy as PolicyContract;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Policy as PolicyGate;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$JWT = new JWT('bootgly-test-secret-32-bytes-long');
$token = $JWT->sign([
   'sub' => 'other-user',
   'exp' => time() + 60,
]);
$OwnedResource = (object) ['owner' => 'e2e-user'];
$OwnershipPolicy = new class extends PolicyContract {
   public function update (Identity $Identity, mixed $Resource = null): null|bool
   {
      return $Resource->owner === $Identity->id;
   }
};

return new Specification(
   description: 'It should deny a real HTTP request failing the Policy resource decision',

   request: function () use ($token) {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(new JWTGuard($JWT))),
      new Authorization(new Authorizing(new PolicyGate(
         Policy: $OwnershipPolicy,
         action: 'update',
         Resource: static function (object $Request) use ($OwnedResource): object {
            return $OwnedResource;
         }
      )))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 403 Forbidden')
         && str_contains($response, 'Forbidden')
         && str_contains($response, 'handler executed') === false
            ?: 'Authorization Policy middleware did not deny the resource decision';
   }
);