<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2\HPACK;


return new Specification(
   description: 'It should decode the RFC 7541 Appendix C.5/C.6 response sequences with a 256-octet table (eviction)',
   test: new Assertions(Case: function (): Generator {
      $response1 = [
         [':status', '302'],
         ['cache-control', 'private'],
         ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'],
         ['location', 'https://www.example.com']
      ];
      $response2 = [
         [':status', '307'],
         ['cache-control', 'private'],
         ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'],
         ['location', 'https://www.example.com']
      ];
      $response3 = [
         [':status', '200'],
         ['cache-control', 'private'],
         ['date', 'Mon, 21 Oct 2013 20:13:22 GMT'],
         ['location', 'https://www.example.com'],
         ['content-encoding', 'gzip'],
         ['set-cookie', 'foo=ASDJKHQKBZXOQWEOPIUAXQWEOIU; max-age=3600; version=1']
      ];

      // @ C.5 — three responses, dynamic table capped at 256 octets → evictions
      $HPACK = new HPACK(256);
      yield new Assertion(
         description: 'C.5.1 first response',
      )
         ->expect($HPACK->decode(hex2bin(
            '4803333032580770726976617465611d4d6f6e2c203231204f637420323031332032303a31333a32'
            . '3120474d546e1768747470733a2f2f7777772e6578616d706c652e636f6d'
         ), PHP_INT_MAX))
         ->to->be($response1)
         ->assert();

      yield new Assertion(
         description: 'C.5.2 second response (evicts the oldest entry, references survivors)',
      )
         ->expect($HPACK->decode(hex2bin('4803333037c1c0bf'), PHP_INT_MAX))
         ->to->be($response2)
         ->assert();

      yield new Assertion(
         description: 'C.5.3 third response (further evictions; date/set-cookie literals)',
      )
         ->expect($HPACK->decode(hex2bin(
            '88c1611d4d6f6e2c203231204f637420323031332032303a31333a323220474d54c05a04677a6970'
            . '7738666f6f3d4153444a4b48514b425a584f5157454f50495541585157454f49553b206d61782d61'
            . '67653d333630303b2076657273696f6e3d31'
         ), PHP_INT_MAX))
         ->to->be($response3)
         ->assert();

      // @ C.6 — the same three responses, Huffman-coded, table capped at 256
      $HPACK = new HPACK(256);
      yield new Assertion(
         description: 'C.6.1 first response (Huffman)',
      )
         ->expect($HPACK->decode(hex2bin(
            '488264025885aec3771a4b6196d07abe941054d444a8200595040b8166e082a62d1bff6e919d29ad'
            . '171863c78f0b97c8e9ae82ae43d3'
         ), PHP_INT_MAX))
         ->to->be($response1)
         ->assert();

      yield new Assertion(
         description: 'C.6.2 second response (Huffman)',
      )
         ->expect($HPACK->decode(hex2bin('4883640effc1c0bf'), PHP_INT_MAX))
         ->to->be($response2)
         ->assert();

      yield new Assertion(
         description: 'C.6.3 third response (Huffman)',
      )
         ->expect($HPACK->decode(hex2bin(
            '88c16196d07abe941054d444a8200595040b8166e084a62d1bffc05a839bd9ab77ad94e7821dd7f2'
            . 'e6c7b335dfdfcd5b3960d5af27087f3672c1ab270fb5291f9587316065c003ed4ee5b1063d5007'
         ), PHP_INT_MAX))
         ->to->be($response3)
         ->assert();
   })
);
