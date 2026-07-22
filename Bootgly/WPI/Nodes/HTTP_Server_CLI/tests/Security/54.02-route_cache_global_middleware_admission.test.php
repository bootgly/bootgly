<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * C2 remediation regression — global admission must precede cache replay.
 *
 * The public pair proves cache hits remain functional inside the global
 * pipeline. A cold invalid request proves the global policy is active. The
 * protected route is middleware-free at Router level so its valid response
 * can be cached, but invalid and missing custom credentials must be rejected
 * globally before the encoder is allowed to fetch that entry.
 */
$controlRuns = 0;
$protectedRuns = 0;

$Admission = new class implements Middleware {
   public int $runs = 0;

   public function process (object $Request, object $Response, Closure $Next): object
   {
      /** @var Request $Request */
      /** @var Response $Response */
      if (str_starts_with($Request->URI, '/c2/global-auth') === false) {
         return $Next($Request, $Response);
      }

      $this->runs++;
      $token = $Request->Header->get('X-C2-Token');

      if ($token !== 'c2-global-valid') {
         return $Response(code: 403, body: "C2-GLOBAL-DENIED:auth={$this->runs}");
      }

      return $Next($Request, $Response);
   }
};

return new Specification(
   description: 'Global admission middleware must execute before route-cache replay',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /c2/global-control HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /c2/global-control HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /c2/global-auth-cold HTTP/1.1\r\n"
         . "Host: localhost\r\nX-C2-Token: invalid\r\n\r\n",
      static fn (): string => "GET /c2/global-auth-cached HTTP/1.1\r\n"
         . "Host: localhost\r\nX-C2-Token: c2-global-valid\r\n\r\n",
      static fn (): string => "GET /c2/global-auth-cached HTTP/1.1\r\n"
         . "Host: localhost\r\nX-C2-Token: invalid\r\n\r\n",
      static fn (): string => "GET /c2/global-auth-cached HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",
   ],

   middlewares: [$Admission],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$controlRuns,
      &$protectedRuns,
      $Admission,
   ) {
      yield $Router->route('/c2/global-control', function (
         Request $Request,
         Response $Response,
      ) use (&$controlRuns): Response {
         $controlRuns++;

         return $Response(body: "C2-GLOBAL-CONTROL:run={$controlRuns}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/c2/global-auth-cold', function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'C2-GLOBAL-COLD-HANDLER-EXECUTED');
      }, GET);

      yield $Router->route('/c2/global-auth-cached', function (
         Request $Request,
         Response $Response,
      ) use (&$protectedRuns, $Admission): Response {
         $protectedRuns++;

         return $Response(
            body: "C2-GLOBAL-SECRET:auth={$Admission->runs};handler={$protectedRuns}"
         );
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 6) {
         return 'C2 global fixture failed: expected six live responses.';
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
         $invalidAfterPrime,
         $missingAfterPrime,
      ] = $bodies;

      $evidence = [
         'control_first' => $controlFirst,
         'control_second' => $controlSecond,
         'cold_invalid' => $coldInvalid,
         'valid_prime' => $validPrime,
         'invalid_after_prime' => $invalidAfterPrime,
         'missing_after_prime' => $missingAfterPrime,
      ];

      if (
         $controlFirst !== 'C2-GLOBAL-CONTROL:run=1'
         || $controlSecond !== $controlFirst
      ) {
         Vars::$labels = ['C2 global cache control'];
         dump(json_encode($evidence));

         return 'C2 global control failed: public cache replay was not retained inside the pipeline.';
      }

      if (
         str_contains($responses[2], 'HTTP/1.1 403 Forbidden') === false
         || str_starts_with((string) $coldInvalid, 'C2-GLOBAL-DENIED:auth=') === false
         || str_contains($responses[2], 'C2-GLOBAL-COLD-HANDLER-EXECUTED')
      ) {
         Vars::$labels = ['C2 global cold-policy control'];
         dump(json_encode($responses[2]), json_encode($evidence));

         return 'C2 global control failed: cold invalid credentials were not rejected.';
      }

      if (
         str_contains($responses[3], 'HTTP/1.1 200 OK') === false
         || $validPrime !== 'C2-GLOBAL-SECRET:auth=2;handler=1'
      ) {
         Vars::$labels = ['C2 global valid prime'];
         dump(json_encode($responses[3]), json_encode($evidence));

         return 'C2 global fixture failed: valid credentials did not prime the protected route.';
      }

      foreach ([4, 5] as $index) {
         if (
            str_contains($responses[$index], 'HTTP/1.1 403 Forbidden') === false
            || str_contains($responses[$index], 'C2-GLOBAL-SECRET:')
            || str_starts_with((string) $bodies[$index], 'C2-GLOBAL-DENIED:auth=') === false
         ) {
            Vars::$labels = ['C2 global post-prime admission evidence'];
            dump(json_encode($responses[$index]), json_encode($evidence));

            return 'C2 global admission failed after priming: cached privileged wire bypassed middleware.';
         }
      }

      return true;
   },
);
