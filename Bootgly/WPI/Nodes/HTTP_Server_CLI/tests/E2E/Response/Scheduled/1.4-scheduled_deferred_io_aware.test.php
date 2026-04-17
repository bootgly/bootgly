<?php

use function fclose;
use function fread;
use function fwrite;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/io HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/', function (Request $Request, Response $Response) {
         return $Response(body: 'Hello World!');
      }, GET);

      yield $Router->route('/deferred/io', function (Request $Request, Response $Response) {
         return $Response->defer(function ()
         use ($Response) {
            // @ Create a local socket pair to simulate async I/O
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($reader, false);

            // @ Write data and close writer (simulates external service response)
            fwrite($writer, 'I/O Ready!');
            fclose($writer);

            // @ Suspend with socket: event loop registers in stream_select()
            $Response->wait($reader);

            // @ Socket is readable — consume data
            $data = fread($reader, 8192);
            fclose($reader);

            $Response(body: $data);
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
      Content-Length: 10\r
      \r
      I/O Ready!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'I/O-aware deferred response not matched';
      }

      return true;
   }
);
