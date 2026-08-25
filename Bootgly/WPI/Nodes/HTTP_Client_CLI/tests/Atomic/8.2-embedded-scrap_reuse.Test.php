<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should scrap a reused pooled connection without orphaning it or renaming its terminal',
   // ! NOTE: the pump bridge below runs the host reactor INSIDE the owner
   //   Fiber, so every callback observes Fiber::getCurrent() !== null — the
   //   inverse of production. The D4 reactor-stack gates are pinned by 8.1;
   //   this spec pins the scrap/close/pool interaction over real sockets.
   test: new Assertions(Case: function (): Generator {
      // ! Host reactor + a keep-alive upstream served BY the host reactor
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;

      $Server = stream_socket_server('tcp://127.0.0.1:0');
      if ($Server === false) {
         throw new RuntimeException('Unable to open the scrap-reuse upstream.');
      }
      stream_set_blocking($Server, false);
      [$address, $port] = explode(':', stream_socket_get_name($Server, false));

      $Peers = [];
      $serving = true;
      $serve = null;
      $serve = function () use (&$serve, &$serving, &$Peers, $Server, $Event): void {
         if ($serving === false) {
            return;
         }
         $Peer = @stream_socket_accept($Server, 0);
         if ($Peer !== false) {
            stream_set_blocking($Peer, false);
            $Peers[] = $Peer;
         }
         foreach ($Peers as $Open) {
            $head = @fread($Open, 4096);
            if ($head !== false && $head !== '') {
               // ? Keep-alive: answer and KEEP the connection open. /two gets
               //   a SHORT body (declared 8, sent 2) so its leg is scrapped
               //   MID-BODY — the shape the terminalization exists for
               if (strpos($head, ' /two ') !== false) {
                  @fwrite($Open, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 8\r\nConnection: keep-alive\r\n\r\nka");
               }
               else {
                  @fwrite($Open, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nka");
               }
            }
         }
         $Event->defer(microtime(true) + 0.005, $serve);
      };
      $Event->defer(microtime(true) + 0.005, $serve);

      // ! Embedded client with an honest pump bridge and a suspend flag
      $Client = new class(HTTP_Client_CLI::MODE_EMBEDDED) extends HTTP_Client_CLI {
         /** @return array{queue:int,created:int,busy:int,idle:int,connections:int,pending:int} */
         public function inspect (): array
         {
            return [
               'queue' => count($this->Queue),
               'created' => $this->Pool->created,
               'busy' => count($this->Pool->busy),
               'idle' => count($this->Pool->idle),
               'connections' => count($this->Connections->Connections),
               'pending' => count($this->pendingRequests)
            ];
         }
      };
      $Client->react($Event);
      $Client->configure(host: '127.0.0.1', port: (int) $port);
      $Client->connectTimeout = 1;
      $Client->timeout = 2;

      // ! Pump budget: PHP_INT_MAX = honest waits; a small budget lets a leg
      //   make partial progress before the bridge stops suspending (tripwire)
      $pumps = PHP_INT_MAX;
      $Client->schedule(static function (mixed $value = null) use (&$pumps, $Event): mixed {
         if ($pumps <= 0) {
            // ? The cancelled-deferral shape: return without suspending
            return null;
         }
         $pumps--;

         // @ Honest wait: pump the host reactor for one short slice
         $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Event->defer(microtime(true) + 0.05, static function () use ($Event): void {
            $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Event->loop();

         return null;
      });

      $results = [];
      $Fiber = new Fiber(function () use ($Client, &$pumps, &$results): void {
         // @ Leg A primes the pool with a keep-alive connection
         $A = $Client->request(method: 'GET', URI: '/one');
         $results['A'] = ['code' => $A->code, 'state' => $Client->inspect()];

         // @ Leg B rides the REUSED pooled connection, receives PART of its
         //   body (3 pump slices), then the bridge stops suspending and the
         //   tripwire scraps it MID-BODY — the replay-orphan + truncation shape
         $pumps = 3;
         $B = $Client->request(method: 'GET', URI: '/two');
         $results['B'] = [
            'code' => $B->code,
            'status' => $B->status,
            'raw' => $B->Body->raw,
            'waiting' => $B->Body->waiting,
            'state' => $Client->inspect()
         ];

         // @ Leg C proves the client (and its pool slot) survived the abort
         $pumps = PHP_INT_MAX;
         $started = microtime(true);
         $C = $Client->request(method: 'GET', URI: '/three');
         $results['C'] = ['code' => $C->code, 'elapsed' => microtime(true) - $started];
      });
      $Fiber->start();

      // @ Teardown
      $serving = false;
      foreach ($Peers as $Open) {
         @fclose($Open);
      }
      fclose($Server);

      yield new Assertion(
         description: 'The primed leg completed on the keep-alive upstream',
         fallback: 'Leg A did not answer 200!'
      )
         ->expect(($results['A']['code'] ?? 0) === 200 && ($results['A']['state']['created'] ?? 0) === 1)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The mid-body scrapped leg is terminalized, never a 200',
         fallback: 'The scrap surfaced a truncated body as a success (or renamed it)!'
      )
         ->expect(($results['B']['code'] ?? -1) === 0
            && ($results['B']['status'] ?? '') === 'Truncated Response'
            && ($results['B']['raw'] ?? '') === 'ka'
            && ($results['B']['waiting'] ?? false) === true)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The scrap left no orphan connection or pool slot',
         fallback: 'A live connection survived the scrap untracked (pool poisoned)!'
      )
         ->expect(($results['B']['state']['connections'] ?? -1) === 0
            && ($results['B']['state']['created'] ?? -1) === 0
            && ($results['B']['state']['busy'] ?? -1) === 0
            && ($results['B']['state']['pending'] ?? -1) === 0)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The next request succeeds promptly after the abort',
         fallback: 'The client was poisoned - the post-scrap request failed or crawled!'
      )
         ->expect(($results['C']['code'] ?? 0) === 200 && ($results['C']['elapsed'] ?? 9.9) < 1.0)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The whole episode ran to completion',
         fallback: 'The owner Fiber never returned!'
      )
         ->expect($Fiber->isTerminated())
         ->to->be(true)
         ->assert();
   })
);
