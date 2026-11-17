<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration;


// ! Gates that differ only by the class a closure is called on, or by the
//   scope it was bound to; a bag whose contents live where PHP keeps them
abstract class Rewind_Roles
{
   public const string ROLE = 'none';

   public static function allow (): bool
   {
      return static::ROLE === 'admin';
   }
}
final class Rewind_Admin extends Rewind_Roles
{
   public const string ROLE = 'admin';
}
final class Rewind_Staff extends Rewind_Roles
{
   public const string ROLE = 'staff';
}
final class Rewind_Bag extends SplObjectStorage
{
}
final class Rewind_Holder
{
   public function reach (): Closure
   {
      return function (): string {
         return self::class;
      };
   }
}


/**
 * A cold Router runs its route set once per attempt: `rewind()` marks the
 * start of the first one, and the first registration of each later one puts
 * that state back; a warm-up that throws goes back to what it found. This
 * pins that a route set which runs N times registers once — dispatch tables,
 * pending groups, intercepted middlewares and route list —, that an attempt
 * which registers nothing keeps the last one, that one which registers part
 * of the routes keeps that part only, and that the intercepted middlewares
 * are not lost that way: a registration an earlier attempt made too is
 * stored with the middlewares the attempt intercepts itself, in their
 * order, plus each one it had then that they lack, in its place — one
 * built again the same way counts once, another configuration does not —
 * and a refused registration takes no turn.
 */
return new Test(
   description: 'It should register a route set once, however many attempts run before the Router warms',
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
      $Counter = new class implements Middleware {
         public int $runs = 0;

         public function process (object $Request, object $Response, Closure $Next): object
         {
            $this->runs++;

            return $Next($Request, $Response);
         }
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

      $Resolve = static function (Router $Router, string $URI, string $method = 'GET')
         use ($Request, $URIProperty, $URLProperty, $WPI): null|string
      {
         $Request->method = $method;
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $WPI->Response = new Response;
         $Result = $Router->resolve();

         return $Result instanceof Response ? $Result->Body->raw : null;
      };

      // ! What a Router holds: [static slots, dynamic entries, catch-all,
      //   pending groups, intercepted middlewares, rows]
      $Sizes = static function (Router $Router): array {
         $Read = static function (string $property) use ($Router): mixed {
            return new ReflectionProperty(Router::class, $property)->getValue($Router);
         };

         $statics = count($Read('RootCache'));
         foreach ($Read('staticCache') as $slots) {
            $statics += count($slots);
         }
         $dynamics = 0;
         foreach ($Read('dynamicCache') as $types) {
            foreach ($types as $entries) {
               $dynamics += count($entries);
            }
         }

         return [
            $statics,
            $dynamics,
            $Read('CatchAllCache') === null ? 0 : 1,
            count($Read('pendingGroups')),
            count($Read('Middlewares')),
            count($Router->Routes),
         ];
      };
      // ! The two flags that let resolve() skip a lookup:
      //   [any method-agnostic static route, any dynamic route]
      $Flags = static function (Router $Router): array {
         return [
            new ReflectionProperty(Router::class, 'hasAgnosticStatic')->getValue($Router),
            new ReflectionProperty(Router::class, 'hasDynamic')->getValue($Router),
         ];
      };
      $Marked = static function (Router $Router): bool {
         return new ReflectionProperty(Router::class, 'Baseline')->getValue($Router) !== null;
      };

      // ! One route set of every stored kind, registered by direct calls
      $Register = static function (Router $Router) use ($Tag, $Counter): void {
         $Router->intercept($Counter);
         $Router->route('/', $Tag('root'), 'GET');
         $Router->route('/static', $Tag('static'), 'GET');
         $Router->route('/open', $Tag('open'));
         $Router->serve('/served', 'served', 'GET');
         $Router->route('/dynamic/:id', $Tag('dynamic'), 'GET');
         $Router->route('/group/:*', function () use ($Router, $Tag): Generator {
            yield $Router->route('child', $Tag('child'), 'GET');
            yield $Router->route('child/:id', $Tag('grandchild'), 'GET');
         });
         $Router->route('/*', $Tag('fallback'));
      };
      $paths = [
         '/', '/static', '/open', '/served', '/dynamic/:id', '/*',
         '/group/child', '/group/child/:id',
      ];

      try {
         // ---
         // @ A route set that runs once per attempt, the Router rewound
         //   before each run — what the server does while it is cold
         $Router = new Router;
         $sizes = [];
         for ($attempt = 1; $attempt <= 5; $attempt++) {
            $Router->rewind();
            $Register($Router);
            $sizes[] = $Sizes($Router);
         }
         $coldMarked = $Marked($Router);

         yield new Assertion(description: 'a route set that runs five times on a rewound cold Router holds what one run registers')
            ->expect([$sizes, $coldMarked, $Router->cached])
            ->to->be([array_fill(0, 5, [4, 1, 1, 1, 1, 6]), true, false])
            ->assert();

         $Router->warm();
         $Counter->runs = 0;
         $answers = [
            $Resolve($Router, '/'),
            $Resolve($Router, '/static'),
            $Resolve($Router, '/open', 'DELETE'),
            $Resolve($Router, '/served'),
            $Resolve($Router, '/dynamic/7'),
            $Resolve($Router, '/group/child'),
            $Resolve($Router, '/group/child/7'),
            $Resolve($Router, '/nowhere'),
         ];

         yield new Assertion(description: 'the warm-up after them lists each route once and each middleware runs once per request')
            ->expect([$Paths($Router->Routes), $answers, $Counter->runs, $Sizes($Router)[3], $Marked($Router)])
            ->to->be([
               $paths,
               ['root', 'static', 'open', 'served', 'dynamic', 'child', 'grandchild', 'fallback'],
               8,
               0,
               false,
            ])
            ->assert();

         // @ The control: the same runs without the rewind pile up
         $Router = new Router;
         for ($attempt = 1; $attempt <= 5; $attempt++) {
            $Register($Router);
         }

         yield new Assertion(description: 'without the rewind the same five runs register five times (the control)')
            ->expect($Sizes($Router))
            ->to->be([4, 5, 1, 5, 5, 30])
            ->assert();

         // ---
         // @ What the Router held before the first attempt is part of the
         //   mark: routes and middlewares registered ahead of it stay
         $Router = new Router;
         $Router->intercept($Counter);
         $Router->route('/early', $Tag('early'), 'GET');
         $Router->route('/early/:id', $Tag('early dynamic'), 'GET');
         $early = $Sizes($Router);
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            $Router->rewind();
            $Router->route('/late', $Tag('late'), 'GET');
         }
         $late = $Sizes($Router);
         $Router->warm();
         $Counter->runs = 0;
         $answers = [
            $Resolve($Router, '/early'),
            $Resolve($Router, '/early/3'),
            $Resolve($Router, '/late'),
         ];

         yield new Assertion(description: 'routes and middlewares registered before the first attempt survive every rewind')
            ->expect([$early, $late, $Paths($Router->Routes), $answers, $Counter->runs])
            ->to->be([
               [1, 1, 0, 0, 1, 2],
               [2, 1, 0, 0, 1, 3],
               ['/early', '/early/:id', '/late'],
               ['early', 'early dynamic', 'late'],
               3,
            ])
            ->assert();

         // ---
         // @ A warm Router ignores rewind() and intercept()
         $Router->rewind();
         $Router->intercept($Counter, $Counter);

         yield new Assertion(description: 'a warm Router ignores rewind() and intercept(): nothing is put back, nothing is appended')
            ->expect([$Sizes($Router), $Marked($Router), $Resolve($Router, '/late')])
            ->to->be([[2, 1, 0, 0, 0, 3], false, 'late'])
            ->assert();

         // ---
         // @ A warm-up that throws goes back to the mark: a route only the
         //   failed attempt registered answers nothing afterwards
         $Router = new Router;
         $Failing = static function (bool $fail) use ($Router, $Tag, $Counter): Generator {
            yield $Router->route('/one', $Tag($fail ? 'failed attempt' : 'good attempt'), 'GET');
            yield $Router->route('/two/:id', $Tag('two'), 'GET');
            yield $Router->route('/nested/:*', function () use ($Router, $Tag): Generator {
               yield $Router->route('three', $Tag('three'), 'GET');
            });
            if ($fail) {
               $Router->intercept($Counter);
               yield $Router->route('/', $Tag('ghost root'), 'GET');
               yield $Router->route('/ghost', $Tag('ghost'), 'GET');
               yield $Router->route('/ghost-open', $Tag('ghost open'));
               yield $Router->route('/ghost/:id', $Tag('ghost dynamic'), 'GET');
               yield $Router->route('/*', $Tag('ghost fallback'));

               throw new RuntimeException('route set failed');
            }
         };
         $afterThrow = [];
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            $Router->rewind();
            try {
               $Router->warm($Failing(true));
            }
            catch (RuntimeException) {
               $afterThrow[] = [$Router->cached, $Sizes($Router), $Flags($Router)];
            }
         }
         $Router->rewind();
         $Router->warm($Failing(false));
         $answers = [
            $Resolve($Router, '/one'),
            $Resolve($Router, '/two/7'),
            $Resolve($Router, '/nested/three'),
            $Resolve($Router, '/'),
            $Resolve($Router, '/ghost'),
            $Resolve($Router, '/ghost-open', 'DELETE'),
            $Resolve($Router, '/ghost/7'),
            $Resolve($Router, '/nowhere'),
         ];

         yield new Assertion(description: 'a warm-up that throws leaves a marked Router as it stood before the attempt')
            ->expect($afterThrow)
            ->to->be(array_fill(0, 3, [false, [0, 0, 0, 0, 0, 0], [false, false]]))
            ->assert();

         yield new Assertion(description: 'the retry dispatches its own routes only: none of the failed attempts answers')
            ->expect([$Paths($Router->Routes), $answers, $Sizes($Router)])
            ->to->be([
               ['/one', '/two/:id', '/nested/three'],
               ['good attempt', 'two', 'three', null, null, null, null, null],
               [2, 1, 0, 0, 0, 3],
            ])
            ->assert();

         // ---
         // @ On a marked Router too a failed warm-up goes back to what it
         //   found — the direct registrations stay, their group pending
         //   again — and the next attempt registers from the mark
         $Router = new Router;
         $Router->route('/kept', $Tag('kept'), 'GET');
         $broken = true;
         $Direct = static function () use ($Router, $Tag, &$broken): void {
            $Router->route('/direct', $Tag('direct'), 'GET');
            $Router->route('/direct/:id', $Tag('direct dynamic'), 'GET');
            $Router->route('/sub/:*', function () use ($Router, $Tag, &$broken): Generator {
               yield $Router->route($broken ? '/absolute' : 'fine', $Tag('fine'), 'GET');
            });
         };
         $Router->rewind();
         $Direct();
         $found = $Sizes($Router);
         $refused = null;
         try {
            $Router->warm();
         }
         catch (InvalidArgumentException $Exception) {
            $refused = $Exception->getMessage();
         }
         $failed = [$Sizes($Router), $Paths($Router->Routes), $Marked($Router), $Router->cached];
         $broken = false;
         $Router->rewind();
         $Direct();
         $Router->warm();

         yield new Assertion(description: 'a warm-up that throws on a marked Router leaves it as warm() found it, and the next attempt registers once')
            ->expect([$refused, $found, $failed, $Paths($Router->Routes), $Sizes($Router), $Resolve($Router, '/sub/fine')])
            ->to->be([
               'Nested route path must be relative!',
               [2, 1, 0, 1, 0, 3],
               [[2, 1, 0, 1, 0, 3], ['/kept', '/direct', '/direct/:id'], true, false],
               ['/kept', '/direct', '/direct/:id', '/sub/fine'],
               [3, 1, 0, 0, 0, 4],
               'fine',
            ])
            ->assert();

         // ---
         // @ A failed warm-up leaves the attempt as it found it — still to
         //   register from the mark: the retry drops what an earlier run left
         $Router = new Router;
         $Router->rewind();
         $Router->route('/left', $Tag('left'), 'GET');
         $Router->rewind();
         $Set = static function (bool $fail) use ($Router, $Tag): Generator {
            yield $Router->route('/set', $Tag('set'), 'GET');
            if ($fail) {
               throw new RuntimeException('route set failed');
            }
         };
         $left = null;
         try {
            $Router->warm($Set(true));
         }
         catch (RuntimeException) {
            $left = $Paths($Router->Routes);
         }
         $Router->warm($Set(false));

         yield new Assertion(description: 'the retry of a failed warm-up still starts from the mark')
            ->expect([$left, $Paths($Router->Routes), $Resolve($Router, '/left'), $Resolve($Router, '/set')])
            ->to->be([['/left'], ['/set'], null, 'set'])
            ->assert();

         // ---
         // @ An attempt that registers nothing keeps what the last one left:
         //   a handler that registers on its first run only keeps its routes
         $Router = new Router;
         $registered = false;
         $Once = static function () use ($Router, $Tag, &$registered): void {
            if ($registered) {
               return;
            }
            $registered = true;

            $Router->route('/once', $Tag('once'), 'GET');
            $Router->route('/once/:id', $Tag('once dynamic'), 'GET');
            $Router->route('/once-group/:*', function () use ($Router, $Tag): Generator {
               yield $Router->route('child', $Tag('once child'), 'GET');
            });
         };
         $sizes = [];
         for ($attempt = 1; $attempt <= 4; $attempt++) {
            $Router->rewind();
            $Once();
            $sizes[] = $Sizes($Router);
         }
         $Router->warm();

         yield new Assertion(description: 'a route set that registers on its first run only is still there when the Router warms')
            ->expect([
               $sizes,
               $Paths($Router->Routes),
               [$Resolve($Router, '/once'), $Resolve($Router, '/once/7'), $Resolve($Router, '/once-group/child')],
            ])
            ->to->be([
               array_fill(0, 4, [1, 1, 0, 1, 0, 2]),
               ['/once', '/once/:id', '/once-group/child'],
               ['once', 'once dynamic', 'once child'],
            ])
            ->assert();

         // ---
         // @ The routes of an attempt are whole or nothing: one that registers
         //   part of what the last one did keeps that part — but the route it
         //   repeats stays behind the middleware the first attempt intercepted
         $Router = new Router;
         $guarded = false;
         $Mixed = static function () use ($Router, $Tag, $Counter, &$guarded): void {
            if ($guarded === false) {
               $guarded = true;

               $Router->intercept($Counter);
               $Router->route('/guarded', $Tag('guarded'), 'GET');
            }
            $Router->route('/repeated', $Tag('repeated'), 'GET');
         };
         $sizes = [];
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            $Router->rewind();
            $Mixed();
            $sizes[] = $Sizes($Router);
         }
         $Router->warm();
         $Counter->runs = 0;

         yield new Assertion(description: 'an attempt that registers part of the route set keeps that part only: the guarded route is gone, the middleware intercepted once still wraps the repeated one')
            ->expect([
               $sizes,
               $Paths($Router->Routes),
               [$Resolve($Router, '/guarded'), $Resolve($Router, '/repeated')],
               $Counter->runs,
            ])
            ->to->be([
               [[2, 0, 0, 0, 1, 2], [1, 0, 0, 0, 1, 1], [1, 0, 0, 0, 1, 1]],
               ['/repeated'],
               [null, 'repeated'],
               1,
            ])
            ->assert();

         // ---
         // @ Intercepted middlewares across attempts: what an attempt
         //   registers before its own intercept() takes what the same
         //   registration had in the last attempt
         // ! [path, intercepted middlewares] of each row, after $attempts
         //   runs of $Set and the warm-up
         $Chains = static function (Closure $Set, int $attempts = 3, null|Closure $Boot = null): array {
            $Router = new Router;
            if ($Boot !== null) {
               $Boot($Router);
            }
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
               $Router->rewind();
               $Set($Router, $attempt);
            }
            $Router->warm();

            $chains = [];
            foreach ($Router->Routes as $Row) {
               $chains[] = [$Row->path, count($Row->Middlewares)];
            }

            return $chains;
         };
         $Group = static function (Router $Router, string $tag) use ($Tag): Closure {
            // ! Not static: flatten() binds a group handler to the Route
            return function () use ($Router, $Tag, $tag): Generator {
               yield $Router->route('page', $Tag($tag), 'GET');
            };
         };

         $once = false;
         $guarded = $Chains(static function (Router $Router) use ($Tag, $Counter, &$once): void {
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
            $Router->route('/admin/:id', $Tag('admin dynamic'), 'GET');
         });
         $repeated = $Chains(static function (Router $Router) use ($Tag, $Counter): void {
            $Router->intercept($Counter);
            $Router->route('/admin', $Tag('admin'), 'GET');
         });
         $ahead = $Chains(static function (Router $Router) use ($Tag, $Counter): void {
            $Router->route('/public', $Tag('public'), 'GET');
            $Router->intercept($Counter);
            $Router->route('/admin', $Tag('admin'), 'GET');
         });
         $once = false;
         $mixed = $Chains(static function (Router $Router) use ($Tag, $Counter, &$once): void {
            $Router->route('/public', $Tag('public'), 'GET');
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         });

         yield new Assertion(description: 'a route stays behind a middleware intercepted on the first attempt only, and a route registered ahead of intercept() stays out of it')
            ->expect([$guarded, $repeated, $ahead, $mixed])
            ->to->be([
               [['/admin', 1], ['/admin/:id', 1]],
               [['/admin', 1]],
               [['/public', 0], ['/admin', 1]],
               [['/public', 0], ['/admin', 1]],
            ])
            ->assert();

         // @ One middleware intercepted on the first run only, another on
         //   every run — a new object each time —, in either order: a route
         //   registered on every run stays behind both, in the first run's order
         $Fresh = new class implements Middleware {
            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
         $Both = static function (bool $onceFirst) use ($Tag, $Counter, $Fresh, $Resolve): array {
            $Router = new Router;
            $once = false;
            $Once = static function () use ($Router, $Counter, &$once): void {
               if ($once === false) {
                  $once = true;

                  $Router->intercept($Counter);
               }
            };
            for ($attempt = 1; $attempt <= 3; $attempt++) {
               $Router->rewind();
               if ($onceFirst) {
                  $Once();
               }
               $Router->intercept(new ($Fresh::class));
               if ($onceFirst === false) {
                  $Once();
               }
               $Router->route('/admin', $Tag('admin'), 'GET');
            }
            $Router->warm();
            $Counter->runs = 0;
            $answer = $Resolve($Router, '/admin');

            $Row = $Router->Routes[0];
            $classes = [];
            foreach ($Row->Middlewares as $Middleware) {
               $classes[] = $Middleware === $Counter ? 'once' : ($Middleware instanceof $Fresh ? 'every' : 'other');
            }

            return [$classes, $answer, $Counter->runs];
         };

         yield new Assertion(description: 'a middleware intercepted on the first run only beside one intercepted on every run: the route stays behind both, whichever comes first')
            ->expect([$Both(true), $Both(false)])
            ->to->be([
               [['once', 'every'], 'admin', 1],
               [['every', 'once'], 'admin', 1],
            ])
            ->assert();

         // @ An attempt whose first registration is the catch-all: it is
         //   stored after the mark is put back, not wiped by the next one
         $Router = new Router;
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            $Router->rewind();
            $Router->route('/*', $Tag('fallback'));
            $Router->route('/a', $Tag('a'), 'GET');
         }
         $Router->warm();

         yield new Assertion(description: 'an attempt that registers the catch-all first keeps it, and the fallback answers')
            ->expect([$Paths($Router->Routes), $Resolve($Router, '/nowhere'), $Resolve($Router, '/a')])
            ->to->be([['/*', '/a'], 'fallback', 'a'])
            ->assert();

         // @ A middleware a later attempt intercepts, alone or beside one
         //   intercepted on every attempt; a path the earlier attempts also
         //   register once ahead of an intercept() of every attempt; a
         //   registration the earlier attempts make and is refused: a route
         //   is never stored with fewer middlewares than an attempt gave it
         $late = $Chains(static function (Router $Router, int $attempt) use ($Tag, $Counter): void {
            if ($attempt === 3) {
               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         });
         $beside = $Chains(static function (Router $Router, int $attempt) use ($Tag, $Counter, $Fresh): void {
            $Router->intercept(new ($Fresh::class));
            if ($attempt === 3) {
               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         });
         $shifted = $Chains(static function (Router $Router, int $attempt) use ($Tag, $Counter): void {
            if ($attempt < 3) {
               $Router->route('/admin', $Tag('maintenance'), 'GET');
            }
            $Router->intercept($Counter);
            $Router->route('/admin', $Tag('admin'), 'GET');
         });
         $once = false;
         $refused = $Chains(static function (Router $Router, int $attempt) use ($Tag, $Counter, &$once): void {
            if ($attempt < 3) {
               try {
                  $Router->route('/admin', $Tag('refused'), 'GET', cache: ['TTL' => 0]);
               }
               catch (InvalidArgumentException) {
                  // ...refused: it stores nothing, and takes no turn
               }
            }
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         });

         yield new Assertion(description: 'a route is never stored with fewer middlewares than an attempt gave it: intercepted on a later attempt only, beside one of every attempt, after a registration of the same path the last attempt skips, or after one refused')
            ->expect([$late, $beside, $shifted, $refused])
            ->to->be([
               [['/admin', 1]],
               [['/admin', 2]],
               [['/admin', 1]],
               [['/admin', 1]],
            ])
            ->assert();

         // @ The second intercept() of an attempt adds to its first: a route
         //   only the later attempt registers is behind both, in order
         $Router = new Router;
         for ($attempt = 1; $attempt <= 2; $attempt++) {
            $Router->rewind();
            $Router->intercept($Counter);
            $Router->intercept($Fresh);
            $Router->route('/a', $Tag('a'), 'GET');
            if ($attempt === 2) {
               $Router->route('/b', $Tag('b'), 'GET');
            }
         }
         $Router->warm();
         $stacked = [];
         foreach ($Router->Routes as $Row) {
            $names = [];
            foreach ($Row->Middlewares as $Middleware) {
               $names[] = $Middleware === $Counter ? 'counter' : ($Middleware === $Fresh ? 'fresh' : 'other');
            }
            $stacked[] = [$Row->path, $names];
         }

         yield new Assertion(description: 'the second intercept() of a later attempt adds to its first, and a route only that attempt registers is behind both')
            ->expect($stacked)
            ->to->be([
               ['/a', ['counter', 'fresh']],
               ['/b', ['counter', 'fresh']],
            ])
            ->assert();

         // @ The catch-all, a route's own middlewares, a method list and a
         //   registration that stores nothing follow the same rules, and what
         //   a later attempt adds goes behind what the route was stored with
         $Limit = new class implements Middleware {
            public int $runs = 0;

            public function process (object $Request, object $Response, Closure $Next): object
            {
               $this->runs++;

               return $Next($Request, $Response);
            }
         };
         // ! The attempts of $Chains, keeping the warm Router
         $Warm = static function (Closure $Set, int $attempts = 3): Router {
            $Router = new Router;
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
               $Router->rewind();
               $Set($Router, $attempt);
            }
            $Router->warm();

            return $Router;
         };
         // ! Each row's middlewares named, in dispatch order
         $Names = static function (Router $Router) use ($Counter, $Fresh, $Limit): array {
            $named = [];
            foreach ($Router->Routes as $Row) {
               $names = [];
               foreach ($Row->Middlewares as $Middleware) {
                  $names[] = match (true) {
                     $Middleware instanceof Stringable => (string) $Middleware,
                     $Middleware === $Counter => 'counter',
                     $Middleware === $Limit => 'limit',
                     $Middleware instanceof $Fresh => 'fresh',
                     default => 'other',
                  };
               }
               $named[] = [$Row->path, $names];
            }

            return $named;
         };

         $once = false;
         $Router = $Warm(static function (Router $Router) use ($Tag, $Counter, $Fresh, &$once): void {
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->intercept(new ($Fresh::class));
            $Router->route('/*', $Tag('fallback'));
         });
         $Counter->runs = 0;
         $fallback = [$Names($Router), $Resolve($Router, '/nowhere'), $Counter->runs];

         $Router = $Warm(static function (Router $Router) use ($Tag, $Counter, $Limit): void {
            $Router->intercept($Counter);
            $Router->route('/pay', $Tag('pay'), 'GET', middlewares: [$Limit]);
         });
         $Counter->runs = 0;
         $pay = [$Names($Router), $Resolve($Router, '/pay'), $Counter->runs, $Limit->runs];

         $behind = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Counter, $Fresh): void {
            $Router->intercept(new ($Fresh::class));
            if ($attempt === 3) {
               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         }));
         $methods = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Counter): void {
            if ($attempt === 1) {
               $Router->route('/x', $Tag('read'), 'GET');
               $Router->intercept($Counter);
            }
            $Router->route('/x', $Tag('write'), 'POST');
         }));
         $once = false;
         $nothing = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Counter, &$once): void {
            if ($attempt < 3) {
               // ...an empty method list stores nothing, and takes no turn
               $Router->route('/x', $Tag('nothing'), []);
            }
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/x', $Tag('every method'));
         }));

         // @ Middlewares of one class: each configuration is a middleware of
         //   its own, and what a later attempt intercepts keeps its order
         $Gate = static function (string $tag): Middleware {
            return new class ($tag) implements Middleware, Stringable {
               public string $tag;

               public function __construct (string $tag)
               {
                  $this->tag = $tag;
               }

               public function __toString (): string
               {
                  return "gate {$this->tag}";
               }

               public function process (object $Request, object $Response, Closure $Next): object
               {
                  return $Next($Request, $Response);
               }
            };
         };
         $sections = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Gate): void {
            if ($attempt === 3) {
               $Router->intercept($Gate('staff'));
            }
            $Router->route('/reports', $Tag('reports'), 'GET');
            if ($attempt === 3) {
               $Router->intercept($Gate('admin'));
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         }));
         $configured = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Gate): void {
            $Router->intercept($Gate($attempt < 3 ? 'open' : 'closed'));
            $Router->route('/admin', $Tag('admin'), 'GET');
         }));
         $ordered = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Counter, $Fresh): void {
            if ($attempt === 3) {
               $Router->intercept($Counter);
            }
            $Router->intercept(new ($Fresh::class));
            $Router->route('/admin', $Tag('admin'), 'GET');
         }));
         $once = false;
         $refusedLater = $Names($Warm(static function (Router $Router, int $attempt) use ($Tag, $Counter, $Fresh, &$once): void {
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->intercept(new ($Fresh::class));
            if ($attempt > 1) {
               try {
                  $Router->route('/admin', $Tag('refused'), 'GET', cache: ['TTL' => 0]);
               }
               catch (InvalidArgumentException) {
                  // ...refused: it stores nothing, and takes no turn
               }
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         }));

         // @ A middleware built again on every run around an object or a
         //   closure built again too is the same one; another closure is not
         $Wrap = static function (object $Inner): Middleware {
            return new class ($Inner) implements Middleware {
               public object $Inner;

               public function __construct (object $Inner)
               {
                  $this->Inner = $Inner;
               }

               public function process (object $Request, object $Response, Closure $Next): object
               {
                  return $Next($Request, $Response);
               }
            };
         };
         $rebuilt = [];
         foreach ([
            static fn (): object => new class {
               public string $realm = 'api';
            },
            static function (): object {
               return static fn (): bool => true;
            },
         ] as $Inner) {
            $rebuilt[] = count($Warm(static function (Router $Router) use ($Tag, $Wrap, $Inner): void {
               $Router->intercept($Wrap($Inner()));
               $Router->route('/admin', $Tag('admin'), 'GET');
            })->Routes[0]->Middlewares);
         }
         $closures = count($Warm(static function (Router $Router, int $attempt) use ($Tag, $Wrap): void {
            if ($attempt === 1) {
               $Router->intercept($Wrap(static fn (): string => 'mfa'));
            }
            if ($attempt === 3) {
               $Router->intercept($Wrap(static fn (): string => 'admin'));
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         })->Routes[0]->Middlewares);

         yield new Assertion(description: 'middlewares of one class with another configuration are kept apart, one built again the same way on every run is kept once, a later attempt keeps the order it intercepts in, and a registration refused on later attempts only takes no turn')
            ->expect([$sections, $configured, $ordered, $refusedLater, $rebuilt, $closures])
            ->to->be([
               [['/reports', ['gate staff']], ['/admin', ['gate staff', 'gate admin']]],
               [['/admin', ['gate open', 'gate closed']]],
               [['/admin', ['counter', 'fresh']]],
               [['/admin', ['counter', 'fresh']]],
               [1, 1],
               2,
            ])
            ->assert();

         // @ What tells two middlewares apart: one intercepted on the first
         //   attempt only beside one intercepted on every attempt — '/admin'
         //   keeps both, unless nothing tells them apart
         $Plain = new class implements Middleware {
            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
         $Kept = static function (Closure $First, Closure $Every, int $attempts = 2) use ($Warm, $Tag, $Plain, $Fresh): array {
            $Router = $Warm(static function (Router $Router, int $attempt) use ($Tag, $First, $Every): void {
               $Middlewares = $attempt === 1 ? [...$First(), ...$Every()] : $Every();
               if ($Middlewares !== []) {
                  $Router->intercept(...$Middlewares);
               }
               $Router->route('/admin', $Tag('admin'), 'GET');
            }, $attempts);

            $names = [];
            foreach ($Router->Routes[0]->Middlewares as $Middleware) {
               $names[] = match (true) {
                  $Middleware instanceof Stringable => (string) $Middleware,
                  $Middleware instanceof $Plain => 'plain',
                  $Middleware instanceof $Fresh => 'fresh',
                  default => 'other',
               };
            }

            return $names;
         };
         // ! A middleware around a value, named after what the value says
         $Around = static function (mixed $Inner): Middleware {
            return new class ($Inner) implements Middleware, Stringable {
               public mixed $Inner;

               public function __construct (mixed $Inner)
               {
                  $this->Inner = $Inner;
               }

               public function __toString (): string
               {
                  $value = match (true) {
                     $this->Inner instanceof Closure => ($this->Inner)('Gate'),
                     $this->Inner instanceof SensitiveParameterValue => $this->Inner->getValue(),
                     $this->Inner instanceof SplObjectStorage => count($this->Inner),
                     default => $this->Inner,
                  };
                  $said = json_encode($value);

                  return "around $said";
               }

               public function process (object $Request, object $Response, Closure $Next): object
               {
                  return $Next($Request, $Response);
               }
            };
         };
         $Role = static function (string $role): Closure {
            return static fn (): string => $role;
         };
         $Policy = static function (string $role): object {
            return new class ($role) {
               public string $role;

               public function __construct (string $role)
               {
                  $this->role = $role;
               }

               public function allow (): bool
               {
                  return $this->role === 'admin';
               }
            };
         };
         $Pick = static function (bool $strict): Closure {
            return $strict ? static fn (): bool => false : static fn (): bool => true;
         };
         $Ghost = static function (string $tag) use ($Gate): Middleware {
            /** @var Middleware $Lazy */
            $Lazy = new ReflectionClass($Gate('prototype'))->newLazyGhost(
               static function (object $Object) use ($tag): void {
                  new ReflectionProperty($Object, 'tag')->setValue($Object, $tag);
               }
            );

            return $Lazy;
         };
         $SelfHeld = static fn (): Middleware => new class implements Middleware {
            public object $Self;

            public function __construct ()
            {
               $this->Self = $this;
            }

            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
         // ! Every-attempt middlewares built once: a stored one can only be
         //   lost by standing for one of them
         $Open = $Around($Pick(false));
         $Public = $Around(new SensitiveParameterValue('public'));
         $Staff = $Ghost('staff');
         $Holder = new Rewind_Holder;
         $apart = [
            // another class, neither with a property
            $Kept(static fn () => [new ($Plain::class)], static fn () => [new ($Fresh::class)]),
            // closures that capture another value
            $Kept(static fn () => [$Around($Role('admin'))], static fn () => [$Around($Role('staff'))]),
            // the same method of another object
            $Kept(static fn () => [$Around($Policy('admin')->allow(...))], static fn () => [$Around($Policy('staff')->allow(...))]),
            // one static method called on another class
            $Kept(static fn () => [$Around(Rewind_Admin::allow(...))], static fn () => [$Around(Rewind_Staff::allow(...))]),
            // one closure bound to another scope, on the same object
            $Kept(
               static fn () => [$Around(Closure::bind($Holder->reach(), $Holder, Rewind_Admin::class))],
               static fn () => [$Around(Closure::bind($Holder->reach(), $Holder, Rewind_Staff::class))]
            ),
            // two closures declared on one line
            $Kept(static fn () => [$Around($Pick(true))], static fn () => [$Open]),
            // options in another order, or loosely equal
            $Kept(static fn () => [$Around(['a' => 1, 'b' => 2])], static fn () => [$Around(['b' => 2, 'a' => 1])]),
            $Kept(static fn () => [$Around(['limit' => 0])], static fn () => [$Around(['limit' => null])]),
            // state PHP keeps out of the properties: an object of a class PHP
            // defines, a lazy object not initialized yet
            $Kept(static fn () => [$Around(new SensitiveParameterValue('admin'))], static fn () => [$Public]),
            $Kept(static fn () => [$Ghost('admin')], static fn () => [$Staff]),
         ];
         $Looped = static fn (): Middleware => new class implements Middleware {
            /** @var array<string,mixed> */
            public array $options = [];

            public function __construct ()
            {
               $this->options['root'] = &$this->options;
            }

            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
         $same = [
            // a middleware that holds itself, built again on every attempt
            count($Kept(static fn () => [], static fn () => [$SelfHeld()], 3)),
            // one whose options hold themselves is told apart, never thrown
            // on: each attempt's is another one
            count($Kept(static fn () => [], static fn () => [$Looped()], 2)),
            // the same one twice in an attempt stays twice, though later
            // attempts intercept it once: one stands for one kept at most
            $Kept(static fn () => [$Gate('log')], static fn () => [$Gate('log')], 3),
            // two intercepted together on the first attempt only keep their
            // order, however many attempts follow
            $Kept(static fn () => [$Gate('a'), $Gate('b')], static fn () => [], 2),
            $Kept(static fn () => [$Gate('a'), $Gate('b')], static fn () => [], 4),
         ];
         $Router = $Warm(static function (Router $Router, int $attempt) use ($Tag, $Gate): void {
            if ($attempt === 1) {
               $Router->intercept($Gate('a'), $Gate('b'), $Gate('x'));
            }
            else {
               $Router->intercept($Gate('b'), $Gate('a'));
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         }, 2);
         $reordered = $Names($Router);

         yield new Assertion(description: 'two middlewares are the same only when nothing tells them apart — class, captured values, bound object, called class, scope, declaration, option order and value, state PHP keeps — and the same ones keep their count and order')
            ->expect([$apart, $same, $reordered])
            ->to->be([
               [
                  ['plain', 'fresh'],
                  ['around "admin"', 'around "staff"'],
                  ['around true', 'around false'],
                  ['around true', 'around false'],
                  ['around "Rewind_Admin"', 'around "Rewind_Staff"'],
                  ['around false', 'around true'],
                  ['around {"a":1,"b":2}', 'around {"b":2,"a":1}'],
                  ['around {"limit":0}', 'around {"limit":null}'],
                  ['around "admin"', 'around "public"'],
                  ['gate admin', 'gate staff'],
               ],
               [
                  1,
                  2,
                  ['gate log', 'gate log'],
                  ['gate a', 'gate b'],
                  ['gate a', 'gate b'],
               ],
               [['/admin', ['gate b', 'gate a', 'gate x']]],
            ])
            ->assert();

         // @ What the comparison keeps track of: a pair of objects compared
         //   once is not taken for another, a class under one PHP defines
         //   hides its state, a function PHP defines has no file, `eval()`'d
         //   code no line to read, one shared array needs no walk — and what
         //   it leaves behind once warm
         $Eval = static function (string $role): Closure {
            return eval("return static fn (): string => '$role';");
         };
         $Node = static function (mixed $next): object {
            return new class ($next) {
               public mixed $next;

               public function __construct (mixed $next)
               {
                  $this->next = $next;
               }
            };
         };
         // ! Options that reach one object twice: near the top, then twelve
         //   levels down, where comparing it again would go past 16 levels
         $Deep = static function () use ($Node): array {
            $Shared = $Node($Node($Node($Node('leaf'))));
            $options = ['first' => $Shared, 'again' => $Shared];
            for ($level = 0; $level < 12; $level++) {
               $options['again'] = ['again' => $options['again']];
            }

            return $options;
         };
         $Capture = static function (object $Policy): Closure {
            return static fn (): bool => $Policy->allow();
         };
         $Staffer = $Policy('staff');
         // ! The same as no other instance, so built once
         $Empty = $Around(new Rewind_Bag);
         // ! Options nested deeper than 16 levels, one array shared by every
         //   attempt: told the same in one step
         $Nested = ['leaf' => true];
         for ($level = 0; $level < 20; $level++) {
            $Nested = ['next' => $Nested];
         }
         $Evaled = $Around($Eval('staff'));
         $tracked = [
            // two kept ones that share an object, beside an own one that
            // differs there: neither is taken for it
            $Kept(
               static function () use ($Around, $Policy): array {
                  $Admin = $Policy('admin');

                  return [$Around($Admin), $Around($Admin)];
               },
               static fn () => [$Around($Staffer)]
            ),
            // an own one that holds one object twice against a kept one
            // that holds two different ones
            $Kept(
               static fn () => [$Around([$Policy('admin'), $Policy('staff')])],
               static function () use ($Around, $Policy): array {
                  $Admin = $Policy('admin');

                  return [$Around([$Admin, $Admin])];
               }
            ),
            // a bag of a class under one PHP defines, holding a gate, beside
            // an empty one
            $Kept(
               static function () use ($Around, $Gate): array {
                  $Bag = new Rewind_Bag;
                  $Bag[$Gate('admin')] = null;

                  return [$Around($Bag)];
               },
               static fn () => [$Empty]
            ),
            // two functions PHP defines
            $Kept(static fn () => [$Around(strtoupper(...))], static fn () => [$Around(strtolower(...))]),
            // closures `eval()`'d at one place
            $Kept(static fn () => [$Around($Eval('admin'))], static fn () => [$Evaled]),
            // the same one twice on the first attempt only, put back twice
            $Kept(static fn () => [$Gate('log'), $Gate('log')], static fn () => [], 2),
         ];
         $rebuilt = [
            // one array shared by every attempt, however deep
            count($Kept(static fn () => [], static fn () => [$Around($Nested)], 2)),
            // options that reach one object twice, rebuilt on every attempt
            count($Kept(static fn () => [], static fn () => [$Around($Deep())], 2)),
            // a function PHP defines, taken again on every attempt
            count($Kept(static fn () => [], static fn () => [$Around(strtolower(...))], 3)),
            // a closure that captures an object rebuilt on every attempt
            count($Kept(static fn () => [], static fn () => [$Around($Capture($Policy('admin')))], 2)),
         ];
         // ! The two closures `$Pick` returns are declared on one line
         $line = new ReflectionFunction($Pick(true))->getStartLine() === new ReflectionFunction($Pick(false))->getStartLine();
         $Router = $Warm(static function (Router $Router) use ($Tag, $Around, $Role): void {
            $Router->intercept($Around($Role('admin')));
            $Router->route('/admin', $Tag('admin'), 'GET');
         }, 2);
         $declarations = new ReflectionProperty(Router::class, 'declarations')->getValue($Router);

         yield new Assertion(description: 'the comparison keeps apart what shares an object, holds one twice, keeps its state in PHP or has no file to read, keeps once what is rebuilt the same way, and leaves nothing behind once warm')
            ->expect([$tracked, $rebuilt, $line, $declarations])
            ->to->be([
               [
                  ['around {"role":"admin"}', 'around {"role":"admin"}', 'around {"role":"staff"}'],
                  ['around [{"role":"admin"},{"role":"staff"}]', 'around [{"role":"admin"},{"role":"admin"}]'],
                  ['around 1', 'around 0'],
                  ['around "GATE"', 'around "gate"'],
                  ['around "admin"', 'around "staff"'],
                  ['gate log', 'gate log'],
               ],
               [1, 1, 1, 1],
               true,
               [],
            ])
            ->assert();

         yield new Assertion(description: 'the catch-all, a route with its own middlewares, a path two method lists register and a registration that stores nothing keep their middlewares, each once, and a later attempt adds behind them')
            ->expect([$fallback, $pay, $behind, $methods, $nothing])
            ->to->be([
               [[['/*', ['counter', 'fresh']]], 'fallback', 1],
               [[['/pay', ['counter', 'limit']]], 'pay', 1, 1],
               [['/admin', ['fresh', 'counter']]],
               [['/x', ['counter']]],
               [['/x', ['counter']]],
            ])
            ->assert();

         $once = false;
         $groups = $Chains(static function (Router $Router) use ($Group, $Counter, &$once): void {
            $Router->route('/open/:*', $Group($Router, 'open'));
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/admin/:*', $Group($Router, 'admin'));
         });
         $once = false;
         $moved = $Chains(static function (Router $Router, int $attempt) use ($Tag, $Counter, &$once): void {
            if ($attempt === 1) {
               $Router->route('/first-only', $Tag('first only'), 'GET');
            }
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
         });
         $twice = $Chains(static function (Router $Router) use ($Tag, $Counter): void {
            $Router->route('/twice/:id', $Tag('open'), 'GET');
            $Router->intercept($Counter);
            $Router->route('/twice/:id', $Tag('closed'), 'GET');
         });
         $once = false;
         $added = $Chains(static function (Router $Router, int $attempt) use ($Tag, $Counter, &$once): void {
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/admin', $Tag('admin'), 'GET');
            if ($attempt > 1) {
               $Router->route('/added', $Tag('added'), 'GET');
            }
         });

         yield new Assertion(description: 'the middlewares follow the registration — a group, a route that changes position, each registration of a path twice — and a new registration takes the ones the last attempt ended with')
            ->expect([$groups, $moved, $twice, $added])
            ->to->be([
               [['/open/page', 0], ['/admin/page', 1]],
               [['/admin', 1]],
               [['/twice/:id', 0], ['/twice/:id', 1]],
               [['/admin', 1], ['/added', 1]],
            ])
            ->assert();

         $Before = new class implements Middleware {
            public function process (object $Request, object $Response, Closure $Next): object
            {
               return $Next($Request, $Response);
            }
         };
         $booted = $Chains(
            static function (Router $Router) use ($Tag, $Counter): void {
               $Router->intercept($Counter);
               $Router->route('/admin', $Tag('admin'), 'GET');
            },
            Boot: static function (Router $Router) use ($Before): void {
               $Router->intercept($Before);
            }
         );
         $once = false;
         $failing = true;
         $retried = $Chains(static function (Router $Router) use ($Tag, $Counter, &$once, &$failing): void {
            if ($once === false) {
               $once = true;

               $Router->intercept($Counter);
            }
            $Router->route('/retried/:*', function () use ($Router, $Tag, &$failing): Generator {
               if ($failing) {
                  $failing = false;

                  throw new RuntimeException('group failed');
               }

               yield $Router->route('page', $Tag('retried'), 'GET');
            });
            try {
               $Router->warm();
            }
            catch (RuntimeException) {
               // the next attempt registers the group again
            }
         }, attempts: 2);

         $failing = true;
         $recovered = $Chains(static function (Router $Router) use ($Tag, $Counter, &$failing): void {
            $Router->route('/public', $Tag('public'), 'GET');
            $Router->intercept($Counter);
            $Router->route('/recovered/:*', function () use ($Router, $Tag, &$failing): Generator {
               if ($failing) {
                  $failing = false;

                  throw new RuntimeException('group failed');
               }

               yield $Router->route('page', $Tag('recovered'), 'GET');
            });
            try {
               $Router->warm();
            }
            catch (RuntimeException) {
               // the next attempt registers everything again
            }
         }, attempts: 2);

         yield new Assertion(description: 'an attempt intercepts on top of the middlewares registered before the first one, never on top of the last attempt, and a warm-up that throws keeps what each registration had: the middleware intercepted once, the public route ahead of intercept()')
            ->expect([$booted, $retried, $recovered])
            ->to->be([
               [['/admin', 2]],
               [['/retried/page', 1]],
               [['/public', 0], ['/recovered/page', 1]],
            ])
            ->assert();

         // @ A warm-up registers on its own: a route set written as a
         //   Generator starts from no intercepted middleware on every
         //   attempt, the ones registered before the first request included
         $Rows = static function (bool $failing) use ($Tag, $Counter, $Before): array {
            $Router = new Router;
            $Router->intercept($Before);
            $Set = static function () use ($Router, $Tag, $Counter, &$failing): Generator {
               $Router->intercept($Counter);
               yield $Router->route('/admin', $Tag('admin'), 'GET');

               if ($failing) {
                  $failing = false;

                  throw new RuntimeException('route set failed');
               }
            };
            for ($attempt = 1; $attempt <= 2 && $Router->cached === false; $attempt++) {
               $Router->rewind();
               try {
                  $Router->warm($Set());
               }
               catch (RuntimeException) {
                  // the next attempt drains the route set again
               }
            }

            $chains = [];
            foreach ($Router->Routes as $Row) {
               $chains[] = [$Row->path, count($Row->Middlewares)];
            }

            return $chains;
         };

         yield new Assertion(description: 'a Generator route set is stored with what its own warm-up intercepts, on the first attempt and on the one after a warm-up that threw')
            ->expect([$Rows(false), $Rows(true)])
            ->to->be([[['/admin', 1]], [['/admin', 1]]])
            ->assert();

         // ---
         // @ A registration that is refused stores nothing: it does not put
         //   the mark back either
         $Router = new Router;
         $Router->rewind();
         $Router->route('/kept', $Tag('kept'), 'GET');
         $Router->rewind();
         $refusals = 0;
         foreach ([
            static fn () => $Router->route('/refused', $Tag('refused'), 'GET', cache: ['TTL' => 0]),
            static fn () => $Router->route('/refused/:id<nope>', $Tag('refused'), 'GET'),
            static fn () => $Router->route('/refused', $Tag('refused'), []),
         ] as $Refused) {
            try {
               $Refused();
            }
            catch (InvalidArgumentException) {
               $refusals++;
            }
         }
         $held = $Sizes($Router);
         $Router->warm();

         yield new Assertion(description: 'an attempt whose registrations are all refused keeps what the last attempt left')
            ->expect([$refusals, $held, $Paths($Router->Routes), $Resolve($Router, '/kept')])
            ->to->be([2, [1, 0, 0, 0, 0, 1], ['/kept'], 'kept'])
            ->assert();

         // ---
         // @ A route a paused Router ignores is no registration: it does not
         //   put the mark back
         $Router = new Router;
         $Router->rewind();
         $Router->route('/held', $Tag('held'), 'GET');
         $Router->rewind();
         $Router->pause();
         $Router->route('/ignored', $Tag('ignored'), 'GET');
         $Router->serve('/ignored-body', 'ignored', 'GET');
         $Router->continue();
         $Router->warm();

         yield new Assertion(description: 'a registration a paused Router ignores keeps what the last attempt left')
            ->expect([$Paths($Router->Routes), $Resolve($Router, '/held')])
            ->to->be([['/held'], 'held'])
            ->assert();

         // ---
         // @ A failed warm-up on an attempt that had already registered
         //   leaves it so: the next registration adds to it
         $Router = new Router;
         $Router->rewind();
         $Router->route('/first', $Tag('first'), 'GET');
         try {
            $Router->warm((static function (): Generator {
               yield false;

               throw new RuntimeException('route set failed');
            })());
         }
         catch (RuntimeException) {
            $Router->route('/second', $Tag('second'), 'GET');
         }

         yield new Assertion(description: 'after a failed warm-up a registration adds to the attempt that was already under way')
            ->expect($Paths($Router->Routes))
            ->to->be(['/first', '/second'])
            ->assert();

         // @ A failed warm-up on a later attempt that has not intercepted
         //   yet: its first intercept() still starts from the mark
         $Router = new Router;
         $Router->rewind();
         $Router->intercept(new ($Fresh::class));
         $Router->route('/a', $Tag('a'), 'GET');
         $Router->rewind();
         try {
            $Router->warm((static function (): Generator {
               yield false;

               throw new RuntimeException('route set failed');
            })());
         }
         catch (RuntimeException) {
            $Router->intercept(new ($Fresh::class));
            $Router->route('/y', $Tag('y'), 'GET');
         }
         $Router->warm();
         $retaken = $Names($Router);

         // @ A warm-up after attempts that registered by direct calls stores
         //   what it registers itself: no trail of those attempts
         $Router = new Router;
         $Router->rewind();
         $Router->intercept($Counter);
         $Router->route('/x', $Tag('direct'), 'GET');
         $Router->rewind();
         $Router->warm((static function () use ($Router, $Tag): Generator {
            yield $Router->route('/x', $Tag('generated'), 'GET');
         })());

         yield new Assertion(description: 'a failed warm-up leaves a later attempt starting from the mark, and a warm-up takes no middleware from the attempts before it')
            ->expect([$retaken, $Names($Router), $Resolve($Router, '/x')])
            ->to->be([
               [['/y', ['fresh']]],
               [['/x', []]],
               'generated',
            ])
            ->assert();

         // ---
         // @ A route set that warms the Router by itself and then throws: the
         //   Router is warm, so nothing is put back
         $Router = new Router;
         $nested = null;
         try {
            $Router->warm((static function () use ($Router, $Tag): Generator {
               yield $Router->route('/inner', $Tag('inner'), 'GET');
               $Router->warm();

               throw new RuntimeException('after the inner warm-up');
            })());
         }
         catch (RuntimeException $Exception) {
            $nested = $Exception->getMessage();
         }

         yield new Assertion(description: 'a route set that warmed the Router before it threw keeps its routes')
            ->expect([$nested, $Router->cached, $Paths($Router->Routes), $Resolve($Router, '/inner')])
            ->to->be(['after the inner warm-up', true, ['/inner'], 'inner'])
            ->assert();

         // ---
         // @ An attempt made of intercept() and a group only — no row until
         //   the flatten — is rewound like any other
         $Router = new Router;
         $sizes = [];
         for ($attempt = 1; $attempt <= 5; $attempt++) {
            $Router->rewind();
            $Router->intercept($Counter);
            $Router->route('/only/:*', function () use ($Router, $Tag): Generator {
               yield $Router->route('child', $Tag('only child'), 'GET');
            });
            $sizes[] = $Sizes($Router);
         }
         $Router->warm();

         yield new Assertion(description: 'five attempts of intercept() and a group hold one middleware and one pending group')
            ->expect([$sizes, $Paths($Router->Routes), count($Router->Routes[0]->Middlewares)])
            ->to->be([array_fill(0, 5, [0, 0, 0, 1, 1, 0]), ['/only/child'], 1])
            ->assert();

         // ---
         // @ With no mark, a warm-up that throws goes back to what it found:
         //   the routes registered before it stay, and so do their groups
         //   and the intercepted middlewares
         $Router = new Router;
         $broken = true;
         $Router->intercept($Counter);
         $Router->route('/top', $Tag('top'), 'GET');
         $Router->route('/sub/:*', function () use ($Router, $Tag, &$broken): Generator {
            yield $Router->route('fine', $Tag('fine'), 'GET');
            if ($broken) {
               yield $Router->route('/absolute', $Tag('absolute'), 'GET');
            }
         });
         $found = $Sizes($Router);
         $failures = [];
         for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
               $Router->warm();
            }
            catch (InvalidArgumentException $Exception) {
               $failures[] = [$Exception->getMessage(), $Router->cached, $Sizes($Router) === $found, $Marked($Router)];
            }
         }
         $broken = false;
         $Router->warm();

         yield new Assertion(description: 'an unmarked Router goes back to what the failed warm-up found, and warms once the route set is fixed')
            ->expect([$found, $failures, $Paths($Router->Routes), $Resolve($Router, '/sub/fine'), $Router->cached])
            ->to->be([
               [1, 0, 0, 1, 1, 1],
               array_fill(0, 3, ['Nested route path must be relative!', false, true, false]),
               ['/top', '/sub/fine'],
               'fine',
               true,
            ])
            ->assert();

         // ---
         // @ The server rewinds a cold Router only: the one call sits in the
         //   `else` of the encoder's warm-router fast path
         $Tokens = [];
         $source = (string) file_get_contents((string) new ReflectionClass(Encoder_::class)->getFileName());
         foreach (PhpToken::tokenize($source) as $Token) {
            if ($Token->isIgnorable() === false) {
               $Tokens[] = $Token;
            }
         }
         $calls = [];
         foreach ($Tokens as $index => $Token) {
            if ($Token->id !== T_STRING || $Token->text !== 'rewind') {
               continue;
            }

            // ! Before the call: `else {`; before that, the block of the `if`
            $before = [
               $Tokens[$index - 4]->text,
               $Tokens[$index - 3]->text,
               $Tokens[$index - 2]->text,
               $Tokens[$index - 1]->text,
            ];
            $depth = 0;
            $opening = $index - 5;
            for (; $opening > 0; $opening--) {
               if ($Tokens[$opening]->text === '}') {
                  $depth++;
               }
               else if ($Tokens[$opening]->text === '{' && --$depth === 0) {
                  break;
               }
            }
            $condition = '';
            for ($position = $opening - 6; $position < $opening; $position++) {
               $condition .= $Tokens[$position]->text;
            }

            $calls[] = [implode(' ', $before), $condition];
         }

         yield new Assertion(description: 'the encoder calls rewind() once, where the Router is not warm')
            ->expect($calls)
            ->to->be([['else { $Router ->', 'if($Router->cached)']])
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
