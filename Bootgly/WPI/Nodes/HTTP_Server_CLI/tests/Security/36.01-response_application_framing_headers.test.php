<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M3 — application response headers must not conflict with the
 * framing selected by the HTTP/1 encoder.
 *
 * The route passes attacker-influenced framing fields through every public
 * Header insertion source. Lowercase names are intentional: HTTP field names
 * are case-insensitive, while this lets the native test client find the
 * encoder's canonical `Content-Length: 3` and capture the complete raw wire
 * response instead of waiting for a supplied false length.
 *
 * This began as a deliberately failing PoC. It is retained as a green
 * regression: every application-supplied framing variant must be removed,
 * while the encoder emits exactly one body-derived Content-Length.
 */

return new Specification(
   description: 'Application framing headers must not survive beside encoder-owned framing',
   Separator: new Separator(line: true),

   requests: [
      function (): string {
         return "GET /m3-framing HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function (): string {
         return "GET /m3-framing HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m3-framing', function (Request $Request, Response $Response) {
         $Response(
            code: 200,
            headers: [
               'transfer-encoding' => 'chunked',
               'content-length' => '999',
               'X-M3-Control' => 'preserved',
            ],
            body: 'abc',
         );

         // @ Exercise every serialization source and mixed casing. The
         //   encoder must remove them all, preserve X-M3-Control and emit
         //   exactly one body-derived Content-Length.
         $Response->Header->set('Content-Length', '123');
         $Response->Header->append('content-length', '7');
         $Response->Header->queue('CONTENT-LENGTH', '77');
         $Response->Header->preset('CoNtEnT-LeNgTh', '55');

         $Response->Header->set('Transfer-Encoding', 'identity');
         $Response->Header->append('transfer-encoding', 'gzip');
         $Response->Header->queue('TRANSFER-ENCODING', 'compress');
         $Response->Header->preset('TrAnSfEr-EnCoDiNg', 'deflate');

         return $Response;
      });
   },

   test: function (array $responses): bool|string {
      if (count($responses) !== 2) {
         return 'M3 probe did not receive both persistent-worker responses.';
      }

      foreach ($responses as $index => $response) {
         $probe = [
            'request' => $index + 1,
            'content_lengths' => [],
            'transfer_encodings' => [],
            'control_header' => '',
            'body' => '',
            'wire' => $response,
         ];
         $separator = strpos($response, "\r\n\r\n");

         if ($separator === false) {
            return 'M3 probe did not receive a complete HTTP response head.';
         }

         $head = substr($response, 0, $separator);
         $probe['body'] = substr($response, $separator + 4);

         foreach (array_slice(explode("\r\n", $head), 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
               continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name === 'content-length') {
               $probe['content_lengths'][] = $value;
            }
            else if ($name === 'transfer-encoding') {
               $probe['transfer_encodings'][] = $value;
            }
            else if ($name === 'x-m3-control') {
               $probe['control_header'] = $value;
            }
         }

         if ($probe['control_header'] !== 'preserved' || $probe['body'] !== 'abc') {
            Vars::$labels = ['M3 raw-wire control evidence'];
            dump(json_encode($probe));

            return 'M3 control header or three-byte response body was not preserved.';
         }

         if ($probe['transfer_encodings'] !== [] || $probe['content_lengths'] !== ['3']) {
            Vars::$labels = ['M3 conflicting framing evidence'];
            dump(json_encode($probe));

            return 'M3 framing conflict: expected no application Transfer-Encoding '
               . 'and exactly one encoder-owned Content-Length: 3 header.';
         }
      }

      return true;
   },
);
