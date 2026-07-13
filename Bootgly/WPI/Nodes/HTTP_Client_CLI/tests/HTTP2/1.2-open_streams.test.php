<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


return new Specification(
   description: 'It should allocate odd increasing stream ids bounded by capacity and stop opening after GOAWAY',
   test: new Assertions(Case: function (): Generator {
      // ! Preface exchange helper: feed the server SETTINGS, drop the handshake output
      $settle = static function (Session $Session, null|Settings $Server = null): void {
         $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, ($Server ?? new Settings)->pack()));
         $Session->outbox = '';
      };

      // @ Odd, strictly increasing ids (RFC 9113 §5.1.1)
      $Session = new Session;
      $settle($Session);
      $first = $Session->open('GET', 'https', 'example.com', '/', []);
      $second = $Session->open('GET', 'https', 'example.com', '/', []);
      $third = $Session->open('GET', 'https', 'example.com', '/', []);
      yield new Assertion(
         description: 'open() allocates 1, 3, 5',
      )
         ->expect([$first, $second, $third])
         ->to->be([1, 3, 5])
         ->assert();

      yield new Assertion(
         description: 'capacity decreases per open (default: min(128, huge) - 3)',
      )
         ->expect([$Session->capacity, $Session->opened])
         ->to->be([125, 3])
         ->assert();

      // @ Capacity honors min(128, Remote->streams)
      $Bounded = new Session;
      $Server = new Settings;
      $Server->streams = 2;
      $settle($Bounded, $Server);
      $one = $Bounded->open('GET', 'https', 'example.com', '/', []);
      $three = $Bounded->open('GET', 'https', 'example.com', '/', []);
      yield new Assertion(
         description: 'MAX_CONCURRENT_STREAMS=2 → two streams open, capacity exhausted',
      )
         ->expect([$one, $three, $Bounded->capacity])
         ->to->be([1, 3, 0])
         ->assert();

      $refused = $Bounded->open('GET', 'https', 'example.com', '/', []);
      yield new Assertion(
         description: 'Third open() returns 0 and spends no stream id',
      )
         ->expect([$refused, $Bounded->next])
         ->to->be([0, 5])
         ->assert();

      // @ After GOAWAY the connection stops opening streams
      $Closing = new Session;
      $settle($Closing);
      $id = $Closing->open('GET', 'https', 'example.com', '/', []);
      $Closing->feed(Frame::pack(
         HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', 1, Errors::None->value)
      ));
      yield new Assertion(
         description: 'GOAWAY received → closing, goaway=1, capacity 0',
      )
         ->expect([$id, $Closing->closing, $Closing->goaway, $Closing->capacity])
         ->to->be([1, true, 1, 0])
         ->assert();

      yield new Assertion(
         description: 'open() after GOAWAY returns 0',
      )
         ->expect($Closing->open('GET', 'https', 'example.com', '/', []))
         ->to->be(0)
         ->assert();
   })
);
