<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Settings;


return new Specification(
   description: 'It should apply and validate peer SETTINGS payloads per RFC 9113 §6.5',
   test: new Assertions(Case: function (): Generator {
      // @ Empty payload (client sends empty SETTINGS) is valid, changes nothing
      $Settings = new Settings;
      yield new Assertion(
         description: 'Empty payload → null (no error)',
      )
         ->expect($Settings->parse(''))
         ->to->be(Type::Null)
         ->assert();

      // @ Apply every known identifier at once
      $Settings = new Settings;
      $payload = pack('nN', HTTP2::SETTINGS_HEADER_TABLE_SIZE, 8192)
         . pack('nN', HTTP2::SETTINGS_ENABLE_PUSH, 0)
         . pack('nN', HTTP2::SETTINGS_MAX_CONCURRENT_STREAMS, 42)
         . pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 131072)
         . pack('nN', HTTP2::SETTINGS_MAX_FRAME_SIZE, 32768)
         . pack('nN', HTTP2::SETTINGS_MAX_HEADER_LIST_SIZE, 8192);
      $error = $Settings->parse($payload);
      yield new Assertion(
         description: 'Six valid pairs → null',
      )
         ->expect($error)
         ->to->be(Type::Null)
         ->assert();
      yield new Assertion(
         description: 'All six values applied',
      )
         ->expect([
            $Settings->table, $Settings->push, $Settings->streams,
            $Settings->window, $Settings->frame, $Settings->list
         ])
         ->to->be([8192, false, 42, 131072, 32768, 8192])
         ->assert();

      // @ Length not a multiple of 6 → FRAME_SIZE_ERROR
      $Settings = new Settings;
      yield new Assertion(
         description: 'Truncated pair → Errors::FrameSize',
      )
         ->expect($Settings->parse("\x00\x04\x00"))
         ->to->be(Errors::FrameSize)
         ->assert();

      // @ ENABLE_PUSH above 1 → PROTOCOL_ERROR
      $Settings = new Settings;
      yield new Assertion(
         description: 'ENABLE_PUSH=2 → Errors::Protocol',
      )
         ->expect($Settings->parse(pack('nN', HTTP2::SETTINGS_ENABLE_PUSH, 2)))
         ->to->be(Errors::Protocol)
         ->assert();

      // @ INITIAL_WINDOW_SIZE above 2^31-1 → FLOW_CONTROL_ERROR
      $Settings = new Settings;
      yield new Assertion(
         description: 'INITIAL_WINDOW_SIZE=2^31 → Errors::FlowControl',
      )
         ->expect($Settings->parse(pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 2147483648)))
         ->to->be(Errors::FlowControl)
         ->assert();

      // @ MAX_FRAME_SIZE below 2^14 → PROTOCOL_ERROR
      $Settings = new Settings;
      yield new Assertion(
         description: 'MAX_FRAME_SIZE=16383 → Errors::Protocol',
      )
         ->expect($Settings->parse(pack('nN', HTTP2::SETTINGS_MAX_FRAME_SIZE, 16383)))
         ->to->be(Errors::Protocol)
         ->assert();

      // @ MAX_FRAME_SIZE above 2^24-1 → PROTOCOL_ERROR
      $Settings = new Settings;
      yield new Assertion(
         description: 'MAX_FRAME_SIZE=2^24 → Errors::Protocol',
      )
         ->expect($Settings->parse(pack('nN', HTTP2::SETTINGS_MAX_FRAME_SIZE, 16777216)))
         ->to->be(Errors::Protocol)
         ->assert();

      // @ Unknown identifiers are ignored (RFC 9113 §6.5.2)
      $Settings = new Settings;
      $error = $Settings->parse(pack('nN', 0x9, 12345) . pack('nN', 0xff, 1));
      yield new Assertion(
         description: 'Unknown ids 0x9/0xff → null, values untouched',
      )
         ->expect([$error, $Settings->table, $Settings->window])
         ->to->be([null, 4096, 65535])
         ->assert();

      // @ Later pair wins (sequential processing)
      $Settings = new Settings;
      $Settings->parse(
         pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 1000)
         . pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 2000)
      );
      yield new Assertion(
         description: 'Duplicated INITIAL_WINDOW_SIZE → last value applied',
      )
         ->expect($Settings->window)
         ->to->be(2000)
         ->assert();
   })
);
