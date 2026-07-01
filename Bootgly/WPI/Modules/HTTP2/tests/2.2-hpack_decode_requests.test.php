<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2\HPACK;


return new Specification(
   description: 'It should decode the RFC 7541 Appendix C.3/C.4 request sequences',
   test: new Assertions(Case: function (): Generator {
      $request1 = [
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', '/'],
         [':authority', 'www.example.com']
      ];
      $request2 = [
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', '/'],
         [':authority', 'www.example.com'],
         ['cache-control', 'no-cache']
      ];
      $request3 = [
         [':method', 'GET'],
         [':scheme', 'https'],
         [':path', '/index.html'],
         [':authority', 'www.example.com'],
         ['custom-key', 'custom-value']
      ];

      // @ C.3 — three requests on one connection, without Huffman coding
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'C.3.1 first request',
      )
         ->expect($HPACK->decode(hex2bin('828684410f7777772e6578616d706c652e636f6d'), PHP_INT_MAX))
         ->to->be($request1)
         ->assert();

      yield new Assertion(
         description: 'C.3.2 second request (0xbe references the C.3.1 :authority entry)',
      )
         ->expect($HPACK->decode(hex2bin('828684be58086e6f2d6361636865'), PHP_INT_MAX))
         ->to->be($request2)
         ->assert();

      yield new Assertion(
         description: 'C.3.3 third request (0xbf reaches the deeper dynamic entry)',
      )
         ->expect($HPACK->decode(hex2bin('828785bf400a637573746f6d2d6b65790c637573746f6d2d76616c7565'), PHP_INT_MAX))
         ->to->be($request3)
         ->assert();

      // @ C.4 — the same three requests, with Huffman-coded string literals
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'C.4.1 first request (Huffman)',
      )
         ->expect($HPACK->decode(hex2bin('828684418cf1e3c2e5f23a6ba0ab90f4ff'), PHP_INT_MAX))
         ->to->be($request1)
         ->assert();

      yield new Assertion(
         description: 'C.4.2 second request (Huffman)',
      )
         ->expect($HPACK->decode(hex2bin('828684be5886a8eb10649cbf'), PHP_INT_MAX))
         ->to->be($request2)
         ->assert();

      yield new Assertion(
         description: 'C.4.3 third request (Huffman)',
      )
         ->expect($HPACK->decode(hex2bin('828785bf408825a849e95ba97d7f8925a849e95bb8e8b4bf'), PHP_INT_MAX))
         ->to->be($request3)
         ->assert();
   })
);
