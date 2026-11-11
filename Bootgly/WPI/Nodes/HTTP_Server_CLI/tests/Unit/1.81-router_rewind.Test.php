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


/**
 * A cold Router runs its route set once per attempt: `rewind()` marks the
 * start of the first one, and the first registration of each later one puts
 * that state back; a warm-up that throws goes back to what it found. This
 * pins that a route set which runs N times registers once — dispatch tables,
 * pending groups, intercepted middlewares and route list —, that an attempt
 * which registers nothing keeps the last one, and that one which registers
 * part of it keeps that part only.
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
         // @ An attempt is whole or nothing: one that registers part of what
         //   the last one did keeps that part — routes and middlewares alike
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

         yield new Assertion(description: 'an attempt that registers part of the route set keeps that part only: the guarded route and middleware are gone')
            ->expect([
               $sizes,
               $Paths($Router->Routes),
               [$Resolve($Router, '/guarded'), $Resolve($Router, '/repeated')],
               $Counter->runs,
            ])
            ->to->be([
               [[2, 0, 0, 0, 1, 2], [1, 0, 0, 0, 0, 1], [1, 0, 0, 0, 0, 1]],
               ['/repeated'],
               [null, 'repeated'],
               0,
            ])
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
