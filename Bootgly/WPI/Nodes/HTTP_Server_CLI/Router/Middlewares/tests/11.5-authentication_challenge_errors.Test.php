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
use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;


/**
 * Authentication challenges carry only what the request earned.
 *
 *   1. Bearer (RFC 6750 §3.1): a request with no Bearer token at all — no
 *      `Authorization`, an empty one, another scheme — gets a challenge with
 *      no `error`, `error_description` or `error_uri`; `realm`, `scope` and
 *      `resource_metadata` stay. A refused token keeps the error challenge.
 *   2. Basic (RFC 9110 §5.6.4 / RFC 7617): the realm is a quoted-string —
 *      `\` and `"` are escaped, HTAB passes; a realm with any other control
 *      character is refused when the guard is configured.
 */

return new Test(
   description: 'Authentication challenges should omit Bearer errors without a token and quote the Basic realm',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $deny = static function (): bool {
         return false;
      };
      $challenge = static function (object $Guard, array $headers = [], array $props = []) use ($createMocks): null|string {
         [$Request, $Response] = $createMocks(requestHeaders: $headers, requestProps: $props);
         $Middleware = new Authentication(new Authenticating($Guard));
         $Result = $Middleware->process($Request, $Response, static function (object $Request, object $Response): object {
            return $Response;
         });

         return $Result->code === 401 ? $Result->Header->get('WWW-Authenticate') : "status {$Result->code}";
      };
      $Bearer = new Bearer(
         $deny,
         realm: 'API',
         description: 'The access token expired',
         URI: 'https://example.com/errors',
         scope: 'files:read',
         metadata: 'https://api.example.com/.well-known/oauth-protected-resource'
      );
      $bare = 'Bearer realm="API", scope="files:read", resource_metadata="https://api.example.com/.well-known/oauth-protected-resource"';
      $error = 'Bearer realm="API", error="invalid_token", error_description="The access token expired", error_uri="https://example.com/errors", scope="files:read", resource_metadata="https://api.example.com/.well-known/oauth-protected-resource"';

      // @ 1: No authentication information at all → no error attributes.
      $absent = [
         'no Authorization header' => [],
         'an empty Authorization header' => ['Authorization' => ''],
         'the Basic scheme' => ['Authorization' => 'Basic dXNlcjpwYXNz'],
         'an unknown scheme' => ['Authorization' => 'Token abc'],
         'a Bearer scheme with no token' => ['Authorization' => 'Bearer    '],
      ];
      foreach ($absent as $label => $headers) {
         yield new Assertion(description: "Bearer challenges {$label} without an error code")
            ->expect($challenge($Bearer, $headers))
            ->to->be($bare)
            ->assert();
      }

      // @ 2: A refused token keeps the configured error challenge.
      yield new Assertion(description: 'Bearer challenges a refused header token with its error')
         ->expect($challenge($Bearer, ['Authorization' => 'Bearer bad-token']))
         ->to->be($error)
         ->assert();
      yield new Assertion(description: 'Bearer challenges a refused resolved token with its error')
         ->expect($challenge($Bearer, props: ['token' => 'bad-token']))
         ->to->be($error)
         ->assert();

      // @ 3: The default guard: a bare realm without a token, invalid_token with one.
      yield new Assertion(description: 'A default Bearer without a token sends only its realm')
         ->expect($challenge(new Bearer($deny)))
         ->to->be('Bearer realm="Protected area"')
         ->assert();
      yield new Assertion(description: 'A default Bearer with a refused token sends invalid_token')
         ->expect($challenge(new Bearer($deny), ['Authorization' => 'Bearer bad-token']))
         ->to->be('Bearer realm="Protected area", error="invalid_token"')
         ->assert();

      // @ 4: JWT (a Bearer) follows the same rule.
      $JWTGuard = new JWTGuard(new JWT('bootgly-test-secret-32-bytes-long'), realm: 'mcp');
      yield new Assertion(description: 'JWT guard without a token sends no error code')
         ->expect($challenge($JWTGuard))
         ->to->be('Bearer realm="mcp"')
         ->assert();
      yield new Assertion(description: 'JWT guard with a malformed token sends invalid_token')
         ->expect($challenge($JWTGuard, ['Authorization' => 'Bearer a.b']))
         ->to->be('Bearer realm="mcp", error="invalid_token"')
         ->assert();

      // @ 5: A response-like object without its Request keeps the error challenge.
      [, $Response] = $createMocks();
      $Response->Request = null;
      yield new Assertion(description: 'Without the Request, the Bearer error challenge is sent')
         ->expect($Bearer->challenge($Response)->Header->get('WWW-Authenticate'))
         ->to->be($error)
         ->assert();

      // @ 6: The verdict is per request — a token-less request after a
      //      refused one, on the same guard, gets no error code.
      $challenge($Bearer, ['Authorization' => 'Bearer bad-token']);
      yield new Assertion(description: 'A token-less request after a refused one gets no error code')
         ->expect($challenge($Bearer))
         ->to->be($bare)
         ->assert();

      // @ 7: Basic realm is an RFC 9110 quoted-string on the real Response.
      $realms = [
         'Protected area' => 'Basic realm="Protected area"',
         'say "hi" \\ bye' => 'Basic realm="say \\"hi\\" \\\\ bye"',
         'Caf' . "\xC3\xA9" => 'Basic realm="Caf' . "\xC3\xA9" . '"',
      ];
      foreach ($realms as $realm => $expected) {
         $Guard = new Basic($deny, $realm);
         $Response = new Response;
         yield new Assertion(description: "Basic challenges the realm {$expected}")
            ->expect($Guard->challenge($Response)->Header->get('WWW-Authenticate'))
            ->to->be($expected)
            ->assert();
      }

      // @ 8: HTAB is qdtext (RFC 9110 §5.6.4): accepted and sent as is.
      $Guard = new Basic($deny, "Team\tA");
      yield new Assertion(description: 'Basic accepts and sends an HTAB in the realm')
         ->expect($Guard->challenge(new Response)->Header->get('WWW-Authenticate'))
         ->to->be("Basic realm=\"Team\tA\"")
         ->assert();

      // @ 9: Basic refuses any other control character at configuration time.
      $controls = [
         'CR' => "a\rb",
         'LF' => "a\nb",
         'CRLF' => "a\r\nSet-Cookie: x=1",
         'NUL' => "a\0b",
         'ESC' => "a\x1Bb",
         'DEL' => "a\x7Fb",
      ];
      foreach ($controls as $label => $realm) {
         $refused = false;
         try {
            new Basic($deny, $realm);
         }
         catch (InvalidArgumentException) {
            $refused = true;
         }
         yield new Assertion(description: "Basic refuses {$label} in the realm")
            ->expect($refused)
            ->to->be(true)
            ->assert();
      }
   })
);
