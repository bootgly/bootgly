<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * An entry a handler writes into `$this->Params` lives for its request only.
 *
 * The Router keeps one Route — and one Params map — for the worker's life,
 * so every request resolved after a handler wrote into the map (by
 * assignment, by appending through the by-reference read, through a kept
 * reference, or by reading a key the request never captured) must start from
 * an empty map: a static route, the root, the catch-all, a route reached
 * while the Router is paused, a miss with no catch-all. The assertions
 * iterate the map: reading `->name === null` would insert the key it checks.
 */
return new Test(
   description: 'It should scrub the route params a handler wrote before the next request',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;
      $OldResponse = $WPI->Response ?? null;
      $Request = new Request;
      $Request->protocol = 'HTTP/1.1';
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $URLProperty = new ReflectionProperty(Request::class, '_URL');
      $WPI->Request = $Request;

      $Holder = new class {
         public mixed $reference = null;
      };

      // ! One persistent Router, as the server keeps it for the worker's life
      $Router = new Router;
      $Router->route('/', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'root');
      }, GET);
      $Router->route('/plain', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'plain');
      }, GET);
      $Router->route('/login', function (Request $Request, Response $Response): Response {
         $this->Params->user = 'alice'; // @phpstan-ignore variable.undefined
         return $Response(body: 'login');
      }, GET);
      $Router->route('/append', function (Request $Request, Response $Response): Response {
         $this->Params->roles[] = 'admin'; // @phpstan-ignore variable.undefined
         return $Response(body: 'append');
      }, GET);
      $Router->route('/reference', function (Request $Request, Response $Response) use ($Holder): Response {
         $Holder->reference = &$this->Params->token; // @phpstan-ignore variable.undefined
         $Holder->reference = 'secret';
         return $Response(body: 'reference');
      }, GET);
      $Router->route('/late', static function (Request $Request, Response $Response) use ($Holder): Response {
         // @ Write through the reference a previous request kept
         $Holder->reference = 'late';
         return $Response(body: 'late');
      }, GET);
      $Router->route('/ghost', function (Request $Request, Response $Response): Response {
         $ghost = $this->Params->ghost; // @phpstan-ignore variable.undefined
         return $Response(body: 'ghost:' . var_export($ghost, true));
      }, GET);
      $Router->route('/dynamic/:id', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'dynamic');
      }, GET);
      $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'catch-all');
      });
      $Router->warm();

      // ! A Router warmed with a pre-set constraint regex and a plain param
      $Constrained = new Router;
      $Constrained->Route->Params->id = '[0-9]+';
      $Constrained->route('/param1/:id', function (Request $Request, Response $Response): Response {
         return $Response(body: 'id=' . $this->Params->id); // @phpstan-ignore variable.undefined
      }, GET);
      $Constrained->route('/user/:uid', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'user');
      }, GET);
      $Constrained->route('/plain', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'plain');
      }, GET);
      $Constrained->route('/login', function (Request $Request, Response $Response): Response {
         $this->Params->user = 'alice'; // @phpstan-ignore variable.undefined
         return $Response(body: 'login');
      }, GET);
      $Constrained->warm();

      // ! A cold Router resolved directly, before its groups are flattened
      $Cold = new Router;
      $Cold->route('/plain', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'plain');
      }, GET);
      $Cold->Route->Params->id = '[0-9]+';
      $Cold->route('/page/:*', function () use ($Cold): Generator {
         yield $Cold->route(':id', function (Request $Request, Response $Response): Response {
            return $Response(body: 'page ' . $this->Params->id); // @phpstan-ignore variable.undefined
         });
      }, GET);

      $Resolve = static function (Router $Router, string $URI) use ($Request, $URIProperty, $URLProperty, $WPI): array {
         $Request->method = 'GET';
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $Response = new Response;
         $WPI->Response = $Response;

         $Result = $Router->resolve();

         return [
            $Result instanceof Response ? $Result->Body->raw : $Result,
            // ! A copy by value: an entry a handler bound by reference would
            //   otherwise change in this snapshot when a later request writes it.
            array_map(static fn (mixed $value): mixed => $value, iterator_to_array($Router->Route->Params)),
         ];
      };

      try {
         // # Assignment (`__set()`)
         [, $duringLogin] = $Resolve($Router, '/login');
         $Kept = $Router->Route->Params;
         [$plainBody, $afterLogin] = $Resolve($Router, '/plain');
         $keptSame = $Router->Route->Params === $Kept;
         $keptMap = iterator_to_array($Kept);
         $Resolve($Router, '/login');
         [$rootBody, $rootAfterLogin] = $Resolve($Router, '/');
         $Resolve($Router, '/login');
         [$catchAllBody, $catchAllAfterLogin] = $Resolve($Router, '/missing');
         // # Append through the by-reference read
         [, $duringAppend] = $Resolve($Router, '/append');
         [, $afterAppend] = $Resolve($Router, '/plain');
         // # Write through a reference, kept and written again later
         [, $duringReference] = $Resolve($Router, '/reference');
         [, $afterReference] = $Resolve($Router, '/plain');
         [, $duringLate] = $Resolve($Router, '/late');
         [, $afterLate] = $Resolve($Router, '/plain');
         // # A read that inserts the key it misses
         [$ghostBody, $duringGhost] = $Resolve($Router, '/ghost');
         [, $afterGhost] = $Resolve($Router, '/plain');
         // # A dynamic match whose handler never reads its params
         [$dynamicBody, $duringDynamic] = $Resolve($Router, '/dynamic/7');
         [, $afterDynamic] = $Resolve($Router, '/plain');
         // # A request reaching a paused Router
         $Resolve($Router, '/login');
         $Router->pause();
         [$pausedResult, $whilePaused] = $Resolve($Router, '/plain');
         $Router->continue();
         // # Registration residue: a pre-set regex and the ghost keys of the probe
         [, $firstAfterWarm] = $Resolve($Constrained, '/plain');
         [$constrainedMiss] = $Resolve($Constrained, '/param1/abc');
         [$constrainedHit, $constrainedParams] = $Resolve($Constrained, '/param1/123');
         // # A miss with no catch-all, after a write
         $Resolve($Constrained, '/login');
         [$missResult, $afterMiss] = $Resolve($Constrained, '/nope');
         // # A cold Router resolved before its warm-up
         [$coldBody] = $Resolve($Cold, '/plain');
         $Cold->warm();
         [$coldMiss] = $Resolve($Cold, '/page/abc');
         [$coldHit] = $Resolve($Cold, '/page/12');
      }
      finally {
         if ($OldRequest instanceof Request) {
            $WPI->Request = $OldRequest;
         }
         else {
            unset($WPI->Request);
         }

         if ($OldResponse instanceof Response) {
            $WPI->Response = $OldResponse;
         }
         else {
            unset($WPI->Response);
         }
      }

      $evidence = static fn (mixed $value): string => (string) json_encode($value);

      yield new Assertion(
         description: 'Each writer stores its entry for its own request',
         fallback: "The writers stored: {$evidence([$duringLogin, $duringAppend, $duringReference, $duringGhost])}"
      )
         ->expect([$duringLogin, $duringAppend, $duringReference, $duringGhost])
         ->to->be([['user' => 'alice'], ['roles' => ['admin']], ['token' => 'secret'], ['ghost' => null]])
         ->assert();

      yield new Assertion(
         description: 'A static route resolved after an assignment reads no params',
         fallback: "The static route read: {$evidence([$plainBody, $afterLogin])}"
      )
         ->expect([$plainBody, $afterLogin])
         ->to->be(['plain', []])
         ->assert();

      yield new Assertion(
         description: 'The Route keeps its one Params object, emptied in place',
         fallback: "The Params object was replaced or kept the entry: {$evidence([$keptSame, $keptMap])}"
      )
         ->expect([$keptSame, $keptMap])
         ->to->be([true, []])
         ->assert();

      yield new Assertion(
         description: 'The root route resolved after an assignment reads no params',
         fallback: "The root route read: {$evidence([$rootBody, $rootAfterLogin])}"
      )
         ->expect([$rootBody, $rootAfterLogin])
         ->to->be(['root', []])
         ->assert();

      yield new Assertion(
         description: 'The catch-all resolved after an assignment reads no params',
         fallback: "The catch-all read: {$evidence([$catchAllBody, $catchAllAfterLogin])}"
      )
         ->expect([$catchAllBody, $catchAllAfterLogin])
         ->to->be(['catch-all', []])
         ->assert();

      yield new Assertion(
         description: 'An entry appended through the by-reference read is scrubbed',
         fallback: "The next request read: {$evidence($afterAppend)}"
      )
         ->expect($afterAppend)
         ->to->be([])
         ->assert();

      yield new Assertion(
         description: 'An entry written through a reference is scrubbed, and a later write through it stays out',
         fallback: "The next requests read: {$evidence([$afterReference, $duringLate, $afterLate])}"
      )
         ->expect([$afterReference, $duringLate, $afterLate])
         ->to->be([[], [], []])
         ->assert();

      yield new Assertion(
         description: 'A key a read inserted is scrubbed',
         fallback: "The ghost read answered {$evidence($ghostBody)}; the next request read: {$evidence($afterGhost)}"
      )
         ->expect([$ghostBody, $afterGhost])
         ->to->be(['ghost:NULL', []])
         ->assert();

      yield new Assertion(
         description: 'A dynamic match is scrubbed though its handler never read its params',
         fallback: "The dynamic match stored {$evidence($duringDynamic)}; the next request read: {$evidence($afterDynamic)}"
      )
         ->expect([$dynamicBody, $duringDynamic, $afterDynamic])
         ->to->be(['dynamic', ['id' => '7'], []])
         ->assert();

      yield new Assertion(
         description: 'A request reaching a paused Router reads no params',
         fallback: "The paused Router answered {$evidence($pausedResult)} and kept: {$evidence($whilePaused)}"
      )
         ->expect([$pausedResult, $whilePaused])
         ->to->be([false, []])
         ->assert();

      yield new Assertion(
         description: 'The first request after the warm-up reads no registration residue',
         fallback: "The first request read: {$evidence($firstAfterWarm)}"
      )
         ->expect($firstAfterWarm)
         ->to->be([])
         ->assert();

      yield new Assertion(
         description: 'A pre-set constraint regex still constrains its route',
         fallback: "The constrained route answered {$evidence([$constrainedMiss, $constrainedHit, $constrainedParams])}"
      )
         ->expect([$constrainedMiss, $constrainedHit, $constrainedParams])
         ->to->be([null, 'id=123', ['id' => '123']])
         ->assert();

      yield new Assertion(
         description: 'A miss with no catch-all leaves no entry for the code that answers it',
         fallback: "The miss answered {$evidence($missResult)} and kept: {$evidence($afterMiss)}"
      )
         ->expect([$missResult, $afterMiss])
         ->to->be([null, []])
         ->assert();

      yield new Assertion(
         description: 'A cold Router resolved directly keeps the constraint its groups read at the warm-up',
         fallback: "The cold Router answered {$evidence([$coldBody, $coldMiss, $coldHit])}"
      )
         ->expect([$coldBody, $coldMiss, $coldHit])
         ->to->be(['plain', null, 'page 12'])
         ->assert();
   })
);
