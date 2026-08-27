<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;


/**
 * `Ownership::check()` is the reaper's question: "does this transport still
 * carry pending work?". It must be true only for an OPEN scope with at least
 * one attached owner — a detached owner leaves its storage allocated but
 * empty, a closed scope keeps notified tombstones, and neither is work.
 */
return new Test(
   description: 'Ownership::check() should report a live scope with attached owners — and nothing else',
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
         // @@ A) No registry at all — and no diagnostic either
         $Scopes->setValue(null, null);
         $Unknown = new stdClass;
         $warnings = [];
         set_error_handler(static function (int $code, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
         });
         try {
            $unknown = Ownership::check($Unknown);
         }
         finally {
            restore_error_handler();
         }

         yield assert(
            assertion: $unknown === false && $warnings === [],
            description: 'an empty registry reports no owners without a diagnostic — warnings: '
               . json_encode($warnings)
         );

         // @@ B) A scope the registry never saw
         Ownership::close(new stdClass);

         yield assert(
            assertion: Ownership::check($Unknown) === false,
            description: 'a scope never seen reports no owners'
         );

         // @@ C) One attached owner
         $Scope = new stdClass;
         $First = $Owner();
         Ownership::attach($Scope, $First);

         yield assert(
            assertion: Ownership::check($Scope) === true,
            description: 'a scope with one attached owner is live'
         );

         // @@ D) Detached: the storage stays allocated, but empty is idle
         Ownership::detach($Scope, $First);
         $State = $Scopes->getValue()[$Scope] ?? null;

         yield assert(
            assertion: Ownership::check($Scope) === false
               && ($State['Owners'] ?? null) !== null
               && ($State['closed'] ?? null) === false,
            description: 'a detached owner leaves an allocated but empty storage — reported as idle, observed: '
               . json_encode(['closed' => $State['closed'] ?? null, 'owners' => ($State['Owners'] ?? null) === null ? null : count($State['Owners'])])
         );

         // @@ E) Two attached, one detached
         $Second = $Owner();
         Ownership::attach($Scope, $First);
         Ownership::attach($Scope, $Second);
         Ownership::detach($Scope, $First);

         yield assert(
            assertion: Ownership::check($Scope) === true,
            description: 'one remaining owner keeps the scope live'
         );

         // @@ F) Closed with an owner: notified once, never counted again
         Ownership::close($Scope);

         yield assert(
            assertion: Ownership::check($Scope) === false && $Second->disconnects === 1,
            description: 'a closed scope reports no owners even though it notified one — disconnects: '
               . $Second->disconnects
         );

         // @@ F-2) A closed scope whose storage was left allocated is still idle
         //   (close() drops the storage; the state is forged to prove the
         //   closed test discriminates on its own)
         $Forged = new stdClass;
         $Leftover = new SplObjectStorage;
         $Leftover->offsetSet($Owner(), true);
         $Registry = $Scopes->getValue();
         $Registry[$Forged] = ['closed' => true, 'Owners' => $Leftover, 'Notified' => null];

         yield assert(
            assertion: Ownership::check($Forged) === false,
            description: 'a closed scope with a non-empty owner storage is still reported idle'
         );

         // @@ G) A late attach on the closed scope
         $Late = $Owner();
         Ownership::attach($Scope, $Late);

         yield assert(
            assertion: Ownership::check($Scope) === false && $Late->disconnects === 1,
            description: 'a late attach on a closed scope is notified and never counted — disconnects: '
               . $Late->disconnects
         );

         // @@ H) The lean tombstone (closed, never attached)
         $Lean = new stdClass;
         Ownership::close($Lean);

         yield assert(
            assertion: Ownership::check($Lean) === false,
            description: 'a lean tombstone reports no owners'
         );

         // @@ I) A fresh scope object starts clean
         $Fresh = new stdClass;
         Ownership::attach($Fresh, $Owner());

         yield assert(
            assertion: Ownership::check($Fresh) === true,
            description: 'a fresh scope with an owner is live'
         );
      }
      finally {
         $Scopes->setValue(null, $registry);
      }
   })
);
