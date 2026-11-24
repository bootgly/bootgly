<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


return new Test(
   description: 'It should end the batch on every way out of drain(), a throw included',
   test: new Assertions(Case: function (): Generator {
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      $Client = new class(HTTP_Client_CLI::MODE_EMBEDDED) extends HTTP_Client_CLI {
         /** @var array<int,string> */
         public array $dials = [];
         protected function dial (Request $Request): bool
         {
            // ! Stand-in handoff: every dialed request is served at once
            $this->dials[] = $Request->URI;
            $Request->Response->code = 200;
            $Request->Response->status = 'OK';
            $Request->completed = true;

            return true;
         }
         public function stuff (Request $Request): void
         {
            // ! Artificial seam: a batched request waiting for capacity keeps
            //   the drain parked (Pool::__construct clamps max to >= 1)
            $this->Queue[] = $Request;
            $this->Pool->max = 0;
         }
         public function release (): void
         {
            $this->Pool->max = 1;
         }
         /** @return array{batching:bool,queue:int} */
         public function inspect (): array
         {
            return ['batching' => $this->batching, 'queue' => count($this->Queue)];
         }
      };
      $Client->react($Host->Event);
      $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 1));
      $Client->connectTimeout = 1;

      // ! The first park throws, as a deferral `Timeout` delivered into the
      //   parked Fiber does; every later park lets the client run
      $parks = 0;
      $Client->schedule(function (mixed $value = null) use (&$parks): mixed {
         $parks++;
         if ($parks === 1) {
            throw new RuntimeException('deferral timeout');
         }

         return null;
      });

      // @ An empty batch: nothing to drain still ends batch mode
      $Client->batch();
      $Empty = new Fiber(static function () use ($Client): void {
         $Client->drain();
      });
      $Empty->start();

      yield new Assertion(
         description: 'A drain() with nothing pending ends the batch mode',
         fallback: 'The client stayed in batch mode after an empty drain()!'
      )
         ->expect([$Empty->isTerminated(), $Client->inspect()])
         ->to->be([true, ['batching' => false, 'queue' => 0]])
         ->assert();

      $Client->batch();
      $Slow = new Request;
      $Slow('GET', '/slow');
      $Client->stuff($Slow);

      $thrown = null;
      $after = null;
      $Fast = null;
      $Fiber = new Fiber(static function () use ($Client, &$thrown, &$after, &$Fast): void {
         try {
            $Client->drain();
         }
         catch (RuntimeException $Timeout) {
            $thrown = $Timeout->getMessage();
         }
         $after = $Client->inspect();

         // @ The job keeps using the client once capacity frees
         $Client->release();
         $Fast = $Client->request('GET', '/fast');
      });
      $Fiber->start();

      yield new Assertion(
         description: 'The throw leaves drain() and ends the batch mode',
         fallback: 'The client stayed in batch mode after the throw!'
      )
         ->expect([$thrown, $after])
         ->to->be(['deferral timeout', ['batching' => false, 'queue' => 1]])
         ->assert();

      yield new Assertion(
         description: 'The next request() is sent and answered, never returned empty',
         fallback: 'The request after the throw came back unsent!'
      )
         ->expect([
            $Fiber->isTerminated(),
            $Fast?->code,
            in_array('/fast', $Client->dials, true),
         ])
         ->to->be([true, 200, true])
         ->assert();
   })
);
