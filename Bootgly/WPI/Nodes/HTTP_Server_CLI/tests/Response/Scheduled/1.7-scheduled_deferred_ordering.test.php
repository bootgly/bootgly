<?php

use function fclose;
use function fread;
use function fwrite;
use function microtime;
use function round;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function strpos;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$connection2 = null;
$sendTime = 0.0;

return new Specification(
   Separator: new Separator(line: ''),

   requests: [
      function (string $hostPort) use (&$connection2, &$sendTime): string {
         // ! Open a separate connection for the deferred (slow) request
         $connection2 = stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 5);
         fwrite($connection2, "GET /deferred/http HTTP/1.1\r\nHost: localhost\r\n\r\n");
         $sendTime = microtime(true);

         // : Return the fast request for the main connection
         return "GET /fast HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ Fast sync endpoint
      yield $Router->route('/fast', function (Request $Request, Response $Response) {
         return $Response(body: 'Fast Response!');
      }, GET);

      // @ Deferred endpoint (async HTTP request to external host)
      yield $Router->route('/deferred/http', function (Request $Request, Response $Response) {
         return $Response->defer(function ()
         use ($Response) {
            // @ Open connection to example.com
            $client = stream_socket_client('tcp://example.com:80', $errno, $errstr, timeout: 5);
            fwrite($client, "GET / HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n");
            stream_set_blocking($client, false);
            // @ Suspend with socket: event loop resumes when response is readable
            $Response->wait($client);

            // @ Read HTTP response
            $raw = fread($client, 8192);
            fclose($client);
            // @ Extract status code
            $statusLine = substr($raw, 0, strpos($raw, "\r\n"));
            $statusCode = substr($statusLine, 9, 3);
            $Response(body: 'Async HTTP: ' . $statusCode);
         });
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$connection2, &$sendTime) {
      $fastReceivedAt = microtime(true);

      // @ Assert: Fast Response (main connection — arrived first)
      $expectedFast = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 14\r
      \r
      Fast Response!
      HTML_RAW;
      if ($responses[0] !== $expectedFast) {
         Vars::$labels = ['Fast Response:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expectedFast));
         return 'Fast response not matched';
      }

      // @ Read Deferred Response (separate connection — blocks until data arrives)
      stream_set_blocking($connection2, true);
      stream_set_timeout($connection2, 10);
      $deferredRaw = fread($connection2, 8192);
      if ($deferredRaw === false) $deferredRaw = '';
      fclose($connection2);
      $deferredReceivedAt = microtime(true);
      // @ Assert: Deferred Response content
      $expectedDeferred = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 15\r
      \r
      Async HTTP: 200
      HTML_RAW;
      if ($deferredRaw !== $expectedDeferred) {
         Vars::$labels = ['Deferred Response:', 'Expected:'];
         dump(json_encode($deferredRaw), json_encode($expectedDeferred));
         return 'Deferred response not matched';
      }
      // @ Assert: Ordering — fast response arrived before deferred completed
      $fastMs = round(($fastReceivedAt - $sendTime) * 1000);
      $deferredMs = round(($deferredReceivedAt - $sendTime) * 1000);
      if ($deferredMs <= $fastMs) {
         Vars::$labels = ['Fast (ms):', 'Deferred (ms):'];
         dump($fastMs, $deferredMs);
         return 'Expected deferred to take longer than fast (non-blocking proof)';
      }

      return true;
   }
);
