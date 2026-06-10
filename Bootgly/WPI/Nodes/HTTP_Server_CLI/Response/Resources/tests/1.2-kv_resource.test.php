<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\KVResource;


use function assert;
use function spl_object_id;
use function str_contains;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\ADI\Databases\KV\Operation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;


/**
 * KV double: each operation completes after a fixed number of advance()
 * calls — the first advance simulates the pipelined write flush, later
 * ones the reply arrival — without touching sockets or a pool.
 */
class SteppedKV extends KVDatabase
{
   /** @var array<int,int> advances remaining per operation */
   public array $steps = [];
   /** @var array<int,mixed> response payload per operation */
   public array $replies = [];
   /** @var array<int,string> error message per operation */
   public array $errors = [];
   /** @var array<int,array<int,Operation>> siblings resolved together (pipelined FIFO read) */
   public array $siblings = [];
   public int $advances = 0;


   public function __construct ()
   {
      parent::__construct(['driver' => 'redis', 'pool' => ['min' => 0, 'max' => 0]]);
      // ! Zero-size pool keeps the real KV constructor connection-free for this double.
   }

   /**
    * @param array<int,mixed> $arguments
    */
   public function command (string $command, array $arguments = []): Operation
   {
      $Operation = new Operation(null, $command, $arguments);
      $id = spl_object_id($Operation);

      // ! Default: one write flush + one not-ready poll + one reply read
      $this->steps[$id] = 3;
      $this->replies[$id] = "{$command}-reply";

      return $Operation;
   }

   public function advance (Operation $Operation): Operation
   {
      // ?
      if ($Operation->finished) {
         return $Operation;
      }

      $this->advances++;
      $id = spl_object_id($Operation);
      $this->steps[$id]--;

      if ($this->steps[$id] > 0) {
         return $Operation;
      }

      // @ Reply arrived
      if (isset($this->errors[$id])) {
         return $Operation->fail($this->errors[$id]);
      }

      $Operation->response = $this->replies[$id];
      $Operation->resolve(new Result($Operation->command));

      // @ One socket read resolves earlier in-flight replies too (FIFO)
      foreach ($this->siblings[$id] ?? [] as $Sibling) {
         if ($Sibling->finished === false) {
            $Sibling->response = $this->replies[spl_object_id($Sibling)];
            $Sibling->resolve(new Result($Sibling->command));
         }
      }

      return $Operation;
   }
}


return new Specification(
   description: 'Response KV resource: pipelined command/await/drain through the response scheduler',
   test: function () {
      $KV = new SteppedKV;
      $Resource = new KV($KV);

      $parks = 0;
      $Resource->schedule(function (mixed $value = null) use (&$parks): object {
         $parks++;

         return new \stdClass;
      });

      // @ Scheduling metadata
      yield assert(
         assertion: $Resource->async === true && $Resource->KV === $KV,
         description: 'KV resource is async (Scheduling) and wraps the KV database'
      );

      // @ command() advances once (write flushed) but does not await
      $Operation = $Resource->command('GET', ['alpha']);
      yield assert(
         assertion: $Operation->finished === false && $KV->advances === 1 && $parks === 0,
         description: 'command() flushes the write (one advance) without awaiting'
      );

      // @ await() completes through the wait bridge
      $Operation = $Resource->await($Operation);
      yield assert(
         assertion: $Operation->finished === true
            && $Operation->response === 'GET-reply'
            && $parks === 1,
         description: 'await() parks on readiness and resolves the reply'
      );

      // @ fetch() unwraps the response of one command
      yield assert(
         assertion: $Resource->fetch('PING') === 'PING-reply',
         description: 'fetch() awaits and unwraps a single command'
      );

      // @ drain() overlaps a pipelined group
      $Operations = [
         $Resource->command('GET', ['a']),
         $Resource->command('GET', ['b']),
         $Resource->command('INCRBY', ['counter', 2]),
      ];
      $parks = 0;
      $Operations = $Resource->drain($Operations);
      yield assert(
         assertion: $Operations[0]->response === 'GET-reply'
            && $Operations[1]->response === 'GET-reply'
            && $Operations[2]->response === 'INCRBY-reply'
            && $Operations[2]->finished === true
            && $parks === 1,
         description: 'drain() completes the whole pipelined group in one readiness park'
      );

      // @ Regression: a later sibling's advance resolves earlier operations
      //   (pipelined FIFO replies) — drain() must re-scan instead of parking
      //   on the stale pending snapshot (a park here would never be woken:
      //   nothing stays in flight on the socket).
      $A = $Resource->command('GET', ['a']);
      $B = $Resource->command('GET', ['b']);
      $C = $Resource->command('GET', ['c']);
      $KV->steps[spl_object_id($A)] = 99;
      $KV->steps[spl_object_id($B)] = 99;
      $KV->steps[spl_object_id($C)] = 1;
      $KV->siblings[spl_object_id($C)] = [$A, $B];

      $parks = 0;
      $Drained = $Resource->drain([$A, $B, $C]);
      yield assert(
         assertion: $parks === 0
            && $Drained[0]->finished === true
            && $Drained[1]->finished === true
            && $Drained[0]->response === 'GET-reply',
         description: 'drain() does not park when a sibling advance finished the whole group'
      );

      // @ await() resolves failed commands with their error message
      $Failing = new SteppedKV;
      $FailingResource = new KV($Failing);
      $FailingResource->schedule(fn (mixed $value = null): object => new \stdClass);

      $Operation = $Failing->command('INCRBY', ['nan', 1]);
      $Failing->errors[spl_object_id($Operation)] = 'ERR value is not an integer or out of range';

      $Operation = $FailingResource->await($Operation);
      yield assert(
         assertion: $Operation->finished === true
            && str_contains((string) $Operation->error, 'not an integer'),
         description: 'Failed commands resolve with their error message'
      );

      // @ Unbound resource fails loudly instead of busy-looping
      $Unbound = new KV(new SteppedKV);
      $caught = null;
      try {
         $Unbound->fetch('GET', ['x']);
      }
      catch (RuntimeException $Throwable) {
         $caught = $Throwable;
      }
      yield assert(
         assertion: $caught !== null && str_contains($caught->getMessage(), 'not bound'),
         description: 'await() without a scheduler bridge throws'
      );
   }
);
