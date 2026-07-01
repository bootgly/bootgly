<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2\HPACK;


return new Specification(
   description: 'It should encode context-free header blocks (static table only, no Huffman)',
   test: new Assertions(Case: function (): Generator {
      // @ Full static match → single indexed byte
      yield new Assertion(
         description: ':status 200 → 0x88 (static index 8)',
      )
         ->expect(HPACK::encode([[':status', '200']]))
         ->to->be("\x88")
         ->assert();

      yield new Assertion(
         description: ':method GET → 0x82 (static index 2)',
      )
         ->expect(HPACK::encode([[':method', 'GET']]))
         ->to->be("\x82")
         ->assert();

      // @ Name-only static match → literal without indexing with indexed name
      yield new Assertion(
         description: 'content-type text/plain → name index 31 (0x0f 0x10) + literal value',
      )
         ->expect(HPACK::encode([['content-type', 'text/plain']]))
         ->to->be("\x0f\x10\x0atext/plain")
         ->assert();

      yield new Assertion(
         description: ':status 418 → name index 8 (0x08) + literal value "418"',
      )
         ->expect(HPACK::encode([[':status', '418']]))
         ->to->be("\x08\x03418")
         ->assert();

      // @ Unknown name → full literal without indexing
      yield new Assertion(
         description: 'x-powered-by Bootgly → literal name + literal value',
      )
         ->expect(HPACK::encode([['x-powered-by', 'Bootgly']]))
         ->to->be("\x00\x0cx-powered-by\x07Bootgly")
         ->assert();

      // @ Long value crosses the 7-bit length prefix (127+ octets → varint)
      $value = str_repeat('x', 200);
      yield new Assertion(
         description: '200-octet value → length 0x7f 0x49 varint',
      )
         ->expect(HPACK::encode([['etag', $value]]))
         ->to->be("\x0f\x13\x7f\x49$value")
         ->assert();

      // @ Round-trip: our own decoder reads our encoder's output
      $fields = [
         [':status', '200'],
         ['content-type', 'application/json'],
         ['content-length', '17'],
         ['set-cookie', 'a=1; HttpOnly'],
         ['set-cookie', 'b=2'],
         ['x-custom', 'value']
      ];
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'decode(encode(fields)) round-trips ordered pairs',
      )
         ->expect($HPACK->decode(HPACK::encode($fields), PHP_INT_MAX))
         ->to->be($fields)
         ->assert();

      // @ Encoding is context-free: byte-identical across repeated calls
      yield new Assertion(
         description: 'Two encodes of the same fields are byte-identical (cacheable)',
      )
         ->expect(HPACK::encode($fields) === HPACK::encode($fields))
         ->to->be(true)
         ->assert();
   })
);
