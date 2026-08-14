<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\Emission;
use Bootgly\ACI\Observability;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry\Admissions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Telemetry correlates request count, in-flight, duration and status by exchange',
   test: function () {
      $PreviousEmitter = Emitter::$Instance;
      Emitter::$Instance = new Emitter();
      $Observability = new Observability(collectors: false);
      new Telemetry($Observability)->boot();

      // # The ordinary synchronous path must remain allocation-free when its
      //   emitter has no core admission observers.
      $EmptyEmitter = new Emitter;
      $EmptyRequest = new Request;
      $EmptyOpened = Admissions::open($EmptyEmitter, $EmptyRequest);
      yield assert(
         assertion: $EmptyOpened === null
            && Exchange::fetch($EmptyRequest) === null,
         description: 'open skips lifecycle allocation when no core observer exists'
      );

      // ? Positive control: once an observer exists, open creates, binds and
      //   publishes that exact Exchange before returning it to the encoder.
      $OpenEmitter = new Emitter;
      $OpenRequest = new Request;
      $ObservedOpen = null;
      $openCalls = 0;
      Admissions::listen(
         $OpenEmitter,
         static function (Exchange $Exchange) use (
            &$ObservedOpen,
            &$openCalls,
         ): void {
            $ObservedOpen = $Exchange;
            $openCalls++;
         },
      );
      $Opened = Admissions::open($OpenEmitter, $OpenRequest);
      $openBound = $Opened instanceof Exchange
         && $Opened === $ObservedOpen
         && Exchange::fetch($OpenRequest) === $Opened;
      $openFinished = $Opened?->finish(new Response(201));
      yield assert(
         assertion: $openCalls === 1
            && $openBound
            && $openFinished === true
            && $Opened?->check() === true
            && Exchange::fetch($OpenRequest) === null,
         description: 'open admits and publishes the same observed Exchange'
      );

      // # Preserve the public event contract used by manual producers: the
      //   Received payload does not expose an internal Exchange, and a
      //   sequential Received -> Handled pair still records one lifecycle.
      $TelemetryEmitter = Emitter::$Instance;
      $LegacyEmitter = new Emitter;
      Emitter::$Instance = $LegacyEmitter;
      $LegacyObservability = new Observability(collectors: false);
      new Telemetry($LegacyObservability)->boot();
      $LegacyEmitter->emit(RequestEvents::Received);
      $LegacyEmitter->emit(RequestEvents::Handled, null, null);
      $LegacyEmitter->emit(RequestEvents::Received);
      $LegacyEmitter->emit(RequestEvents::Handled, null, new Response(204));
      $legacyMetrics = $LegacyObservability->gather()->metrics;
      $legacy2XX = null;
      foreach ($legacyMetrics['http_responses_total']['series'] as $Series) {
         if (($Series['labels']['class'] ?? null) === '2xx') {
            $legacy2XX = $Series['value'];
         }
      }
      Emitter::$Instance = $TelemetryEmitter;

      yield assert(
         assertion: $legacyMetrics['http_requests_total']['series'][0]['value'] === 2.0
            && $legacyMetrics['http_requests_in_flight']['series'][0]['value'] === 0.0
            && $legacyMetrics['http_request_duration_seconds']['series'][0]['count'] === 2
            && $legacy2XX === 1.0,
         description: 'manual Received and Handled event pairs remain compatible'
      );

      // # A production Handled may interleave while a manual pair is pending;
      //   it must finish through its Exchange without consuming that pair.
      $MixedEmitter = new Emitter;
      Emitter::$Instance = $MixedEmitter;
      $MixedObservability = new Observability(collectors: false);
      new Telemetry($MixedObservability)->boot();
      $MixedEmitter->emit(RequestEvents::Received);
      $ProductionRequest = new \Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
      $ProductionExchange = new Exchange;
      Exchange::admit($ProductionRequest, $ProductionExchange);
      Admissions::admit($MixedEmitter, $ProductionExchange);
      $MixedEmitter->emit(RequestEvents::Received, $ProductionRequest);
      $ProductionResponse = new Response(201);
      Exchange::track($ProductionResponse, $ProductionExchange);
      // ! Simulate an earlier maximum-priority Handled listener selecting
      //   out-of-band output before Telemetry's public compatibility listener.
      $ProductionExchange->finish($ProductionResponse);
      $MixedEmitter->emit(
         RequestEvents::Handled,
         $ProductionRequest,
         $ProductionResponse,
      );
      $MixedEmitter->emit(RequestEvents::Handled, null, new Response(404));
      $mixedMetrics = $MixedObservability->gather()->metrics;
      $mixed2XX = null;
      $mixed4XX = null;
      foreach ($mixedMetrics['http_responses_total']['series'] as $Series) {
         if (($Series['labels']['class'] ?? null) === '2xx') {
            $mixed2XX = $Series['value'];
         }
         else if (($Series['labels']['class'] ?? null) === '4xx') {
            $mixed4XX = $Series['value'];
         }
      }
      Emitter::$Instance = $TelemetryEmitter;

      yield assert(
         assertion: $mixedMetrics['http_requests_total']['series'][0]['value'] === 2.0
            && $mixedMetrics['http_requests_in_flight']['series'][0]['value'] === 0.0
            && $mixedMetrics['http_request_duration_seconds']['series'][0]['count'] === 2
            && $mixed2XX === 1.0
            && $mixed4XX === 1.0,
         description: 'production Handled does not consume a pending manual pair'
      );

      // ! Production admission is a non-stoppable core phase. An application
      //   listener registered earlier at the maximum public priority cannot
      //   blind this registry by stopping the Received emission.
      $GuardedEmitter = new Emitter;
      $GuardedEmitter->listen(
         RequestEvents::Received,
         static function (Emission $Emission): void {
            $Emission->stop();
         },
         priority: PHP_INT_MAX,
      );
      $GuardedObservability = new Observability(collectors: false);
      $TelemetryEmitter = Emitter::$Instance;
      Emitter::$Instance = $GuardedEmitter;
      new Telemetry($GuardedObservability)->boot();
      Emitter::$Instance = $TelemetryEmitter;
      $Guarded = new Exchange;
      Admissions::admit($GuardedEmitter, $Guarded);
      $GuardedEmitter->emit(RequestEvents::Received, null, $Guarded);
      $Guarded->finish(new Response(206));
      $guardedMetrics = $GuardedObservability->gather()->metrics;
      $guarded2xx = null;
      foreach ($guardedMetrics['http_responses_total']['series'] as $Series) {
         if (($Series['labels']['class'] ?? null) === '2xx') {
            $guarded2xx = $Series['value'];
         }
      }

      yield assert(
         assertion: $guardedMetrics['http_requests_total']['series'][0]['value'] === 1.0
            && $guardedMetrics['http_requests_in_flight']['series'][0]['value'] === 0.0
            && $guardedMetrics['http_request_duration_seconds']['series'][0]['count'] === 1
            && $guarded2xx === 1.0,
         description: 'core admission survives an earlier max-priority stopping listener'
      );

      // # The final admission registry contains observer failures so one
      //   registry cannot hide the exchange from later registries. Its Emitter
      //   key is weak and must not extend a retired worker bus lifetime.
      $IsolatedEmitter = new Emitter;
      $observed = [];
      Admissions::listen($IsolatedEmitter, static function (Exchange $Exchange) use (
         &$observed,
      ): void {
         $observed[] = 'throwing';
         throw new RuntimeException('isolated admission observer');
      });
      Admissions::listen($IsolatedEmitter, static function (Exchange $Exchange) use (
         &$observed,
      ): void {
         $observed[] = 'following';
      });
      Admissions::admit($IsolatedEmitter, new Exchange);
      $WeakEmitter = WeakReference::create($IsolatedEmitter);
      unset($IsolatedEmitter);
      gc_collect_cycles();

      yield assert(
         assertion: $observed === ['throwing', 'following'],
         description: 'core admission contains one observer and continues in order'
      );
      yield assert(
         assertion: $WeakEmitter->get() === null,
         description: 'core admission registry keeps emitter keys weak'
      );

      // # A terminal token may reach Telemetry after another Received
      //   listener has already completed it. Late replay must close the
      //   observation synchronously without driving the gauge negative.
      $Completed = new Exchange;
      $preFinished = $Completed->finish(new Response(201));
      Emitter::$Instance->emit(RequestEvents::Received, null, $Completed);
      $completedMetrics = $Observability->gather()->metrics;

      yield assert(
         assertion: $preFinished === true,
         description: 'exchange fixture terminalized before Received'
      );
      yield assert(
         assertion: $completedMetrics['http_requests_in_flight']['series'][0]['value'] === 0.0,
         description: 'pre-finalized exchange late replay leaves in-flight at zero'
      );

      // # Admit A, then B, and finish them in reverse order. A shared scalar
      //   timestamp loses one duration here; per-exchange ownership does not.
      $ExchangeA = new Exchange;
      $ExchangeB = new Exchange;
      Emitter::$Instance->emit(RequestEvents::Received, null, $ExchangeA);
      Emitter::$Instance->emit(RequestEvents::Received, null, $ExchangeB);

      $ExchangeB->finish(new Response(204));
      $ExchangeA->finish(null);
      $duplicate = $ExchangeA->finish(new Response(500));

      $metrics = $Observability->gather()->metrics;

      yield assert(
         assertion: $metrics['http_requests_total']['series'][0]['value'] === 3.0,
         description: 'pre-finalized and two live exchanges counted'
      );
      yield assert(
         assertion: $metrics['http_requests_in_flight']['series'][0]['value'] === 0.0,
         description: 'in-flight released back to zero (Received++ / terminal--)'
      );
      yield assert(
         assertion: $metrics['http_request_duration_seconds']['series'][0]['count'] === 3,
         description: 'duration histogram observed all three exchanges'
      );

      // # Status class 2xx counts both the late Response(201) replay and the
      //   live Response(204) transition exactly once.
      $twoxx = null;
      foreach ($metrics['http_responses_total']['series'] as $Series) {
         if (($Series['labels']['class'] ?? null) === '2xx') {
            $twoxx = $Series['value'];
         }
      }
      yield assert(
         assertion: $twoxx === 2.0,
         description: 'two 2xx responses recorded'
      );
      yield assert(
         assertion: $duplicate === false,
         description: 'duplicate terminal attempts are ignored'
      );

      Emitter::$Instance = $PreviousEmitter;
   }
);
