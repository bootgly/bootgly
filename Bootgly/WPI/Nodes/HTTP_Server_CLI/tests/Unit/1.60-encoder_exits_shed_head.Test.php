<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;


/**
 * H-HSC-5 — every exit of `encode()` that answered a request drops its head.
 *
 * Some exits are only reached by rare paths — a Session save failing after an
 * inline defer, an SSE or cloned Response emitting inside routing, a deferral
 * selected by a `Handled` listener, the route-cache replay — and a live probe
 * per path would be slow and partial. The rule is structural, so it is pinned
 * on the tokens of both encoders (the production one and the Test-environment
 * mirror):
 *
 * - a deferred exit — `$Request->scrub();` … `return '';` — calls
 *   `if ($Request->pinned === false) { $Request->shed(); }` right before the
 *   return (an SSE stream reads its live Request while it runs);
 * - a synchronous exit — `return self::$wire;`, `return $cacheWire;`,
 *   `return $buffer;` — is preceded by
 *   `if ($Packages->Template === null) { $Request->shed(); }`, after the route
 *   cache stored or skipped the wire.
 *
 * - no `shed()` call exists anywhere else — in particular not next to the
 *   synchronous `scrub()`, which runs before the route cache reads the head
 *   (also pinned by behaviour: E2E Router 7.x, Security 103.04 row I4).
 *
 * The same walk pins the JSON refusal (`Request::check()`): the first
 * statement after the `Body->waiting` guard answers `413` to a body whose
 * decode bound does not fit, so no route-cache replay, event or handler runs
 * before it. The other refusal that drops the head before it answers is a
 * `Request\Events::Received` listener that throws (`500`, or `503` for a
 * budget `Refusal`) — BUGS ENC-12: its Throwable no longer reaches the reactor.
 * It sheds before both of its answers: the HTTP/2 stream denial (the branch on
 * `$Packages->decoded`) and the HTTP/1 `reject()`.
 */
return new Test(
   description: 'H-HSC-5: every encode() exit that answered a request drops its head',
   test: new Assertions(Case: function (): Generator {
      $synchronous = 'if($Packages->Template===null){$Request->shed();}';
      $deferred = 'if($Request->pinned===false){$Request->shed();}';

      foreach ([Encoder_::class, Encoder_Testing::class] as $class) {
         // ! The significant tokens of `encode()`
         $Method = new ReflectionMethod($class, 'encode');
         $lines = (array) file((string) $Method->getFileName());
         $source = implode('', array_slice(
            $lines,
            $Method->getStartLine() - 1,
            $Method->getEndLine() - $Method->getStartLine() + 1
         ));
         $tokens = [];
         foreach (token_get_all("<?php {$source}") as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
               continue;
            }
            $tokens[] = is_array($token) ? $token[1] : $token;
         }

         // @@ Every shed() call, by the exit it guards
         $sites = ['deferred' => 0, 'synchronous' => 0, 'refusal' => 0];
         $misplaced = [];
         $total = count($tokens);
         for ($index = 0; $index < $total; $index++) {
            if (implode('', array_slice($tokens, $index, 6)) !== '$Request->shed();') {
               continue;
            }

            // ! The gate around the call and what follows it
            $gate = implode('', array_slice($tokens, $index - 9, 16));
            $next = implode('', array_slice($tokens, $index + 7, 5));
            if ($gate === $deferred && str_starts_with($next, "return'';")) {
               $sites['deferred']++;
               continue;
            }
            // ? A refusal (the JSON bound, a throwing Received listener) drops
            //   the refused request before it answers — the Received one before
            //   its HTTP/2 branch too
            $answer = implode('', array_slice($tokens, $index + 6, 6));
            if (
               implode('', array_slice($tokens, $index - 6, 6)) === '$Request->scrub();'
               && (
                  str_starts_with($answer, '$Packages->reject(')
                  || str_starts_with($answer, '$H2=$Packages->decoded;')
               )
            ) {
               $sites['refusal']++;
               continue;
            }
            if (
               $gate === $synchronous
               && (
                  str_starts_with($next, 'returnself::$wire;')
                  || str_starts_with($next, 'return$cacheWire;')
                  || str_starts_with($next, 'return$buffer;')
               )
            ) {
               $sites['synchronous']++;
               continue;
            }
            $misplaced[] = "token #{$index}: {$gate} … {$next}";
         }

         // @@ Every deferred exit — scrubbed, then returned empty — sheds
         $unshed = 0;
         $scrubbed = false;
         $shed = false;
         for ($index = 0; $index < $total; $index++) {
            $call = implode('', array_slice($tokens, $index, 6));
            if ($call === '$Request->scrub();') {
               $scrubbed = true;
               $shed = false;
            }
            else if ($call === '$Request->shed();') {
               $shed = true;
            }
            if ($tokens[$index] !== 'return') {
               continue;
            }
            if ($scrubbed && $shed === false && implode('', array_slice($tokens, $index + 1, 2)) === "'';") {
               $unshed++;
            }
            $scrubbed = false;
            $shed = false;
         }

         $short = substr($class, strrpos($class, '\\') + 1);
         $evidence = json_encode(['sites' => $sites, 'misplaced' => $misplaced, 'unshed' => $unshed]);

         yield new Assertion(
            description: "{$short}::encode(): every exit drops the head, and only at an exit",
            fallback: "A shed() is missing or out of place: {$evidence}"
         )
            ->expect([$sites, $misplaced, $unshed])
            ->to->be([['deferred' => 4, 'synchronous' => 2, 'refusal' => 2], [], 0])
            ->assert();

         // @ The JSON refusal is the first statement after the waiting guard
         $flat = implode('', $tokens);
         $waiting = "if(\$Request->Body->waiting){return'';}";
         $refusal = 'if($Request->Body->length>0&&$Request->stream===0&&$Request->check()===false){$Request->scrub();$Request->shed();$Packages->reject("HTTP/1.1 413 Request Entity Too Large\r\n\r\n");return\'\';}';
         $guard = strpos($flat, $waiting);
         $first = $guard === false
            ? ''
            : substr($flat, $guard + strlen($waiting), strlen($refusal));

         yield new Assertion(
            description: "{$short}::encode(): a JSON body over its decode bound is refused right after the waiting guard",
            fallback: "The refusal is missing, repeated or not first: {$first}"
         )
            ->expect([substr_count($flat, $waiting), substr_count($flat, $refusal), $first])
            ->to->be([1, 1, $refusal])
            ->assert();
      }
   }),
);
