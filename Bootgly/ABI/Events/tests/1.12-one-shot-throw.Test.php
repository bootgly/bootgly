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


return new Test(
   description: 'Emitter: a one-shot listener that throws is gone under both listener contracts, and each contract keeps its policy',
   test: function () {
      // # Steering — the Throwable propagates, the listener does not come back
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'gate';

         throw new RuntimeException('one-shot refusal');
      }, priority: 10, once: true);
      $Emitter->listen(Events::Alpha, function () use (&$order) {
         $order[] = 'handler';
      });

      $escaped = null;
      try {
         $Emitter->emit(Events::Alpha);
      }
      catch (RuntimeException $Thrown) {
         $escaped = $Thrown->getMessage();
      }

      yield assert(
         assertion: $escaped === 'one-shot refusal' && $order === ['gate'],
         description: 'Steering: a throwing one-shot listener propagates to the emitter and halts the emission, like any listener'
      );

      $order = [];
      $escaped = null;
      try {
         $Emitter->emit(Events::Alpha);
         $Emitter->emit(Events::Alpha);
      }
      catch (RuntimeException $Thrown) {
         $escaped = $Thrown->getMessage();
      }

      yield assert(
         assertion: $escaped === null && $order === ['handler', 'handler'],
         description: 'Steering: it was removed before it ran — later emissions reach the other listeners only'
      );

      // # Steering — alone on its event
      $Emitter = new Emitter();

      $runs = 0;
      $Emitter->listen(Events::Alpha, function () use (&$runs) {
         $runs++;

         throw new RuntimeException('only listener');
      }, once: true);

      $escaped = null;
      try {
         $Emitter->emit(Events::Alpha);
      }
      catch (RuntimeException $Thrown) {
         $escaped = $Thrown->getMessage();
      }

      yield assert(
         assertion: $escaped === 'only listener'
            && $runs === 1
            && $Emitter->check(Events::Alpha) === false
            && $Emitter->emit(Events::Alpha) === null,
         description: 'Steering: a throwing one-shot listener that was alone leaves the event unobserved'
      );

      // # Steering — it emits its event again, then throws
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function () use ($Emitter, &$order) {
         $order[] = 'once';

         $Emitter->emit(Events::Alpha, 'nested');

         throw new RuntimeException('after the nested emission');
      }, priority: 10, once: true);
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order) {
         $order[] = $Emission->payload[0] ?? 'root';
      });

      $escaped = null;
      try {
         $Emitter->emit(Events::Alpha);
      }
      catch (RuntimeException $Thrown) {
         $escaped = $Thrown->getMessage();
      }

      yield assert(
         assertion: $escaped === 'after the nested emission' && $order === ['once', 'nested'],
         description: 'Steering: a one-shot listener that emits again and then throws ran exactly once'
      );

      // # Steering — a Listener object
      $Emitter = new Emitter();

      $Object = new class implements Listener {
         public int $handled = 0;

         public function handle (Emission $Emission): void
         {
            $this->handled++;

            throw new RuntimeException('one-shot object');
         }
      };
      $Emitter->listen(Events::Beta, $Object, once: true);

      $escaped = [];
      for ($emission = 0; $emission < 2; $emission++) {
         try {
            $Emitter->emit(Events::Beta);
         }
         catch (RuntimeException $Thrown) {
            $escaped[] = $Thrown->getMessage();
         }
      }

      yield assert(
         assertion: $escaped === ['one-shot object'] && $Object->handled === 1,
         description: 'Steering: a throwing one-shot Listener object propagates once and is handled once'
      );

      // # Observing — contained and reported, the listener does not come back
      $previousReporters = Throwables::$reporters;
      Throwables::$reporters = [];
      $reported = [];
      Throwables::$reporters[] = function (Throwable $Throwable, array $context) use (&$reported) {
         $reported[] = [$Throwable->getMessage(), $context];
      };

      try {
         $Emitter = new Emitter();

         $order = [];
         $Emitter->listen(Observations::Gamma, function () use (&$order) {
            $order[] = 'hostile once';

            throw new RuntimeException('hostile one-shot');
         }, priority: 10, once: true);
         $Emitter->listen(Observations::Gamma, function () use (&$order) {
            $order[] = 'audit';
         });

         $escaped = null;
         try {
            $Emission = $Emitter->emit(Observations::Gamma);
         }
         catch (Throwable $Thrown) {
            $escaped = $Thrown->getMessage();
         }

         yield assert(
            assertion: $escaped === null
               && $order === ['hostile once', 'audit']
               && $Emission instanceof Emission,
            description: 'Observing: a throwing one-shot listener does not escape emit() and does not blind a later listener'
         );
         yield assert(
            assertion: count($reported) === 1
               && $reported[0][0] === 'hostile one-shot'
               && ($reported[0][1]['event'] ?? null) === 'Gamma'
               && ($reported[0][1]['phase'] ?? null) === 'Emitter',
            description: 'Observing: the contained failure is reported once through Throwables::notify'
         );

         $order = [];
         $Emitter->emit(Observations::Gamma);
         $Emitter->emit(Observations::Gamma);

         yield assert(
            assertion: $order === ['audit', 'audit'] && count($reported) === 1,
            description: 'Observing: it was removed before it ran — later emissions neither run it nor report it again'
         );

         // # Observing — alone on its event
         $Emitter = new Emitter();
         $reported = [];

         $runs = 0;
         $Emitter->listen(Observations::Gamma, function () use (&$runs) {
            $runs++;

            throw new RuntimeException('alone and hostile');
         }, once: true);

         $First = $Emitter->emit(Observations::Gamma);
         $Second = $Emitter->emit(Observations::Gamma);

         yield assert(
            assertion: $First instanceof Emission
               && $Second === null
               && $runs === 1
               && count($reported) === 1
               && $Emitter->check(Observations::Gamma) === false,
            description: 'Observing: a throwing one-shot listener that was alone leaves the event unobserved'
         );

         // # Observing — removed before it runs, like on a steering event
         $Emitter = new Emitter();
         $reported = [];

         $inside = [];
         $Emitter->listen(Observations::Gamma, function () use ($Emitter, &$inside) {
            $inside[] = $Emitter->check(Observations::Gamma);
            $inside[] = $Emitter->emit(Observations::Gamma);

            throw new RuntimeException('after looking around');
         }, once: true);

         $Emitter->emit(Observations::Gamma);

         yield assert(
            assertion: $inside === [false, null] && count($reported) === 1,
            description: 'Observing: inside a one-shot listener its event is already unobserved — it cannot reach itself by emitting again'
         );

         // # Observing — a wrong-signature one-shot (engine-raised TypeError)
         $Emitter = new Emitter();
         $reported = [];

         $after = 0;
         $Emitter->listen(Observations::Gamma, function (string $wrong): void {
         }, priority: 10, once: true);
         $Emitter->listen(Observations::Gamma, function () use (&$after) {
            $after++;
         });

         $Emitter->emit(Observations::Gamma);
         $Emitter->emit(Observations::Gamma);

         yield assert(
            assertion: $after === 2 && count($reported) === 1,
            description: 'Observing: a one-shot listener the engine refuses to call is contained once and gone'
         );
      }
      finally {
         Throwables::$reporters = $previousReporters;
      }
   }
);
