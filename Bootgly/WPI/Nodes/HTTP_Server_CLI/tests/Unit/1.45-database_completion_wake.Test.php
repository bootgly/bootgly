<?php

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;

return new Test(
   description: 'Database readiness parks must be woken by sibling-context completions',
   test: new Assertions(Case: function (): Generator {
      // # The strand (warmup hang root cause): a Fiber parked on socket-read
      //   readiness inside drain()/await() while a sibling execution context
      //   consumes the socket AND finishes the parked Fiber's operations. The
      //   socket never signals again — only the completion edge can wake it.
      $SQL = new class extends SQL {
         // ! Inert protocol: the test owns operation state transitions, so a
         //   parked Fiber can only return through the completion wake edge.
         public function advance (Operation $Operation): Operation
         {
            return $Operation;
         }
      };

      $Server = new TCP_Server_CLI;
      $Connections = new Connections($Server);
      $Select = new Select($Connections);
      TCP_Server_CLI::$Event = $Select;

      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      assert($Pair !== false);
      stream_set_blocking($Pair[0], false);
      stream_set_blocking($Pair[1], false);

      // ! No deadline on purpose: with a lost wakeup, nothing else ever
      //   resumes these Fibers — the assertions below then fail.
      $Drained = new Operation(null, 'SELECT 1');
      $Drained->await(Readiness::read($Pair[0]));
      $Awaited = new Operation(null, 'SELECT 2');
      $Awaited->await(Readiness::read($Pair[0]));

      $bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);

      $DrainResource = new Database($SQL);
      $DrainResource->schedule($bridge);
      $AwaitResource = new Database($SQL);
      $AwaitResource->schedule($bridge);

      $drained = false;
      $DrainFiber = new Fiber(function () use ($DrainResource, $Drained, &$drained): void {
         $DrainResource->drain([$Drained]);
         $drained = true;
      });
      $awaited = false;
      $AwaitFiber = new Fiber(function () use ($AwaitResource, $Awaited, &$awaited): void {
         $AwaitResource->await($Awaited);
         $awaited = true;
      });

      $Select->schedule($DrainFiber, $DrainFiber->start());
      $Select->schedule($AwaitFiber, $AwaitFiber->start());

      // @ The "sibling": finishes both operations without ever touching the
      //   socket — exactly what a co-located pipeline read does for a parked
      //   sibling's operations.
      $Select->defer(microtime(true) + 0.05, static function () use ($Drained, $Awaited): void {
         $Drained->fail('finished by a sibling context');
         $Awaited->fail('finished by a sibling context');
      });

      // @ Fail-closed bound: a lost wakeup must end the loop, not hang the suite.
      $Select->defer(microtime(true) + 0.25, static function () use ($Select): void {
         $Select->destroy();
      });

      $Select->loop();

      fclose($Pair[0]);
      fclose($Pair[1]);

      yield new Assertion(
         description: 'both parked Fibers return through the completion edge, with no socket readiness',
      )
         ->expect([
            'drained' => $drained,
            'awaited' => $awaited,
            'drain_finished' => $Drained->finished,
            'await_finished' => $Awaited->finished,
            'drain_terminated' => $DrainFiber->isTerminated(),
            'await_terminated' => $AwaitFiber->isTerminated(),
         ])
         ->to->be([
            'drained' => true,
            'awaited' => true,
            'drain_finished' => true,
            'await_finished' => true,
            'drain_terminated' => true,
            'await_terminated' => true,
         ])
         ->assert();

      // # Operation completion hook contract (ADI)
      $fires = 0;
      $armedAtFire = null;
      $Contract = new Operation(null, 'SELECT 1');
      $Contract->Waker = function () use (&$fires, &$armedAtFire, $Contract): void {
         $fires++;
         // ! Cleared BEFORE invocation: a hook that re-finishes cannot recurse
         $armedAtFire = $Contract->Waker;
      };
      $Contract->fail('finished by a sibling context');
      $Contract->fail('finished twice');

      $Resolved = new Operation(null, 'SELECT 1');
      $resolvedFires = 0;
      $Resolved->Waker = function () use (&$resolvedFires): void {
         $resolvedFires++;
      };
      $Resolved->resolve(new Result);

      $Disarmed = new Operation(null, 'SELECT 1');
      $Disarmed->fail('finished while disarmed');

      yield new Assertion(
         description: 'an armed Waker fires exactly once, cleared before invocation',
      )
         ->expect([
            'fires' => $fires,
            'armed_at_fire' => $armedAtFire,
            'resolve_fires' => $resolvedFires,
            'disarmed_waker' => $Disarmed->Waker,
         ])
         ->to->be([
            'fires' => 1,
            'armed_at_fire' => null,
            'resolve_fires' => 1,
            'disarmed_waker' => null,
         ])
         ->assert();
   })
);
