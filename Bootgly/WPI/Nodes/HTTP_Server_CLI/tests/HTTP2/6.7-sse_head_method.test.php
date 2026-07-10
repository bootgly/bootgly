<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Specification(
   description: 'It should answer HEAD on an SSE route without sustaining the stream',
   test: new Assertions(Case: function (): Generator {
      // @ HEAD on the SSE route: open() must not hijack/sustain — the
      //   normal pipeline serializes a content-free head that ENDS the
      //   stream (RFC 9110 §9.3.2)
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
            [':method', 'HEAD'],
            [':scheme', 'http'],
            [':path', '/sse'],
            [':authority', 'localhost:8085']
         ]))
      );

      $headers = $Client->expect(HTTP2::FRAME_HEADERS, 5.0);

      // ! Decode the block: the head must still be the SSE metadata head
      $fields = [];
      if ($headers !== null) {
         $HPACK = new HPACK(4096);
         foreach ($HPACK->decode($headers['payload'], PHP_INT_MAX) ?? [] as [$name, $value]) {
            $fields[$name] = $value;
         }
      }

      yield new Assertion(
         description: 'HEAD gets the SSE metadata head with END_STREAM and no content-length',
      )
         ->expect(
            $headers !== null
            && $headers['stream'] === 1
            && ($headers['flags'] & HTTP2::FLAG_END_STREAM) !== 0
            && ($fields[':status'] ?? '') === '200'
            && ($fields['content-type'] ?? '') === 'text/event-stream'
            && isSet($fields['content-length']) === false
         )
         ->to->be(true)
         ->assert();

      // @ No SSE frames follow — the connection just answers PING
      $Client->send(Frame::pack(HTTP2::FRAME_PING, 0, 0, 'headping'));
      $pong = $Client->expect(HTTP2::FRAME_PING);

      yield new Assertion(
         description: 'No DATA follows: PING is the next answered frame',
      )
         ->expect($pong !== null && ($pong['flags'] & HTTP2::FLAG_ACK) !== 0 && $pong['payload'] === 'headping')
         ->to->be(true)
         ->assert();

      $Client->close();
   })
);
