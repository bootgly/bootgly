<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration\Kinds;


/**
 * The route list is a registration log: one row per registration that was
 * stored, complete once the Router is warm. This pins what leaves no row,
 * what a path registered twice looks like, what a row says where dispatch
 * departs from the arguments of `route()`, and the warm-up — `warm()` on its
 * own, through `routing()`, and when it throws.
 */
return new Test(
   description: 'It should log one row per stored registration and complete the list when the Router warms',
   test: new Assertions(Case: function (): Generator {
      $Tag = static function (string $tag): Closure {
         return function (Request $Request, Response $Response) use ($tag): Response {
            return $Response(body: $tag);
         };
      };
      /** @param array<int,Registration> $Routes */
      $Paths = static function (array $Routes): array {
         $paths = [];
         foreach ($Routes as $Row) {
            $paths[] = $Row->path;
         }

         return $paths;
      };

      // ! resolve() harness — the worker's Request/Response, one per probe
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;
      $OldResponse = $WPI->Response ?? null;
      $Request = new Request;
      $Request->protocol = 'HTTP/1.1';
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $URLProperty = new ReflectionProperty(Request::class, '_URL');
      $WPI->Request = $Request;

      $Prepare = static function (string $URI, string $method)
         use ($Request, $URIProperty, $URLProperty, $WPI): void
      {
         $Request->method = $method;
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $WPI->Response = new Response;
      };
      $Resolve = static function (Router $Router, string $URI, string $method = 'GET')
         use ($Prepare): null|string
      {
         $Prepare($URI, $method);
         $Result = $Router->resolve();

         return $Result instanceof Response ? $Result->Body->raw : null;
      };

      // ! The two warm-up tables that let rows share equal lists
      $Tables = static function (Router $Router): array {
         return [
            new ReflectionProperty(Router::class, 'methodLists')->getValue($Router),
            new ReflectionProperty(Router::class, 'parameterLists')->getValue($Router),
         ];
      };

      try {
         // ---
         // @ What leaves no row
         $Router = new Router;
         $coldEmpty = $Router->Routes === [] && $Router->cached === false;

         $Router->route('/kept', $Tag('kept'), 'GET');
         // # A group: only its children are routes
         $Router->route('/group/:*', function () use ($Router, $Tag): Generator {
            yield $Router->route('child', $Tag('child'));
         }, 'GET');
         // # A paused Router registers nothing
         $Router->pause();
         $Router->route('/paused', $Tag('paused'), 'GET');
         $Router->serve('/paused-body', 'paused', 'GET');
         $Router->continue();
         // # A registration that threw stored nothing
         $refusals = 0;
         $Refused = [
            static fn () => $Router->route('/bad/:id<nope>', $Tag('x'), 'GET'),
            static fn () => $Router->route('/bad/:rest*/tail', $Tag('x'), 'GET'),
            static fn () => $Router->route('/bad-cache', $Tag('x'), 'GET', cache: ['TTL' => 0]),
            static fn () => $Router->route('/bad-option', $Tag('x'), 'GET', cache: ['ttl' => 5]),
         ];
         foreach ($Refused as $Refusal) {
            try {
               $Refusal();
            }
            catch (InvalidArgumentException) {
               $refusals++;
            }
         }
         $coldPaths = $Paths($Router->Routes);
         $coldCached = $Router->cached;

         // @ warm() with no Generator: the directly registered routes stay,
         //   the groups are flattened
         $coldTables = $Tables($Router);
         $Router->warm();
         $warmPaths = $Paths($Router->Routes);
         $warmCached = $Router->cached;
         $warmTables = $Tables($Router);

         // # After the warm-up a registration is ignored
         $Router->route('/late', $Tag('late'), 'GET');
         $Router->serve('/late-body', 'late', 'GET');
         $started = false;
         $Router->warm((static function () use ($Router, $Tag, &$started): Generator {
            $started = true;
            yield $Router->route('/later', $Tag('later'), 'GET');
         })());
         $latePaths = $Paths($Router->Routes);

         yield new Assertion(description: 'a cold Router lists the routes stored so far; a group, a paused or a refused registration leaves no row')
            ->expect([$coldEmpty, $coldPaths, $coldCached, $refusals])
            ->to->be([true, ['/kept'], false, 4])
            ->assert();

         yield new Assertion(description: 'warm() flattens the groups and completes the list without a request')
            ->expect([$warmPaths, $warmCached, $Resolve($Router, '/group/child')])
            ->to->be([['/kept', '/group/child'], true, 'child'])
            ->assert();

         yield new Assertion(description: 'the tables that share equal lists between rows are released when the warm-up ends')
            ->expect([$coldTables[0] !== [], $warmTables])
            ->to->be([true, [[], []]])
            ->assert();

         yield new Assertion(description: 'a warm Router ignores registrations and another warm(): no row, no drain')
            ->expect([$latePaths, $started, $Resolve($Router, '/late'), $Resolve($Router, '/later')])
            ->to->be([['/kept', '/group/child'], false, null, null])
            ->assert();

         // ---
         // @ A path registered twice keeps both rows; which one dispatch
         //   reaches depends on the kind
         $Router = new Router;
         $Router->warm((static function () use ($Router, $Tag): Generator {
            yield $Router->route('/static', $Tag('static-1'), 'GET');
            yield $Router->route('/static', $Tag('static-2'), 'GET');
            yield $Router->route('/dynamic/:id', $Tag('dynamic-1'), 'GET');
            yield $Router->route('/dynamic/:id', $Tag('dynamic-2'), 'GET');
            yield $Router->route('/*', $Tag('fallback-1'));
            yield $Router->route('/*', $Tag('fallback-2'));
         })());

         yield new Assertion(description: 'a path registered twice has two rows: the later static or catch-all wins, the earlier dynamic')
            ->expect([
               $Paths($Router->Routes),
               $Resolve($Router, '/static'),
               $Resolve($Router, '/dynamic/7'),
               $Resolve($Router, '/nowhere'),
            ])
            ->to->be([
               ['/static', '/static', '/dynamic/:id', '/dynamic/:id', '/*', '/*'],
               'static-2',
               'dynamic-1',
               'fallback-2',
            ])
            ->assert();

         // ---
         // @ Where dispatch departs from the arguments of route(), the row
         //   says what dispatch does
         $Router = new Router;
         $Router->warm((static function () use ($Router, $Tag): Generator {
            // # The generic catch-all is tested against no method
            yield $Router->route('/*', $Tag('fallback'), 'GET');
            // # An empty method list: a dynamic route answers every method,
            //   a static one is stored nowhere
            yield $Router->route('/open/:id', $Tag('open'), []);
            yield $Router->route('/dropped', $Tag('dropped'), []);
            // # An empty method name is the method-agnostic slot of a static
            //   route, alone or beside other methods
            yield $Router->route('/blank', $Tag('blank'), '');
            yield $Router->route('/mixed', $Tag('mixed'), ['GET', '']);
            // # A group's own '' child lands on the site root
            yield $Router->route('/section/:*', function () use ($Router, $Tag): Generator {
               yield $Router->route('', $Tag('index'), 'GET');
            });
         })());
         /** @var array<string,Registration> $Rows */
         $Rows = [];
         foreach ($Router->Routes as $Row) {
            $Rows[$Row->path] = $Row;
         }

         yield new Assertion(description: 'a row records what dispatch does: the catch-all and an empty dynamic list answer every method')
            ->expect([
               $Paths($Router->Routes),
               $Rows['/*']->methods,
               $Rows['/*']->Kind,
               $Resolve($Router, '/nowhere', 'POST'),
               $Rows['/open/:id']->methods,
               $Resolve($Router, '/open/1', 'DELETE'),
               $Resolve($Router, '/dropped', 'GET'),
               $Rows['/']->methods,
               $Resolve($Router, '/', 'GET'),
               $Resolve($Router, '/section', 'GET'),
               $Rows['/blank']->methods,
               $Resolve($Router, '/blank', 'DELETE'),
               $Rows['/mixed']->methods,
               $Resolve($Router, '/mixed', 'DELETE'),
            ])
            ->to->be([
               ['/*', '/open/:id', '/blank', '/mixed', '/'],
               null,
               Kinds::CatchAll,
               'fallback',
               null,
               'open',
               'fallback',
               ['GET'],
               'index',
               'fallback',
               null,
               'blank',
               null,
               'mixed',
            ])
            ->assert();

         // ---
         // @ The generic catch-all keeps its served body and its cache options
         $Router = new Router;
         $Router->warm((static function () use ($Router): Generator {
            yield $Router->serve('/*', 'gone');
         })());
         $Served = $Router->Routes[0];
         $Router = new Router;
         $Router->warm((static function () use ($Router, $Tag): Generator {
            yield $Router->route('/*', $Tag('fallback'), cache: ['TTL' => 9]);
         })());
         $Cached = $Router->Routes[0];

         yield new Assertion(description: 'a catch-all row keeps the served body and the cache options')
            ->expect([$Served->Kind, $Served->handler, $Served->body, $Served->cache, $Cached->body, $Cached->cache])
            ->to->be([Kinds::CatchAll, null, 'gone', null, null, ['TTL' => 9]])
            ->assert();

         // ---
         // @ A warm-up starts from no intercepted middleware: what a route
         //   set intercepts is its own
         $Middleware = new class implements Middleware {
            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
         $Router = new Router;
         $Router->intercept($Middleware);
         $Router->warm((static function () use ($Router, $Tag, $Middleware): Generator {
            yield $Router->route('/before', $Tag('before'), 'GET');
            $Router->intercept($Middleware);
            yield $Router->route('/after', $Tag('after'), 'GET');
         })());

         yield new Assertion(description: 'warm() drops the middlewares intercepted before it: a row carries only its route set\'s')
            ->expect([$Router->Routes[0]->Middlewares, $Router->Routes[1]->Middlewares])
            ->to->be([[], [$Middleware]])
            ->assert();

         // ---
         // @ routing() warms on the first request, then resolves it
         $Router = new Router;
         $Prepare('/first', 'GET');
         $answers = [];
         $Routes = (static function () use ($Router, $Tag): Generator {
            yield $Router->route('/first', $Tag('first'), 'GET');
            yield $Router->route('/nested/:*', function () use ($Router, $Tag): Generator {
               yield $Router->route('second', $Tag('second'), 'GET');
            });
         })();
         foreach ($Router->routing($Routes) as $Answer) {
            $answers[] = $Answer instanceof Response ? $Answer->Body->raw : null;
         }

         yield new Assertion(description: 'routing() completes the list on the first request it resolves')
            ->expect([$answers, $Router->cached, $Paths($Router->Routes)])
            ->to->be([['first'], true, ['/first', '/nested/second']])
            ->assert();

         // ---
         // @ A warm-up that throws: the Router stays cold, its list is back
         //   to what the attempt found, and the retry lists each route once
         $Router = new Router;
         $attempts = 0;
         $Failing = static function (bool $fail) use ($Router, $Tag, &$attempts): Generator {
            $attempts++;
            yield $Router->route('/one', $Tag('one'), 'GET');
            yield $Router->route('/two/:id', $Tag('two'), 'GET');
            if ($fail) {
               throw new RuntimeException('route set failed');
            }
            yield $Router->route('/three', $Tag('three'), 'GET');
         };
         $thrown = [];
         $afterThrow = [];
         $tablesAfterThrow = [];
         for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
               $Router->warm($Failing(true));
            }
            catch (RuntimeException $Exception) {
               $thrown[] = $Exception->getMessage();
            }
            $afterThrow[] = [$Router->cached, count($Router->Routes)];
            $tablesAfterThrow[] = $Tables($Router);
         }
         $Router->warm($Failing(false));

         yield new Assertion(description: 'a warm-up that throws rethrows, leaves the Router cold and its list empty')
            ->expect([$thrown, $afterThrow, $tablesAfterThrow])
            ->to->be([
               ['route set failed', 'route set failed', 'route set failed'],
               [[false, 0], [false, 0], [false, 0]],
               [[[], []], [[], []], [[], []]],
            ])
            ->assert();

         yield new Assertion(description: 'the retry lists each route once, however many attempts failed')
            ->expect([$attempts, $Router->cached, $Paths($Router->Routes)])
            ->to->be([4, true, ['/one', '/two/:id', '/three']])
            ->assert();

         // @ The same when the failure is a group child, found by the flatten:
         //   the route registered before warm() keeps its row
         $Router = new Router;
         $Router->route('/top', $Tag('top'), 'GET');
         $Router->route('/broken/:*', function () use ($Router, $Tag): Generator {
            yield $Router->route('fine', $Tag('fine'), 'GET');
            yield $Router->route('/absolute', $Tag('absolute'), 'GET');
         });
         $flattened = null;
         try {
            $Router->warm();
         }
         catch (InvalidArgumentException $Exception) {
            $flattened = $Exception->getMessage();
         }

         yield new Assertion(description: 'a group child that is refused fails the warm-up: the list is back to what warm() found')
            ->expect([$flattened, $Router->cached, $Paths($Router->Routes)])
            ->to->be(['Nested route path must be relative!', false, ['/top']])
            ->assert();

         // ---
         // @ Read-only from outside: the list and each row
         $Router = new Router;
         $Router->route('/row', $Tag('row'), 'GET');
         $Row = $Router->Routes[0];
         $errors = [];
         $Writes = [
            static function () use ($Router): void {
               // @phpstan-ignore-next-line
               $Router->Routes = [];
            },
            static function () use ($Router): void {
               // @phpstan-ignore-next-line
               $Router->Routes[] = $Router->Routes[0];
            },
            static function () use ($Row): void {
               // @phpstan-ignore-next-line
               $Row->path = '/changed';
            },
            static function () use ($Row): void {
               // @phpstan-ignore-next-line
               $Row->methods = null;
            },
         ];
         foreach ($Writes as $Write) {
            try {
               $Write();
               $errors[] = null;
            }
            catch (Error $Error) {
               $errors[] = $Error::class;
            }
         }

         yield new Assertion(description: 'the list and its rows cannot be written from outside the Router')
            ->expect([$errors, count($Router->Routes), $Row->path, $Row->methods])
            ->to->be([['Error', 'Error', 'Error', 'Error'], 1, '/row', ['GET']])
            ->assert();
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
   })
);
