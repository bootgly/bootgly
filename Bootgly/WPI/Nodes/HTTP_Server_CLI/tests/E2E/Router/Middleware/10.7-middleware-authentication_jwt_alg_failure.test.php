<?php

use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$secret = 'bootgly-test-secret-32-bytes-long';
$JWT = new JWT($secret);
$pack = static function (string $value): string {
   return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};
$header = $pack(json_encode(['typ' => 'JWT', 'alg' => 'none'], JSON_THROW_ON_ERROR));
$payload = $pack(json_encode(['sub' => 'e2e-user', 'exp' => time() + 60], JSON_THROW_ON_ERROR));
$data = "{$header}.{$payload}";
$signature = $pack(hash_hmac('sha256', $data, $secret, true));
$token = "{$data}.{$signature}";

return new Specification(
   description: 'It should reject a JWT with mismatched alg using authentication middleware',

   request: function () use ($token) {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(new JWTGuard($JWT)))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 401 Unauthorized')
         && str_contains($response, 'WWW-Authenticate: Bearer realm="Protected area", error="invalid_token"')
         && str_contains($response, 'handler executed') === false
            ?: 'JWT middleware did not reject mismatched alg token';
   }
);
