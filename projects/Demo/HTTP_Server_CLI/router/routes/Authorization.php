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
 * Authorization routes example.
 *
 * Enable it in router/router.index.php: add 'Authorization' to the manifest.
 *
 * Try:
 *   curl http://localhost:8082/authz/jwt/issue
 *   curl -H 'Authorization: Bearer <token>' http://localhost:8082/authz/scope
 *   curl -H 'Authorization: Bearer <token>' http://localhost:8082/authz/role
 *   curl -H 'Authorization: Bearer <token>' http://localhost:8082/authz/policy
 */

use Bootgly\API\Security\Authorization\Policy as PolicyContract;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Policy as PolicyGate;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Role;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Scope;


$JWT = new JWT('bootgly-demo-authorization-secret');
$JWTStrategy = new Authenticating(
   new JWTGuard($JWT)
);

$OwnedResource = (object) ['owner' => 'demo-user'];
$OwnershipPolicy = new class extends PolicyContract {
   public function update (Identity $Identity, mixed $Resource = null): null|bool
   {
      if (is_object($Resource) === false || property_exists($Resource, 'owner') === false) {
         return null;
      }

      return $Resource->owner === $Identity->id;
   }
};
$Identify = static function (Request $Request): string {
   $Identity = $Request->identity;

   return $Identity instanceof Identity ? $Identity->id : '';
};

return static function (Request $Request, Response $Response, Router $Router) use ($JWT, $JWTStrategy, $OwnedResource, $OwnershipPolicy, $Identify)
{
   yield $Router->route('/authz', static function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'examples' => [
            'jwt_issue' => 'curl http://localhost:8082/authz/jwt/issue',
            'scope' => "curl -H 'Authorization: Bearer <token>' http://localhost:8082/authz/scope",
            'role' => "curl -H 'Authorization: Bearer <token>' http://localhost:8082/authz/role",
            'policy' => "curl -H 'Authorization: Bearer <token>' http://localhost:8082/authz/policy",
         ],
      ]);
   }, GET);

   yield $Router->route('/authz/jwt/issue', static function (Request $Request, Response $Response) use ($JWT) {
      $token = $JWT->sign([
         'sub' => 'demo-user',
         'scope' => 'demo:read demo:write',
         'roles' => ['editor'],
         'exp' => time() + 3600,
      ]);

      return $Response->JSON->send([
         'token' => $token,
         'authorization' => "Bearer {$token}",
      ]);
   }, GET);

   yield $Router->route('/authz/scope', static function (Request $Request, Response $Response) use ($Identify) {
      return $Response->JSON->send([
         'authorized' => true,
         'gate' => 'Scope',
         'identity' => $Identify($Request),
      ]);
   }, GET, middlewares: [
      new Authentication($JWTStrategy),
      new Authorization(new Authorizing(new Scope('demo:read'))),
   ]);

   yield $Router->route('/authz/role', static function (Request $Request, Response $Response) use ($Identify) {
      return $Response->JSON->send([
         'authorized' => true,
         'gate' => 'Role',
         'identity' => $Identify($Request),
      ]);
   }, GET, middlewares: [
      new Authentication($JWTStrategy),
      new Authorization(new Authorizing(new Role('editor'))),
   ]);

   yield $Router->route('/authz/policy', static function (Request $Request, Response $Response) use ($Identify) {
      return $Response->JSON->send([
         'authorized' => true,
         'gate' => 'Policy',
         'identity' => $Identify($Request),
      ]);
   }, GET, middlewares: [
      new Authentication($JWTStrategy),
      new Authorization(new Authorizing(new PolicyGate(
         Policy: $OwnershipPolicy,
         action: 'update',
         Resource: static function (object $Request) use ($OwnedResource): object {
            return $OwnedResource;
         }
      ))),
   ]);

   yield $Router->route('/*', static function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
