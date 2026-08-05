<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


/**
 * Regression for RES-7: Header has four serialization sources — queued,
 * preset (minus the per-response mask), fields and prepared — and build()
 * serializes their case-insensitive union, but get()/__isSet() read only
 * $fields. A field written through Response::__invoke() (which routes into
 * prepare()) was present on the wire while get() reported '' — the
 * Authentication middleware then re-marked a redirecting Fallback to 401
 * with `Location` still serialized.
 *
 * Every scenario asserts get()/isset() against what build() actually put in
 * $raw: the read side must report the wire.
 */
return new Specification(
   description: 'Header reads must see every field build() serializes',
   test: new Assertions(Case: function (): Generator {
      // # A prepare()d field — the Response::__invoke() route — must be
      //   readable and isset()-able while it is on the wire
      $Header = new Header;
      $Header->prepare(['Location' => '/login']);
      $Header->build();

      yield new Assertion(
         description: 'a prepare()d field is visible to get() while build() serializes it',
      )
         ->expect(
            $Header->get('Location') === '/login'
            && str_contains($Header->raw, 'Location: /login')
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'isset() agrees with get() on a prepare()d field',
      )
         ->expect(isSet($Header->Location))
         ->to->be(true)
         ->assert();

      // # set() fields outrank prepare()d ones in build()'s union — get()
      //   must report the same winner the wire carries
      $Header = new Header;
      $Header->set('X-A', '1');
      $Header->prepare(['X-A' => '2']);
      $Header->build();

      yield new Assertion(
         description: 'get() reports the set() value that outranks the prepared one on the wire',
      )
         ->expect(
            $Header->get('X-A') === '1'
            && str_contains($Header->raw, 'X-A: 1')
            && str_contains($Header->raw, 'X-A: 2') === false
         )
         ->to->be(true)
         ->assert();

      // # Worker presets serialize ahead of fields; remove() masks them for
      //   the response — get() must follow both transitions
      $Header = new Header;
      $Header->preset('X-P', 'p');
      $Header->set('X-P', 'override');
      $Header->build();

      yield new Assertion(
         description: 'get() reports the preset value that outranks a same-name set() field',
      )
         ->expect(
            $Header->get('X-P') === 'p'
            && str_contains($Header->raw, 'X-P: p')
         )
         ->to->be(true)
         ->assert();

      $Header->remove('X-P');
      $Header->build();

      yield new Assertion(
         description: 'a remove()d (masked) field reads as absent, exactly as the wire drops it',
      )
         ->expect(
            $Header->get('X-P') === ''
            && str_contains($Header->raw, 'X-P:') === false
         )
         ->to->be(true)
         ->assert();

      // # Queued lines serialize first and own their field identity
      $Header = new Header;
      $Header->queue('X-Q', 'v');
      $Header->set('X-Q', 'w');
      $Header->build();

      yield new Assertion(
         description: 'get() reports the queued line that owns the field on the wire',
      )
         ->expect(
            $Header->get('X-Q') === 'v'
            && str_contains($Header->raw, 'X-Q: v')
            && str_contains($Header->raw, 'X-Q: w') === false
         )
         ->to->be(true)
         ->assert();

      // # Field identity is case-insensitive on every source
      $Header = new Header;
      $Header->set('LOCATION', '/x');

      yield new Assertion(
         description: 'get() finds a field stored under another casing',
      )
         ->expect($Header->get('Location') === '/x')
         ->to->be(true)
         ->assert();

      // # Deliberate exclusion: the default Content-Type is a build()-time
      //   fallback of $type, not a written field. Encoder_ cache adoption
      //   relies on '' meaning "this response never wrote the field", so
      //   get() must NOT report the default even though the wire carries it.
      $Header = new Header;
      $Header->build();

      yield new Assertion(
         description: 'the default Content-Type fallback stays invisible to get()',
      )
         ->expect(
            $Header->get('Content-Type') === ''
            && str_contains($Header->raw, 'Content-Type:')
         )
         ->to->be(true)
         ->assert();
   })
);
