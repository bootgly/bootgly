<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;


/**
 * Pay-for-use tombstones, and the late attachment they must keep serving.
 *
 * `Ownership::attach()` is reachable only from `Response::retain()`, i.e. only
 * from `defer()`. `Ownership::close()` is reachable from every
 * `Decoder_HTTP2\Stream::close()` — once per HTTP/2 response — and from every
 * `Connection::close()`. The overwhelmingly common scope therefore closes with
 * no owner ever attached, and must not pay for owner bookkeeping it never used.
 *
 * The tombstone entry ITSELF is load-bearing and is deliberately still written:
 * a late `attach()` on a closed scope has to observe closure and notify its
 * owner immediately, otherwise deferred work whose transport died during the
 * handler is stored against a dead scope and never cancelled — the leak class
 * this machinery exists to prevent. Only the collections are made lazy.
 *
 * The second and third assertions exist because the obvious lean tombstone
 * (`['closed' => true]` alone) is a FATAL bug: `attach()`'s closed branch does
 * `$State['Notified'][$Owner] = true`, which auto-vivifies a plain array and
 * throws `TypeError: Cannot access offset of type … on array`.
 */
return new Test(
   description: 'Ownership should not allocate owner bookkeeping for scopes that never attach, and must still notify late owners exactly once',
   test: new Assertions(Case: function (): Generator {
      $Scopes = new ReflectionProperty(Ownership::class, 'Scopes');
      $registry = $Scopes->getValue();

      $Owner = static fn (): object => new class implements Disconnecting {
         public int $disconnects = 0;

         public function disconnect (): void
         {
            $this->disconnects++;
         }
      };

      try {
         // @@ A) A scope that never attached must close without allocating
         $Scopes->setValue(null, null);
         $Untouched = new stdClass;
         Ownership::close($Untouched);

         $State = $Scopes->getValue()[$Untouched] ?? null;

         yield assert(
            assertion: $State !== null && ($State['closed'] ?? null) === true,
            description: 'a closed scope keeps its terminal tombstone — observed: '
               . var_export($State === null ? null : ($State['closed'] ?? null), true)
         );

         yield assert(
            assertion: ($State['Owners'] ?? null) === null
               && ($State['Notified'] ?? null) === null,
            description: 'closing an owner-less scope allocates no owner bookkeeping — Owners: '
               . get_debug_type($State['Owners'] ?? null)
               . ', Notified: ' . get_debug_type($State['Notified'] ?? null)
         );

         // @@ B) That lean tombstone must still serve a LATE attachment
         $Late = $Owner();
         Ownership::attach($Untouched, $Late);

         yield assert(
            assertion: $Late->disconnects === 1,
            description: 'a late owner on a lean tombstone is notified exactly once — observed: '
               . $Late->disconnects
         );

         // @@ C) …and only once, however many times it re-attaches
         Ownership::attach($Untouched, $Late);
         Ownership::attach($Untouched, $Late);

         yield assert(
            assertion: $Late->disconnects === 1,
            description: 'repeated late attachment stays terminal-once — observed: '
               . $Late->disconnects
         );

         // @@ D) The ordinary attach -> close path is unchanged
         $Scopes->setValue(null, null);
         $Live = new stdClass;
         $First = $Owner();
         $Second = $Owner();
         Ownership::attach($Live, $First);
         Ownership::attach($Live, $Second);
         Ownership::detach($Live, $Second);
         Ownership::close($Live);
         Ownership::close($Live);

         yield assert(
            assertion: $First->disconnects === 1 && $Second->disconnects === 0,
            description: 'an attached owner is notified once and a detached one never — observed: '
               . "{$First->disconnects}/{$Second->disconnects}"
         );

         // @@ E) A late attach after a POPULATED close is still notified once
         $Third = $Owner();
         Ownership::attach($Live, $Third);

         yield assert(
            assertion: $Third->disconnects === 1,
            description: 'a late owner on a populated tombstone is notified once — observed: '
               . $Third->disconnects
         );
      }
      finally {
         $Scopes->setValue(null, $registry);
      }
   }),
);
