<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Policies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic as BasicGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;


return new Specification(
   description: 'It should authorize requests with Session, Bearer, JWT and Basic guards',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         $Response->Body->raw = 'passed';
         return $Response;
      };

      // @ Empty authentication strategy fails early.
      $failed = false;
      try {
         new Authentication(new Authenticating);
      }
      catch (InvalidArgumentException) {
         $failed = true;
      }

      yield new Assertion(description: 'Empty authentication strategy should fail early')
         ->expect($failed)
         ->to->be(true)
         ->assert();

      // @ Bearer opaque token passes through.
      [$Request, $Response] = $createMocks(requestProps: ['token' => 'secret-token']);
      $Middleware = new Authentication(new Authenticating(
         new Bearer(function (string $token): bool {
            return $token === 'secret-token';
         })
      ));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Valid Bearer token should pass through')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      // @ Bearer opaque token rejects and challenges.
      $called = false;
      [$Request, $Response] = $createMocks(requestProps: ['token' => 'bad-token']);
      $Middleware = new Authentication(new Authenticating(
         new Bearer(function (): bool {
            return false;
         })
      ));
      $Result = $Middleware->process(
         $Request,
         $Response,
         function (object $Request, object $Response) use (&$called): object {
            $called = true;
            return $Response;
         }
      );

      yield new Assertion(description: 'Invalid Bearer token should not call handler')
         ->expect($called)
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'Invalid Bearer token should return 401')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();

      yield new Assertion(description: 'Invalid Bearer token should emit challenge')
         ->expect($Result->Header->get('WWW-Authenticate'))
         ->to->be('Bearer realm="Protected area", error="invalid_token"')
         ->assert();

      // @ Custom fallback keeps unauthorized status.
      [$Request, $Response] = $createMocks(requestProps: ['token' => 'bad-token']);
      $Middleware = new Authentication(
         Authenticating: new Authenticating(new Bearer(function (): bool {
            return false;
         })),
         Fallback: function (object $Request, object $Response): object {
            $Response->code = 200;
            $Response->Body->raw = 'custom unauthorized body';
            return $Response;
         }
      );
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Custom fallback should stay unauthorized')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();

      yield new Assertion(description: 'Custom fallback should keep custom body')
         ->expect($Result->Body->raw)
         ->to->be('custom unauthorized body')
         ->assert();

      // @ JWT passes through and exposes claims/identity.
      $JWT = new JWT('bootgly-test-secret-32-bytes-long');
      $token = $JWT->sign([
         'sub' => 'user-42',
         'scope' => 'demo:read demo:write',
         'exp' => time() + 60,
      ]);
      [$Request, $Response] = $createMocks(requestProps: ['token' => $token]);
      $Middleware = new Authentication(new Authenticating(new JWTGuard($JWT)));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Valid JWT should pass through')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      yield new Assertion(description: 'JWT guard should expose identity')
         ->expect($Request->identity instanceof Identity)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'JWT guard should expose claims')
         ->expect($Request->claims['sub'])
         ->to->be('user-42')
         ->assert();

      yield new Assertion(description: 'JWT guard should expose token headers')
         ->expect($Request->tokenHeaders['alg'])
         ->to->be('HS256')
         ->assert();

      yield new Assertion(description: 'JWT guard should expose identity scopes')
         ->expect($Request->identity->check('demo:read'))
         ->to->be(true)
         ->assert();

      $token = $JWT->sign([
         'sub' => 'user-43',
         'scp' => ['demo:delete'],
         'exp' => time() + 60,
      ]);
      [$Request, $Response] = $createMocks(requestProps: ['token' => $token]);
      $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'JWT scp claim should expose identity scopes')
         ->expect($Request->identity->check('demo:delete'))
         ->to->be(true)
         ->assert();

      // @ JWT claim policies pass and fail closed.
      $Policies = new Policies(
         issuers: 'https://issuer.bootgly.dev',
         audiences: 'api://bootgly-demo',
         subject: true
      );
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => 'api://bootgly-demo',
         'sub' => 'user-44',
         'exp' => time() + 60,
      ]);
      [$Request, $Response] = $createMocks(requestProps: ['token' => $token]);
      $PolicyMiddleware = new Authentication(new Authenticating(new JWTGuard($JWT, Policies: $Policies)));
      $Result = $PolicyMiddleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'JWT policy guard should pass valid claims')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      yield new Assertion(description: 'JWT policy guard should expose identity')
         ->expect($Request->identity->id)
         ->to->be('user-44')
         ->assert();

      $called = false;
      $token = $JWT->sign([
         'iss' => 'https://issuer.bootgly.dev',
         'aud' => 'api://other',
         'sub' => 'user-44',
         'exp' => time() + 60,
      ]);
      [$Request, $Response] = $createMocks(requestProps: ['token' => $token]);
      $Result = $PolicyMiddleware->process(
         $Request,
         $Response,
         function (object $Request, object $Response) use (&$called): object {
            $called = true;
            return $Response;
         }
      );

      yield new Assertion(description: 'JWT policy guard should reject invalid audience')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();

      yield new Assertion(description: 'JWT policy guard should not call handler on invalid audience')
         ->expect($called)
         ->to->be(false)
         ->assert();

      // @ Request authentication metadata resets across reuse boundaries.
      $Original = new Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
      $Original->identity = 'user-42';
      $Original->claims = ['sub' => 'user-42'];
      $Original->tokenHeaders = ['alg' => 'HS256'];
      $Clone = clone $Original;

      yield new Assertion(description: 'Request clone should reset identity')
         ->expect($Clone->identity)
         ->to->be(Type::Null)
         ->assert();

      yield new Assertion(description: 'Request clone should reset claims')
         ->expect($Clone->claims)
         ->to->be([])
         ->assert();

      yield new Assertion(description: 'Request clone should reset token headers')
         ->expect($Clone->tokenHeaders)
         ->to->be([])
         ->assert();

      $Original->identity = 'user-42';
      $Original->claims = ['sub' => 'user-42'];
      $Original->tokenHeaders = ['alg' => 'HS256'];
      $Original->reboot();

      yield new Assertion(description: 'Request reboot should reset identity')
         ->expect($Original->identity)
         ->to->be(Type::Null)
         ->assert();

      yield new Assertion(description: 'Request reboot should reset claims')
         ->expect($Original->claims)
         ->to->be([])
         ->assert();

      yield new Assertion(description: 'Request reboot should reset token headers')
         ->expect($Original->tokenHeaders)
         ->to->be([])
         ->assert();

      // @ Session guard fails closed when the session contract is absent.
      [$Request, $Response] = $createMocks();
      $Middleware = new Authentication(new Authenticating(new SessionGuard));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Missing Session object should be unauthorized')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();

      [$Request, $Response] = $createMocks([], ['Session' => 'broken']);
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Non-object Session should be unauthorized')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();

      [$Request, $Response] = $createMocks([], [
         'Session' => new class {
            public function check (string $name): bool
            {
               return false;
            }
         }
      ]);
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Session missing identity key should be unauthorized')
         ->expect($Result->code)
         ->to->be(401)
         ->assert();

      // @ Session guard passes through.
      [$Request, $Response] = $createMocks();
      $Request->Session = new class {
         /** @var array<string,mixed> */
         private array $data = ['identity' => 'session-user'];

         public function check (string $name): bool
         {
            return isset($this->data[$name]);
         }

         public function get (string $name): mixed
         {
            return $this->data[$name] ?? null;
         }
      };
      $Middleware = new Authentication(new Authenticating(new SessionGuard));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Session identity should pass through')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();

      yield new Assertion(description: 'Session guard should expose identity')
         ->expect($Request->identity)
         ->to->be('session-user')
         ->assert();

      // @ Basic compatibility guard passes through.
      [, $Response] = $createMocks();
      $Request = new class extends Bootgly\WPI\Nodes\HTTP_Server_CLI\Request {
         public function authenticate (): Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic
         {
            return new Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic('admin', 'secret');
         }
      };
      $Middleware = new Authentication(new Authenticating(
         new BasicGuard(function (string $username, string $password): bool {
            return $username === 'admin' && $password === 'secret';
         })
      ));
      $Result = $Middleware->process($Request, $Response, $passthrough);

      yield new Assertion(description: 'Valid Basic credentials should pass through')
         ->expect($Result->Body->raw)
         ->to->be('passed')
         ->assert();
   })
);