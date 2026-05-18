<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/** @var resource|null $connection2 */
$connection2 = null;

return new Specification(
   Separator: new Separator(line: ''),

   requests: [
      function (string $hostPort, int $testIndex = 0) use (&$connection2): string {
         $Connection = stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 5);

         if ($Connection !== false) {
            $connection2 = $Connection;
            fwrite($connection2, "GET /deferred/json HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n\r\n");
         }

         return "GET /fast/json HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/fast/json', function (Request $Request, Response $Response) {
         return $Response->code(200)->JSON->send(['route' => 'fast']);
      }, GET);

      yield $Router->route('/deferred/json', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            $Response->wait();
            $Response->code(200)->JSON->send(['route' => 'deferred']);
         });
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$connection2) {
      $fast = preg_replace("/Date: .*\r\n/", '', $responses[0]) ?? $responses[0];

      $expectedFast = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 16\r
      \r
      {"route":"fast"}
      HTML_RAW;

      if ($fast !== $expectedFast) {
         Vars::$labels = ['Fast Response:', 'Expected:'];
         dump(json_encode($fast), json_encode($expectedFast));
         return 'Fast JSON response not matched';
      }

      if (is_resource($connection2) === false) {
         return 'Deferred connection was not opened';
      }

      stream_set_blocking($connection2, true);
      stream_set_timeout($connection2, 10);
      $deferred = (string) fread($connection2, 8192);
      fclose($connection2);

      $deferred = preg_replace("/Date: .*\r\n/", '', $deferred) ?? $deferred;

      $expectedDeferred = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 20\r
      \r
      {"route":"deferred"}
      HTML_RAW;

      if ($deferred !== $expectedDeferred) {
         Vars::$labels = ['Deferred Response:', 'Expected:'];
         dump(json_encode($deferred), json_encode($expectedDeferred));
         return 'Deferred JSON response was contaminated by interleaved sync response';
      }

      return true;
   }
);
