<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should track independent multi-bar progress in one frame',
   test: function () {
      // ! Progress with an in-memory stream
      $Output = new Output('php://memory');

      $Progress = new Progress($Output);
      $Progress->throttle = 0.0;

      // @ Independent tracks
      $Download = $Progress->Bars->add('Download');
      $Download->total = 100;

      $Extract = $Progress->Bars->add('Extract');
      $Extract->total = 40;

      yield assert(
         assertion: $Progress->Bars->count === 2,
         description: 'Track Bars register in the collection'
      );

      // @ Advancing a track only moves its own percent
      $Download->advance(50);
      $Extract->advance(10);

      yield assert(
         assertion: $Download->percent === 50.0 && $Extract->percent === 25.0,
         description: 'Each track derives its own percent (50/100 = 50%, 10/40 = 25%)'
      );

      // @ Track overflow clamps at the total
      $Extract->advance(1000);

      yield assert(
         assertion: $Extract->percent === 100.0 && $Extract->current === 40.0,
         description: 'Advancing past the total clamps the track at 100%'
      );

      // @ Lifecycle renders the grid frame
      $Progress->start();
      $Progress->tick();
      $Progress->finish();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($output, 'Download') === true && str_contains($output, 'Extract') === true,
         description: 'The frame renders every track description'
      );
      yield assert(
         assertion: str_contains($output, '100.0%') === true,
         description: 'finish() forces every track to 100%'
      );
      yield assert(
         assertion: $Download->percent === 100.0,
         description: 'Unfinished tracks are completed by finish()'
      );
   }
);
