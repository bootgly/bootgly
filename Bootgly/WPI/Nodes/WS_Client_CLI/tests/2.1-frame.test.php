<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame;


return new Specification(
   description: 'It should encode masked client frames and decode unmasked server frames',
   test: new Assertions(Case: function (): Generator {
      $max = 1048576;

      // @ Encode (client frames are always masked) then decode round-trip.
      $decoded = Frame::decode(Frame::encode(WS::OPCODE_TEXT, 'hello'), 0, $max);
      yield new Assertion(description: 'encode() sets the mask bit')
         ->expect($decoded !== null && $decoded->masked === true)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'a masked round-trip preserves the payload')
         ->expect($decoded?->payload ?? '')
         ->to->be('hello')
         ->assert();

      yield new Assertion(description: 'encode() preserves the opcode and FIN')
         ->expect($decoded !== null && $decoded->opcode === WS::OPCODE_TEXT && $decoded->fin === true)
         ->to->be(true)
         ->assert();

      // @ The mask key is random — two encodes of the same payload differ on the wire.
      yield new Assertion(description: 'two encodes use a different random mask')
         ->expect(Frame::encode(WS::OPCODE_TEXT, 'hello') !== Frame::encode(WS::OPCODE_TEXT, 'hello'))
         ->to->be(true)
         ->assert();

      // @ Decode an unmasked (server) frame.
      $server = Frame::decode(chr(0x81) . chr(0x05) . 'world', 0, $max);
      yield new Assertion(description: 'decode() reads an unmasked server frame')
         ->expect($server !== null && $server->masked === false && $server->payload === 'world')
         ->to->be(true)
         ->assert();

      // @ Extended payload length (16-bit / 126 form).
      $big = str_repeat('x', 300);
      $extended = Frame::decode(Frame::encode(WS::OPCODE_BINARY, $big), 0, $max);
      yield new Assertion(description: 'encode/decode handles a 16-bit extended length')
         ->expect($extended !== null && $extended->length === 300 && $extended->payload === $big && $extended->opcode === WS::OPCODE_BINARY)
         ->to->be(true)
         ->assert();

      // @ Oversize payload → fatal fault 1009 (DoS guard).
      $oversize = chr(0x82) . chr(0x80 | 126) . pack('n', 300) . random_bytes(4) . str_repeat('y', 300);
      $fault = Frame::decode($oversize, 0, 100);
      yield new Assertion(description: 'decode() faults 1009 on an oversize frame')
         ->expect($fault !== null && $fault->error === 1009)
         ->to->be(true)
         ->assert();

      // @ Partial header → null (wait for more bytes).
      yield new Assertion(description: 'decode() returns null on a partial header')
         ->expect(Frame::decode(chr(0x81), 0, $max) === null)
         ->to->be(true)
         ->assert();

      // @ Message DTO marks binary by opcode.
      $Binary = new Message(WS::OPCODE_BINARY, 'x');
      $Text = new Message(WS::OPCODE_TEXT, 'x');
      yield new Assertion(description: 'Message marks a binary opcode as binary')
         ->expect($Binary->binary)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'Message marks a text opcode as non-binary')
         ->expect($Text->binary)
         ->to->be(false)
         ->assert();
   })
);
