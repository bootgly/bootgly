<?php

use function fclose;
use function fread;
use function fwrite;
use function microtime;
use function stream_set_blocking;
use function stream_set_timeout;
use function strpos;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$connection2 = null;
$dependencyPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($dependencyPair === false) {
   throw new RuntimeException('Could not create the deferred dependency fixture.');
}
[$dependencyWorker, $dependencyTest] = $dependencyPair;

return new Specification(
   Separator: new Separator(line: ''),

   requests: [
      function (
         string $hostPort,
         int $testIndex = 0
      ) use (&$connection2): string {
         // ! Open a separate connection for the deferred (slow) request
         $connection2 = stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 5);
         fwrite(
            $connection2,
            "GET /deferred/http HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n"
         );
         // : Return the fast request for the main connection
         return "GET /fast HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (
      Request $Request,
      Response $Response,
      Router $Router
   ) use ($dependencyWorker, $dependencyTest)
   {
      // @ Fast sync endpoint
      yield $Router->route('/fast', function (Request $Request, Response $Response) {
         return $Response(body: 'Fast Response!');
      }, GET);

      // @ Deferred endpoint (delayed local HTTP dependency)
      yield $Router->route('/deferred/http', function (
         Request $Request,
         Response $Response
      ) use ($dependencyWorker, $dependencyTest) {
         return $Response->defer(function (Response $Response) use (
            $dependencyWorker,
            $dependencyTest
         ) {
            @fclose($dependencyTest);
            stream_set_blocking($dependencyWorker, false);
            fwrite(
               $dependencyWorker,
               "GET /dependency HTTP/1.1\r\nHost: local.fixture\r\n\r\n"
            );

            // @ The test peer releases this only after observing the fast response.
            $Response->wait($dependencyWorker);

            // @ Read HTTP response
            $raw = fread($dependencyWorker, 8192);
            fclose($dependencyWorker);
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

   test: function (array $responses) use (
      &$connection2,
      $dependencyWorker,
      $dependencyTest
   ) {
      $fastReceivedAt = microtime(true);
      $fast = preg_replace("/Date: .*\r\n/", '', $responses[0]) ?? $responses[0];

      // @ Assert: Fast Response (main connection — arrived first)
      $expectedFast = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 14\r
      \r
      Fast Response!
      HTML_RAW;
      if ($fast !== $expectedFast) {
         Vars::$labels = ['Fast Response:', 'Expected:'];
         dump(json_encode($fast), json_encode($expectedFast));
         return 'Fast response not matched';
      }

      // @ Release the dependency only after the fast response was observed,
      // making the ordering causal rather than timer-dependent.
      if (is_resource($dependencyWorker)) {
         @fclose($dependencyWorker);
      }
      stream_set_blocking($dependencyTest, true);
      stream_set_timeout($dependencyTest, 10);
      $dependencyRequest = '';
      while (str_contains($dependencyRequest, "\r\n\r\n") === false) {
         $chunk = fread($dependencyTest, 8192);
         if ($chunk === false || $chunk === '') {
            break;
         }
         $dependencyRequest .= $chunk;
      }
      $expectedDependencyRequest = "GET /dependency HTTP/1.1\r\n"
         . "Host: local.fixture\r\n\r\n";
      if ($dependencyRequest !== $expectedDependencyRequest) {
         return 'Deferred dependency request not matched: '
            . json_encode($dependencyRequest);
      }

      $dependencyResponse = "HTTP/1.1 200 OK\r\n"
         . "Content-Length: 0\r\n"
         . "Connection: close\r\n\r\n";
      $offset = 0;
      while ($offset < strlen($dependencyResponse)) {
         $written = fwrite($dependencyTest, substr($dependencyResponse, $offset));
         if ($written === false || $written === 0) {
            break;
         }
         $offset += $written;
      }
      fclose($dependencyTest);
      if ($offset !== strlen($dependencyResponse)) {
         return 'Deferred dependency response was not written completely';
      }

      // @ Read Deferred Response (separate connection — blocks until data arrives)
      stream_set_blocking($connection2, true);
      stream_set_timeout($connection2, 10);
      $deferredRaw = fread($connection2, 8192);
      if ($deferredRaw === false) $deferredRaw = '';
      fclose($connection2);
      $deferredReceivedAt = microtime(true);
      $deferred = preg_replace("/Date: .*\r\n/", '', $deferredRaw) ?? $deferredRaw;
      // @ Assert: Deferred Response content
      $expectedDeferred = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 15\r
      \r
      Async HTTP: 200
      HTML_RAW;
      if ($deferred !== $expectedDeferred) {
         Vars::$labels = ['Deferred Response:', 'Expected:'];
         dump(json_encode($deferred), json_encode($expectedDeferred));
         return 'Deferred response not matched: ' . json_encode($deferred);
      }
      // @ Assert: Ordering — fast response arrived before deferred completed
      if ($deferredReceivedAt <= $fastReceivedAt) {
         Vars::$labels = ['Fast:', 'Deferred:'];
         dump($fastReceivedAt, $deferredReceivedAt);
         return 'Expected deferred to take longer than fast (non-blocking proof)';
      }

      return true;
   }
);
