<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M10 — the Pre response resource must encode request-derived
 * values before placing them in its default HTML response context.
 *
 * The benign request is a positive control for query decoding, routing and
 * the built-in Pre resource. The attack request closes the wrapper and opens
 * a script element. The invalid UTF-8 request retains coverage for the
 * substitution behavior required by a safe encoder.
 */
return new Specification(
   description: 'Pre response resource must encode request-derived HTML',
   Separator: new Separator(line: true),

   requests: [
      static function (): string {
         return "GET /m10/pre?value=M10-CONTROL HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return 'GET /m10/pre?value=%3C%2Fpre%3E%3Cscript%3EglobalThis.__bootglyM10%3D1%3C%2Fscript%3E%26%22%27'
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return "GET /m10/pre?value=%C3%28 HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m10/pre', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Pre = $Response->Pre;

         return $Pre->send($Request->query('value'));
      }, GET);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 3) {
         return 'M10 probe did not receive all three Pre responses.';
      }

      $Body = static function (string $response): null|string {
         $separator = strpos($response, "\r\n\r\n");

         return $separator === false ? null : substr($response, $separator + 4);
      };
      $bodies = array_map($Body, $responses);
      [$controlBody, $attackBody, $invalidUTF8Body] = $bodies;

      $evidence = [
         'control_body' => $controlBody,
         'attack_body' => $attackBody,
         'attack_body_hex' => is_string($attackBody) ? bin2hex($attackBody) : null,
         'invalid_utf8_body_hex' => is_string($invalidUTF8Body) ? bin2hex($invalidUTF8Body) : null,
      ];

      foreach ($responses as $index => $response) {
         if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
            Vars::$labels = ['M10 non-200 response', 'M10 evidence'];
            dump(json_encode(['request' => $index + 1, 'wire_hex' => bin2hex($response)]), json_encode($evidence));

            return 'M10 fixture failed: one Pre request did not receive HTTP 200.';
         }

         if (str_contains($response, "\r\nContent-Type: text/html; charset=UTF-8\r\n") === false) {
            Vars::$labels = ['M10 response media-type evidence'];
            dump(json_encode(['request' => $index + 1, 'wire_hex' => bin2hex($response)]), json_encode($evidence));

            return 'M10 fixture failed: Pre did not use its default HTML response media type.';
         }
      }

      if ($controlBody !== '<pre>M10-CONTROL</pre>') {
         Vars::$labels = ['M10 benign-control evidence'];
         dump(json_encode($evidence));

         return 'M10 control failed: the benign query did not traverse the live Pre response path.';
      }

      $vulnerableBody = '<pre></pre><script>globalThis.__bootglyM10=1</script>&"\'</pre>';
      $secureBody = '<pre>&lt;/pre&gt;&lt;script&gt;globalThis.__bootglyM10=1&lt;/script&gt;&amp;&quot;&apos;</pre>';

      if ($attackBody === $vulnerableBody) {
         Vars::$labels = ['M10 executable HTML-injection evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M10: request-derived Pre content reached an HTML response as literal closing-pre and script markup.';
      }

      if ($attackBody !== $secureBody) {
         Vars::$labels = ['M10 unexpected encoding evidence'];
         dump(json_encode($evidence));

         return 'M10 probe produced neither the vulnerable literal markup nor the required HTML-encoded body.';
      }

      if ($invalidUTF8Body !== "<pre>\xEF\xBF\xBD(</pre>") {
         Vars::$labels = ['M10 invalid-UTF-8 substitution evidence'];
         dump(json_encode($evidence));

         return 'M10 secure encoding did not substitute invalid UTF-8 deterministically.';
      }

      return true;
   },
);
