<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? H-HCLI-4 — a Content-Length or close-delimited body must cost what a chunked one
//   costs: its head is parsed once and each read only appends. Re-parsing the whole
//   message on every read (~240 reads for 15 MiB) made both ~30× slower than chunked
//   (1.1 s vs 0.03 s). Timed against a chunked control in the same run, so the bound
//   follows the host's speed.
$size = 15 * 1024 * 1024;
$times = [];

return new Test(
   description: 'It should decode large Content-Length and close-delimited bodies in linear time',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function () use ($size): string {
         $chunk = str_repeat('C', 65536);
         $chunks = str_repeat(dechex(65536) . "\r\n{$chunk}\r\n", intdiv($size, 65536));

         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n{$chunks}0\r\n\r\n";
      },
      function () use ($size): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: {$size}\r\nConnection: close\r\n\r\n" . str_repeat('L', $size - 1) . 'Z';
      },
      function () use ($size): string {
         return "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . str_repeat('D', $size - 1) . 'Z';
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$times): Response {
         $started = hrtime(true);
         $Response = $Client->request(method: 'GET', URI: '/linear/chunked');
         $times['chunked'] = (hrtime(true) - $started) / 1e9;

         return $Response;
      },
      function (HTTP_Client_CLI $Client) use (&$times): Response {
         $started = hrtime(true);
         $Response = $Client->request(method: 'GET', URI: '/linear/content-length');
         $times['length'] = (hrtime(true) - $started) / 1e9;

         return $Response;
      },
      function (HTTP_Client_CLI $Client) use (&$times): Response {
         $started = hrtime(true);
         $Response = $Client->request(method: 'GET', URI: '/linear/close-delimited');
         $times['close'] = (hrtime(true) - $started) / 1e9;

         return $Response;
      },
   ],

   test: function (Response $Chunked, Response $Length, Response $Close) use ($size, &$times) {
      $bound = 4 * ($times['chunked'] ?? 0) + 0.2;

      yield assert(
         assertion: $Chunked->code === 200 && strlen($Chunked->Body->raw) === $size,
         description: "the chunked control downloads whole: {$Chunked->code} {$Chunked->status} (" . strlen($Chunked->Body->raw) . ' bytes)'
      );
      yield assert(
         assertion: $Length->code === 200 && strlen($Length->Body->raw) === $size
            && $Length->Body->downloaded === $size && $Length->Body->waiting === false
            && ($Length->Body->raw[0] ?? '') === 'L' && substr($Length->Body->raw, -1) === 'Z',
         description: "the Content-Length body is whole: {$Length->code} {$Length->status} (" . strlen($Length->Body->raw) . ' bytes)'
      );
      yield assert(
         assertion: $Close->code === 200 && strlen($Close->Body->raw) === $size
            && $Close->Body->length === $size && $Close->Body->downloaded === $size
            && $Close->Body->waiting === false && substr($Close->Body->raw, -1) === 'Z',
         description: "the close-delimited body is whole at the close: {$Close->code} {$Close->status} (" . strlen($Close->Body->raw) . ' bytes)'
      );
      yield assert(
         assertion: ($times['length'] ?? INF) <= $bound,
         description: sprintf('Content-Length costs about what chunked costs: %.3fs vs chunked %.3fs (bound %.3fs)', $times['length'] ?? -1, $times['chunked'] ?? -1, $bound)
      );
      yield assert(
         assertion: ($times['close'] ?? INF) <= $bound,
         description: sprintf('close-delimited costs about what chunked costs: %.3fs vs chunked %.3fs (bound %.3fs)', $times['close'] ?? -1, $times['chunked'] ?? -1, $bound)
      );
   }
);
