<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


return new Specification(
   description: 'It should encode requests as HEADERS(+DATA) that round-trip through HPACK with forbidden fields stripped',
   test: new Assertions(Case: function (): Generator {
      // ! Outbox frame parser — the inline unpack idiom, under test control
      $parse = static function (string $raw): array {
         $frames = [];
         $length = strlen($raw);
         $offset = 0;
         while ($length - $offset >= 9) {
            $head = unpack('Nword/Cflags/Nstream', $raw, $offset);
            $size = $head['word'] >> 8;
            $frames[] = [
               'type' => $head['word'] & 0xff,
               'flags' => $head['flags'],
               'stream' => $head['stream'] & 0x7fffffff,
               'payload' => substr($raw, $offset + 9, $size)
            ];
            $offset += 9 + $size;
         }
         return $frames;
      };
      $settle = static function (Session $Session, null|Settings $Server = null): void {
         $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, ($Server ?? new Settings)->pack()));
         $Session->outbox = '';
      };

      // @ POST with body: HEADERS (END_HEADERS, no END_STREAM) + DATA (END_STREAM)
      $Session = new Session;
      $settle($Session);
      $id = $Session->open(
         'POST', 'https', 'example.com', '/x',
         ['X-Custom' => 'v', 'Connection' => 'keep-alive', 'TE' => 'trailers'],
         'hello'
      );
      $frames = $parse($Session->outbox);
      yield new Assertion(
         description: 'open() → stream 1, exactly HEADERS + DATA queued',
      )
         ->expect([
            $id,
            count($frames),
            $frames[0]['type'],
            $frames[0]['stream'],
            $frames[1]['type'],
            $frames[1]['stream']
         ])
         ->to->be([1, 2, HTTP2::FRAME_HEADERS, 1, HTTP2::FRAME_DATA, 1])
         ->assert();

      yield new Assertion(
         description: 'HEADERS carries END_HEADERS but not END_STREAM (a body follows)',
      )
         ->expect([
            ($frames[0]['flags'] & HTTP2::FLAG_END_HEADERS) !== 0,
            ($frames[0]['flags'] & HTTP2::FLAG_END_STREAM) !== 0
         ])
         ->to->be([true, false])
         ->assert();

      // @ The block decodes back through a fresh HPACK context: pseudo-headers
      //   first, regular fields lowercased, connection-specific ones ABSENT
      $HPACK = new HPACK;
      $fields = $HPACK->decode($frames[0]['payload'], PHP_INT_MAX);
      yield new Assertion(
         description: ':method/:scheme/:authority/:path lead; x-custom kept; connection/te stripped',
      )
         ->expect($fields)
         ->to->be([
            [':method', 'POST'],
            [':scheme', 'https'],
            [':authority', 'example.com'],
            [':path', '/x'],
            ['x-custom', 'v']
         ])
         ->assert();

      yield new Assertion(
         description: 'DATA carries the body with END_STREAM',
      )
         ->expect([$frames[1]['payload'], ($frames[1]['flags'] & HTTP2::FLAG_END_STREAM) !== 0])
         ->to->be(['hello', true])
         ->assert();

      // @ No body → END_STREAM rides the HEADERS frame itself
      $Session->outbox = '';
      $id = $Session->open('GET', 'https', 'example.com', '/', []);
      $frames = $parse($Session->outbox);
      yield new Assertion(
         description: 'Bodyless open() → single HEADERS with END_HEADERS|END_STREAM',
      )
         ->expect([
            $id,
            count($frames),
            $frames[0]['flags'] & (HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM)
         ])
         ->to->be([3, 1, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM])
         ->assert();

      // @ Header list larger than the peer SETTINGS_MAX_HEADER_LIST_SIZE:
      //   local pre-check — nothing is sent, no stream id is spent
      $Capped = new Session;
      $Server = new Settings;
      $Server->list = 64;
      $settle($Capped, $Server);
      $refused = $Capped->open(
         'GET', 'https', 'example.com', '/',
         ['x-big' => str_repeat('a', 200)]
      );
      yield new Assertion(
         description: 'Oversized header list → open() 0, $next unchanged, outbox untouched',
      )
         ->expect([$refused, $Capped->next, $Capped->opened, $Capped->outbox])
         ->to->be([0, 1, 0, ''])
         ->assert();
   })
);
