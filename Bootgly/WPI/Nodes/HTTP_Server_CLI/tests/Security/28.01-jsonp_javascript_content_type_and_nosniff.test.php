<?php

use function str_contains;
use function strpos;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `JSONP::send()` emits JavaScript (`callback(...)`) but labels it
 * `Content-Type: application/json` (audit F-7).
 *
 * Why it matters — JSONP is, by construction, a CORS bypass for *reads*: a
 *   third-party page loads the endpoint with `<script src>` and the browser
 *   executes `callback({...})` regardless of MIME, handing the attacker the
 *   (cookie-authenticated) response body. The wrong `application/json` label
 *   also (a) misrepresents the payload and (b) lets a content sniffer treat a
 *   directly-navigated response as something it is not.
 *
 * Attack scenario — cross-origin data read:
 *
 *     <!-- attacker.example -->
 *     <script>function steal(d){ exfiltrate(d); }</script>
 *     <script src="https://victim/api?callback=steal"></script>
 *
 * Fixed behaviour — JSONP is served as `text/javascript` (the honest MIME for
 *   what is emitted) AND with `X-Content-Type-Options: nosniff`, so a directly
 *   navigated response cannot be content-sniffed into HTML. (The read-CSRF
 *   property is inherent to JSONP and is documented; the framework's job here
 *   is to label the bytes honestly and stop sniffing.)
 *
 * Observed on the wire by inspecting the response headers + body.
 */

return new Specification(
   description: 'JSONP must be served as text/javascript + nosniff, never application/json',
   Separator: new Separator(line: true),

   request: function (string $hostPort): string {
      // @ A namespaced (dotted) callback is a legitimate JSONP shape and must
      //   be preserved verbatim in the body.
      return "GET /jsonp-poc?callback=window.app.cb HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/jsonp-poc', function (Request $Request, Response $Response) {
         return $Response->JSONP->send(['secret' => 'data']);
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      // @ Reach-assertion: require a 200 so we know the /jsonp-poc handler ran.
      if (! str_contains($response, '200 OK')) {
         return 'Handler did not run (expected 200 OK from /jsonp-poc). '
            . 'Response: ' . substr($response, 0, 200);
      }

      // @ Split head/body.
      $sepPos = strpos($response, "\r\n\r\n");
      if ($sepPos === false) {
         return 'Malformed response (no CRLFCRLF): ' . substr($response, 0, 200);
      }
      $head = substr($response, 0, $sepPos);
      $body = substr($response, $sepPos + 4);

      // ? Honest MIME — JavaScript, not JSON.
      if (str_contains($head, 'Content-Type: application/json')) {
         return 'JSONP is mislabelled `Content-Type: application/json` while '
            . 'emitting JavaScript — any third-party <script src> can read it. '
            . 'Head: ' . substr($head, 0, 300);
      }
      if (! str_contains($head, 'Content-Type: text/javascript')) {
         return 'JSONP must set `Content-Type: text/javascript`. '
            . 'Head: ' . substr($head, 0, 300);
      }

      // ? Anti-sniffing.
      if (! str_contains($head, 'X-Content-Type-Options: nosniff')) {
         return 'JSONP must set `X-Content-Type-Options: nosniff`. '
            . 'Head: ' . substr($head, 0, 300);
      }

      // ? Dotted callback preserved verbatim.
      if (! str_contains($body, 'window.app.cb({"secret":"data"})')) {
         return 'JSONP body did not echo the namespaced callback verbatim. '
            . 'Body: ' . substr($body, 0, 200);
      }

      return true;
   }
);
