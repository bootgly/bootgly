<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M5 — null and false session identities must not authenticate.
 *
 * The seeding middleware models application or logout code that leaves the
 * identity key present with a falsey sentinel. The following production
 * Session guard and Authentication middleware must reject those requests.
 * A valid string identity and a missing key provide positive and negative
 * controls for the same live middleware pipeline.
 */
return new Specification(
   description: 'Session authentication must reject null and false identities',
   Separator: new Separator(line: true),

   requests: [
      function (): string {
         return "GET /m5/protected HTTP/1.1\r\nHost: localhost\r\nX-M5-Identity: valid\r\n\r\n";
      },
      function (): string {
         return "GET /m5/protected HTTP/1.1\r\nHost: localhost\r\nX-M5-Identity: absent\r\n\r\n";
      },
      function (): string {
         return "GET /m5/protected HTTP/1.1\r\nHost: localhost\r\nX-M5-Identity: null\r\n\r\n";
      },
      function (): string {
         return "GET /m5/protected HTTP/1.1\r\nHost: localhost\r\nX-M5-Identity: false\r\n\r\n";
      },
   ],

   middlewares: [
      new class implements Middleware {
         public function process (object $Request, object $Response, Closure $next): object
         {
            $mode = $Request->Header->get('X-M5-Identity');
            $Session = $Request->Session;

            if ($mode === 'valid') {
               $Session->set('identity', 'm5-user');
            }
            else if ($mode === 'null') {
               $Session->set('identity', null);
            }
            else if ($mode === 'false') {
               $Session->set('identity', false);
            }

            return $next($Request, $Response);
         }
      },
      new Authentication(new Authenticating(new SessionGuard)),
   ],

   response: function (Request $Request, Response $Response): Response {
      $mode = $Request->Header->get('X-M5-Identity') ?? 'unknown';

      return $Response(body: 'M5-PROTECTED-HANDLER:' . $mode);
   },

   test: function (array $responses): bool|string {
      if (count($responses) !== 4) {
         return 'M5 probe did not receive all four authentication responses.';
      }

      [$validResponse, $absentResponse, $nullResponse, $falseResponse] = $responses;

      if (
         ! str_contains($validResponse, 'HTTP/1.1 200 OK')
         || ! str_contains($validResponse, 'M5-PROTECTED-HANDLER:valid')
      ) {
         Vars::$labels = ['M5 valid-identity control response'];
         dump(json_encode($validResponse));

         return 'M5 valid-identity control failed: the protected handler was not reachable.';
      }

      if (
         ! str_contains($absentResponse, 'HTTP/1.1 401 Unauthorized')
         || str_contains($absentResponse, 'M5-PROTECTED-HANDLER:absent')
      ) {
         Vars::$labels = ['M5 missing-identity control response'];
         dump(json_encode($absentResponse));

         return 'M5 missing-identity control failed: an absent key was not rejected.';
      }

      $bypasses = [];
      foreach (['null' => $nullResponse, 'false' => $falseResponse] as $value => $response) {
         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && str_contains($response, 'M5-PROTECTED-HANDLER:' . $value)
         ) {
            $bypasses[] = $value;
            continue;
         }

         if (
            ! str_contains($response, 'HTTP/1.1 401 Unauthorized')
            || str_contains($response, 'M5-PROTECTED-HANDLER:' . $value)
         ) {
            Vars::$labels = ["M5 unexpected {$value}-identity response"];
            dump(json_encode($response));

            return "M5 {$value}-identity request neither authenticated nor reached the rejection control.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['M5 null-identity bypass', 'M5 false-identity bypass'];
         dump(json_encode($nullResponse), json_encode($falseResponse));

         return 'CONFIRMED M5: protected handler executed with present falsey session identities: '
            . implode(', ', $bypasses) . '.';
      }

      return true;
   },
);
