<?php

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Test(
   description: 'It should not abort a queue that a fresh promote() pass can still serve',
   test: new Assertions(Case: function (): Generator {
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      $Client = new class(HTTP_Client_CLI::MODE_EMBEDDED) extends HTTP_Client_CLI {
         public int $dials = 0;
         protected function dial (Request $Request): bool
         {
            // ! Stand-in handoff: capacity was released, the queue is served
            $this->dials++;
            $Request->Response->code = 200;
            $Request->Response->status = 'OK';
            $Request->completed = true;

            return true;
         }
         public function stuff (Request $Request): void
         {
            // ! Artificial seam: Pool::__construct clamps max to >= 1
            $this->Queue[] = $Request;
            $this->Pool->max = 0;
         }
         /** @return array{queue:int,dials:int} */
         public function inspect (): array
         {
            return ['queue' => count($this->Queue), 'dials' => $this->dials];
         }
      };
      $Client->react($Host->Event);
      $Client->configure(host: '127.0.0.1', port: 1);
      $Client->connectTimeout = 1;

      // ? Capacity is RELEASED during the very wait that expires: the abort
      //   must be judged only after the next promote() pass
      $parks = 0;
      $Client->schedule(function (mixed $value = null) use (&$parks, $Client): mixed {
         $parks++;
         if ($parks === 1 && $value instanceof Readiness) {
            usleep((int) max(0, ($value->deadline - microtime(true)) * 1_000_000));
            $Client->Pool->max = 1;
         }

         return null;
      });

      $Queued = new Request;
      $Queued('GET', '/');
      $Client->stuff($Queued);

      $Fiber = new Fiber(static function () use ($Client): void {
         $Client->drain();
      });
      $Fiber->start();

      yield new Assertion(
         description: 'A queue paired with late capacity is served, never scrapped',
         fallback: 'The starvation abort fired on capacity released at the deadline!'
      )
         ->expect($Fiber->isTerminated()
            && $Queued->Response->code === 200
            && $Client->inspect() === ['queue' => 0, 'dials' => 1])
         ->to->be(true)
         ->assert();
   })
);
