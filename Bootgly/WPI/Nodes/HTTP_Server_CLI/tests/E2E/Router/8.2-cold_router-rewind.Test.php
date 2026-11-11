<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * A request handler that registers its routes with direct `$Router->route()`
 * calls runs again on every request while the Router is cold — and the Router
 * stays cold while the handler answers by itself or throws. This drives the
 * production `Encoder_` (the testing encoder builds a new Router per test) and
 * pins that those runs register once: the route list, the dispatch tables and
 * the intercepted middlewares hold one route set however many cold requests
 * came before the first routed one — and that a handler which registers on
 * its first run only keeps its routes.
 */
$probe = [
   'error' => '',
   'answered' => [],
   'thrown' => [],
   'missed' => [],
   'once' => [],
];

return new Test(
   description: 'It should register the routes once when the handler runs on every request of a cold Router',

   request: static function () use (&$probe): string {
      $harness = "GET /cold-router-harness HTTP/1.1\r\nHost: localhost\r\n\r\n";

      $socket = tmpfile();
      if (is_resource($socket) === false) {
         $probe['error'] = 'Could not allocate the production-encoder stream surrogate.';

         return $harness;
      }

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;
      $OldEmitter = Emitter::$Instance;
      $oldEntries = Cache::$entries;
      $oldBytes = Cache::$bytes;
      $oldURIs = Cache::$URIs;
      $oldGeneration = Cache::$generation;

      try {
         /** @var Connection $Connection */
         $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         $Connection->Socket = $socket;
         $Connection->timers = [];
         $Connection->ip = '127.0.0.1';
         $Connection->port = 12345;
         $Connection->encrypted = false;
         $Connection->writes = 0;

         // ! One request through the production encoder: its status and body
         $Send = static function (string $URI) use ($Connection): string {
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;
                  $this->consumed = 0;
                  $this->rejected = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };

            Server::$Request = new Request;
            Server::$Response = new Response;
            Server::$Decoder = new Decoder_;

            $raw = "GET $URI HTTP/1.1\r\nHost: localhost\r\n\r\n";
            $size = strlen($raw);
            $State = Server::$Request->decode($Package, $raw, $size);
            if ($State !== States::Complete || $Package->rejected) {
               throw new RuntimeException('Production Request::decode() rejected the probe request.');
            }

            $length = null;
            $wire = Encoder_::encode($Package, $length);
            $status = substr($wire, 9, 3);
            $separator = strpos($wire, "\r\n\r\n");
            $body = $separator === false ? '' : substr($wire, $separator + 4);

            return $status === '200' ? "200 $body" : $status;
         };
         // ! What the worker's Router holds:
         //   [cached, static slots, dynamic entries, pending groups, intercepted, rows]
         $Sizes = static function (): array {
            $Router = Server::$Router;
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
               $Router->cached,
               $statics,
               $dynamics,
               count($Read('pendingGroups')),
               count($Read('Middlewares')),
               count($Router->Routes),
            ];
         };
         $Paths = static function (): array {
            $paths = [];
            foreach (Server::$Router->Routes as $Row) {
               $paths[] = $Row->path;
            }

            return $paths;
         };
         $Tag = static function (string $tag): Closure {
            return function (Request $Request, Response $Response) use ($tag): Response {
               return $Response(body: $tag);
            };
         };
         $Counter = new class implements Middleware {
            public int $runs = 0;

            public function process (object $Request, object $Response, Closure $Next): object
            {
               $this->runs++;

               return $Next($Request, $Response);
            }
         };

         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;

         // ---
         // @ The handler registers by direct calls, then answers a probe by
         //   itself: nothing throws, and the Router stays cold
         Server::$Router = new Router;
         Server::$Router->route('/early', $Tag('early'), 'GET');
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use ($Tag, $Counter): null|Response {
            $Router->intercept($Counter);
            $Router->route('/about', $Tag('about'), 'GET');
            $Router->route('/users/:id', $Tag('user'), 'GET');
            $Router->route('/api/:*', function () use ($Router, $Tag): Generator {
               yield $Router->route('ping', $Tag('pong'), 'GET');
            });

            if ($Request->URI === '/probe') {
               return $Response(body: 'OK');
            }

            return null;
         };

         $probes = [];
         for ($request = 1; $request <= 20; $request++) {
            $probes[] = $Send('/probe');
         }
         $cold = $Sizes();
         $first = $Send('/api/ping');
         $warm = $Sizes();
         $runs = $Counter->runs;
         $probe['answered'] = [
            'probes' => array_values(array_unique($probes)),
            'cold' => $cold,
            'first' => $first,
            'warm' => $warm,
            'paths' => $Paths(),
            'runs' => $runs,
            'next' => [$Send('/users/7'), $Send('/about'), $Send('/early'), $Send('/probe')],
            'after' => $Sizes(),
         ];

         // ---
         // @ The handler registers by direct calls, then throws
         Server::$Router = new Router;
         $fail = true;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use ($Tag, &$fail): null {
            $Router->route('/about', $Tag('about'), 'GET');
            $Router->route('/users/:id', $Tag('user'), 'GET');
            if ($fail) {
               $Router->route('/ghost/:id', $Tag('ghost'), 'GET');

               throw new RuntimeException('handler failed');
            }

            return null;
         };

         $failures = [];
         for ($request = 1; $request <= 20; $request++) {
            $failures[] = $Send('/about');
         }
         $cold = $Sizes();
         $fail = false;
         $probe['thrown'] = [
            'failures' => array_values(array_unique($failures)),
            'cold' => $cold,
            'first' => $Send('/users/7'),
            'warm' => $Sizes(),
            'paths' => $Paths(),
            'ghost' => $Send('/ghost/7') === '200 ghost',
         ];

         // ---
         // @ A warm Router whose handler runs again on every miss
         Server::$Router = new Router;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use ($Tag, $Counter): null {
            $Router->intercept($Counter);
            $Router->route('/about', $Tag('about'), 'GET');

            return null;
         };

         $first = $Send('/about');
         for ($request = 1; $request <= 20; $request++) {
            $Send('/missing');
         }
         $probe['missed'] = [
            'first' => $first,
            'after' => $Sizes(),
         ];

         // ---
         // @ A handler that registers on its first run only, and answers
         //   that first request by itself: its routes are still there
         Server::$Router = new Router;
         $registered = false;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use ($Tag, &$registered): null|Response {
            if ($registered === false) {
               $registered = true;

               $Router->route('/about', $Tag('about'), 'GET');
               $Router->route('/users/:id', $Tag('user'), 'GET');
            }

            if ($Request->URI === '/probe') {
               return $Response(body: 'OK');
            }

            return null;
         };

         $probe['once'] = [
            'probes' => [$Send('/probe'), $Send('/probe')],
            'cold' => $Sizes(),
            'next' => [$Send('/about'), $Send('/users/7')],
            'warm' => $Sizes(),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         Cache::$entries = $oldEntries;
         Cache::$bytes = $oldBytes;
         Cache::$URIs = $oldURIs;
         Cache::$generation = $oldGeneration;
         Emitter::$Instance = $OldEmitter;
         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }

         @fclose($socket);
      }

      return $harness;
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/cold-router-harness', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$probe): bool|string {
      $Fail = static function (string $message) use (&$probe): string {
         $measured = json_encode($probe);

         Vars::$labels = ['Cold-router probe:'];
         dump($measured);

         return "$message — measured: $measured";
      };

      if ($probe['error'] !== '') {
         return $Fail("The fixture failed before validation: {$probe['error']}");
      }
      if (str_ends_with($response, "\r\n\r\nHARNESS-OK") === false) {
         return $Fail('The harness request did not reach its route');
      }

      // @ Handler that answers by itself: one route set after 20 cold probes
      $expected = [
         'probes' => ['200 OK'],
         // ? Cold: /early + /about, one dynamic, one pending group, one
         //   intercepted middleware, three rows
         'cold' => [false, 2, 1, 1, 1, 3],
         'first' => '200 pong',
         'warm' => [true, 3, 1, 0, 0, 4],
         'paths' => ['/early', '/about', '/users/:id', '/api/ping'],
         // ? The middleware intercepted once runs once for the routed request
         'runs' => 1,
         'next' => ['200 user', '200 about', '200 early', '200 OK'],
         'after' => [true, 3, 1, 0, 0, 4],
      ];
      if ($probe['answered'] !== $expected) {
         return $Fail('A handler that answers by itself on a cold Router registered its routes more than once');
      }

      // @ Handler that throws: the good run registers over none of the failed ones
      $expected = [
         'failures' => ['500'],
         // ? Cold: what the last failed run stored, and no more
         'cold' => [false, 1, 2, 0, 0, 3],
         'first' => '200 user',
         'warm' => [true, 1, 1, 0, 0, 2],
         'paths' => ['/about', '/users/:id'],
         // ? The route only the failed runs registered answers nothing
         'ghost' => false,
      ];
      if ($probe['thrown'] !== $expected) {
         return $Fail('A handler that throws on a cold Router left registrations of its failed runs');
      }

      // @ Warm Router: the handler's intercept() on every miss adds nothing
      $expected = [
         'first' => '200 about',
         'after' => [true, 1, 0, 0, 0, 1],
      ];
      if ($probe['missed'] !== $expected) {
         return $Fail('A warm Router kept the middlewares intercepted by a handler that ran again');
      }

      // @ Registered once: an attempt that registers nothing drops nothing
      $expected = [
         'probes' => ['200 OK', '200 OK'],
         'cold' => [false, 1, 1, 0, 0, 2],
         'next' => ['200 about', '200 user'],
         'warm' => [true, 1, 1, 0, 0, 2],
      ];
      if ($probe['once'] !== $expected) {
         return $Fail('A handler that registers on its first run only lost its routes');
      }

      return true;
   }
);
