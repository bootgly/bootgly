<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;


return new Specification(
   description: 'It should serialize HTTP/2 frames per RFC 9113 §4.1',
   test: new Assertions(Case: function (): Generator {
      // @ Empty SETTINGS frame: length=0, type=0x4, flags=0, stream=0
      yield new Assertion(
         description: 'Empty SETTINGS frame is 9 bytes: 00 00 00 04 00 00 00 00 00',
      )
         ->expect(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0))
         ->to->be("\x00\x00\x00\x04\x00\x00\x00\x00\x00")
         ->assert();

      // @ SETTINGS ACK: flags=0x01
      yield new Assertion(
         description: 'SETTINGS ACK sets the ACK flag byte',
      )
         ->expect(Frame::pack(HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK, 0))
         ->to->be("\x00\x00\x00\x04\x01\x00\x00\x00\x00")
         ->assert();

      // @ DATA frame with payload on stream 1: 24-bit length prefix
      yield new Assertion(
         description: 'DATA "Hi" on stream 1: length=2, type=0x0, END_STREAM, id=1',
      )
         ->expect(Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 1, 'Hi'))
         ->to->be("\x00\x00\x02\x00\x01\x00\x00\x00\x01Hi")
         ->assert();

      // @ PING payload is exactly 8 opaque bytes
      yield new Assertion(
         description: 'PING frame carries its 8-byte payload verbatim',
      )
         ->expect(Frame::pack(HTTP2::FRAME_PING, 0, 0, "\x01\x02\x03\x04\x05\x06\x07\x08"))
         ->to->be("\x00\x00\x08\x06\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05\x06\x07\x08")
         ->assert();

      // @ Large stream id keeps the reserved bit clear (uint31)
      yield new Assertion(
         description: 'Stream id 2147483647 (2^31-1) packs into the last 4 header bytes',
      )
         ->expect(Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 2147483647, pack('N', 65535)))
         ->to->be("\x00\x00\x04\x08\x00\x7f\xff\xff\xff\x00\x00\xff\xff")
         ->assert();

      // @ RST_STREAM with error code payload
      yield new Assertion(
         description: 'RST_STREAM(stream 3, CANCEL=0x8): length=4, type=0x3',
      )
         ->expect(Frame::pack(HTTP2::FRAME_RST_STREAM, 0, 3, pack('N', 0x8)))
         ->to->be("\x00\x00\x04\x03\x00\x00\x00\x00\x03\x00\x00\x00\x08")
         ->assert();

      // @ 24-bit length field: 16384-byte payload → length bytes 00 40 00
      yield new Assertion(
         description: 'Length 16384 encodes as 0x004000 in the 24-bit prefix',
      )
         ->expect(substr(Frame::pack(HTTP2::FRAME_DATA, 0, 1, str_repeat('a', 16384)), 0, 3))
         ->to->be("\x00\x40\x00")
         ->assert();
   })
);
