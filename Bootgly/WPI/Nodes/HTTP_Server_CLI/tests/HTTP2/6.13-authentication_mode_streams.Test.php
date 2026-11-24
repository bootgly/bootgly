<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client;


return new Test(
   description: 'It should authenticate two h2 streams on their own snapshots with the deferring Authentication',
   test: new Assertions(Case: function (): Generator {
      $request = static fn (string $token): array => [
         [':method', 'GET'],
         [':scheme', 'http'],
         [':path', '/auth-mode'],
         [':authority', 'localhost:8085'],
         ['authorization', "Bearer {$token}"]
      ];

      // @ Two streams in one flight: alice's lookup parks longer than bob's
      $Client = new Client;
      $Client->send(
         HTTP2::PREFACE
         . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode($request('tok-alice')))
         . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 3, HPACK::encode($request('tok-bob')))
      );

      // @ Collect the DATA frames in arrival order
      $responses = [];
      for ($i = 0; $i < 2; $i++) {
         $data = $Client->expect(HTTP2::FRAME_DATA, 3.0);
         if ($data === null) {
            break;
         }
         $responses[] = [$data['stream'], $data['payload']];
      }
      $Client->close();

      yield new Assertion(
         description: 'Each stream answers its own identity on its own snapshot; bob is answered while alice is still parked',
      )
         ->expect($responses)
         ->to->be([[3, 'identity=bob;snapshot=yes'], [1, 'identity=alice;snapshot=yes']])
         ->assert();
   })
);
