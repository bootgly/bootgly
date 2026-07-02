<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function is_bool;
use function is_string;
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render Progress into a stream with or without a queryable cursor',
   test: function () {
      $Output = new Output('php://memory');

      $Progress = new Progress($Output);
      $Progress->total = 10;

      // @
      $Progress->start();
      $i = 0;
      while ($i++ < 10) {
         $Progress->advance();
      }
      $Progress->finish();

      // @ Valid
      yield assert(
         assertion: $Progress->finished === true,
         description: 'Progress finished'
      );
      yield assert(
         assertion: is_bool($Progress->anchored),
         description: 'Anchored mode resolved: ' . ($Progress->anchored ? 'anchored (saved cursor)' : 'positioned (queried cursor)')
      );

      rewind($Output->stream);
      $written = stream_get_contents($Output->stream);
      yield assert(
         assertion: is_string($written) && $written !== '',
         description: 'Progress wrote output to the stream'
      );
   }
);
