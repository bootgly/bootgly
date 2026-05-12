<?php

namespace projects\Bootgly\WPI;


use function fclose;
use function feof;
use function fread;
use function fwrite;
use function json_encode;
use function microtime;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_pair;
use function strlen;
use function strpos;
use function substr;
use function usleep;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router)
{
   // @ Sync baseline (no defer)
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Hello World!');
   }, GET);

   // ---

   // @ Deferred response: tick-based (Fiber resumed every loop iteration)
   yield $Router->route('/deferred/tick', function (Request $Request, Response $Response) {
      return $Response->defer(function () use ($Response) {
         // @ Simulate awaiting async work (~100ms delay across loop iterations)
         $start = microtime(true);
         while (microtime(true) - $start < 0.1) {
            $Response->wait();
         }

         // @ Complete the response after resuming
         $Response(body: 'Deferred Tick!');
      });
   }, GET);
   // @ Deferred response: I/O-aware (Fiber resumed only when socket is readable)
   yield $Router->route('/deferred/io', function (Request $Request, Response $Response) {
      return $Response->defer(function () use ($Response) {
         // @ Create a local socket pair to simulate async I/O
         [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($reader, false);

         // @ Write data and close writer (simulates external service response)
         fwrite($writer, 'Data from async I/O!');
         fclose($writer);

         // @ Suspend with socket: event loop registers in stream_select()
         $Response->wait($reader);

         // @ Socket is readable — consume data
         $data = fread($reader, 8192);
         fclose($reader);

         $Response(body: 'Deferred I/O: ' . $data);
      });
   }, GET);

   // ---

   // @ Deferred response: async HTTP request to external host (non-blocking)
   yield $Router->route('/deferred/http', function (Request $Request, Response $Response) {
      return $Response->defer(function () use ($Response) {
         // @ Open connection to example.com (blocking connect)
         $client = stream_socket_client(
            'tcp://example.com:80',
            $errno,
            $errstr,
            timeout: 5
         );
         fwrite($client, "GET / HTTP/1.0\r\nHost: example.com\r\nConnection: close\r\n\r\n");
         stream_set_blocking($client, false);

         // @ Suspend with socket: event loop resumes when response is readable
         $Response->wait($client);

         // @ Read HTTP response
         $raw = fread($client, 8192);
         fclose($client);

         // @ Extract status code from HTTP response (e.g. "HTTP/1.0 200 OK")
         $statusLine = substr($raw, 0, strpos($raw, "\r\n"));
         $statusCode = substr($statusLine, 9, 3);

         $Response(body: 'Async HTTP: ' . $statusCode);
      });
   }, GET);
   // @ Blocking HTTP request (synchronous — blocks the worker entirely)
   yield $Router->route('/blocking/http', function (Request $Request, Response $Response) {
      // @ Open connection to example.com (blocks until complete)
      $client = stream_socket_client(
         'tcp://example.com:80',
         $errno,
         $errstr,
         timeout: 5
      );
      fwrite($client, "GET / HTTP/1.0\r\nHost: example.com\r\nConnection: close\r\n\r\n");

      // @ Read HTTP response (blocking — worker cannot process anything else)
      $raw = fread($client, 8192);
      fclose($client);

      // @ Extract status code from HTTP response
      $statusLine = substr($raw, 0, strpos($raw, "\r\n"));
      $statusCode = substr($statusLine, 9, 3);

      return $Response(body: 'Blocking HTTP: ' . $statusCode);
   }, GET);

   // ---

   // @ Deferred response: async file download (non-blocking, multi-chunk I/O)
   yield $Router->route('/deferred/download', function (Request $Request, Response $Response) {
      return $Response->defer(function () use ($Response) {
         // @ Open connection to test file server (blocking connect)
         $client = stream_socket_client(
            'tcp://speedtest.tele2.net:80',
            $errno,
            $errstr,
            timeout: 5
         );
         fwrite($client, "GET /10MB.zip HTTP/1.0\r\nHost: speedtest.tele2.net\r\nConnection: close\r\n\r\n");
         stream_set_blocking($client, false);

         // @ Read in chunks — each wait() yields to the event loop
         $totalBytes = 0;
         while (!feof($client)) {
            $Response->wait($client);

            $chunk = fread($client, 65536);
            if ($chunk !== false && $chunk !== '') {
               $totalBytes += strlen($chunk);
            }
         }
         fclose($client);

         $Response(body: 'Async Download: ' . $totalBytes . ' bytes');
      });
   }, GET);
   // @ Blocking file download (synchronous — blocks the worker entirely)
   yield $Router->route('/blocking/download', function (Request $Request, Response $Response) {
      // @ Open connection to test file server (blocks until complete)
      $client = stream_socket_client(
         'tcp://speedtest.tele2.net:80',
         $errno,
         $errstr,
         timeout: 5
      );
      fwrite($client, "GET /10MB.zip HTTP/1.0\r\nHost: speedtest.tele2.net\r\nConnection: close\r\n\r\n");

      // @ Read entire file (blocking — worker cannot process anything else)
      $totalBytes = 0;
      while (!feof($client)) {
         $chunk = fread($client, 65536);
         if ($chunk !== false && $chunk !== '') {
            $totalBytes += strlen($chunk);
         }
      }
      fclose($client);

      return $Response(body: 'Blocking Download: ' . $totalBytes . ' bytes');
   }, GET);

   // ---

   // @ Deferred response: multiple sequential HTTP requests (non-blocking)
   yield $Router->route('/deferred/multi', function (Request $Request, Response $Response) {
      return $Response->defer(function () use ($Response) {
         $hosts = ['example.com', 'httpbin.org', 'example.com', 'httpbin.org', 'example.com'];
         $results = [];

         foreach ($hosts as $host) {
            // @ Open connection (blocking connect, then non-blocking read)
            $client = stream_socket_client(
               "tcp://{$host}:80",
               $errno,
               $errstr,
               timeout: 5
            );
            fwrite($client, "GET / HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
            stream_set_blocking($client, false);

            // @ Suspend: event loop resumes when response arrives
            $Response->wait($client);

            // @ Read and extract status code
            $raw = fread($client, 8192);
            fclose($client);

            $statusLine = substr($raw, 0, strpos($raw, "\r\n"));
            $results[] = $host . ':' . substr($statusLine, 9, 3);
         }

         $Response(body: 'Async Multi: ' . json_encode($results));
      });
   }, GET);
   // @ Blocking: multiple sequential HTTP requests (blocks for each one)
   yield $Router->route('/blocking/multi', function (Request $Request, Response $Response) {
      $hosts = ['example.com', 'httpbin.org', 'example.com', 'httpbin.org', 'example.com'];
      $results = [];

      foreach ($hosts as $host) {
         // @ Each request blocks the worker completely
         $client = stream_socket_client(
            "tcp://{$host}:80",
            $errno,
            $errstr,
            timeout: 5
         );
         fwrite($client, "GET / HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");

         $raw = fread($client, 8192);
         fclose($client);

         $statusLine = substr($raw, 0, strpos($raw, "\r\n"));
         $results[] = $host . ':' . substr($statusLine, 9, 3);
      }

      return $Response(body: 'Blocking Multi: ' . json_encode($results));
   }, GET);

   // ---

   // @ Deferred response: simulated database query with latency (tick-based wait loop)
   yield $Router->route('/deferred/db', function (Request $Request, Response $Response) {
      return $Response->defer(function () use ($Response) {
         // @ Simulate 100 sequential DB queries with ~10000ms latency each
         $results = [];
         for ($i = 1; $i <= 100; $i++) {
            $start = microtime(true);
            while (microtime(true) - $start < 0.1) {
               // @ Yield to event loop between queries — worker serves other requests
               $Response->wait();
            }
            $results[] = "query{$i}:ok";
         }

         $Response(body: 'Async DB: ' . json_encode($results));
      });
   }, GET);
   // ---

   // @ Blocking: simulated database query with latency (usleep blocks everything)
   yield $Router->route('/blocking/db', function (Request $Request, Response $Response) {
      // @ Simulate 100 sequential DB queries with ~10000ms latency each
      $results = [];
      for ($i = 1; $i <= 100; $i++) {
         // @ Worker is completely blocked — no other requests processed
         usleep(100_000);
         $results[] = "query{$i}:ok";
      }

      return $Response(body: 'Blocking DB: ' . json_encode($results));
   }, GET);

   // ---

   // @ Catch-all 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
