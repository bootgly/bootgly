<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function is_resource;
use function json_encode;
use function preg_replace;
use function stream_set_blocking;
use function stream_socket_pair;
use Fiber;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/removed-readiness HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/deferred/removed-readiness', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($reader, false);
            stream_set_blocking($writer, false);

            $Closer = new Fiber(function () use ($reader, $writer): void {
               Fiber::suspend();

               TCP_Server_CLI::$Event->del($reader, TCP_Server_CLI::$Event::EVENT_READ);
               fclose($writer);
               fclose($reader);
            });
            $Closer->start();
            TCP_Server_CLI::$Event->schedule($Closer);

            $Response->wait($reader);

            $Response(body: is_resource($reader) ? 'Still waiting' : 'Released');
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
      Content-Length: 8\r
      \r
      Released
      HTML_RAW;

      if ($normalized !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($normalized), json_encode($expected));
         return 'Removed readiness deferred response not released';
      }

      return true;
   }
);
