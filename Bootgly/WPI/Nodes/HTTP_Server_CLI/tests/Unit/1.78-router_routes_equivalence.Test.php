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
 * The route list (`Router::$Routes`) against the dispatcher: over one table
 * mixing every kind of route, each row says which path, which methods, which
 * handler and which middlewares — and `resolve()` must agree with every one
 * of those claims, for a listed method and for one that is not.
 */
return new Test(
   description: 'It should list every stored route in a row that agrees with what resolve() dispatches',
   test: new Assertions(Case: function (): Generator {
      // ! Middlewares that record the order dispatch runs them in
      $Trace = new ArrayObject;
      $Spawn = static function () use ($Trace): Middleware {
         return new class ($Trace) implements Middleware {
            /** @var ArrayObject<int,Middleware> */
            private ArrayObject $Trace;

            /** @param ArrayObject<int,Middleware> $Trace */
            public function __construct (ArrayObject $Trace)
            {
               $this->Trace = $Trace;
            }

            public function process (object $Request, object $Response, Closure $Next): object
            {
               $this->Trace[] = $this;

               return $Next($Request, $Response);
            }
         };
      };
      $Global = $Spawn();
      $Group = $Spawn();
      $First = $Spawn();
      $Second = $Spawn();

      // ! Handlers, each answering with its own tag — a bindable closure, a
      //   static one, an invokable object, an [object, method] pair and a
      //   callable name
      $Tag = static function (string $tag): Closure {
         return function (Request $Request, Response $Response) use ($tag): Response {
            return $Response(body: $tag);
         };
      };
      $Invokable = new class {
         public function __invoke (Request $Request, Response $Response): Response
         {
            return $Response(body: 'invokable');
         }

         public function handle (Request $Request, Response $Response): Response
         {
            return $Response(body: 'method');
         }

         public static function name (Request $Request, Response $Response): Response
         {
            return $Response(body: 'named');
         }
      };
      $Static = static function (Request $Request, Response $Response): Response {
         return $Response(body: 'static-closure');
      };
      /** @var array<string,callable> $Handlers tag → handler as registered */
      $Handlers = [
         'root' => $Tag('root'),
         'any' => $Tag('any'),
         'multi' => $Tag('multi'),
         'user' => $Tag('user'),
         'order' => $Tag('order'),
         'file' => $Tag('file'),
         'inline' => $Tag('inline'),
         'preset' => $Tag('preset'),
         'guarded' => $Tag('guarded'),
         'cached' => $Tag('cached'),
         'static-closure' => $Static,
         'invokable' => $Invokable,
         'method' => [$Invokable, 'handle'],
         'named' => $Invokable::class . '::name',
         'cached-item' => $Tag('cached-item'),
         'intercepted' => $Tag('intercepted'),
         'fallback' => $Tag('fallback'),
         'items' => $Tag('items'),
         'item' => $Tag('item'),
         'thing' => $Tag('thing'),
         'narrow' => $Tag('narrow'),
         'narrow-item' => $Tag('narrow-item'),
      ];

      // ! One table mixing every kind; $fallback adds the generic catch-all
      $Register = static function (Router $Router, bool $fallback) use (
         $Handlers,
         $Global,
         $Group,
         $First,
         $Second
      ): Generator {
         yield $Router->route('/', $Handlers['root'], 'GET');
         yield $Router->serve('/health', 'OK', 'GET');
         yield $Router->route('/any', $Handlers['any']);
         yield $Router->route('/multi', $Handlers['multi'], ['GET', 'POST']);
         yield $Router->route('/users/:id', $Handlers['user'], 'GET');
         yield $Router->route('/orders/:oid<int>', $Handlers['order'], ['GET', 'PUT']);
         yield $Router->route('/files/:path*', $Handlers['file']);
         yield $Router->route('/inline/:n(\d+)', $Handlers['inline'], 'GET');
         $Router->Route->Params->code = '[A-Z]{3}';
         yield $Router->route('/preset/:code', $Handlers['preset'], 'GET');
         yield $Router->route('/guarded', $Handlers['guarded'], 'GET', [$First, $Second]);
         yield $Router->serve('/served-guarded', 'SG', 'GET', [$First]);
         yield $Router->serve('/served/:id', 'SD', 'GET');
         yield $Router->route('/cached', $Handlers['cached'], 'GET', cache: ['TTL' => 5]);
         yield $Router->route('/static-closure', $Handlers['static-closure'], 'GET');
         yield $Router->route('/invokable', $Handlers['invokable'], 'POST');
         yield $Router->route('/method', $Handlers['method'], 'GET');
         yield $Router->route('/named', $Handlers['named'], 'GET');
         yield $Router->route('/cached/:id', $Handlers['cached-item'], 'GET', cache: ['TTL' => 7]);

         // @ From here on every registration carries the intercepted one
         $Router->intercept($Global);
         yield $Router->route('/intercepted', $Handlers['intercepted'], 'GET');
         if ($fallback) {
            yield $Router->route('/*', $Handlers['fallback']);
         }

         yield $Router->route('/api/:*', function () use ($Router, $Handlers, $Group, $First): Generator {
            $Router->intercept($Group);
            yield $Router->route('items', $Handlers['items']);
            yield $Router->route('items/:id<int>', $Handlers['item'], 'GET', [$First]);
            yield $Router->serve('ping', 'pong');
            // # A dynamic child with no methods inherits the group's
            yield $Router->route('things/:id', $Handlers['thing']);
            // # A method the group lacks is narrowed away, static and dynamic
            yield $Router->route('narrow', $Handlers['narrow'], ['GET', 'DELETE']);
            yield $Router->route('narrow/:id', $Handlers['narrow-item'], ['POST', 'DELETE']);
         }, ['GET', 'POST']);
      };

      $Router = new Router;
      $Router->warm($Register($Router, true));
      $Bare = new Router;
      $Bare->warm($Register($Bare, false));

      // ! resolve() harness — the worker's Request/Response, one per probe
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;
      $OldResponse = $WPI->Response ?? null;
      $Request = new Request;
      $Request->protocol = 'HTTP/1.1';
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $URLProperty = new ReflectionProperty(Request::class, '_URL');
      $WPI->Request = $Request;

      $Resolve = static function (Router $Router, string $URI, string $method)
         use ($Request, $URIProperty, $URLProperty, $WPI, $Trace): null|string
      {
         $Request->method = $method;
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $Response = new Response;
         $WPI->Response = $Response;
         $Trace->exchangeArray([]);

         $Result = $Router->resolve();

         return $Result instanceof Response ? $Result->Body->raw : null;
      };

      // ! What a row claims: the tag its handler answers with, and a path
      //   that its pattern matches (one sample value per param pattern)
      $Claim = static function (Registration $Row) use ($Handlers): null|string {
         if ($Row->handler === null) {
            return $Row->body;
         }
         foreach ($Handlers as $tag => $Handler) {
            if ($Row->handler === $Handler) {
               return $tag;
            }
         }

         return null;
      };
      $samples = [
         '[^\/]+' => 'v',
         '[0-9]+' => '42',
         '.+' => 'a/b.txt',
         '\d+' => '7',
         '[A-Z]{3}' => 'BRL',
      ];
      $Sample = static function (Registration $Row) use ($samples): string {
         if ($Row->Kind === Kinds::CatchAll) {
            return '/nowhere/at/all';
         }

         $parameters = $Row->parameters;
         $segments = explode('/', $Row->path);
         foreach ($segments as $index => $segment) {
            if ($segment !== '' && $segment[0] === ':') {
               $parameter = array_shift($parameters);
               $segments[$index] = $samples[$parameter['pattern'] ?? ''] ?? '?';
            }
         }

         return implode('/', $segments);
      };

      try {
         // @ Every row against resolve(): each listed method and one that is
         //   not — on the Router with a fallback and on the one without
         $mismatches = [];
         $probes = 0;
         foreach ([[$Router, 'fallback'], [$Bare, null]] as [$Subject, $miss]) {
            foreach ($Subject->Routes as $Row) {
               $tag = $Claim($Row);
               $path = $Sample($Row);
               $methods = [...($Row->methods ?? ['GET', 'DELETE']), 'PATCH'];

               foreach ($methods as $method) {
                  $listed = $Row->methods === null || in_array($method, $Row->methods, true);
                  $body = $Resolve($Subject, $path, $method);
                  $probes++;

                  // ? A listed method reaches this row's handler, through
                  //   this row's middlewares, in this row's order
                  if ($listed) {
                     if ($tag === null || $body !== $tag || $Trace->getArrayCopy() !== $Row->Middlewares) {
                        $mismatches[] = "$method $path: row says '$tag', dispatch answered " . var_export($body, true);
                     }

                     continue;
                  }
                  // ? An unlisted one never does: it falls to the generic
                  //   catch-all when there is one, and misses otherwise
                  if ($body !== $miss) {
                     $mismatches[] = "$method $path: unlisted, dispatch answered " . var_export($body, true);
                  }
               }
            }
         }
         $unrowed = $Resolve($Bare, '/nowhere/at/all', 'GET');
         $unrowedRoot = $Resolve($Bare, '/users', 'GET');

         yield new Assertion(description: 'each row matches resolve() for its listed methods and for one unlisted')
            ->expect([$mismatches, $probes > 60])
            ->to->be([[], true])
            ->assert();

         yield new Assertion(description: 'a path with no row misses')
            ->expect([$unrowed, $unrowedRoot])
            ->to->be([null, null])
            ->assert();

         // @ Rows are in registration order; a group registers its children
         //   when it is flattened, after every top-level route
         $paths = [];
         $kinds = [];
         foreach ($Router->Routes as $Row) {
            $paths[] = $Row->path;
            $kinds[] = $Row->Kind;
         }

         yield new Assertion(description: 'one row per stored route, in registration order, with its group prefix')
            ->expect($paths)
            ->to->be([
               '/',
               '/health',
               '/any',
               '/multi',
               '/users/:id',
               '/orders/:oid<int>',
               '/files/:path*',
               '/inline/:n(\d+)',
               '/preset/:code',
               '/guarded',
               '/served-guarded',
               '/served/:id',
               '/cached',
               '/static-closure',
               '/invokable',
               '/method',
               '/named',
               '/cached/:id',
               '/intercepted',
               '/*',
               '/api/items',
               '/api/items/:id<int>',
               '/api/ping',
               '/api/things/:id',
               '/api/narrow',
               '/api/narrow/:id',
            ])
            ->assert();

         yield new Assertion(description: 'the kind names the lookup: a served body only where no handler call is left')
            ->expect($kinds)
            ->to->be([
               Kinds::Static,
               Kinds::Body,
               Kinds::Static,
               Kinds::Static,
               Kinds::Dynamic,
               Kinds::Dynamic,
               Kinds::Dynamic,
               Kinds::Dynamic,
               Kinds::Dynamic,
               Kinds::Static,
               Kinds::Static,
               Kinds::Dynamic,
               Kinds::Static,
               Kinds::Static,
               Kinds::Static,
               Kinds::Static,
               Kinds::Static,
               Kinds::Dynamic,
               Kinds::Static,
               Kinds::CatchAll,
               Kinds::Static,
               Kinds::Dynamic,
               Kinds::Static,
               Kinds::Dynamic,
               Kinds::Static,
               Kinds::Dynamic,
            ])
            ->assert();

         /** @var array<string,Registration> $Rows */
         $Rows = [];
         foreach ($Router->Routes as $Row) {
            $Rows[$Row->path] = $Row;
         }

         // @ Methods: null is method-agnostic; a group child inherits the
         //   group's methods and an explicit list narrows them
         yield new Assertion(description: 'methods are the ones dispatch tests, null when the route answers every method')
            ->expect([
               $Rows['/']->methods,
               $Rows['/any']->methods,
               $Rows['/multi']->methods,
               $Rows['/files/:path*']->methods,
               $Rows['/*']->methods,
               $Rows['/api/items']->methods,
               $Rows['/api/items/:id<int>']->methods,
               $Rows['/api/ping']->methods,
               $Rows['/api/things/:id']->methods,
               $Rows['/api/narrow']->methods,
               $Rows['/api/narrow/:id']->methods,
            ])
            ->to->be([
               ['GET'],
               null,
               ['GET', 'POST'],
               null,
               null,
               ['GET', 'POST'],
               ['GET'],
               ['GET', 'POST'],
               ['GET', 'POST'],
               ['GET'],
               ['POST'],
            ])
            ->assert();

         // @ Handler: the callable that was registered, whatever dispatch holds
         $handlers = true;
         foreach ($Handlers as $tag => $Handler) {
            $found = false;
            foreach ($Router->Routes as $Row) {
               if ($Row->handler === $Handler && $Row->body === null) {
                  $found = true;
               }
            }
            $handlers = $handlers && $found;
         }

         yield new Assertion(description: 'the handler is the registered callable; a served route has a body and no handler')
            ->expect([
               $handlers,
               $Rows['/health']->handler,
               $Rows['/health']->body,
               $Rows['/served-guarded']->handler,
               $Rows['/served-guarded']->body,
               $Rows['/served/:id']->body,
               $Rows['/api/ping']->body,
            ])
            ->to->be([true, null, 'OK', null, 'SG', 'SD', 'pong'])
            ->assert();

         // @ Middlewares: intercept(), the group's, then the route's own —
         //   and nothing registered before an intercept() carries it
         yield new Assertion(description: 'middlewares are merged in dispatch order and never applied backwards')
            ->expect([
               $Rows['/']->Middlewares,
               $Rows['/guarded']->Middlewares,
               $Rows['/served-guarded']->Middlewares,
               $Rows['/method']->Middlewares,
               $Rows['/intercepted']->Middlewares,
               $Rows['/*']->Middlewares,
               $Rows['/api/items']->Middlewares,
               $Rows['/api/items/:id<int>']->Middlewares,
               $Rows['/api/ping']->Middlewares,
            ])
            ->to->be([
               [],
               [$First, $Second],
               [$First],
               [],
               [$Global],
               [$Global],
               [$Global, $Group],
               [$Global, $Group, $First],
               [$Global, $Group],
            ])
            ->assert();

         // @ Parameters: name, named constraint and the pattern of each
         yield new Assertion(description: 'parameters carry their name, constraint type and pattern, in path order')
            ->expect([
               $Rows['/']->parameters,
               $Rows['/users/:id']->parameters,
               $Rows['/orders/:oid<int>']->parameters,
               $Rows['/files/:path*']->parameters,
               $Rows['/inline/:n(\d+)']->parameters,
               $Rows['/preset/:code']->parameters,
               $Rows['/api/items/:id<int>']->parameters,
            ])
            ->to->be([
               [],
               [['name' => 'id', 'constraint' => null, 'pattern' => '[^\/]+']],
               [['name' => 'oid', 'constraint' => 'int', 'pattern' => '[0-9]+']],
               [['name' => 'path', 'constraint' => null, 'pattern' => '.+']],
               [['name' => 'n', 'constraint' => null, 'pattern' => '\d+']],
               [['name' => 'code', 'constraint' => null, 'pattern' => '[A-Z]{3}']],
               [['name' => 'id', 'constraint' => 'int', 'pattern' => '[0-9]+']],
            ])
            ->assert();

         // @ Cache options: as registered
         yield new Assertion(description: 'cache options are recorded as registered, null without the option')
            ->expect([
               $Rows['/cached']->cache,
               $Rows['/cached/:id']->cache,
               $Rows['/']->cache,
               $Rows['/health']->cache,
               $Rows['/users/:id']->cache,
            ])
            ->to->be([['TTL' => 5], ['TTL' => 7], null, null, null])
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
