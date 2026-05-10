<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Authentication routes example.
 *
 * Enable it in Demo-HTTP_Server_CLI.project.php with:
 *   request: require __DIR__ . '/router/HTTP_Server_CLI-authentication.SAPI.php',
 *
 * Try:
 *   curl http://localhost:8082/auth
 *   curl -H 'Authorization: Bearer demo-bearer-token' http://localhost:8082/auth/bearer
 *   curl http://localhost:8082/auth/jwt/issue
 *   curl -H 'Authorization: Bearer <token>' http://localhost:8082/auth/jwt
 *   curl -u demo:secret http://localhost:8082/auth/basic
 */

use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic as BasicGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;


$JWT = new JWT('bootgly-demo-authentication-secret');

$Bearer = new Authenticating(
   new Bearer(function (string $token): bool {
      return $token === 'demo-bearer-token';
   })
);

$JWTStrategy = new Authenticating(
   new JWTGuard($JWT)
);

$Basic = new Authenticating(
   new BasicGuard(function (string $username, string $password): bool {
      return $username === 'demo' && $password === 'secret';
   })
);

return static function (Request $Request, Response $Response, Router $Router) use ($Bearer, $Basic, $JWT, $JWTStrategy)
{
   yield $Router->route('/auth', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'examples' => [
            'bearer' => "curl -H 'Authorization: Bearer demo-bearer-token' http://localhost:8082/auth/bearer",
            'jwt_issue' => 'curl http://localhost:8082/auth/jwt/issue',
            'jwt' => "curl -H 'Authorization: Bearer <token>' http://localhost:8082/auth/jwt",
            'basic' => 'curl -u demo:secret http://localhost:8082/auth/basic',
         ],
      ]);
   }, GET);

   yield $Router->route('/auth/bearer', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'authorized' => true,
         'guard' => 'Bearer',
      ]);
   }, GET, middlewares: [new Authentication($Bearer)]);

   yield $Router->route('/auth/jwt/issue', function (Request $Request, Response $Response) use ($JWT) {
      $token = $JWT->sign([
         'sub' => 'demo-user',
         'scope' => 'demo:read',
         'exp' => time() + 3600,
      ]);

      return $Response->JSON->send([
         'token' => $token,
         'authorization' => "Bearer {$token}",
      ]);
   }, GET);

   yield $Router->route('/auth/jwt', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'authorized' => true,
         'guard' => 'JWT',
      ]);
   }, GET, middlewares: [new Authentication($JWTStrategy)]);

   yield $Router->route('/auth/basic', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'authorized' => true,
         'guard' => 'Basic',
      ]);
   }, GET, middlewares: [new Authentication($Basic)]);

   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};