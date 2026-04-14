<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;


return new Specification(
   description: 'It should encode Request to a raw HTTP string',
   test: function () {
      $Request = new Request;
      $Request('GET', '/api/status', ['Accept' => 'text/plain']);

      $length = null;
      $raw = Encoder_::encode(
         $Request->method,
         $Request->URI,
         $Request->protocol,
         $Request->Header->build(),
         $Request->Body->raw,
         'example.com',
         80,
         $length
      );

      // @ Request line
      yield assert(
         assertion: str_starts_with($raw, "GET /api/status HTTP/1.1\r\n"),
         description: 'Request line present'
      );

      // @ Host header (port 80 = omit port)
      yield assert(
         assertion: str_contains($raw, "Host: example.com\r\n"),
         description: 'Host header without port for 80'
      );

      // @ Accept header
      yield assert(
         assertion: str_contains($raw, "Accept: text/plain\r\n"),
         description: 'Custom Accept header present'
      );

      // @ Connection header auto-set
      yield assert(
         assertion: str_contains($raw, "Connection: keep-alive\r\n"),
         description: 'Connection header auto-set'
      );

      // @ User-Agent header auto-set
      yield assert(
         assertion: str_contains($raw, "User-Agent: Bootgly/HTTP_Client_CLI\r\n"),
         description: 'User-Agent header auto-set'
      );

      // @ Non-standard port
      $Request2 = new Request;
      $Request2('GET', '/');

      $length2 = null;
      $raw2 = Encoder_::encode(
         $Request2->method,
         $Request2->URI,
         $Request2->protocol,
         $Request2->Header->build(),
         $Request2->Body->raw,
         'localhost',
         8080,
         $length2
      );

      yield assert(
         assertion: str_contains($raw2, "Host: localhost:8080\r\n"),
         description: 'Host header with non-standard port: localhost:8080'
      );
   }
);
