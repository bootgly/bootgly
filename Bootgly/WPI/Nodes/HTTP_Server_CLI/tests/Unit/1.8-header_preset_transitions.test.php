<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Specification(
   description: 'It should serialize same-second preset transitions on one persistent Header and never cache the stale wire',
   test: new Assertions(Case: function (): Generator {
      // ! ONE persistent Response/Header across "requests" — a fresh instance
      //   per scenario cannot catch the raw-memo/state split under test
      $Response = new Response;
      $Header = $Response->Header;

      // @@ Retry until every build lands inside one wall-clock second — the
      //    window build()'s dirty-gated same-second fast return opens
      $attempts = 0;
      do {
         $attempts++;

         $t0 = time();

         // # Request 1 — a cookie preset is serialized
         $Header->preset('Set-Cookie', 'session=SECRET');
         $Header->clean();
         $Header->build();
         $first = str_contains($Header->raw, 'session=SECRET');

         // # Same second — replace, then remove the preset
         $Header->preset('Set-Cookie', 'session=ROTATED');
         $Header->clean();
         $Header->build();
         $replaced = str_contains($Header->raw, 'session=ROTATED')
            && str_contains($Header->raw, 'session=SECRET') === false;

         $Header->preset('Set-Cookie', null);
         $Header->clean();
         $Header->build();
         $removed = str_contains($Header->raw, 'session=') === false;

         $t1 = time();
      } while ($t0 !== $t1 && $attempts < 100);

      yield new Assertion(
         description: 'the preset cookie serializes on the first build',
      )
         ->expect($first)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a same-second preset replacement rebuilds the raw block',
      )
         ->expect($replaced)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a same-second preset removal rebuilds the raw block',
      )
         ->expect($removed)
         ->to->be(true)
         ->assert();

      // @ Cache eligibility with the REAL built wire (not a synthetic buffer):
      //   after the removal transition the response is cookie-free and its
      //   stored wire must be cookie-free too
      $Request = new Request;
      $Request->method = 'GET';
      $Request->protocol = 'HTTP/1.1';
      (new ReflectionProperty(Request::class, 'URI'))->setValue($Request, '/preset-transition');
      (new ReflectionProperty(Response::class, 'Request'))->setValue($Response, $Request);
      $Response->cache = 60;

      Cache::flush();
      $Response->stash("HTTP/1.1 200 OK\r\n{$Header->raw}\r\n\r\nok");

      $storedCookie = false;
      foreach (Cache::$entries as $entry) {
         if (str_contains($entry[0], 'session=')) {
            $storedCookie = true;
         }
      }
      $stored = Cache::$entries !== [];
      Cache::flush();

      yield new Assertion(
         description: 'the post-removal wire stores and carries no stale cookie bytes',
      )
         ->expect($stored === true && $storedCookie === false)
         ->to->be(true)
         ->assert();
   })
);
