<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


return new Specification(
   description: 'It should never rebuild a cleaned queued header into the next same-second response',
   test: new Assertions(Case: function (): Generator {
      // @@ Retry until both builds land inside one wall-clock second — the
      //    leak window build()'s same-second fast return opens
      $attempts = 0;
      do {
         $attempts++;
         $Header = new Header;

         $t0 = time();
         // # Response 1 — a route queues a session cookie (the canonical
         //   Cookies::append path emits through Header::queue())
         $Header->Cookies->append(new Cookie('session', 'SECRET'));
         $Header->build();
         $raw1 = $Header->raw;

         // # Response 2 (same second) — the per-request lifecycle is
         //   Response::reset() → Header->clean(); no header mutations
         $Header->clean();
         $Header->build();
         $raw2 = $Header->raw;
         $t1 = time();
      } while ($t0 !== $t1 && $attempts < 100);

      yield new Assertion(
         description: 'response 1 serialized its Set-Cookie',
      )
         ->expect(str_contains($raw1, 'session=SECRET'))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'response 2 does not replay response 1\'s Set-Cookie',
      )
         ->expect(str_contains($raw2, 'session=SECRET'))
         ->to->be(false)
         ->assert();

      // @ The per-response cookie accumulator must not grow for the worker
      //   lifetime — clean() resets it with the queued lines
      yield new Assertion(
         description: 'clean() resets the Cookies accumulator',
      )
         ->expect($Header->Cookies->cookies === [])
         ->to->be(true)
         ->assert();
   })
);
