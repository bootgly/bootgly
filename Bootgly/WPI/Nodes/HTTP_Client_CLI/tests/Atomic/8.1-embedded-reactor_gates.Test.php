<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Test(
   description: 'It should gate reactor-stack dials and abort a parked drain deterministically',
   test: new Assertions(Case: function (): Generator {
      // ! Host reactor + a client subclass exposing the protected seams
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $make = function () {
         return new class(HTTP_Client_CLI::MODE_EMBEDDED) extends HTTP_Client_CLI {
            public function attempt (Request $Request): bool
            {
               return $this->dial($Request);
            }
            public function service (): void
            {
               $this->promote();
            }
            public function plant (Request $Request): void
            {
               $this->pendingRequests[999999] = $Request;
            }
            /** @return array{queue:int,created:int,connections:int,retrying:int} */
            public function inspect (): array
            {
               return [
                  'queue' => count($this->Queue),
                  'created' => $this->Pool->created,
                  'connections' => count($this->Connections->Connections),
                  'retrying' => $this->retrying
               ];
            }
         };
      };
      $forge = function (string $method = 'GET', string $URI = '/'): Request {
         $Request = new Request;
         $Request($method, $URI);

         return $Request;
      };

      // @ D4 — a reactor-stack dial (no current Fiber) must queue, never dial
      $A = $make();
      $A->react($Host->Event);
      $A->configure(host: '127.0.0.1', port: 1);
      $handled = $A->attempt($forge());
      $state = $A->inspect();

      yield new Assertion(
         description: 'A reactor-stack dial is queued as handled',
         fallback: 'The D4 dial gate did not queue the request!'
      )
         ->expect($handled === true && $state['queue'] === 1)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'No connection is ever dialed from the reactor stack',
         fallback: 'The D4 gate let a reactor-stack dial through!'
      )
         ->expect($state['created'] === 0 && $state['connections'] === 0)
         ->to->be(true)
         ->assert();

      // @ D4 — reactor-stack promote() must not pop the queue either
      $A->service();

      yield new Assertion(
         description: 'A reactor-stack promote() leaves the queue to the Fiber',
         fallback: 'The D4 promote() gate dialed or dropped a queued request!'
      )
         ->expect($A->inspect()['queue'])
         ->to->be(1)
         ->assert();

      // @ Guards — parking without the bridge, and outside a Fiber
      $refusals = [];
      $Fiber = new Fiber(static function () use ($A): void {
         $A->drain();
      });
      try {
         $Fiber->start();
      }
      catch (LogicException $Refusal) {
         $refusals[] = $Refusal->getMessage();
      }
      $A->schedule(static fn (mixed $value = null): mixed => null);
      try {
         $A->drain();
      }
      catch (LogicException $Refusal) {
         $refusals[] = $Refusal->getMessage();
      }

      yield new Assertion(
         description: 'Parking refuses without the bridge and outside a Fiber',
         fallback: 'A parking precondition was not enforced!'
      )
         ->expect(count($refusals) === 2
            && str_contains($refusals[0], 'wait bridge')
            && str_contains($refusals[1], 'requires a Fiber'))
         ->to->be(true)
         ->assert();

      // @ Tripwire — a bridge that never suspends must abort deterministically
      $B = $make();
      $B->react($Host->Event);
      $B->configure(host: '127.0.0.1', port: 1);
      $B->schedule(static fn (mixed $value = null): mixed => null);
      $Planted = $forge();
      $B->plant($Planted);
      $Fiber = new Fiber(static function () use ($B): void {
         $B->drain();
      });
      $Fiber->start();

      yield new Assertion(
         description: 'The non-suspending bridge trips the wire and scraps',
         fallback: 'A non-suspending bridge span or hung instead of aborting!'
      )
         ->expect($Fiber->isTerminated()
            && $Planted->completed === true
            && $Planted->Response->code === 0
            && $Planted->Response->status === 'Connection Failed')
         ->to->be(true)
         ->assert();

      // @ Admission rejection — recognized, scrapped, absorbed
      $C = $make();
      $C->react($Host->Event);
      $C->configure(host: '127.0.0.1', port: 1);
      $C->schedule(static function (mixed $value = null): mixed {
         throw new RuntimeException('Fiber I/O resource failed selector admission.');
      });
      $Planted = $forge();
      $C->plant($Planted);
      $Fiber = new Fiber(static function () use ($C): void {
         $C->drain();
      });
      $Fiber->start();

      yield new Assertion(
         description: 'A selector admission rejection scraps and returns',
         fallback: 'The admission rejection did not tear the episode down!'
      )
         ->expect($Fiber->isTerminated()
            && $Planted->completed === true
            && $Planted->Response->status === 'Connection Failed')
         ->to->be(true)
         ->assert();

      // @ Foreign RuntimeExceptions are NOT laundered into a teardown
      $D = $make();
      $D->react($Host->Event);
      $D->configure(host: '127.0.0.1', port: 1);
      $D->schedule(static function (mixed $value = null): mixed {
         throw new RuntimeException('boom');
      });
      $D->plant($forge());
      $leaked = null;
      $Fiber = new Fiber(static function () use ($D): void {
         $D->drain();
      });
      try {
         $Fiber->start();
      }
      catch (RuntimeException $Foreign) {
         $leaked = $Foreign->getMessage();
      }

      yield new Assertion(
         description: 'A foreign RuntimeException propagates instead of scrapping',
         fallback: 'An unrelated exception was silently converted into a mass abort!'
      )
         ->expect($leaked)
         ->to->be('boom')
         ->assert();
   })
);
