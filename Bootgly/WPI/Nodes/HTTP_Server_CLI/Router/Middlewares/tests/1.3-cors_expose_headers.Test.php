<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;


/**
 * `CORS` `expose` → `Access-Control-Expose-Headers`.
 *
 *   1. Sent on actual (non-preflight) responses for an allowed origin, joined
 *      with `, ` in the configured order; omitted when `expose` is empty.
 *   2. Never sent on a disallowed origin, a preflight, or a no-Origin request
 *      under an allowlist (no Access-Control-Allow-Origin there).
 *   3. Header names must be RFC 9110 tokens; anything else is refused.
 */

return new Test(
   description: 'CORS should expose configured headers on allowed actual responses only',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };
      $expose = ['Mcp-Session-Id', 'WWW-Authenticate'];
      $refuse = static function (array $names): bool {
         try {
            new CORS(origins: ['https://app.example.com'], expose: $names);
         }
         catch (InvalidArgumentException) {
            return true;
         }

         return false;
      };

      // @ 1: Allowed origin, actual request → exposed.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'https://app.example.com'],
         requestProps: ['method' => 'POST']
      );
      $CORS = new CORS(origins: ['https://app.example.com'], expose: $expose);
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(description: 'Allowed origin receives Access-Control-Expose-Headers')
         ->expect($Result->Header->get('Access-Control-Expose-Headers'))
         ->to->be('Mcp-Session-Id, WWW-Authenticate')
         ->assert();
      yield new Assertion(description: 'expose keeps the configured names')
         ->expect($CORS->expose)
         ->to->be($expose)
         ->assert();

      // @ 2: Wildcard policy, actual request → exposed.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'https://any.example.com']
      );
      $Result = new CORS(origins: ['*'], expose: ['Mcp-Session-Id'])->process($Request, $Response, $passthrough);
      yield new Assertion(description: 'Wildcard policy receives Access-Control-Expose-Headers')
         ->expect($Result->Header->get('Access-Control-Expose-Headers'))
         ->to->be('Mcp-Session-Id')
         ->assert();

      // @ 3: Disallowed origin → 403, never exposed.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'https://evil.example.com']
      );
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(description: 'Disallowed origin is rejected')
         ->expect($Result->code)
         ->to->be(403)
         ->assert();
      yield new Assertion(description: 'Disallowed origin never receives Access-Control-Expose-Headers')
         ->expect($Result->Header->get('Access-Control-Expose-Headers'))
         ->to->be(null)
         ->assert();

      // @ 4: Preflight → never exposed (Fetch: actual responses only).
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'https://app.example.com'],
         requestProps: ['method' => 'OPTIONS']
      );
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(description: 'Preflight answers 204')
         ->expect($Result->code)
         ->to->be(204)
         ->assert();
      yield new Assertion(description: 'Preflight never receives Access-Control-Expose-Headers')
         ->expect($Result->Header->get('Access-Control-Expose-Headers'))
         ->to->be(null)
         ->assert();

      // @ 5: Allowlist + no Origin (not a CORS request) → not exposed.
      [$Request, $Response] = $createMocks();
      $Result = $CORS->process($Request, $Response, $passthrough);
      yield new Assertion(description: 'No-Origin request under an allowlist receives no Access-Control-Expose-Headers')
         ->expect($Result->Header->get('Access-Control-Expose-Headers'))
         ->to->be(null)
         ->assert();

      // @ 6: Empty expose (default) → omitted.
      [$Request, $Response] = $createMocks(
         requestHeaders: ['Origin' => 'https://app.example.com']
      );
      $Default = new CORS(origins: ['https://app.example.com']);
      $Result = $Default->process($Request, $Response, $passthrough);
      yield new Assertion(description: 'Default expose is empty')
         ->expect($Default->expose)
         ->to->be([])
         ->assert();
      yield new Assertion(description: 'Empty expose omits Access-Control-Expose-Headers')
         ->expect($Result->Header->get('Access-Control-Expose-Headers'))
         ->to->be(null)
         ->assert();

      // @ 7: Names must be RFC 9110 tokens.
      $invalid = [
         'empty' => [''],
         'space' => ['Mcp Session'],
         'comma list' => ['A, B'],
         'colon' => ['X-A:'],
         'CRLF' => ["X-A\r\nSet-Cookie: a=b"],
         'NUL' => ["X-A\0"],
         'trailing LF' => ["X-A\n"],
         'non-ASCII' => ["X-\xC3\xA9"],
         'non-string' => [42],
      ];
      foreach ($invalid as $label => $names) {
         yield new Assertion(description: "expose refuses a {$label} name")
            ->expect($refuse($names))
            ->to->be(true)
            ->assert();
      }
      yield new Assertion(description: 'expose accepts token names (incl. tchar symbols and *)')
         ->expect($refuse(['Mcp-Session-Id', 'X_A.b~1', '*']))
         ->to->be(false)
         ->assert();
   })
);
