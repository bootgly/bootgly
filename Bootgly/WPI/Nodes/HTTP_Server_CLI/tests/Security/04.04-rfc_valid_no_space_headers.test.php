<?php

use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function json_encode;
use function str_contains;
use function strlen;
use function substr;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — RFC-valid no-space header fields must be parsed canonically.
 *
 * RFC 9110 field syntax is `field-name ":" OWS field-value`; the SP after
 * `:` is optional. Ignoring `Content-Length:10` silently mis-frames the body
 * as a pipelined request and can dispatch an attacker-controlled second
 * request. Ignoring `Host:localhost`, `Cookie:a=b`, `Authorization:Basic ...`,
 * or `Transfer-Encoding:chunked` makes application-facing lookup diverge from
 * framing semantics.
 */

$probe = [
   'cl' => '',
   'host' => '',
   'lookup' => '',
   'te' => '',
];

return new Specification(
   description: 'RFC-valid no-space headers must parse consistently (CL, TE, Host, Cookie, Authorization)',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      $send = static function (string $raw) use ($hostPort): string {
         $socket = @stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 5);
         if (! is_resource($socket)) {
            return '';
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 2);
         @fwrite($socket, $raw);

         $response = '';
         while (true) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }
               $meta = stream_get_meta_data($socket);
               if (($meta['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }

            $response .= $chunk;
         }

         @fclose($socket);
         return $response;
      };

      // # Content-Length no-space: body bytes must not be re-dispatched as a request.
      $body = "GET /smuggled HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "\r\n";
      $probe['cl'] = $send(
         "POST /nospace-cl HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Content-Length:" . strlen($body) . "\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $body
      );

      // # Host no-space: valid HTTP/1.1 Host must satisfy host-required parsing.
      $probe['host'] = $send(
         "GET /nospace-host HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host:localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n"
      );

      // # Application lookup no-space: auth/cookie values must be visible.
      $probe['lookup'] = $send(
         "GET /nospace-lookup HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Authorization:Basic abc123\r\n"
         . "Cookie:sid=ZXKQ; theme=dark\r\n"
         . "Connection: close\r\n"
         . "\r\n"
      );

      // # Transfer-Encoding no-space: chunked body must be decoded, not ignored.
      $probe['te'] = $send(
         "POST /nospace-te HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Transfer-Encoding:chunked\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . "5\r\nHELLO\r\n"
         . "0\r\n\r\n"
      );

      return "GET /nospace-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/nospace-cl', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'CL-OK body=' . $Request->Body->raw);
      }, POST);

      yield $Router->route('/smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SMUGGLED-REACHED');
      }, GET);

      yield $Router->route('/nospace-host', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HOST=' . ($Request->Header->get('Host') ?? ''));
      }, GET);

      yield $Router->route('/nospace-lookup', function (Request $Request, Response $Response) {
         $auth = $Request->Header->get('Authorization') ?? '';
         $sid = $Request->Header->Cookies->get('sid') ?? '';
         return $Response(code: 200, body: "AUTH={$auth};SID={$sid}");
      }, GET);

      yield $Router->route('/nospace-te', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'BODY=' . $Request->Body->raw);
      }, POST);

      yield $Router->route('/nospace-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response:'];
         dump(json_encode(substr($response, 0, 400)));
         return 'Harness request did not reach /nospace-harness.';
      }

      $cl = $probe['cl'];
      $host = $probe['host'];
      $lookup = $probe['lookup'];
      $te = $probe['te'];

      if ($cl === '' || str_contains($cl, 'SMUGGLED-REACHED')) {
         Vars::$labels = ['Content-Length no-space response:'];
         dump(json_encode(substr($cl, 0, 400)));
         return 'Content-Length:10 without a space was ignored and body bytes were dispatched as a pipelined request.';
      }

      if (! str_contains($host, 'HOST=localhost')) {
         Vars::$labels = ['Host no-space response:'];
         dump(json_encode(substr($host, 0, 400)));
         return 'Host:localhost without a space was not parsed as the HTTP/1.1 Host header. Response: '
            . json_encode(substr($host, 0, 200));
      }

      if (! str_contains($lookup, 'AUTH=Basic abc123;SID=ZXKQ')) {
         Vars::$labels = ['Lookup no-space response:'];
         dump(json_encode(substr($lookup, 0, 400)));
         return 'Authorization/Cookie no-space headers were not visible through Request Header lookup. Response: '
            . json_encode(substr($lookup, 0, 200));
      }

      if (! str_contains($te, 'BODY=HELLO')) {
         Vars::$labels = ['Transfer-Encoding no-space response:'];
         dump(json_encode(substr($te, 0, 400)));
         return 'Transfer-Encoding:chunked without a space did not decode the chunked body. Response: '
            . json_encode(substr($te, 0, 200));
      }

      return true;
   }
);
