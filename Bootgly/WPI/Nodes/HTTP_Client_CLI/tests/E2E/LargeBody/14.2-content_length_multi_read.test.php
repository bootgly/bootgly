<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? HCLI-10 regression — the transport reads at most 65535 bytes per read
//   event, so every response bigger than one read is split by construction.
//   Before the fix the first event's receipt charged more bytes than the
//   buffer held, the caller wiped $pendingBuffer (headers included), and a
//   512 KiB download died as code 0 'Truncated Response' after one read.
return new Specification(
   description: 'It should download a Content-Length body larger than one read',

   response: function (): string {
      $body = str_repeat('A', 524287) . 'Z';

      return "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: 524288\r\nConnection: close\r\n\r\n{$body}";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/large-body'
      );
   },

   test: function (Response $Response): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: "a body larger than one read completes: code {$Response->code} ({$Response->status})"
      );
      yield assert(
         assertion: strlen($Response->Body->raw) === 524288,
         description: 'all 524288 body bytes arrive (got ' . strlen($Response->Body->raw) . ')'
      );
      yield assert(
         assertion: ($Response->Body->raw[0] ?? '') === 'A'
            && substr($Response->Body->raw, -1) === 'Z'
            && $Response->Body->waiting === false,
         description: 'first and last body bytes are intact'
      );
   }
);
