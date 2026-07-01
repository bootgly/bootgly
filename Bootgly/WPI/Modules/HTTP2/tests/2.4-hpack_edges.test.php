<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Modules\HTTP2\HPACK\Huffman;


return new Specification(
   description: 'It should reject malformed HPACK input (truncation, overflow, invalid Huffman, table abuse)',
   test: new Assertions(Case: function (): Generator {
      // @ Index 0 is never valid for an indexed field
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Indexed field 0x80 (index 0) → null',
      )
         ->expect($HPACK->decode("\x80", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Reference beyond the static table with an empty dynamic table
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Indexed field 0xbe (62) with empty dynamic table → null',
      )
         ->expect($HPACK->decode("\xbe", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Truncated string literal (declared length exceeds block)
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Literal with 10-byte declared value but 2 bytes present → null',
      )
         ->expect($HPACK->decode("\x00\x01a\x0ab", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Truncated integer continuation
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Integer continuation cut mid-varint → null',
      )
         ->expect($HPACK->decode("\xff\x80", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Integer overflow (endless continuation)
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Varint wider than 28 bits of continuation → null',
      )
         ->expect($HPACK->decode("\xff\x80\x80\x80\x80\x80\x80\x01", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Dynamic table size update above our advertised limit
      $HPACK = new HPACK(4096);
      yield new Assertion(
         description: 'Table size update to 8192 with limit 4096 → null',
      )
         ->expect($HPACK->decode("\x3f\xe1\x3f", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Table size update after a field was decoded (must lead the block)
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Size update after a field → null',
      )
         ->expect($HPACK->decode("\x82\x20", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ Size update leading the block is accepted
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'Leading size update (to 0) then indexed field → decodes',
      )
         ->expect($HPACK->decode("\x20\x82", PHP_INT_MAX))
         ->to->be([[':method', 'GET']])
         ->assert();

      // @ Decoded header list cap (name + value + 32 accounting)
      $HPACK = new HPACK;
      yield new Assertion(
         description: ':method GET (7 + 3 + 32 = 42 octets) over a 41-octet cap → null',
      )
         ->expect($HPACK->decode("\x82", 41))
         ->to->be(Type::Null)
         ->assert();

      // @ Huffman: EOS inside the data is a coding error
      yield new Assertion(
         description: 'Huffman EOS (30×1 bits) in payload → null',
      )
         ->expect(Huffman::decode("\xff\xff\xff\xfc"))
         ->to->be(Type::Null)
         ->assert();

      // @ Huffman: padding must be all-ones
      yield new Assertion(
         description: "Huffman 'a' (00011) + zero padding → null",
      )
         ->expect(Huffman::decode("\x18"))
         ->to->be(Type::Null)
         ->assert();

      // @ Huffman: valid single symbol with correct padding
      yield new Assertion(
         description: "Huffman 'a' (00011) + 111 padding (0x1f) → 'a'",
      )
         ->expect(Huffman::decode("\x1f"))
         ->to->be('a')
         ->assert();

      // @ Huffman: empty input is a valid empty string
      yield new Assertion(
         description: 'Huffman empty input → empty string',
      )
         ->expect(Huffman::decode(''))
         ->to->be('')
         ->assert();

      // @ Huffman: 'www.example.com' vector from C.4.1
      yield new Assertion(
         description: 'Huffman f1e3c2e5f23a6ba0ab90f4ff → www.example.com',
      )
         ->expect(Huffman::decode(hex2bin('f1e3c2e5f23a6ba0ab90f4ff')))
         ->to->be('www.example.com')
         ->assert();

      // @ Entry larger than the table maximum empties the table (no error)
      $HPACK = new HPACK(64);
      $big = str_repeat('v', 100);
      $block = "\x40\x03big" . "\x64" . $big; // literal w/ indexing: big=<100 bytes>
      yield new Assertion(
         description: 'Oversized insertion decodes fine (table just resets)',
      )
         ->expect($HPACK->decode($block, PHP_INT_MAX))
         ->to->be([['big', $big]])
         ->assert();
      yield new Assertion(
         description: '...and the dynamic table stays empty afterwards',
      )
         ->expect($HPACK->decode("\xbe", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();
   })
);
