<?php

use function hash;
use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\ETag;


/**
 * PoC — `ETag` mutates every response and compares `If-None-Match` by exact
 *   string (audit F-11).
 *
 * Before the fix it (a) computed and set an ETag for ANY non-empty body —
 *   including 4xx/5xx error and auth-challenge pages — and (b) returned `304`
 *   only on a byte-exact `If-None-Match` match, ignoring RFC 7232 §3.2 (`*`,
 *   comma-separated lists, and the weak comparison that ignores the `W/`
 *   prefix).
 *
 * Fixed behaviour: ETag is gated on 2xx/3xx, and `If-None-Match` is evaluated
 *   with the RFC weak comparison (`*` / list / `W/`-insensitive).
 */

return new Specification(
   description: 'ETag must gate on 2xx/3xx and evaluate If-None-Match per RFC 7232',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $body = 'Hello World!';
      $hash = hash('xxh3', $body);
      $weakETag = 'W/"' . $hash . '"';
      $strongETag = '"' . $hash . '"';

      // @ 1: A 4xx error body must NOT receive an ETag (status gate).
      [$Request, $Response] = $createMocks();
      $errorHandler = function (object $Request, object $Response) use ($body): object {
         $Response->code = 404;
         $Response->Body->raw = $body;
         return $Response;
      };
      $Result = (new ETag)->process($Request, $Response, $errorHandler);
      yield new Assertion(description: 'No ETag on a 404 error response')
         ->expect($Result->Header->get('ETag'))
         ->to->be(Type::Null)
         ->assert();

      // @ 2: If-None-Match `*` matches any current representation → 304.
      [$Request, $Response] = $createMocks(requestHeaders: ['If-None-Match' => '*']);
      $okHandler = function (object $Request, object $Response) use ($body): object {
         $Response->Body->raw = $body;
         return $Response;
      };
      $Result = (new ETag)->process($Request, $Response, $okHandler);
      yield new Assertion(description: 'If-None-Match: * returns 304')
         ->expect($Result->code)
         ->to->be(304)
         ->assert();

      // @ 3: Weak comparison — a strong response tag matches a W/-prefixed
      //   If-None-Match (the `W/` is ignored for the match) → 304.
      [$Request, $Response] = $createMocks(requestHeaders: ['If-None-Match' => $weakETag]);
      $Result = (new ETag(weak: false))->process($Request, $Response, $okHandler);
      yield new Assertion(description: 'Strong ETag matches W/-prefixed If-None-Match (weak comparison) → 304')
         ->expect($Result->code)
         ->to->be(304)
         ->assert();

      // @ 4: Comma-separated list — the response tag listed among others → 304.
      [$Request, $Response] = $createMocks(requestHeaders: ['If-None-Match' => '"deadbeef", ' . $weakETag]);
      $Result = (new ETag)->process($Request, $Response, $okHandler);
      yield new Assertion(description: 'If-None-Match list containing the tag → 304')
         ->expect($Result->code)
         ->to->be(304)
         ->assert();

      // @ 5: Regression — a non-matching If-None-Match must NOT 304; the ETag
      //   is still set and the body returned 200.
      [$Request, $Response] = $createMocks(requestHeaders: ['If-None-Match' => '"nomatch"']);
      $Result = (new ETag)->process($Request, $Response, $okHandler);
      yield new Assertion(description: 'Non-matching If-None-Match stays 200')
         ->expect($Result->code)
         ->to->be(200)
         ->assert();
      yield new Assertion(description: 'Non-matching request still carries the ETag')
         ->expect($Result->Header->get('ETag'))
         ->to->be($weakETag)
         ->assert();
   })
);
