<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame;


return new Specification(
   description: 'It should decode masked client frames and encode unmasked server frames',
   test: new Assertions(Case: function (): Generator {
      $max = 1048576;

      // @ Build a masked client frame (as a browser would send).
      $mask = function (int $opcode, string $payload, bool $fin = true): string {
         $byte0 = ($fin ? 0x80 : 0x00) | ($opcode & 0x0F);
         $length = strlen($payload);
         $key = "\x21\x52\x83\xf4";

         $header = chr($byte0);
         if ($length < 126) {
            $header .= chr(0x80 | $length);
         }
         else if ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
         }
         else {
            $header .= chr(0x80 | 127) . pack('J', $length);
         }
         $header .= $key;

         $masked = $payload ^ substr(str_repeat($key, intdiv($length, 4) + 1), 0, $length);

         return $header . $masked;
      };

      // @ Small text frame round-trip.
      $frame = Frame::decode($mask(0x1, 'hello'), 0, $max);
      yield new Assertion(description: 'decode() unmasks the payload')
         ->expect($frame?->payload)
         ->to->be('hello')
         ->assert();

      yield new Assertion(description: 'decode() reads the opcode')
         ->expect($frame?->opcode)
         ->to->be(1)
         ->assert();

      yield new Assertion(description: 'decode() reads the FIN bit')
         ->expect($frame?->fin)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'decode() flags the client mask')
         ->expect($frame?->masked)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'decode() reports the consumed wire length')
         ->expect($frame?->consumed)
         ->to->be(2 + 4 + 5)
         ->assert();

      // @ Extended 16-bit length (payload >= 126 bytes).
      $payload = str_repeat('x', 300);
      $frame = Frame::decode($mask(0x2, $payload), 0, $max);
      yield new Assertion(description: 'decode() handles a 16-bit extended length')
         ->expect($frame?->length)
         ->to->be(300)
         ->assert();

      yield new Assertion(description: 'decode() unmasks an extended-length payload')
         ->expect($frame?->payload)
         ->to->be($payload)
         ->assert();

      // @ Non-FIN fragment.
      $frame = Frame::decode($mask(0x1, 'Hel', false), 0, $max);
      yield new Assertion(description: 'decode() reads a non-final fragment')
         ->expect($frame?->fin)
         ->to->be(false)
         ->assert();

      // @ Partial frame — not enough bytes yet.
      $bytes = $mask(0x1, 'hello');
      yield new Assertion(description: 'decode() returns null on a truncated frame')
         ->expect(Frame::decode(substr($bytes, 0, 4), 0, $max) === null)
         ->to->be(true)
         ->assert();

      // @ Oversize guard — declared length exceeds the cap.
      $frame = Frame::decode($mask(0x1, str_repeat('a', 200)), 0, 10);
      yield new Assertion(description: 'decode() faults oversize frames with 1009')
         ->expect($frame?->error)
         ->to->be(1009)
         ->assert();

      // @ 64-bit length with the most-significant bit set (§5.2).
      $bad = chr(0x81) . chr(0xFF) . "\x80\x00\x00\x00\x00\x00\x00\x00";
      $frame = Frame::decode($bad, 0, $max);
      yield new Assertion(description: 'decode() faults a 64-bit MSB length with 1002')
         ->expect($frame?->error)
         ->to->be(1002)
         ->assert();

      // @ Non-minimal length encodings are a protocol error (§5.2).
      yield new Assertion(description: 'decode() faults a non-minimal 16-bit length with 1002')
         ->expect(Frame::decode(chr(0x81) . chr(0xFE) . "\x00\x0a", 0, $max)?->error)
         ->to->be(1002)
         ->assert();

      yield new Assertion(description: 'decode() faults a non-minimal 64-bit length with 1002')
         ->expect(Frame::decode(chr(0x81) . chr(0xFF) . "\x00\x00\x00\x00\x00\x00\x00\x0a", 0, $max)?->error)
         ->to->be(1002)
         ->assert();

      // @ Server encoding is never masked; check the wire bytes.
      yield new Assertion(description: 'encode() builds an unmasked text frame')
         ->expect(Frame::encode(0x1, 'hi'))
         ->to->be("\x81\x02hi")
         ->assert();

      yield new Assertion(description: 'encode() sets RSV1 when requested')
         ->expect(Frame::encode(0x1, 'hi', true, 0x40)[0])
         ->to->be("\xc1")
         ->assert();

      yield new Assertion(description: 'encode() uses the 16-bit length form at 126 bytes')
         ->expect(Frame::encode(0x2, str_repeat('y', 130))[1])
         ->to->be(chr(126))
         ->assert();

      // @ Decode what encode produced (server frame is unmasked) round-trips.
      $server = Frame::encode(0x1, 'pong-data');
      $decoded = Frame::decode($server, 0, $max);
      yield new Assertion(description: 'decode() reads an unmasked server frame')
         ->expect($decoded?->payload)
         ->to->be('pong-data')
         ->assert();
   })
);
