<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic as BasicGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Remember;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;


/**
 * The deferring mode of `Authentication` — its synchronous half: the
 * constructor arguments, the pre-check that answers a request without
 * credentials at once (no `defer()`), `Guard::detect()` of every built-in
 * guard, the unchanged default mode, and `seal()`. What runs inside the
 * deferral is pinned by the server suites (E2E_Deferred 10.x).
 */
return new Test(
   description: 'It should defer authentication only for requests that may carry credentials',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      // ! A Response double that records defer() — runs nothing
      $wrap = static function (object $Response): object {
         return new class ($Response) {
            public object $Header;
            public object $Body;
            public int $code = 200;
            public bool $deferred = false;
            public null|object $Request;
            public int $defers = 0;
            private object $Inner;

            public function __construct (object $Inner)
            {
               $this->Inner = $Inner;
               $this->Header = $Inner->Header;
               $this->Body = $Inner->Body;
               $this->Request = $Inner->Request;
            }
            /** @param array<string,string>|null $headers */
            public function __invoke (int $code = 200, array|null $headers = null, string $body = ''): static
            {
               ($this->Inner)($code, $headers, $body);
               $this->code = $code;

               return $this;
            }
            public function authenticate (object $Method): static
            {
               $this->Inner->authenticate($Method);
               $this->code = 401;

               return $this;
            }
            public function code (int $code): static
            {
               $this->code = $code;

               return $this;
            }
            public function defer (Closure $Work, int|float $timeout = 0): static
            {
               $this->defers++;
               $this->deferred = true;

               return $this;
            }
         };
      };
      $Cookies = static fn (array $cookies): object => new class ($cookies) {
         /** @var array<string,string> */
         public array $cookies;
         /** @param array<string,string> $cookies */
         public function __construct (array $cookies)
         {
            $this->cookies = $cookies;
         }
         public function get (string $name): string
         {
            return $this->cookies[$name] ?? '';
         }
      };
      $Session = static fn (array $data): object => new class ($data) {
         /** @var array<string,mixed> */
         public array $data;
         /** @param array<string,mixed> $data */
         public function __construct (array $data)
         {
            $this->data = $data;
         }
         public function check (string $name): bool
         {
            return array_key_exists($name, $this->data);
         }
         public function get (string $name, mixed $default = null): mixed
         {
            return $this->data[$name] ?? $default;
         }
      };
      $resolved = 0;
      $Bearer = new Bearer(
         Resolver: static function (string $token) use (&$resolved): mixed {
            $resolved++;

            return $token === 'good' ? 'alice' : null;
         },
         metadata: 'https://rs.example/.well-known/oauth-protected-resource'
      );
      $next = static function (object $Request, object $Response): object {
         $Response->Body->raw = 'passed';

         return $Response;
      };

      // @ Constructor
      $Default = new Authentication(new Authenticating($Bearer));
      $Mode = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 0.25);
      $refused = [];
      foreach (['negative' => -1, 'NAN' => NAN, 'INF' => INF] as $name => $timeout) {
         try {
            new Authentication(new Authenticating($Bearer), deferred: true, timeout: $timeout);
            $refused[$name] = false;
         }
         catch (InvalidArgumentException) {
            $refused[$name] = true;
         }
      }
      $Unbounded = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 0);

      yield new Assertion(description: 'The mode is off and bounded at 5 s by default; deferred and timeout are kept')
         ->expect([$Default->deferred, $Default->timeout, $Mode->deferred, $Mode->timeout, $Unbounded->timeout])
         ->to->be([false, 5, true, 0.25, 0])
         ->assert();

      yield new Assertion(description: 'A negative or non-finite timeout is refused at construction')
         ->expect($refused)
         ->to->be(['negative' => true, 'NAN' => true, 'INF' => true])
         ->assert();

      // @ Default mode: the guards run synchronously, defer() is never called
      [$Request, $Response] = $createMocks(requestProps: ['token' => 'good']);
      $Response = $wrap($Response);
      $Result = $Default->process($Request, $Response, $next);

      yield new Assertion(description: 'The default mode authenticates synchronously without deferring')
         ->expect([$Result->Body->raw, $Response->defers, $Request->identity])
         ->to->be(['passed', 0, 'alice'])
         ->assert();

      // @ Mode, no credentials: the invitation 401 at once, no defer()
      $resolved = 0;
      [$Request, $Response] = $createMocks();
      $Response = $wrap($Response);
      $called = false;
      $Result = $Mode->process($Request, $Response, static function (object $Request, object $Response) use (&$called): object {
         $called = true;

         return $Response;
      });

      yield new Assertion(description: 'Without credentials the mode answers the invitation challenge without deferring')
         ->expect([
            $Result === $Response,
            $Result->code,
            $Result->Header->get('WWW-Authenticate'),
            $Response->defers,
            $called,
            $resolved
         ])
         ->to->be([
            true,
            401,
            'Bearer realm="Protected area", resource_metadata="https://rs.example/.well-known/oauth-protected-resource"',
            0,
            false,
            0
         ])
         ->assert();

      // @ Same request, default mode: the same answer (byte-identical challenge)
      [$Request, $Response] = $createMocks();
      $Result = $Default->process($Request, $Response, $next);

      yield new Assertion(description: 'The invitation of the mode is the synchronous mode\'s invitation')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([401, 'Bearer realm="Protected area", resource_metadata="https://rs.example/.well-known/oauth-protected-resource"'])
         ->assert();

      // @ Mode, credentials present: defer() once, nothing resolved synchronously
      $resolved = 0;
      [$Request, $Response] = $createMocks(requestProps: ['token' => 'good']);
      $Response = $wrap($Response);
      $called = false;
      $Result = $Mode->process($Request, $Response, static function (object $Request, object $Response) use (&$called): object {
         $called = true;

         return $Response;
      });

      yield new Assertion(description: 'With credentials the mode defers once and resolves nothing on the worker')
         ->expect([$Result === $Response, $Response->defers, $called, $resolved, $Request->identity ?? null])
         ->to->be([true, 1, false, 0, null])
         ->assert();

      // @ Mode, no credentials, Fallback: adopted synchronously as ever
      $Redirecting = new Authentication(
         Authenticating: new Authenticating($Bearer),
         Fallback: static function (object $Request, object $Response): object {
            $Response->code = 302;
            $Response->Header->set('Location', '/login');

            return $Response;
         },
         deferred: true
      );
      [$Request, $Response] = $createMocks();
      $Response = $wrap($Response);
      $Result = $Redirecting->process($Request, $Response, $next);

      yield new Assertion(description: 'Without credentials a redirecting Fallback answers at once')
         ->expect([$Result->code, $Result->Header->get('Location'), $Response->defers])
         ->to->be([302, '/login', 0])
         ->assert();

      // @ detect() — Bearer, JWT (through Bearer), Basic
      $JWTGuard = new JWTGuard(new JWT('bootgly-test-secret-32-bytes-long'));
      $Basic = new BasicGuard(static fn (): bool => true);
      $detections = [];
      foreach ([
         'bearer:none' => [$Bearer, []],
         'bearer:header' => [$Bearer, ['Authorization' => 'Bearer abc']],
         'bearer:empty' => [$Bearer, ['Authorization' => 'Bearer ']],
         'bearer:basic' => [$Bearer, ['Authorization' => 'Basic YTpi']],
         'jwt:header' => [$JWTGuard, ['Authorization' => 'Bearer x.y.z']],
         'jwt:none' => [$JWTGuard, []],
      ] as $case => [$Guard, $headers]) {
         [$Request] = $createMocks(requestHeaders: $headers);
         $detections[$case] = $Guard->detect($Request);
      }
      [$Request] = $createMocks(requestProps: ['token' => 'resolved']);
      $detections['bearer:token'] = $Bearer->detect($Request);
      foreach ([
         'basic:valid' => 'Basic ' . base64_encode('alice:secret'),
         'basic:malformed' => 'Basic !!!',
         'basic:bearer' => 'Bearer abc',
      ] as $case => $authorization) {
         $Request = new Request;
         $Request->Header->adopt(['authorization' => $authorization]);
         $detections[$case] = $Basic->detect($Request);
      }
      $Request = new Request;
      $detections['basic:none'] = $Basic->detect($Request);
      [$Request] = $createMocks();
      $detections['basic:parserless'] = $Basic->detect($Request);

      yield new Assertion(description: 'Bearer, JWT and Basic detect credentials from the request head only')
         ->expect($detections)
         ->to->be([
            'bearer:none' => false,
            'bearer:header' => true,
            'bearer:empty' => false,
            'bearer:basic' => false,
            'jwt:header' => true,
            'jwt:none' => false,
            'bearer:token' => true,
            'basic:valid' => true,
            'basic:malformed' => false,
            'basic:bearer' => false,
            'basic:none' => false,
            'basic:parserless' => false,
         ])
         ->assert();

      // @ detect() — Session, Remember, and the default of a custom guard
      $SessionGuard = new SessionGuard('user');
      $Remember = new Remember(new Trust(new SQL(['driver' => 'sqlite', 'database' => ':memory:'])), 'user');
      $Custom = new class extends Guard {
         public function authenticate (object $Request): bool
         {
            return false;
         }
         public function challenge (object $Response): object
         {
            return $Response;
         }
      };
      $detections = [];
      [$Request] = $createMocks(requestProps: ['Cookies' => $Cookies([])]);
      $detections['session:none'] = $SessionGuard->detect($Request);
      $detections['remember:none'] = $Remember->detect($Request);
      $detections['custom'] = $Custom->detect($Request);
      [$Request] = $createMocks(requestProps: ['Cookies' => $Cookies(['PHPSID' => 'abc', 'remember' => 'sel.val'])]);
      $detections['session:cookie'] = $SessionGuard->detect($Request);
      $detections['remember:cookie'] = $Remember->detect($Request);
      [$Request] = $createMocks(requestProps: [
         'sessioned' => true,
         'Session' => $Session(['user' => 7]),
         'Cookies' => $Cookies([])
      ]);
      $detections['session:identity'] = $SessionGuard->detect($Request);
      [$Request] = $createMocks(requestProps: [
         'sessioned' => true,
         'Session' => $Session(['csrf' => 'token']),
         'Cookies' => $Cookies(['PHPSID' => 'abc'])
      ]);
      $detections['session:guest'] = $SessionGuard->detect($Request);
      [$Request] = $createMocks(requestProps: [
         'sessioned' => true,
         'Session' => $Session(['user' => false]),
         'Cookies' => $Cookies(['PHPSID' => 'abc'])
      ]);
      $detections['session:false'] = $SessionGuard->detect($Request);

      yield new Assertion(description: 'Session and Remember detect from cookies or a built Session; a custom guard answers "maybe"')
         ->expect($detections)
         ->to->be([
            'session:none' => false,
            'remember:none' => false,
            'custom' => true,
            'session:cookie' => true,
            'remember:cookie' => true,
            'session:identity' => true,
            'session:guest' => false,
            'session:false' => false,
         ])
         ->assert();

      // @ A sessioned guest (behind CSRF) is denied at once, without deferring
      $Guest = new Authentication(new Authenticating($SessionGuard, $Remember), deferred: true);
      [$Request, $Response] = $createMocks(requestProps: [
         'sessioned' => true,
         'Session' => $Session(['csrf' => 'token']),
         'Cookies' => $Cookies(['PHPSID' => 'abc'])
      ]);
      $Response = $wrap($Response);
      $Result = $Guest->process($Request, $Response, $next);

      yield new Assertion(description: 'A sessioned guest without a remember cookie is denied without deferring')
         ->expect([$Result->code, $Response->defers])
         ->to->be([401, 0])
         ->assert();

      // @ seal(): the cookies a deferred guard chain emitted reach any answer, once
      $Snapshot = new Request;
      $Emitted = new ReflectionProperty(Authentication::class, 'Emitted');
      $Map = new WeakMap;
      $Map[$Snapshot] = ['remember=rotated; Path=/', 'PHPSID=fresh; Path=/'];
      $Emitted->setValue(null, $Map);
      $Errored = new Response(code: 500);
      $Mode->seal($Snapshot, $Errored);
      $Mode->seal($Snapshot, $Errored);
      $Default->seal($Snapshot, $Errored);
      $Clone = new Response;
      $Clone->Header->queue('Set-Cookie', 'remember=rotated; Path=/');
      $Mode->seal($Snapshot, $Clone);
      $Untouched = new Response;
      $Mode->seal(new Request, $Untouched);
      $Emitted->setValue(null, null);

      yield new Assertion(description: 'seal() queues each recorded cookie once on a fresh answer and skips what it already carries')
         ->expect([$Errored->Header->queued, array_values($Clone->Header->queued), $Untouched->Header->queued])
         ->to->be([
            ['Set-Cookie: remember=rotated; Path=/', 'Set-Cookie: PHPSID=fresh; Path=/'],
            ['Set-Cookie: remember=rotated; Path=/', 'Set-Cookie: PHPSID=fresh; Path=/'],
            []
         ])
         ->assert();
   })
);
