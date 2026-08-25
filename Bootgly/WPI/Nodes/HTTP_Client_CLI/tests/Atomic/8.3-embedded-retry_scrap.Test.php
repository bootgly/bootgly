<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should tear an armed retry campaign down with the abort, with no late resurrection',
   test: new Assertions(Case: function (): Generator {
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;

      $Client = new class(HTTP_Client_CLI::MODE_EMBEDDED) extends HTTP_Client_CLI {
         /** @var array<int,int> Watch timers held by a Request with an ARMED campaign. */
         public array $armed = [];

         protected function promote (): void
         {
            foreach ($this->Retries as $campaign) {
               $this->armed[] = count($campaign['Request']->timers);
            }
            parent::promote();
         }
         /** @return array{queue:int,retrying:int,pending:int} */
         public function inspect (): array
         {
            return [
               'queue' => count($this->Queue),
               'retrying' => $this->retrying,
               'pending' => count($this->pendingRequests)
            ];
         }
      };
      $Client->react($Event);
      $Client->configure(host: '127.0.0.1', port: 1);
      $Client->connectTimeout = 1;
      $Client->maxRetries = 1;
      $Client->retryDelay = 0.4;
      $Client->retryJitter = 0.0;
      // ? A bridge that never suspends: every park tripwires while the
      //   backoff deferral is armed on the host reactor
      $Client->schedule(static fn (mixed $value = null): mixed => null);

      $result = null;
      $Fiber = new Fiber(function () use ($Client, &$result): void {
         $started = microtime(true);
         $Upstream = $Client->request(method: 'GET', URI: '/');
         $result = [
            'code' => $Upstream->code,
            'status' => $Upstream->status,
            'elapsed' => microtime(true) - $started
         ];
      });
      $Fiber->start();

      yield new Assertion(
         description: 'The retried request resolves with a named terminal',
         fallback: 'The scrapped retry campaign returned an unnamed code-0 terminal!'
      )
         ->expect($Fiber->isTerminated()
            && ($result['code'] ?? -1) === 0
            && ($result['status'] ?? '') === 'Connection Failed')
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The campaign accounting is fully released',
         fallback: 'The abort left retrying/queued residue behind!'
      )
         ->expect($Client->inspect())
         ->to->be(['queue' => 0, 'retrying' => 0, 'pending' => 0])
         ->assert();

      // @ Let the (cancelled) backoff window pass on the host reactor — a
      //   surviving deferral would re-queue the already-returned request
      $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
      $Event->defer(microtime(true) + 0.6, static function () use ($Event): void {
         $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
      });
      $Event->loop();

      yield new Assertion(
         description: 'An armed backoff never occupies the request watch slot',
         fallback: 'The backoff deferral was pushed into Request->timers again!'
      )
         ->expect($Client->armed !== [] && array_sum($Client->armed) === 0)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'No late deferral resurrects the returned request',
         fallback: 'A stale backoff re-queued a request whose Response was already handed back!'
      )
         ->expect($Client->inspect())
         ->to->be(['queue' => 0, 'retrying' => 0, 'pending' => 0])
         ->assert();
   })
);
