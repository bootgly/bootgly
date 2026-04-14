<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;


return new Specification(
   description: 'It should encode Host header correctly for HTTPS (port 443)',
   Separator: new Separator(line: 'SSL/TLS'),
   test: function () {
      $Request = new Request;
      $Request('GET', '/secure');

      $length = null;

      // @ Port 443 should omit port in Host header (same as port 80)
      $raw443 = Encoder_::encode(
         $Request->method,
         $Request->URI,
         $Request->protocol,
         $Request->Header->build(),
         $Request->Body->raw,
         'secure.example.com',
         443,
         $length
      );

      yield assert(
         assertion: str_contains($raw443, "Host: secure.example.com\r\n"),
         description: 'Port 443: Host header without port'
      );

      yield assert(
         assertion: !str_contains($raw443, 'Host: secure.example.com:443'),
         description: 'Port 443: no :443 suffix in Host'
      );

      // @ Non-standard HTTPS port should include port in Host header
      $raw8443 = Encoder_::encode(
         $Request->method,
         $Request->URI,
         $Request->protocol,
         $Request->Header->build(),
         $Request->Body->raw,
         'secure.example.com',
         8443,
         $length
      );

      yield assert(
         assertion: str_contains($raw8443, "Host: secure.example.com:8443\r\n"),
         description: 'Port 8443: Host header with port'
      );
   }
);
