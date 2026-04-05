<?php

use function fclose;
use function fread;
use function fwrite;
use function stream_set_blocking;
use function stream_socket_client;
use function strpos;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/http HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ Deferred endpoint (makes async HTTP request to external host)
      yield $Router->route('/deferred/http', function (Request $Request, Response $Response) {
         return $Response->defer(function ()
         use ($Response) {
            // @ Open connection to example.com (blocking connect)
            $client = stream_socket_client(
               'tcp://example.com:80',
               $errno,
               $errstr,
               timeout: 5
            );
            fwrite($client, "GET / HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n");
            stream_set_blocking($client, false);

            // @ Suspend with socket: event loop resumes when response is readable
            $Response->wait($client);

            // @ Read HTTP response
            $raw = fread($client, 8192);
            fclose($client);

            // @ Extract status code from HTTP response (e.g. "HTTP/1.1 200 OK")
            $statusLine = substr($raw, 0, strpos($raw, "\r\n"));
            $statusCode = substr($statusLine, 9, 3);

            $Response(body: 'Async HTTP: ' . $statusCode);
         });
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 15\r
      \r
      Async HTTP: 200
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Async HTTP deferred response not matched';
      }

      return true;
   }
);
