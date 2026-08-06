<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


/**
 * Regression for RES-8: vary() — the canonical entry point for every Vary
 * writer — located the current Vary by scanning $fields alone. A Vary
 * declared through prepare()/queue()/preset() was invisible to it, so
 * vary() started a second independent declaration and build()'s
 * case-insensitive collision handling silently discarded one of the two:
 * a queued or preset Vary erased the framework's automatic
 * `Vary: Accept-Language`, while a prepared Vary lost the application's
 * dimension to the framework's.
 *
 * Every scenario asserts the built wire ($raw): exactly ONE Vary line
 * carrying the union of every declared token.
 */
return new Specification(
   description: 'vary() must merge Vary declarations across every serialization source',
   test: new Assertions(Case: function (): Generator {
      // # A prepare()d Vary — the Response::__invoke() route — must merge
      //   with the framework token instead of being shadowed by it
      $Header = new Header;
      $Header->prepare(['Vary' => 'Accept-Encoding']);
      $Header->vary('Accept-Language');
      $Header->build();

      yield new Assertion(
         description: 'a prepared Vary merges with the framework token on one line',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: Accept-Encoding, Accept-Language')
            && substr_count($Header->raw, 'Vary:') === 1
            && $Header->get('Vary') === 'Accept-Encoding, Accept-Language'
         )
         ->to->be(true)
         ->assert();

      // # A queue()d Vary owns the wire line — it must join the merge, not
      //   erase the framework token
      $Header = new Header;
      $Header->queue('Vary', 'X-Tenant');
      $Header->vary('Accept-Language');
      $Header->build();

      yield new Assertion(
         description: 'a queued Vary merges with the framework token on one line',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: X-Tenant, Accept-Language')
            && substr_count($Header->raw, 'Vary:') === 1
         )
         ->to->be(true)
         ->assert();

      // # A preset Vary serializes ahead of fields — it must join the merge
      //   (masked for this response by the consolidation)
      $Header = new Header;
      $Header->preset('Vary', 'X-Region');
      $Header->vary('Accept-Language');
      $Header->build();

      yield new Assertion(
         description: 'a preset Vary merges with the framework token on one line',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: X-Region, Accept-Language')
            && substr_count($Header->raw, 'Vary:') === 1
         )
         ->to->be(true)
         ->assert();

      // # A wildcard in a non-fields source already covers every request
      //   field — no token may be appended beside it
      $Header = new Header;
      $Header->prepare(['Vary' => '*']);
      $Header->vary('Accept-Language');
      $Header->build();

      yield new Assertion(
         description: 'a prepared wildcard stays alone — no redundant token beside *',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: *')
            && substr_count($Header->raw, 'Vary:') === 1
            && str_contains($Header->raw, 'Accept-Language') === false
         )
         ->to->be(true)
         ->assert();

      // # Token identity is case-insensitive ACROSS sources — a prepared
      //   lowercase token must not duplicate under the canonical casing
      $Header = new Header;
      $Header->prepare(['Vary' => 'accept-language']);
      $Header->vary('Accept-Language');
      $Header->build();

      yield new Assertion(
         description: 'a prepared lowercase token is never duplicated by the merge',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: accept-language')
            && substr_count($Header->raw, 'Vary:') === 1
            && substr_count(strtolower($Header->raw), 'accept-language') === 1
         )
         ->to->be(true)
         ->assert();

      // # Control — the fields-only path (set() + vary()) keeps today's
      //   behavior byte-identically, superstring tokens included
      $Header = new Header;
      $Header->set('Vary', 'X-Accept-Language-Experiment');
      $Header->vary('Accept-Language');
      $Header->build();

      yield new Assertion(
         description: 'the fields-only path is unchanged — superstring does not satisfy the token',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: X-Accept-Language-Experiment, Accept-Language')
            && substr_count($Header->raw, 'Vary:') === 1
         )
         ->to->be(true)
         ->assert();

      // # Vary is a comma-list field (RFC 9110 §5.2): a declaration written
      //   AFTER the response's last vary() call — the CORS middleware emits
      //   `Vary: Origin` BEFORE the handler runs — must still reach the wire
      //   joined, never discarded by build()'s collision handling
      $Header = new Header;
      $Header->vary('Origin');
      $Header->queue('Vary', 'X-Tenant');
      $Header->build();

      yield new Assertion(
         description: 'a Vary queued after the last vary() call joins the earlier declaration',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: X-Tenant, Origin')
            && substr_count($Header->raw, 'Vary:') === 1
            && $Header->get('Vary') === 'X-Tenant, Origin'
         )
         ->to->be(true)
         ->assert();

      $Header = new Header;
      $Header->vary('Origin');
      $Header->prepare(['Vary' => 'Accept-Encoding']);
      $Header->build();

      yield new Assertion(
         description: 'a Vary prepared after the last vary() call joins the earlier declaration',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: Origin, Accept-Encoding')
            && substr_count($Header->raw, 'Vary:') === 1
            && $Header->get('Vary') === 'Origin, Accept-Encoding'
         )
         ->to->be(true)
         ->assert();

      // # The preset-only serialization shortcut takes its own build() path —
      //   a queued + preset collision must join there too
      $Header = new Header;
      $Header->preset('Vary', 'X-Region');
      $Header->queue('Vary', 'X-Tenant');
      $Header->build();

      yield new Assertion(
         description: 'a queued + preset Vary collision joins on the preset-only build path',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: X-Tenant, X-Region')
            && substr_count($Header->raw, 'Vary:') === 1
         )
         ->to->be(true)
         ->assert();

      // # A wildcard member absorbs the whole list on the build()-level join
      $Header = new Header;
      $Header->queue('Vary', '*');
      $Header->set('Vary', 'X-Tenant');
      $Header->build();

      yield new Assertion(
         description: 'a wildcard member absorbs the joined list — Vary: * alone',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: *')
            && substr_count($Header->raw, 'Vary:') === 1
            && str_contains($Header->raw, 'X-Tenant') === false
         )
         ->to->be(true)
         ->assert();

      // # `*` passed as the vary() ARGUMENT is the wildcard, not a token —
      //   RFC 9110 §12.5.5: a sender should not generate `*` beside names
      $Header = new Header;
      $Header->queue('Vary', 'X-Tenant');
      $Header->vary('*');
      $Header->build();

      yield new Assertion(
         description: 'vary(*) collapses a foreign token list to the canonical wildcard',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: *')
            && substr_count($Header->raw, 'Vary:') === 1
            && str_contains($Header->raw, 'X-Tenant') === false
         )
         ->to->be(true)
         ->assert();

      $Header = new Header;
      $Header->vary('Accept-Language');
      $Header->vary('*');
      $Header->build();

      yield new Assertion(
         description: 'vary(*) collapses an existing fields list to the canonical wildcard',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: *')
            && substr_count($Header->raw, 'Vary:') === 1
            && str_contains($Header->raw, 'Accept-Language') === false
         )
         ->to->be(true)
         ->assert();

      // # preset() keys by exact casing, so two case-variant preset Vary
      //   entries coexist — the preset-only fast path must join them like
      //   every other collision (a just-emitted line is joinable too)
      $Header = new Header;
      $Header->preset('Vary', 'Accept-Encoding');
      $Header->preset('vary', 'Origin');
      $Header->build();

      yield new Assertion(
         description: 'case-variant preset Vary entries join on the preset-only fast path',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: Accept-Encoding, Origin')
            && substr_count(strtolower($Header->raw), 'vary:') === 1
            && $Header->get('Vary') === 'Accept-Encoding, Origin'
         )
         ->to->be(true)
         ->assert();

      // # A `true` preset Vary (trusted-subclass write) must never cast to a
      //   fabricated `1` token when it is the join's holder side
      $Header = new class extends Header {
         public function seed (): void
         {
            $this->preset = ['Vary' => true];
         }
      };
      $Header->seed();
      $Header->set('Vary', 'Origin');
      $Header->build();

      yield new Assertion(
         description: 'a true preset Vary holder joins as empty — no fabricated 1 token',
      )
         ->expect(
            str_contains($Header->raw, 'Vary: Origin')
            && substr_count(strtolower($Header->raw), 'vary:') === 1
            && str_contains($Header->raw, '1, Origin') === false
         )
         ->to->be(true)
         ->assert();
   })
);
