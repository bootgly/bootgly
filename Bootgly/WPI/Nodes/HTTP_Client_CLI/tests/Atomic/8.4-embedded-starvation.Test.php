<?php

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Test(
   description: 'It should fail queued requests loud when no capacity can ever serve them',
   test: new Assertions(Case: function (): Generator {
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      $Client = new class(HTTP_Client_CLI::MODE_EMBEDDED) extends HTTP_Client_CLI {
         public function stuff (Request $Request): void
         {
            // ! Queue-only starvation: a queued request with zero capacity.
            //   Artificial seam — Pool::__construct clamps max to >= 1, so
            //   this state is unreachable through the public API
            $this->Queue[] = $Request;
            $this->Pool->max = 0;
         }
         /** @return array{queue:int,retrying:int} */
         public function inspect (): array
         {
            return [
               'queue' => count($this->Queue),
               'retrying' => $this->retrying
            ];
         }
      };
      $Client->react($Host->Event);
      $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 1));
      $Client->connectTimeout = 1;
      // ? An honest expiring wait: sleep to the park deadline, wake with nothing
      $Client->schedule(static function (mixed $value = null): mixed {
         if ($value instanceof Readiness) {
            usleep((int) max(0, ($value->deadline - microtime(true)) * 1_000_000));
         }

         return null;
      });

      $Starved = new Request;
      $Starved('GET', '/');
      $Client->stuff($Starved);

      $elapsed = null;
      $Fiber = new Fiber(function () use ($Client, &$elapsed): void {
         $started = microtime(true);
         $Client->drain();
         $elapsed = microtime(true) - $started;
      });
      $Fiber->start();

      yield new Assertion(
         description: 'The starved drain returns instead of parking forever',
         fallback: 'A capacity-starved queue wedged the owner Fiber!'
      )
         ->expect($Fiber->isTerminated())
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The abort waits one full silent slice, never fires eagerly',
         fallback: 'The starvation abort fired without paying the silent deadline!'
      )
         ->expect($elapsed ?? 99.9)
         ->to->delimit(0.9, 2.5)
         ->assert();

      yield new Assertion(
         description: 'The starved request is failed loud, with a named terminal',
         fallback: 'Starvation left the queued request pending or unnamed!'
      )
         ->expect($Starved->completed === true
            && $Starved->Response->code === 0
            && $Starved->Response->status === 'Connection Failed')
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The queue is empty after the abort',
         fallback: 'The starved queue survived the abort!'
      )
         ->expect($Client->inspect())
         ->to->be(['queue' => 0, 'retrying' => 0])
         ->assert();
   })
);
