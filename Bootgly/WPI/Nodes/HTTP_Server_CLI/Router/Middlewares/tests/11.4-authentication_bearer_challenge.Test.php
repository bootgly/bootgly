<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;


/**
 * Bearer challenge hardening + RFC 9728 `resource_metadata`.
 *
 *   1. Challenge values are RFC 9110 quoted-strings: `\` and `"` are escaped,
 *      plain values stay byte-identical.
 *   2. Control characters (CR, LF, NUL, HTAB, DEL, …) are refused when the
 *      guard is configured — never emitted at request time.
 *   3. `metadata` is sent as `resource_metadata="…"` after the other params.
 *   4. RFC 6750 §3 character sets: `error`/`description` are %x20-21 /
 *      %x23-5B / %x5D-7E, `URI` the same without space, `scope` is
 *      space-separated scope-tokens; `forbid()` checks its scope-tokens.
 *   5. `Challenge::mark/reject/announce` accept only 401 or 403.
 */

return new Test(
   description: 'Bearer challenges should quote values, refuse controls and advertise resource_metadata',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $deny = static function (): bool {
         return false;
      };
      $challenge = static function (Bearer $Guard) use ($createMocks): null|string {
         [$Request, $Response] = $createMocks(requestProps: ['token' => 'bad-token']);
         $Middleware = new Authentication(new Authenticating($Guard));
         $Result = $Middleware->process($Request, $Response, static function (object $Request, object $Response): object {
            return $Response;
         });

         return $Result->Header->get('WWW-Authenticate');
      };
      $refuse = static function (Closure $configure): bool {
         try {
            $configure();
         }
         catch (InvalidArgumentException) {
            return true;
         }

         return false;
      };
      $message = static function (Closure $configure): string {
         try {
            $configure();
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }

         return '';
      };

      // @ 1: Plain values stay byte-identical to the previous output.
      yield new Assertion(description: 'Plain challenge values are emitted unchanged')
         ->expect($challenge(new Bearer(
            $deny,
            realm: 'API',
            description: 'The access token expired',
            URI: 'https://example.com/errors',
            scope: 'files:read files:write'
         )))
         ->to->be('Bearer realm="API", error="invalid_token", error_description="The access token expired", error_uri="https://example.com/errors", scope="files:read files:write"')
         ->assert();

      // @ 2: Backslash and double quote are escaped as quoted-pairs (realm
      //      and metadata keep the general quoted-string rule).
      yield new Assertion(description: 'Backslash and double quote are escaped')
         ->expect($challenge(new Bearer(
            $deny,
            realm: 'say "hi" \\ bye',
            metadata: 'https://x/a\\"b'
         )))
         ->to->be('Bearer realm="say \\"hi\\" \\\\ bye", error="invalid_token", resource_metadata="https://x/a\\\\\\"b"')
         ->assert();

      // @ 3: resource_metadata comes last, after every other parameter.
      yield new Assertion(description: 'metadata is sent as the last resource_metadata parameter')
         ->expect($challenge(new Bearer(
            $deny,
            realm: 'mcp',
            scope: 'mcp:tools',
            metadata: 'https://mcp.example.com/.well-known/oauth-protected-resource'
         )))
         ->to->be('Bearer realm="mcp", error="invalid_token", scope="mcp:tools", resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"')
         ->assert();

      // @ 4: Empty metadata (the default) omits the parameter.
      $Guard = new Bearer($deny);
      yield new Assertion(description: 'Empty metadata omits resource_metadata')
         ->expect($challenge($Guard))
         ->to->be('Bearer realm="Protected area", error="invalid_token"')
         ->assert();
      yield new Assertion(description: 'metadata defaults to an empty string')
         ->expect($Guard->metadata)
         ->to->be('')
         ->assert();

      // @ 5: JWT (a Bearer) forwards metadata to the same challenge.
      $JWTGuard = new JWTGuard(
         new JWT('bootgly-test-secret-32-bytes-long'),
         realm: 'mcp',
         metadata: 'https://mcp.example.com/.well-known/oauth-protected-resource'
      );
      yield new Assertion(description: 'JWT guard advertises resource_metadata')
         ->expect($challenge($JWTGuard))
         ->to->be('Bearer realm="mcp", error="invalid_token", resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource"')
         ->assert();

      // @ 6: Control characters are refused at configuration time, per value.
      $controls = [
         'CR' => "a\rb",
         'LF' => "a\nb",
         'CRLF' => "a\r\nWWW-Authenticate: Basic",
         'NUL' => "a\0b",
         'HTAB' => "a\tb",
         'DEL' => "a\x7Fb",
         'ESC' => "a\x1Bb",
      ];
      $parameters = ['realm', 'error', 'description', 'URI', 'scope', 'metadata'];
      foreach ($parameters as $parameter) {
         foreach ($controls as $label => $value) {
            yield new Assertion(description: "Bearer refuses {$label} in {$parameter}")
               ->expect($refuse(static function () use ($deny, $parameter, $value): void {
                  new Bearer($deny, ...[$parameter => $value]);
               }))
               ->to->be(true)
               ->assert();
         }
      }
      yield new Assertion(description: 'JWT guard refuses a control character in metadata')
         ->expect($refuse(static function (): void {
            new JWTGuard(new JWT('bootgly-test-secret-32-bytes-long'), metadata: "https://x\r\nSet-Cookie: a=b");
         }))
         ->to->be(true)
         ->assert();

      // @ 7: RFC 6750 §3 character sets, per attribute.
      $outside = [
         'error' => [
            'double quote' => 'invalid"token',
            'backslash' => 'invalid\\token',
            'non-ASCII' => "invalid_\xC3\xA9",
            'trailing LF' => "invalid_token\n",
         ],
         'description' => [
            'double quote' => 'The "token" expired',
            'backslash' => 'C:\\token',
            'non-ASCII' => "Token expir\xC3\xA9",
            'trailing LF' => "The access token expired\n",
         ],
         'URI' => [
            'space' => 'https://example.com/a b',
            'double quote' => 'https://example.com/"a"',
            'backslash' => 'https://example.com\\a',
            'non-ASCII' => "https://example.com/\xC3\xA9",
            'trailing LF' => "https://example.com/errors\n",
         ],
         'scope' => [
            'double quote' => 'files:"read"',
            'backslash' => 'files\\read',
            'non-ASCII' => "files:r\xC3\xA9ad",
            'double space' => 'files:read  files:write',
            'leading space' => ' files:read',
            'trailing space' => 'files:read ',
            'trailing LF' => "files:read\n",
         ],
      ];
      foreach ($outside as $parameter => $values) {
         foreach ($values as $label => $value) {
            yield new Assertion(description: "Bearer refuses a {$label} in {$parameter}")
               ->expect($refuse(static function () use ($deny, $parameter, $value): void {
                  new Bearer($deny, ...[$parameter => $value]);
               }))
               ->to->be(true)
               ->assert();
         }
      }
      yield new Assertion(description: 'A refusal names the parameter')
         ->expect([
            $message(static function () use ($deny): void {
               new Bearer($deny, error: 'a"b');
            }),
            $message(static function () use ($deny): void {
               new Bearer($deny, description: 'a"b');
            }),
            $message(static function () use ($deny): void {
               new Bearer($deny, URI: 'a b');
            }),
            $message(static function () use ($deny): void {
               new Bearer($deny, scope: 'a  b');
            }),
         ])
         ->to->be([
            'Bearer error must hold only RFC 6750 characters: printable ASCII without `"` and `\`.',
            'Bearer description (error_description) must hold only RFC 6750 characters: printable ASCII without `"` and `\`.',
            'Bearer URI (error_uri) must hold only RFC 6750 characters: a URI-reference of printable ASCII without space, `"` and `\`.',
            'Bearer scope must hold only RFC 6750 characters: scope-tokens (printable ASCII without space, `"` and `\`) separated by one space.',
         ])
         ->assert();
      yield new Assertion(description: 'A trailing LF is refused by the RFC 6750 check itself (anchored with /D)')
         ->expect([
            $message(static function () use ($deny): void {
               new Bearer($deny, description: "expired\n");
            }),
            $message(static function () use ($deny): void {
               new Bearer($deny, URI: "https://example.com/errors\n");
            }),
            $message(static function () use ($deny): void {
               new Bearer($deny, scope: "files:read\n");
            }),
            $message(static function () use ($deny, $createMocks): void {
               [, $Response] = $createMocks();
               new Bearer($deny)->forbid($Response, ["files:read\n"]);
            }),
         ])
         ->to->be([
            'Bearer description (error_description) must hold only RFC 6750 characters: printable ASCII without `"` and `\`.',
            'Bearer URI (error_uri) must hold only RFC 6750 characters: a URI-reference of printable ASCII without space, `"` and `\`.',
            'Bearer scope must hold only RFC 6750 characters: scope-tokens (printable ASCII without space, `"` and `\`) separated by one space.',
            'Bearer forbid() scopes must be RFC 6750 scope-tokens: printable ASCII without space, `"` and `\`.',
         ])
         ->assert();
      yield new Assertion(description: 'JWT guard refuses a double quote in description')
         ->expect($refuse(static function (): void {
            new JWTGuard(new JWT('bootgly-test-secret-32-bytes-long'), description: 'a"b');
         }))
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'Every character of the RFC 6750 sets is accepted')
         ->expect($challenge(new Bearer(
            $deny,
            error: ' !#[]~',
            description: 'a !#$%&\'()*+,-./09:;<=>?@AZ[]^_`az{|}~',
            URI: 'urn:x!#$%&\'()*+,-./09:;<=>?@AZ[]^_`az{|}~',
            scope: 'a!#[]~ b:c'
         )))
         ->to->be('Bearer realm="Protected area", error=" !#[]~", error_description="a !#$%&\'()*+,-./09:;<=>?@AZ[]^_`az{|}~", error_uri="urn:x!#$%&\'()*+,-./09:;<=>?@AZ[]^_`az{|}~", scope="a!#[]~ b:c"')
         ->assert();
      yield new Assertion(description: 'realm and metadata keep obs-text bytes')
         ->expect($challenge(new Bearer($deny, realm: "Caf\xC3\xA9", metadata: "https://x/\xC3\xA9")))
         ->to->be("Bearer realm=\"Caf\xC3\xA9\", error=\"invalid_token\", resource_metadata=\"https://x/\xC3\xA9\"")
         ->assert();
      yield new Assertion(description: 'Empty RFC 6750 attributes are omitted, never refused')
         ->expect($challenge(new Bearer($deny, error: '')))
         ->to->be('Bearer realm="Protected area"')
         ->assert();

      // @ 8: forbid() checks the scope-tokens it is given.
      $Guard = new Bearer($deny, realm: 'API');
      $forbidden = [
         'empty' => [''],
         'space' => ['files read'],
         'double quote' => ['files"read'],
         'backslash' => ['files\\read'],
         'non-ASCII' => ["files:r\xC3\xA9ad"],
         'trailing LF' => ["files:read\n"],
         'CRLF' => ["a\r\nb"],
      ];
      foreach ($forbidden as $label => $scopes) {
         yield new Assertion(description: "forbid() refuses a {$label} scope")
            ->expect($refuse(static function () use ($Guard, $createMocks, $scopes): void {
               [, $Response] = $createMocks();
               $Guard->forbid($Response, ['files:list', ...$scopes]);
            }))
            ->to->be(true)
            ->assert();
      }
      [, $Response] = $createMocks();
      yield new Assertion(description: 'forbid() keeps valid scope-tokens byte-identical')
         ->expect($Guard->forbid($Response, ['a!#[]~', 'https://api.example.com/files.read'])->Header->get('WWW-Authenticate'))
         ->to->be('Bearer realm="API", error="insufficient_scope", scope="a!#[]~ https://api.example.com/files.read"')
         ->assert();
      [, $Response] = $createMocks();
      yield new Assertion(description: 'forbid() with no scope omits the scope attribute')
         ->expect($Guard->forbid($Response, [])->Header->get('WWW-Authenticate'))
         ->to->be('Bearer realm="API", error="insufficient_scope"')
         ->assert();

      // @ 9: Challenge::mark/reject/announce accept only 401 or 403.
      foreach ([0, 200, 302, 400, 402, 404, 500] as $code) {
         yield new Assertion(description: "mark/reject/announce refuse status {$code}")
            ->expect([
               $refuse(static function () use ($createMocks, $code): void {
                  [, $Response] = $createMocks();
                  Challenge::mark($Response, $code);
               }),
               $refuse(static function () use ($createMocks, $code): void {
                  [, $Response] = $createMocks();
                  Challenge::reject($Response, $code);
               }),
               $refuse(static function () use ($createMocks, $code): void {
                  [, $Response] = $createMocks();
                  Challenge::announce($Response, 'Bearer', $code);
               }),
            ])
            ->to->be([true, true, true])
            ->assert();
      }
      [, $Response] = $createMocks();
      try {
         Challenge::announce($Response, 'Bearer', 500);
      }
      catch (InvalidArgumentException) {
         // Refused before any write.
      }
      yield new Assertion(description: 'A refused status writes neither the code nor the header')
         ->expect([$Response->code, $Response->Header->get('WWW-Authenticate')])
         ->to->be([200, null])
         ->assert();
      yield new Assertion(description: 'The status refusal names the code')
         ->expect($message(static function () use ($createMocks): void {
            [, $Response] = $createMocks();
            Challenge::mark($Response, 500);
         }))
         ->to->be('Authentication challenge status must be 401 or 403, 500 given.')
         ->assert();
      $codes = [];
      foreach ([401, 403] as $code) {
         [, $Response] = $createMocks();
         $codes[] = Challenge::mark($Response, $code)->code;
         [, $Response] = $createMocks();
         $codes[] = Challenge::reject($Response, $code)->code;
         [, $Response] = $createMocks();
         $codes[] = Challenge::announce($Response, 'Bearer', $code)->code;
      }
      yield new Assertion(description: 'mark/reject/announce accept 401 and 403')
         ->expect($codes)
         ->to->be([401, 401, 401, 403, 403, 403])
         ->assert();

      // @ 10: Challenge::quote() — the quoted-string primitive itself.
      yield new Assertion(description: 'quote() wraps a plain value in double quotes')
         ->expect(Challenge::quote('Protected area'))
         ->to->be('"Protected area"')
         ->assert();
      yield new Assertion(description: 'quote() escapes the backslash before the quote')
         ->expect(Challenge::quote('\\"'))
         ->to->be('"\\\\\\""')
         ->assert();
      yield new Assertion(description: 'quote() keeps obs-text bytes')
         ->expect(Challenge::quote("\xC3\xA9"))
         ->to->be("\"\xC3\xA9\"")
         ->assert();
      yield new Assertion(description: 'quote() refuses a control character')
         ->expect($refuse(static function (): void {
            Challenge::quote("a\nb");
         }))
         ->to->be(true)
         ->assert();
   })
);
