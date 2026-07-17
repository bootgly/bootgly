<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;


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

      // # Validation is atomic — invalid calls cannot normalize-and-store a
      //   different persistent field or value.
      $baseline = $Header->preset;
      $Preset = new ReflectionProperty(Header::class, 'preset');
      $invalidNamesRejected = true;
      foreach ([
         '',
         'Bad Name',
         'Bad:Name',
         "X-M11\r\nInjected",
         "X-M11\r",
         "X-M11\n",
         "X-M11\0Injected",
         'Não-ASCII',
      ] as $name) {
         $Header->preset($name, 'value');
         $invalidNamesRejected = $invalidNamesRejected && $Header->preset === $baseline;
      }

      $invalidValuesRejected = true;
      for ($code = 0; $code <= 0x1F; $code++) {
         if ($code === 0x09) {
            continue;
         }

         $Header->preset('X-M11-CTL', 'safe' . chr($code) . 'value');
         $invalidValuesRejected = $invalidValuesRejected && $Header->preset === $baseline;
      }
      $Header->preset('X-M11-CTL', "safe\x7Fvalue");
      $invalidValuesRejected = $invalidValuesRejected && $Header->preset === $baseline;
      $Preset->setValue($Header, [
         'X-M11-Hook' => "safe\r\nInjected: hook",
      ]);
      $invalidHookRejected = $Header->preset === $baseline;

      yield new Assertion(
         description: 'invalid preset API and protected-hook writes leave the persistent map unchanged',
      )
         ->expect($invalidNamesRejected && $invalidValuesRejected && $invalidHookRejected)
         ->to->be(true)
         ->assert();

      // # RFC-compatible value bytes: HTAB and obs-text are not response-line
      //   delimiters and remain available to trusted persistent configuration.
      //   An all-decimal name is also a valid token despite PHP's numeric-key cast.
      $Header->preset('X-M11-Compatible', "alpha\tbeta\x80\xFF");
      $Header->preset('123', 'numeric-name');
      $Header->clean();
      $Header->build();
      $compatible = str_contains($Header->raw, "X-M11-Compatible: alpha\tbeta\x80\xFF")
         && str_contains($Header->raw, '123: numeric-name');
      $Header->preset('X-M11-Compatible', null);
      $Header->preset('123', null);

      yield new Assertion(
         description: 'preset values preserve permitted HTAB and obs-text bytes',
      )
         ->expect($compatible)
         ->to->be(true)
         ->assert();

      // # Exact null removal must remain able to clean a legacy invalid key.
      $legacyName = "X-M11-Legacy\r\nInjected";
      $legacy = $Header->preset;
      $legacy[$legacyName] = 'legacy';
      $Preset->setRawValue($Header, $legacy);
      $Header->preset($legacyName, null);
      $Header->clean();
      $Header->build();
      $legacyClean = ! str_contains($Header->raw, 'X-M11-Compatible')
         && ! str_contains($Header->raw, 'X-M11-Legacy');

      yield new Assertion(
         description: 'null preset removal can delete an exact legacy-invalid key',
      )
         ->expect($Header->preset === $baseline && $legacyClean)
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
