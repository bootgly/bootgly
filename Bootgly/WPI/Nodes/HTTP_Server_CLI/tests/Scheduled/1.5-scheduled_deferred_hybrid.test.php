<?php

use function fclose;
use function fread;
use function fwrite;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/hybrid HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/deferred/hybrid', function (Request $Request, Response $Response) {
         return $Response->defer(function ()
         use ($Response) {
            // @ Create a local socket pair to simulate async I/O
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($reader, false);

            // @ Phase 1: tick-based — write char by char with suspend between each
            $message = 'Hybrid OK!';
            for ($i = 0; $i < strlen($message); $i++) {
               fwrite($writer, $message[$i]);
               $Response->wait(); // tick-based: resume next iteration
            }
            fclose($writer);

            // @ Phase 2: I/O-aware — suspend with socket, resume when readable
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
      Hybrid OK!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Hybrid deferred response not matched';
      }

      return true;
   }
);
