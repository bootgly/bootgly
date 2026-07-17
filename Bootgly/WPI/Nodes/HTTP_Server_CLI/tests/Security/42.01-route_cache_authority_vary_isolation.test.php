<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M8 — route-cache entries must be isolated by authority and
 * every declared Vary request-header dimension.
 *
 * The first pair is a positive control proving that route caching is active:
 * the handler counter must remain at one. The next pairs use the same method
 * and URI while changing Host or X-M8-Variant. Secure behavior may isolate
 * those entries or decline to cache them, but it must never replay bytes from
 * the first tenant/variant to the second.
 */
$controlRuns = 0;
$authorityRuns = 0;
$varyRuns = 0;

return new Specification(
   description: 'Route-cache entries must isolate authority and declared Vary dimensions',
   Separator: new Separator(line: true),

   requests: [
      static function (): string {
         return "GET /m8/cache-control HTTP/1.1\r\nHost: control.example.test\r\n\r\n";
      },
      static function (): string {
         return "GET /m8/cache-control HTTP/1.1\r\nHost: control.example.test\r\n\r\n";
      },
      static function (): string {
         return "GET /m8/authority HTTP/1.1\r\nHost: tenant-a.example.test\r\n\r\n";
      },
      static function (): string {
         return "GET /m8/authority HTTP/1.1\r\nHost: tenant-b.example.test\r\n\r\n";
      },
      static function (): string {
         return "GET /m8/vary HTTP/1.1\r\nHost: vary.example.test\r\nX-M8-Variant: alpha\r\n\r\n";
      },
      static function (): string {
         return "GET /m8/vary HTTP/1.1\r\nHost: vary.example.test\r\nX-M8-Variant: beta\r\n\r\n";
      },
   ],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$controlRuns,
      &$authorityRuns,
      &$varyRuns,
   ) {
      yield $Router->route('/m8/cache-control', static function (
         Request $Request,
         Response $Response,
      ) use (&$controlRuns): Response {
         $controlRuns++;

         return $Response(body: "M8-CONTROL:run={$controlRuns}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m8/authority', static function (
         Request $Request,
         Response $Response,
      ) use (&$authorityRuns): Response {
         $authorityRuns++;

         return $Response(body: "M8-AUTHORITY:{$Request->host}:run={$authorityRuns}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m8/vary', static function (
         Request $Request,
         Response $Response,
      ) use (&$varyRuns): Response {
         $varyRuns++;
         $variant = $Request->Header->get('X-M8-Variant');
         $variant = is_string($variant) ? $variant : 'missing';
         $Response->Header->vary('X-M8-Variant');

         return $Response(body: "M8-VARY:{$variant}:run={$varyRuns}");
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 6) {
         return 'M8 probe did not receive all six route-cache responses.';
      }

      $Body = static function (string $response): null|string {
         $separator = strpos($response, "\r\n\r\n");

         return $separator === false ? null : substr($response, $separator + 4);
      };
      $bodies = array_map($Body, $responses);

      [
         $controlFirst,
         $controlSecond,
         $authorityA,
         $authorityB,
         $varyAlpha,
         $varyBeta,
      ] = $bodies;

      $evidence = [
         'control_first' => $controlFirst,
         'control_second' => $controlSecond,
         'authority_a' => $authorityA,
         'authority_b' => $authorityB,
         'vary_alpha' => $varyAlpha,
         'vary_beta' => $varyBeta,
      ];

      foreach ($responses as $index => $response) {
         if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
            Vars::$labels = ['M8 non-200 response', 'M8 evidence'];
            dump(json_encode(['request' => $index + 1, 'wire' => $response]), json_encode($evidence));

            return 'M8 fixture failed: one route-cache request did not receive HTTP 200.';
         }
      }

      if ($controlFirst !== 'M8-CONTROL:run=1' || $controlSecond !== $controlFirst) {
         Vars::$labels = ['M8 cache-positive-control evidence'];
         dump(json_encode($evidence));

         return 'M8 control failed: the repeated identical request was not replayed from the live route cache.';
      }

      if ($authorityA !== 'M8-AUTHORITY:tenant-a.example.test:run=1') {
         Vars::$labels = ['M8 authority fixture evidence'];
         dump(json_encode($evidence));

         return 'M8 authority fixture failed before the cross-tenant request.';
      }

      if (
         $varyAlpha !== 'M8-VARY:alpha:run=1'
         || str_contains($responses[4], "\r\nVary: X-M8-Variant\r\n") === false
      ) {
         Vars::$labels = ['M8 Vary fixture evidence'];
         dump(json_encode($evidence), json_encode($responses[4]));

         return 'M8 Vary fixture failed before the cross-variant request.';
      }

      $contaminated = [];
      if ($authorityB === $authorityA) {
         $contaminated[] = 'Host tenant-a.example.test -> tenant-b.example.test';
      }
      if ($varyBeta === $varyAlpha) {
         $contaminated[] = 'X-M8-Variant alpha -> beta';
      }

      if ($contaminated !== []) {
         Vars::$labels = ['M8 cross-dimension cache replay evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M8: route-cache bytes crossed request dimensions: '
            . implode('; ', $contaminated) . '.';
      }

      if (
         str_starts_with((string) $authorityB, 'M8-AUTHORITY:tenant-b.example.test:run=') === false
         || str_starts_with((string) $varyBeta, 'M8-VARY:beta:run=') === false
      ) {
         Vars::$labels = ['M8 unexpected isolation evidence'];
         dump(json_encode($evidence));

         return 'M8 probe produced neither cross-dimension replay nor correctly isolated responses.';
      }

      return true;
   },
);
