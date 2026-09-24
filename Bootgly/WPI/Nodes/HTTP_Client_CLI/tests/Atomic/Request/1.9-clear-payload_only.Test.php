<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Test(
   description: 'It should clear only the payload — the body and the fields that describe it (HCLI-13)',
   test: function () {
      $Request = new Request;
      $Request('POST', '/pay', [
         'Authorization' => 'Bearer SECRET',
         'accept' => 'application/json',
         'X-API-Key' => 'key-SECRET',
         // ! Any spelling of a payload field goes
         'content-encoding' => 'gzip',
         'CONTENT-LANGUAGE' => 'en',
         'Content-Location' => '/pay/1',
         'Expect' => '100-continue',
         'Digest' => 'sha-256=x',
         'Content-Digest' => 'sha-256=:x:',
         'Repr-Digest' => 'sha-256=:x:',
      ], 'card=4111');
      $Request->encoded = 'memo';

      $Request->clear();
      $names = array_map('strtolower', array_keys($Request->Header->fields));
      sort($names);

      yield assert(
         assertion: $names === ['accept', 'authorization', 'x-api-key'],
         description: 'Every non-payload header stays: ' . json_encode($names)
      );

      yield assert(
         assertion: $Request->Body->raw === '' && $Request->encoded === null,
         description: 'The body and the memoized encoding are gone'
      );
   }
);
