<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function json_encode;
use function preg_replace;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/readiness-write HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/deferred/readiness-write', function (Request $Request, Response $Response) {
         return $Response->defer(function ()
         use ($Response) {
            // @ Create a local socket pair to simulate async write/read I/O
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($reader, false);
            stream_set_blocking($writer, false);

            // @ Suspend with explicit write readiness
            $Response->wait(Readiness::write($writer));

            // @ Socket is writable — produce data
            fwrite($writer, 'Writable OK!');
            fclose($writer);

            // @ Suspend with explicit read readiness
            $Response->wait(Readiness::read($reader));

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
      $normalized = preg_replace("/Date: .*\r\n/", '', $response) ?? $response;

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Writable OK!
      HTML_RAW;

      // @ Assert
      if ($normalized !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($normalized), json_encode($expected));
         return 'Write-readiness deferred response not matched';
      }

      return true;
   }
);
