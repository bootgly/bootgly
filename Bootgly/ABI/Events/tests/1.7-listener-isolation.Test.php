<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ABI\Events\tests\Observations;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/Observations.php';


final class IsolationThrowingListener implements Listener
{
   public function handle (Emission $Emission): void
   {
      throw new RuntimeException('hostile listener object');
   }
}

return new Test(
   description: 'Emitter: an Observing event isolates its listeners; every other event keeps the steering contract',
   test: function () {
      // # Observing event — a thrown Exception stays inside emit(), later
      //   listeners still run, and the failure is reported out-of-band
      $Emitter = new Emitter();
      $previousReporters = Throwables::$reporters;
      Throwables::$reporters = [];
      $reported = [];
      Throwables::$reporters[] = function (Throwable $Throwable, array $context) use (&$reported) {
         $reported[] = [$Throwable->getMessage(), $context];
      };

      try {
         $ran = [];
         $Emitter->listen(Observations::Gamma, function () use (&$ran) {
            $ran[] = 'hostile';
            throw new RuntimeException('hostile listener');
         }, priority: 10);
         $Emitter->listen(Observations::Gamma, function () use (&$ran) {
            $ran[] = 'audit';
         }, priority: -10);

         $escaped = null;
         try {
            $Emitter->emit(Observations::Gamma);
         }
         catch (Throwable $Thrown) {
            $escaped = $Thrown->getMessage();
         }

         yield assert(
            assertion: $escaped === null && $ran === ['hostile', 'audit'],
            description: 'Observing: a throwing listener does not escape emit() and does not blind a lower-priority listener'
         );

         yield assert(
            assertion: count($reported) === 1
               && $reported[0][0] === 'hostile listener'
               && ($reported[0][1]['event'] ?? null) === 'Gamma'
               && ($reported[0][1]['phase'] ?? null) === 'Emitter',
            description: 'Observing: the contained failure is reported through Throwables::notify with the event name'
         );

         // # The Error family is contained too — the dominant real-world shape
         //   is a wrong-signature listener, which makes the ENGINE raise a
         //   TypeError at call time
         $Errors = new Emitter();

         $after = 0;
         $Errors->listen(Observations::Gamma, function (string $wrong): void {
         }, priority: 10);
         $Errors->listen(Observations::Gamma, function () use (&$after) {
            $after++;
         }, priority: 0);

         $escaped = null;
         try {
            $Errors->emit(Observations::Gamma);
         }
         catch (Throwable $Thrown) {
            $escaped = $Thrown->getMessage();
         }

         yield assert(
            assertion: $escaped === null && $after === 1,
            description: 'Observing: an engine-raised TypeError from a wrong listener signature is contained like any listener failure'
         );

         // # A Listener OBJECT is isolated exactly like a Closure
         $Objects = new Emitter();

         $late = 0;
         $Objects->listen(Observations::Gamma, new IsolationThrowingListener(), priority: 10);
         $Objects->listen(Observations::Gamma, function () use (&$late) {
            $late++;
         }, priority: 0);

         $escaped = null;
         try {
            $Objects->emit(Observations::Gamma);
         }
         catch (Throwable $Thrown) {
            $escaped = $Thrown->getMessage();
         }

         yield assert(
            assertion: $escaped === null && $late === 1,
            description: 'Observing: a throwing Listener object is contained and does not blind a later listener'
         );

         // # Emission->stop() remains the sanctioned halt — set before a
         //   throw, it is still honored
         $Stops = new Emitter();

         $ran = [];
         $Stops->listen(Observations::Gamma, function (Emission $Emission) use (&$ran) {
            $ran[] = 'stopper';
            $Emission->stop();
            throw new RuntimeException('stopped and broke');
         }, priority: 10);
         $Stops->listen(Observations::Gamma, function () use (&$ran) {
            $ran[] = 'late';
         }, priority: 0);

         $Emission = $Stops->emit(Observations::Gamma);

         yield assert(
            assertion: $ran === ['stopper']
               && $Emission instanceof Emission
               && $Emission->stopped === true,
            description: 'Observing: a listener that stopped propagation before breaking still halts delivery'
         );
      }
      finally {
         Throwables::$reporters = $previousReporters;
      }

      // # Steering contract — an unmarked event lets a listener Throwable
      //   propagate to the emitter, closure and Listener object alike: a
      //   pre-routing refusal gate or a bounded error boundary depends on it
      $Steering = new Emitter();

      $Steering->listen(Events::Alpha, function (): void {
         throw new RuntimeException('refusal gate');
      });

      $escaped = null;
      try {
         $Steering->emit(Events::Alpha);
      }
      catch (RuntimeException $Thrown) {
         $escaped = $Thrown->getMessage();
      }

      yield assert(
         assertion: $escaped === 'refusal gate',
         description: 'Steering: a throwing listener on an unmarked event propagates to the emitter'
      );

      $SteeringObjects = new Emitter();
      $SteeringObjects->listen(Events::Beta, new IsolationThrowingListener());

      $escaped = null;
      try {
         $SteeringObjects->emit(Events::Beta);
      }
      catch (RuntimeException $Thrown) {
         $escaped = $Thrown->getMessage();
      }

      yield assert(
         assertion: $escaped === 'hostile listener object',
         description: 'Steering: a throwing Listener object on an unmarked event propagates to the emitter'
      );
   }
);
