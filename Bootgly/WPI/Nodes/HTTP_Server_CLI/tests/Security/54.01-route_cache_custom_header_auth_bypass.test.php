<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC C2 — a route-cache hit must not bypass authentication that
 * depends on an application-defined request header.
 *
 * The public pair proves the live per-worker response cache is active. The
 * cold invalid request proves the same route middleware rejects a bad token
 * before any protected handler executes. A valid X-C2-Token then primes a
 * cache-enabled protected route; invalid and missing tokens must still reach
 * the middleware and receive 403, never the cached privileged response.
 */
$controlRuns = 0;
$protectedRuns = 0;

$Authorization = new class implements Middleware {
   public int $runs = 0;

   public function process (object $Request, object $Response, Closure $Next): object
   {
      /** @var Request $Request */
      /** @var Response $Response */
      $this->runs++;
      $token = $Request->Header->get('X-C2-Token');

      if ($token !== 'c2-valid-secret') {
         return $Response(code: 403, body: "C2-DENIED:auth={$this->runs}");
      }

      return $Next($Request, $Response);
   }
};

return new Specification(
   description: 'Route-cache hits must not bypass custom-header authentication middleware',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /c2/cache-control HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /c2/cache-control HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /c2/auth-cold HTTP/1.1\r\n"
         . "Host: localhost\r\nX-C2-Token: invalid\r\n\r\n",
      static fn (): string => "GET /c2/auth-cached HTTP/1.1\r\n"
         . "Host: localhost\r\nX-C2-Token: c2-valid-secret\r\n\r\n",
      static fn (): string => "GET /c2/auth-cached HTTP/1.1\r\n"
         . "Host: localhost\r\nX-C2-Token: invalid\r\n\r\n",
      static fn (): string => "GET /c2/auth-cached HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",
   ],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$controlRuns,
      &$protectedRuns,
      $Authorization,
   ) {
      yield $Router->route('/c2/cache-control', function (
         Request $Request,
         Response $Response,
      ) use (&$controlRuns): Response {
         $controlRuns++;

         return $Response(body: "C2-CONTROL:run={$controlRuns}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/c2/auth-cold', function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'C2-COLD-HANDLER-EXECUTED');
      }, GET, middlewares: [$Authorization]);

      yield $Router->route('/c2/auth-cached', function (
         Request $Request,
         Response $Response,
      ) use (&$protectedRuns, $Authorization): Response {
         $protectedRuns++;

         return $Response(
            body: "C2-SECRET:auth={$Authorization->runs};handler={$protectedRuns}"
         );
      }, GET, middlewares: [$Authorization], cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 6) {
         return 'C2 fixture failed: expected six live route-cache responses.';
      }

      $Body = static function (string $response): null|string {
         $separator = strpos($response, "\r\n\r\n");

         return $separator === false ? null : substr($response, $separator + 4);
      };
      $bodies = array_map($Body, $responses);

      [
         $controlFirst,
         $controlSecond,
         $coldInvalid,
         $validPrime,
         $invalidReplay,
         $missingReplay,
      ] = $bodies;

      $evidence = [
         'control_first' => $controlFirst,
         'control_second' => $controlSecond,
         'cold_invalid' => $coldInvalid,
         'valid_prime' => $validPrime,
         'invalid_after_prime' => $invalidReplay,
         'missing_after_prime' => $missingReplay,
      ];

      if (
         str_contains($responses[0], 'HTTP/1.1 200 OK') === false
         || str_contains($responses[1], 'HTTP/1.1 200 OK') === false
         || $controlFirst !== 'C2-CONTROL:run=1'
         || $controlSecond !== $controlFirst
      ) {
         Vars::$labels = ['C2 cache-positive-control evidence'];
         dump(json_encode($evidence));

         return 'C2 control failed: the repeated public request was not replayed from the live route cache. '
            . 'Evidence: ' . json_encode($evidence);
      }

      if (
         str_contains($responses[2], 'HTTP/1.1 403 Forbidden') === false
         || str_starts_with((string) $coldInvalid, 'C2-DENIED:auth=') === false
         || str_contains($responses[2], 'C2-COLD-HANDLER-EXECUTED')
      ) {
         Vars::$labels = ['C2 cold authentication-control response', 'C2 evidence'];
         dump(json_encode($responses[2]), json_encode($evidence));

         return 'C2 control failed: the custom-header middleware did not reject a cold invalid request.';
      }

      if (
         str_contains($responses[3], 'HTTP/1.1 200 OK') === false
         || $validPrime !== 'C2-SECRET:auth=2;handler=1'
      ) {
         Vars::$labels = ['C2 valid-prime response', 'C2 evidence'];
         dump(json_encode($responses[3]), json_encode($evidence));

         return 'C2 fixture failed: the valid custom credential did not prime the protected route.';
      }

      $bypasses = [];
      foreach ([
         'invalid X-C2-Token' => 4,
         'missing X-C2-Token' => 5,
      ] as $scenario => $index) {
         $body = $bodies[$index];
         $response = $responses[$index];

         if (
            str_contains($response, 'HTTP/1.1 200 OK')
            && $body === $validPrime
            && str_starts_with((string) $body, 'C2-SECRET:')
         ) {
            $bypasses[] = $scenario;
            continue;
         }

         if (
            str_contains($response, 'HTTP/1.1 403 Forbidden') === false
            || str_contains($response, 'C2-SECRET:')
            || str_starts_with((string) $body, 'C2-DENIED:auth=') === false
         ) {
            Vars::$labels = ['C2 unexpected post-prime response', 'C2 evidence'];
            dump(json_encode(['scenario' => $scenario, 'wire' => $response]), json_encode($evidence));

            return "C2 {$scenario} request neither received the cached secret nor the expected middleware denial.";
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['C2 custom-header route-cache bypass evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED C2: an early route-cache hit replayed a privileged response before '
            . 'custom-header authentication middleware ran for: ' . implode(', ', $bypasses) . '. '
            . 'Evidence: ' . json_encode($evidence);
      }

      return true;
   },
);
