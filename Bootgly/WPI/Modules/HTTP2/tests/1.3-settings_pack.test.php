<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Settings;


return new Specification(
   description: 'It should serialize only non-default settings into the SETTINGS payload',
   test: new Assertions(Case: function (): Generator {
      // @ All-default set → empty payload (omitted = default for the peer)
      $Settings = new Settings;
      yield new Assertion(
         description: 'Defaults → empty payload',
      )
         ->expect($Settings->pack())
         ->to->be('')
         ->assert();

      // @ Server-style set: capped streams + header list
      $Settings = new Settings;
      $Settings->streams = 128;
      $Settings->list = 16384;
      yield new Assertion(
         description: 'streams=128 + list=16384 → exactly two pairs',
      )
         ->expect($Settings->pack())
         ->to->be(
            pack('nN', HTTP2::SETTINGS_MAX_CONCURRENT_STREAMS, 128)
            . pack('nN', HTTP2::SETTINGS_MAX_HEADER_LIST_SIZE, 16384)
         )
         ->assert();

      // @ push=false emits ENABLE_PUSH=0
      $Settings = new Settings;
      $Settings->push = false;
      yield new Assertion(
         description: 'push=false → ENABLE_PUSH=0 pair',
      )
         ->expect($Settings->pack())
         ->to->be(pack('nN', HTTP2::SETTINGS_ENABLE_PUSH, 0))
         ->assert();

      // @ Round-trip: pack() output parsed back yields the same values
      $Local = new Settings;
      $Local->table = 0;
      $Local->streams = 128;
      $Local->window = 1048576;
      $Local->list = 16384;
      $Mirror = new Settings;
      $Mirror->parse($Local->pack());
      yield new Assertion(
         description: 'parse(pack()) round-trips table/streams/window/list',
      )
         ->expect([$Mirror->table, $Mirror->streams, $Mirror->window, $Mirror->list])
         ->to->be([0, 128, 1048576, 16384])
         ->assert();
   })
);
