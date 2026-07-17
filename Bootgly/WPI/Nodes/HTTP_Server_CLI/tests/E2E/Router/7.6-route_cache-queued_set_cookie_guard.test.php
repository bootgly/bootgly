<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Security regression H6: Cookies::append() serializes Set-Cookie through the
// queued-header path. Such a response must never enter the route cache, whose
// hits are returned before middleware and the handler. A cookie-free route in
// the same test is the positive control proving that route caching is active.

$cookieRuns = 0;
$controlRuns = 0;

return new Specification(
   Separator: new Separator(left: 'Route response cache security'),
   description: 'It should not cache or replay a queued Set-Cookie response before middleware',

   requests: [
      function () {
         return "GET /cached/security-h6-cookie HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/security-h6-cookie HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/security-h6-control HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/security-h6-control HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   middlewares: [new RequestId],
   response: function (Request $Request, Response $Response, Router $Router) use (&$cookieRuns, &$controlRuns)
   {
      yield $Router->route('/cached/security-h6-cookie', function ($Request, $Response) use (&$cookieRuns) {
         $cookieRuns++;
         $Response->Header->Cookies->append(new Cookie('PHPSID', 'attacker-known'));

         return $Response(body: "cookie-run={$cookieRuns}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/cached/security-h6-control', function ($Request, $Response) use (&$controlRuns) {
         $controlRuns++;

         return $Response(body: "control-run={$controlRuns}");
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [$cookie1, $cookie2, $control1, $control2] = $responses;

      $Extract = static function (string $response): null|string {
         if (preg_match('/^X-Request-Id:\s*([^\r\n]+)\r?$/mi', $response, $matches) !== 1) {
            return null;
         }

         return $matches[1];
      };

      $cookieID1 = $Extract($cookie1);
      $cookieID2 = $Extract($cookie2);
      $controlID1 = $Extract($control1);
      $controlID2 = $Extract($control2);

      $evidence = [
         'cookie_1_request_id' => $cookieID1,
         'cookie_2_request_id' => $cookieID2,
         'control_1_request_id' => $controlID1,
         'control_2_request_id' => $controlID2,
         'cookie_1_reexecuted' => str_contains($cookie1, 'cookie-run=1'),
         'cookie_2_reexecuted' => str_contains($cookie2, 'cookie-run=2'),
         'control_replayed' => str_contains($control1, 'control-run=1')
            && str_contains($control2, 'control-run=1'),
      ];

      if (
         str_contains($cookie1, 'HTTP/1.1 200 OK') === false
         || str_contains($cookie2, 'HTTP/1.1 200 OK') === false
         || str_contains($cookie1, 'Set-Cookie: PHPSID=attacker-known') === false
         || str_contains($cookie2, 'Set-Cookie: PHPSID=attacker-known') === false
      ) {
         Vars::$labels = ['Cookie response 1:', 'Cookie response 2:', 'Evidence:'];
         dump(json_encode($cookie1), json_encode($cookie2), json_encode($evidence));
         return 'The queued Set-Cookie setup did not produce two valid cookie-setting responses';
      }

      if (
         str_contains($cookie1, 'cookie-run=1') === false
         || str_contains($cookie2, 'cookie-run=2') === false
         || $cookieID1 === null || $cookieID2 === null || $cookieID1 === $cookieID2
      ) {
         Vars::$labels = ['Cookie response 1:', 'Cookie response 2:', 'Evidence:'];
         dump(json_encode($cookie1), json_encode($cookie2), json_encode($evidence));
         return 'H6 reproduced: a queued Set-Cookie response was replayed before middleware/handler execution';
      }

      if (
         str_contains($control1, 'control-run=1') === false
         || str_contains($control2, 'control-run=1') === false
         || $controlID1 === null || $controlID2 === null || $controlID1 !== $controlID2
      ) {
         Vars::$labels = ['Control response 1:', 'Control response 2:', 'Evidence:'];
         dump(json_encode($control1), json_encode($control2), json_encode($evidence));
         return 'The cookie-free positive control was not replayed from the route cache';
      }

      return true;
   }
);
