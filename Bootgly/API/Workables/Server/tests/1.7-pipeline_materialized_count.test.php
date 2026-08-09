<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\API\Workables\Server\Middlewares;


final class MaterializedCountMiddleware implements Middleware
{
   public function process (object $Request, object $Response, Closure $next): object
   {
      return $next($Request, $Response);
   }
}


return new Specification(
   description: 'It should materialize middleware count without admitting incomplete pipelines',
   test: new Assertions(Case: function (): Generator {
      // !
      $Pipeline = new Middlewares;

      // @
      yield new Assertion(
         description: 'A normally constructed empty pipeline should expose zero',
      )
         ->expect($Pipeline->count)
         ->to->be(0)
         ->assert();

      $Pipeline
         ->append(new MaterializedCountMiddleware)
         ->prepend(new MaterializedCountMiddleware)
         ->pipe(
            new MaterializedCountMiddleware,
            new MaterializedCountMiddleware,
         )
         ->pipe();

      yield new Assertion(
         description: 'Every stack mutation should keep the materialized count exact',
      )
         ->expect($Pipeline->count)
         ->to->be(4)
         ->assert();

      // ! Reflection simulates a stale or forged serialized counter. Clone
      //   and wakeup must derive authority from the private stack instead.
      $Count = new ReflectionProperty(Middlewares::class, 'count');
      $Count->setValue($Pipeline, 0);

      yield new Assertion(
         description: 'The forged-count control should actually diverge from the stack',
      )
         ->expect($Pipeline->count)
         ->to->be(0)
         ->assert();

      $Clone = clone $Pipeline;
      $Roundtrip = unserialize(
         serialize($Pipeline),
         ['allowed_classes' => [
            Middlewares::class,
            MaterializedCountMiddleware::class,
         ]],
      );

      yield new Assertion(
         description: 'Cloning should repair a stale materialized count',
      )
         ->expect($Clone->count)
         ->to->be(4)
         ->assert();

      yield new Assertion(
         description: 'Unserialization should repair a stale materialized count',
      )
         ->expect(
            $Roundtrip instanceof Middlewares
            ? $Roundtrip->count
            : null
         )
         ->to->be(4)
         ->assert();

      $Empty = new Middlewares;
      $EmptyClone = clone $Empty;
      $EmptyRoundtrip = unserialize(
         serialize($Empty),
         ['allowed_classes' => [Middlewares::class]],
      );

      yield new Assertion(
         description: 'Clone and serialization should preserve a valid empty pipeline',
      )
         ->expect([
            $EmptyClone->count,
            $EmptyRoundtrip instanceof Middlewares
               ? $EmptyRoundtrip->count
               : null,
         ])
         ->to->be([0, 0])
         ->assert();

      $Reflection = new ReflectionClass(Middlewares::class);
      $Bypassed = $Reflection->newInstanceWithoutConstructor();
      $BypassedClone = clone $Bypassed;
      $BypassedRoundtrip = unserialize(
         serialize($Bypassed),
         ['allowed_classes' => [Middlewares::class]],
      );

      yield new Assertion(
         description: 'Constructor-bypassed pipelines should remain fail-closed across copies',
      )
         ->expect([
            $Bypassed->count,
            $BypassedClone->count,
            $BypassedRoundtrip instanceof Middlewares
               ? $BypassedRoundtrip->count
               : null,
         ])
         ->to->be([1, 1, 1])
         ->assert();

      $writeProtected = false;
      try {
         $Empty->count = 1;
      }
      catch (Error) {
         $writeProtected = true;
      }

      yield new Assertion(
         description: 'External code should not be able to forge the counter',
      )
         ->expect($writeProtected)
         ->to->be(true)
         ->assert();
   })
);
