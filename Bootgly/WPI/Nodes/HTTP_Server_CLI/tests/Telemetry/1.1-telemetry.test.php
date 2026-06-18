<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Telemetry records request count, in-flight, duration and status class from request events',
   test: function () {
      Emitter::$Instance = new Emitter();
      $O = new Observability(collectors: false);
      new Telemetry($O)->boot();

      // # Pair 1: a request with no Response payload (core metrics still record)
      Emitter::$Instance->emit(RequestEvents::Received);
      Emitter::$Instance->emit(RequestEvents::Handled, null, null);

      // # Pair 2: a 2xx response (exercises the status-class counter)
      Emitter::$Instance->emit(RequestEvents::Received);
      Emitter::$Instance->emit(RequestEvents::Handled, null, new Response(204));

      $metrics = $O->gather()->metrics;

      yield assert(
         assertion: $metrics['http_requests_total']['series'][0]['value'] === 2.0,
         description: 'two requests counted'
      );
      yield assert(
         assertion: $metrics['http_requests_in_flight']['series'][0]['value'] === 0.0,
         description: 'in-flight released back to zero (Received++ / Handled--)'
      );
      yield assert(
         assertion: $metrics['http_request_duration_seconds']['series'][0]['count'] === 2,
         description: 'duration histogram observed both requests'
      );

      // # Status class 2xx counted exactly once (from the Response(204))
      $twoxx = null;
      foreach ($metrics['http_responses_total']['series'] as $Series) {
         if (($Series['labels']['class'] ?? null) === '2xx') {
            $twoxx = $Series['value'];
         }
      }
      yield assert(
         assertion: $twoxx === 1.0,
         description: 'one 2xx response recorded'
      );
   }
);
