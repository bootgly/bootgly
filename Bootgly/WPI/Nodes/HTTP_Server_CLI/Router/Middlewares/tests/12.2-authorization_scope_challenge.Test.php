<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\Identity;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Scope;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;


/**
 * Scope denial with an RFC 6750 §3.1 `insufficient_scope` challenge.
 *
 *   1. With a Bearer, a denial answers 403 + `WWW-Authenticate: Bearer
 *      realm, error="insufficient_scope", scope="<required, space-separated>"`
 *      and the Bearer's `resource_metadata` when set.
 *   2. Without a Bearer, the denial stays a bare 403 Forbidden.
 *   3. Challenged scopes must be RFC 6749 scope-tokens (refused otherwise).
 *   4. With a Bearer, a request with no identity at all gets the Bearer's
 *      401 challenge (as the authentication would), never insufficient_scope.
 *      The identity is read from the Request the Response answers, so the
 *      gate keeps no per-request state: interleaved requests (a resolve()
 *      that suspends) each get their own answer.
 */

return new Test(
   description: 'Scope denials should answer insufficient_scope challenges when given a Bearer',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $called = false;
      $handler = function (object $Request, object $Response) use (&$called): object {
         $called = true;
         $Response->Body->raw = 'passed';
         return $Response;
      };
      $deny = static function (): bool {
         return false;
      };
      $metadata = 'https://mcp.example.com/.well-known/oauth-protected-resource';
      $refuse = static function (Closure $configure): bool {
         try {
            $configure();
         }
         catch (InvalidArgumentException) {
            return true;
         }

         return false;
      };

      // @ 1: Bearer + metadata → 403 + full insufficient_scope challenge.
      $Bearer = new Bearer($deny, realm: 'mcp', metadata: $metadata);
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['mcp:read'])
      ]);
      $Middleware = new Authorization(new Authorizing(
         new Scope(['mcp:read', 'mcp:write'], Bearer: $Bearer)
      ));
      $Result = $Middleware->process($Request, $Response, $handler);

      yield new Assertion(description: 'Challenged denial does not call the handler')
         ->expect($called)
         ->to->be(false)
         ->assert();
      yield new Assertion(description: 'Challenged denial answers 403')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();
      yield new Assertion(description: 'Challenged denial keeps the Forbidden body')
         ->expect($Result->Body->raw)
         ->to->be('Forbidden')
         ->assert();
      yield new Assertion(description: 'Challenged denial carries the insufficient_scope challenge')
         ->expect($Result->Header->get('WWW-Authenticate'))
         ->to->be("Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"mcp:read mcp:write\", resource_metadata=\"{$metadata}\"")
         ->assert();

      // @ 2: Bearer without metadata → no resource_metadata; any-of gate lists all scopes.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: [])
      ]);
      $Middleware = new Authorization(new Authorizing(
         new Scope(['files:read', 'files:admin'], all: false, Bearer: new Bearer($deny))
      ));
      $Result = $Middleware->process($Request, $Response, $handler);

      yield new Assertion(description: 'Challenge without metadata omits resource_metadata')
         ->expect($Result->Header->get('WWW-Authenticate'))
         ->to->be('Bearer realm="Protected area", error="insufficient_scope", scope="files:read files:admin"')
         ->assert();

      // @ 3: The Bearer's own invalid_token error/description/scope never leak into the 403.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: [])
      ]);
      $Middleware = new Authorization(new Authorizing(
         new Scope('reports:read', Bearer: new Bearer(
            $deny,
            realm: 'API',
            error: 'invalid_token',
            description: 'expired',
            URI: 'https://example.com/errors',
            scope: 'other:scope'
         ))
      ));
      $Result = $Middleware->process($Request, $Response, $handler);

      yield new Assertion(description: '403 challenge uses only realm, insufficient_scope and the required scope')
         ->expect($Result->Header->get('WWW-Authenticate'))
         ->to->be('Bearer realm="API", error="insufficient_scope", scope="reports:read"')
         ->assert();

      // @ 4: No Bearer → bare 403, unchanged.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['mcp:read'])
      ]);
      $Scope = new Scope('mcp:write');
      $Middleware = new Authorization(new Authorizing($Scope));
      $Result = $Middleware->process($Request, $Response, $handler);

      yield new Assertion(description: 'Scope Bearer defaults to null')
         ->expect($Scope->Bearer)
         ->to->be(null)
         ->assert();
      yield new Assertion(description: 'Bare denial answers 403')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();
      yield new Assertion(description: 'Bare denial keeps the Forbidden body')
         ->expect($Result->Body->raw)
         ->to->be('Forbidden')
         ->assert();
      yield new Assertion(description: 'Bare denial carries no WWW-Authenticate')
         ->expect($Result->Header->get('WWW-Authenticate'))
         ->to->be(null)
         ->assert();

      // @ 5: Granted scope with a Bearer passes through untouched.
      $called = false;
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['mcp:write'])
      ]);
      $Middleware = new Authorization(new Authorizing(new Scope('mcp:write', Bearer: $Bearer)));
      $Result = $Middleware->process($Request, $Response, $handler);

      yield new Assertion(description: 'Granted scope with a Bearer reaches the handler')
         ->expect($called)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'Granted scope with a Bearer emits no challenge')
         ->expect($Result->Header->get('WWW-Authenticate'))
         ->to->be(null)
         ->assert();

      // @ 6: No identity at all with a Bearer → the Bearer's 401 challenge,
      //      exactly what the authentication answers for the same request.
      $called = false;
      $Gate = new Scope('mcp:write', Bearer: $Bearer);
      $Middleware = new Authorization(new Authorizing($Gate));
      [$Request, $Response] = $createMocks();
      $Result = $Middleware->process($Request, $Response, $handler);
      [$Request, $Response] = $createMocks();
      $Expected = new Authentication(new Authenticating($Bearer))->process($Request, $Response, $handler);
      $unauthorized = "Bearer realm=\"mcp\", error=\"invalid_token\", resource_metadata=\"{$metadata}\"";

      yield new Assertion(description: 'No identity with a Bearer does not call the handler')
         ->expect($called)
         ->to->be(false)
         ->assert();
      yield new Assertion(description: 'No identity with a Bearer answers the 401 challenge')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([401, $unauthorized])
         ->assert();
      yield new Assertion(description: 'No identity with a Bearer answers what the authentication answers')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate'), $Result->Body->raw])
         ->to->be([$Expected->code, $Expected->Header->get('WWW-Authenticate'), $Expected->Body->raw])
         ->assert();

      // @ 6.1: An explicit null identity is no identity either.
      [$Request, $Response] = $createMocks(requestProps: ['identity' => null]);
      $Result = $Middleware->process($Request, $Response, $handler);
      yield new Assertion(description: 'A null identity with a Bearer answers the 401 challenge')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([401, $unauthorized])
         ->assert();

      // @ 6.2: The flag never leaks: the next request, with an identity
      //        lacking the scope, is a 403 insufficient_scope again.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['mcp:read'])
      ]);
      $Result = $Middleware->process($Request, $Response, $handler);
      yield new Assertion(description: 'An identity after an anonymous denial answers 403 insufficient_scope')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([403, "Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"mcp:write\", resource_metadata=\"{$metadata}\""])
         ->assert();

      // @ 6.3: A non-Identity identity (an opaque resolver result) is an
      //        authenticated client without scopes: 403 insufficient_scope.
      [$Request, $Response] = $createMocks(requestProps: ['identity' => 'opaque-token']);
      $Result = $Middleware->process($Request, $Response, $handler);
      yield new Assertion(description: 'An opaque identity with a Bearer answers 403 insufficient_scope')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([403, "Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"mcp:write\", resource_metadata=\"{$metadata}\""])
         ->assert();

      // @ 6.4: Without a Bearer, no identity stays a bare 403.
      [$Request, $Response] = $createMocks();
      $Result = new Authorization(new Authorizing(new Scope('mcp:write')))->process($Request, $Response, $handler);
      yield new Assertion(description: 'No identity without a Bearer answers a bare 403')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate'), $Result->Body->raw])
         ->to->be([403, null, 'Forbidden'])
         ->assert();

      // @ 6.5: deny() reads the identity of the Request the Response answers,
      //        not anything a previous authorize() left behind.
      $Gate = new Scope('mcp:write', Bearer: $Bearer);
      [$Anonymous, $AnonymousResponse] = $createMocks();
      [$Weak] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['mcp:read'])
      ]);
      $Gate->authorize($Anonymous);
      $Gate->authorize($Weak);
      $Result = $Gate->deny($AnonymousResponse);
      yield new Assertion(description: 'deny() after another request\'s authorize() still answers its own 401')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([401, $unauthorized])
         ->assert();

      [, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: ['mcp:read'])
      ]);
      $Result = new Scope('mcp:write', Bearer: $Bearer)->deny($Response);
      yield new Assertion(description: 'deny() without a prior authorize() answers 403 for an identified Request')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();

      [, $Response] = $createMocks();
      $Response->Request = null;
      $Result = new Scope('mcp:write', Bearer: $Bearer)->deny($Response);
      yield new Assertion(description: 'deny() on a response without a Request answers 403 insufficient_scope')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([403, "Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"mcp:write\", resource_metadata=\"{$metadata}\""])
         ->assert();

      // @ 6.6: Interleaved requests — a gate whose resolve() suspends (an
      //        async identity lookup) answers each request its own challenge.
      $Async = new class ('mcp:write', true, $Bearer) extends Scope {
         protected function resolve (object $Request): null|Identity
         {
            Fiber::suspend();

            return parent::resolve($Request);
         }
      };
      $Middleware = new Authorization(new Authorizing($Async));
      $answers = [];
      $Fibers = [];
      foreach ([
         'anonymous' => $createMocks(),
         'weak' => $createMocks(requestProps: [
            'identity' => new Identity(id: 'user-42', scopes: ['mcp:read'])
         ]),
      ] as $name => [$Request, $Response]) {
         $Fibers[$name] = new Fiber(function () use ($Middleware, $Request, $Response, $handler, $name, &$answers): void {
            $Result = $Middleware->process($Request, $Response, $handler);
            $answers[$name] = [$Result->code, $Result->Header->get('WWW-Authenticate')];
         });
      }
      foreach ($Fibers as $Fiber) {
         $Fiber->start();
      }
      foreach ($Fibers as $Fiber) {
         $Fiber->resume();
      }
      yield new Assertion(description: 'Interleaved requests each get their own challenge (401 anonymous, 403 insufficient_scope)')
         ->expect($answers)
         ->to->be([
            'anonymous' => [401, $unauthorized],
            'weak' => [403, "Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"mcp:write\", resource_metadata=\"{$metadata}\""],
         ])
         ->assert();

      // @ 7: Custom Authorization fallback owns the response: no challenge is added.
      [$Request, $Response] = $createMocks(requestProps: [
         'identity' => new Identity(id: 'user-42', scopes: [])
      ]);
      $Middleware = new Authorization(
         Authorizing: new Authorizing(new Scope('mcp:write', Bearer: $Bearer)),
         Fallback: function (object $Request, object $Response): object {
            $Response->Body->raw = 'custom';
            return $Response;
         }
      );
      $Result = $Middleware->process($Request, $Response, $handler);

      yield new Assertion(description: 'Custom fallback keeps 403 without a challenge')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([403, null])
         ->assert();

      // @ 8: Challenged scopes must be RFC 6749 scope-tokens.
      $tokens = [
         'space' => 'mcp read',
         'double quote' => 'mcp"read',
         'backslash' => 'mcp\\read',
         'CR' => "mcp\rread",
         'LF' => "mcp\nread",
         'NUL' => "mcp\0read",
         'non-ASCII' => "mcp\xC3\xA9",
         'trailing LF' => "mcp:read\n",
      ];
      foreach ($tokens as $label => $scope) {
         yield new Assertion(description: "Scope with a Bearer refuses a {$label} in a scope")
            ->expect($refuse(static function () use ($scope, $Bearer): void {
               new Scope(['mcp:read', $scope], Bearer: $Bearer);
            }))
            ->to->be(true)
            ->assert();
      }
      yield new Assertion(description: 'Scope without a Bearer keeps accepting any non-empty scope')
         ->expect($refuse(static function (): void {
            new Scope('mcp read');
         }))
         ->to->be(false)
         ->assert();
      yield new Assertion(description: 'Scope with a Bearer accepts URI-like scope-tokens')
         ->expect($refuse(static function () use ($Bearer): void {
            new Scope(['https://api.example.com/files.read', 'a!#[]~'], Bearer: $Bearer);
         }))
         ->to->be(false)
         ->assert();

      // @ 9: Bearer::forbid() — direct use writes the 403 challenge.
      [, $Response] = $createMocks();
      $Result = $Bearer->forbid($Response, ['a:b', 'c']);
      yield new Assertion(description: 'forbid() writes 403 + insufficient_scope challenge')
         ->expect([$Result->code, $Result->Header->get('WWW-Authenticate')])
         ->to->be([403, "Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"a:b c\", resource_metadata=\"{$metadata}\""])
         ->assert();
      yield new Assertion(description: 'forbid() refuses a control character in a scope')
         ->expect($refuse(static function () use ($Bearer, $createMocks): void {
            [, $Response] = $createMocks();
            $Bearer->forbid($Response, ["a\r\nb"]);
         }))
         ->to->be(true)
         ->assert();

      // @ 10: Challenge::announce() — 403 on a header-less invocable response.
      $Invocable = new class {
         public int $code = 200;
         /** @var array<string,string> */
         public array $headers = [];
         public string $body = '';
         /** @param array<string,string> $headers */
         public function __invoke (int $code, array $headers, string $body): static
         {
            $this->code = $code;
            $this->headers = $headers;
            $this->body = $body;
            return $this;
         }
      };
      $Result = Challenge::announce($Invocable, 'Bearer error="insufficient_scope"', 403);
      yield new Assertion(description: 'announce() answers 403 Forbidden through an invocable response')
         ->expect([$Result->code, $Result->headers, $Result->body])
         ->to->be([403, ['WWW-Authenticate' => 'Bearer error="insufficient_scope"'], 'Forbidden'])
         ->assert();
      $Result = Challenge::announce(new $Invocable, 'Bearer');
      yield new Assertion(description: 'announce() keeps 401 Unauthorized by default')
         ->expect([$Result->code, $Result->body])
         ->to->be([401, 'Unauthorized'])
         ->assert();
   })
);
