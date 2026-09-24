<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;


return new Test(
   description: 'It should add the default Host, Connection and User-Agent unless those very fields are present',
   test: function () {
      $count = static fn (string $raw, string $name): int => preg_match_all("/\\r\\n{$name}:/i", "\r\n{$raw}");

      // @ Lookalike names and values never stand in for the fields themselves
      $raw = Encoder_::encode(
         'GET',
         '/',
         'HTTP/1.1',
         "X-Forwarded-Host: forwarded.example\r\nX-User-Agent: bot\r\nProxy-Connection: close\r\nX-Note: Host: fake\r\n",
         host: 'origin.example',
         port: 8080
      );

      yield assert(
         assertion: $count($raw, 'Host') === 1 && str_contains($raw, "\r\nHost: origin.example:8080\r\n"),
         description: 'X-Forwarded-Host and a value quoting "Host:" do not suppress Host'
      );

      yield assert(
         assertion: $count($raw, 'Connection') === 1 && $count($raw, 'User-Agent') === 1,
         description: 'Proxy-Connection and X-User-Agent do not suppress Connection and User-Agent'
      );

      // @ The caller's own field wins, whatever its case — also on the first line
      $raw = Encoder_::encode(
         'GET',
         '/',
         'HTTP/1.1',
         "host: caller.example\r\nconnection: close\r\nuser-agent: mine\r\n",
         host: 'origin.example',
         port: 80
      );

      yield assert(
         assertion: $count($raw, 'Host') === 1 && $count($raw, 'Connection') === 1 && $count($raw, 'User-Agent') === 1
            && str_contains($raw, "\r\nhost: caller.example\r\n"),
         description: 'A caller field is never doubled: ' . json_encode($raw)
      );
   }
);
