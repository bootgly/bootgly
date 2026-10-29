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
use Bootgly\ABI\Data\Language;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSON;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSONP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Negotiation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Plaintext;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Pre;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\View;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\XML;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * What a deferred job leaves behind keeps answering after the job ended, and
 * still cannot reach the wire.
 *
 * A finished generation is freed by refcounting, so its forked Response no
 * longer outlives the job on its own: it stays only while something holds
 * it. Driven through the real `defer()` and job loop on a pooled Fiber, with
 * the worker reactor installed, over a real connection on a socket pair, and
 * the cycle collector disabled; the ambient Response a deferred segment binds
 * over serves a live request on a connection of its own. Pinned:
 *
 * - a retained clone: its Exchange is the finished tombstone; every resource
 *   the job built is still the instance reached through it; a late send of
 *   every built-in formatter lands on it and returns it, a late pagination
 *   writes its headers on it, `defer()` is refused (its work never runs), a
 *   late SSE head is refused, `wait()` returns at once, and nothing reaches
 *   the wire; a clone of it carries the same tombstone and refuses `defer()`
 *   too; a definition the job never built, fetched first on it, answers on
 *   it as well — a send of every built-in formatter and a pagination land on
 *   it, an SSE head is refused — while a project resource whose clean()
 *   wipes what its construction set up is handed over as built, with its
 *   state;
 * - once that clone is let go, refcounting frees it — nothing a late call
 *   left behind holds it, every built-in first built after the job (each
 *   formatter, Database, SSE) included — and the formatter built that way,
 *   kept past it, answers for a detached stand-in; nothing reaches the wire;
 * - resources, and the registry, retained without their clone (which did
 *   not outlive the job): every late call — a send of every built-in
 *   formatter, an SSE head, a pagination, a query, a wait through the
 *   bridge, a lazy registry fetch and send, a lazy fetch of a configured
 *   database and of a user resource with a wait bridge, a mount, a
 *   definition — completes without an exception and returns what it
 *   returned before (a Response, the same body, the mounted instance); each
 *   Response is a detached stand-in — not the ambient Response, with no
 *   transport, deferred, bound to no request — and the ambient Response
 *   keeps its body, status and media type; nothing reaches either wire;
 * - a subclass of a built-in keeps the strong edge it always had: a View
 *   subclass registered over `View` still reads its response across two
 *   `Response::reset()` cycles, and a JSON subclass built by a deferred job
 *   still reads the finished clone after the job ended;
 * - each context a registry kept past its clone hands a factory is a fresh
 *   stand-in: four fetches across two such registries get four distinct
 *   ones, none the ambient Response; the registry of a Response subclass's
 *   clone is left out of release() — it keeps that clone, alive through
 *   the registry alone after a collector run, and hands it to a factory.
 */
if (! class_exists('U170Waiter', false)) {
   /**
    * A user resource that parks through the response wait bridge.
    */
   class U170Waiter extends Resource implements Scheduling
   {
      // * Data
      private null|Closure $Wait = null;


      public function schedule (Closure $Wait): static
      {
         $this->Wait = $Wait;

         return $this;
      }

      public function await (mixed $value = null): object
      {
         $Wait = $this->Wait ?? throw new RuntimeException('U170 waiter is not bound.');

         return $Wait($value);
      }
   }
}
if (! class_exists('U170Plain', false)) {
   /**
    * A user resource with no lifecycle of its own.
    */
   class U170Plain extends Resource
   {
   }
}
if (! class_exists('U170Stateful', false)) {
   /**
    * A user resource whose clean() wipes the state its construction set up.
    */
   class U170Stateful extends Resource
   {
      // * Data
      public private(set) string $state;


      public function __construct ()
      {
         parent::__construct();

         $this->state = 'seeded';
      }

      public function clean (): void
      {
         $this->state = '';
      }
   }
}
if (! class_exists('U170Response', false)) {
   /**
    * A project Response subclass.
    */
   class U170Response extends Response
   {
   }
}
if (! class_exists('U170View', false)) {
   /**
    * A project View that reads its protected response itself.
    */
   class U170View extends View
   {
      public function peek (): Response
      {
         return $this->Response;
      }
   }
}
if (! class_exists('U170JSON', false)) {
   /**
    * A project JSON formatter that reads its protected response itself.
    */
   class U170JSON extends JSON
   {
      public function peek (): Response
      {
         return $this->Response;
      }
   }
}
if (! class_exists('U170Item', false)) {
   #[Table('u170_items')]
   final class U170Item
   {
      #[Key]
      public null|int $id = null;
      #[Column]
      public string $name = '';
   }
}
if (! class_exists('U170SQL', false)) {
   /**
    * A database whose every query resolves inline: `COUNT(*)` reports 23,
    * items honor the LIMIT.
    */
   final class U170SQL extends SQL
   {
      public function __construct ()
      {
         parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
      }

      /**
       * @param string|Builder|Query $query
       * @param array<int|string,mixed> $parameters
       */
      public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
      {
         $Normalized = new Normalized($query, $parameters);
         $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters);

         if (str_contains($Operation->SQL, 'COUNT(*)')) {
            $Operation->resolve(new Result(rows: [['total' => 23]], columns: ['total']));

            return $Operation;
         }

         preg_match('/LIMIT (\d+)/', $Operation->SQL, $matches);
         $limit = (int) ($matches[1] ?? 1);
         $rows = [];
         for ($id = 1; $id <= $limit; $id++) {
            $rows[] = ['id' => $id, 'name' => "Item {$id}"];
         }
         $Operation->resolve(new Result(rows: $rows, columns: ['id', 'name']));

         return $Operation;
      }
   }
}


return new Test(
   description: 'What a deferred job leaves behind should keep answering after the job ended — a retained clone exactly as before and freed by refcount once let go, a resource or registry retained without it with a fresh detached stand-in and no exception, a subclass through its own strong edge — and never reach the wire',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $Parked = new ReflectionProperty(Response::class, 'Pool');
      $Contexts = new ReflectionProperty(Language::class, 'Contexts');
      $Transport = new ReflectionProperty(Response::class, 'Package');

      // ! Statics this case writes — directly, or through defer() and the
      //   job loop
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $OldRouter = isset($WPI->Router) ? $WPI->Router : null;
      $OldAmbient = isset($WPI->Request) ? $WPI->Request : null;
      $OldAnswer = isset($WPI->Response) ? $WPI->Response : null;
      $OldPool = $Parked->getValue();
      $OldContexts = $Contexts->getValue();
      $oldTimeout = Response::$deferredTimeout;
      $collectable = gc_enabled();

      $Sockets = [];
      $Select = null;

      // ! A decoded per-connection Request, its head in strings of its own
      $Decode = static function (string $path): Request {
         $Connection = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         $Connection->ip = '127.0.0.1';
         $Connection->port = 40170;
         $Connection->encrypted = false;
         $Connection->writes = 1;
         $Package = new class extends TCPPackages {
            // ! decode() only reads the connection truth and the reject slot
            public function __construct () {}

            public function reject (string $raw): void
            {
               throw new RuntimeException("A 1.70 head was rejected: {$raw}");
            }
         };
         $Package->Connection = $Connection;
         $Request = new Request;
         $Request->reset();
         $buffer = "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $Request->decode($Package, $buffer, strlen($buffer));

         return $Request;
      };
      // ! A server end the response is written to and a client end that
      //   never blocks
      $Open = static function () use (&$Sockets): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U170 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         $Sockets[] = $Server;
         $Sockets[] = $Client;

         return [new Connection($Server, '127.0.0.1', 40170), $Client];
      };
      // ! Whatever reached the client end, without blocking
      $Receive = static function (mixed $Client): string {
         $wire = '';
         while (is_resource($Client)) {
            $chunk = @fread($Client, 65_536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $wire .= $chunk;
         }

         return $wire;
      };
      // ! The context a built-in factory is handed
      $Within = static fn (object $Context): Response => $Context instanceof Response
         ? $Context
         : throw new RuntimeException('U170 expects a Response context.');
      // ! The worker's persistent Response for one live request, with the
      //   configured resources a project registers — the `Lazy` and `Late`
      //   ones (one per framework built-in) and `Stateful` are never built
      //   by a job
      $respond = static function (array $definitions = [], null|Response $Response = null) use ($Open, $Decode, $Within): array {
         [$Connection, $Client] = $Open();

         $Response ??= new Response;
         $Response->Resources->load([
            'Database' => static fn (object $Context): Database => new Database(new U170SQL),
            'Waiter' => static fn (object $Context): U170Waiter => new U170Waiter,
            'LazyDatabase' => static fn (object $Context): Database => new Database(new U170SQL),
            'LazyWaiter' => static fn (object $Context): U170Waiter => new U170Waiter,
            'LazyXML' => static fn (object $Context): XML => new XML($Within($Context)),
            'LateJSON' => static fn (object $Context): JSON => new JSON($Within($Context)),
            'LateJSONP' => static fn (object $Context): JSONP => new JSONP($Within($Context)),
            'LateNegotiation' => static fn (object $Context): Negotiation => new Negotiation($Within($Context)),
            'LatePlaintext' => static fn (object $Context): Plaintext => new Plaintext($Within($Context)),
            'LatePre' => static fn (object $Context): Pre => new Pre($Within($Context)),
            'LateSSE' => static fn (object $Context): SSE => new SSE($Within($Context)),
            'LateView' => static fn (object $Context): View => new View($Within($Context)),
            'Stateful' => static fn (object $Context): U170Stateful => new U170Stateful,
            ...$definitions,
         ]);
         $Response->reset($Connection, $Decode('/u170'));

         return [$Response, $Connection, $Client];
      };
      // ! What a pagination returns, without the object identities
      $page = static fn (array $body): array => [
         'items' => array_map(static fn (object $Item): string => $Item->name ?? '?', $body['items'] ?? []),
         'page' => $body['page'] ?? null,
         'pages' => $body['pages'] ?? null,
         'limit' => $body['limit'] ?? null,
         'total' => $body['total'] ?? null,
      ];
      $expected = [
         'items' => ['Item 1', 'Item 2', 'Item 3', 'Item 4', 'Item 5', 'Item 6', 'Item 7', 'Item 8', 'Item 9', 'Item 10'],
         'page' => 1,
         'pages' => 3,
         'limit' => 10,
         'total' => 23,
      ];
      $names = ['JSON', 'JSONP', 'Negotiation', 'Plaintext', 'Pre', 'SSE', 'View', 'XML', 'Database', 'Waiter'];
      // ! A late send of every built-in formatter
      $sends = [
         'JSON' => static fn (object $Formatter): Response => $Formatter->send(['late' => 1]),
         'JSONP' => static fn (object $Formatter): Response => $Formatter->send(['late' => 1]),
         'Negotiation' => static fn (object $Formatter): Response => $Formatter->send(['late' => 1]),
         'Plaintext' => static fn (object $Formatter): Response => $Formatter->send('late'),
         'Pre' => static fn (object $Formatter): Response => $Formatter->send('late'),
         'View' => static fn (object $Formatter): Response => $Formatter->send(170),
         'XML' => static fn (object $Formatter): Response => $Formatter->send('<late/>'),
      ];
      // ! A Response a late call returned: a detached stand-in — never the
      //   ambient one, no transport, deferred, bound to no request
      $Inert = static fn (mixed $Answer): bool => $Answer instanceof Response
         && $Answer !== $WPI->Response
         && $Transport->getValue($Answer) === null
         && $Answer->deferred
         && $Answer->Request === null;

      try {
         // ! The worker reactor: defer() requires a context-aware scheduler
         $Peers = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Select = new Select($Peers);
         TCP_Server_CLI::$Event = $Select;
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $WPI->Router = new Router;
         // ! The ambient context a deferred segment binds over and restores
         //   — a live request on a connection of its own, and the request a
         //   late pagination reads
         [$AmbientConnection, $AmbientClient] = $Open();
         $WPI->Request = $Decode('/ambient');
         $Ambient = new Response;
         $Ambient->reset($AmbientConnection, $WPI->Request);
         $WPI->Response = $Ambient;
         $Parked->setValue(null, []);
         Response::$deferredTimeout = 0;
         // ! What the ambient Response carries before any late call
         $Snapshot = static fn (): array => [
            'body' => $Ambient->Body->raw,
            'sent' => $Ambient->sent,
            'type' => $Ambient->Header->type,
            'code' => $Ambient->code,
         ];
         $before = $Snapshot();

         // ! No cycle collection from here on: only refcounting decides what
         //   outlives the job
         gc_disable();

         // @@ A) A retained clone
         [$Response, $Connection, $Client] = $respond();
         $Kept = null;
         $Built = [];

         $Response->defer(static function (Response $Deferred) use ($names, &$Kept, &$Built): void {
            $Kept = $Deferred;
            foreach ($names as $name) {
               $Built[$name] = WeakReference::create($Deferred->{$name});
            }

            $Deferred->JSON->send(['leg' => 'A']);
         });
         // ! The worker's Response moves on to the next request
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);

         $same = [];
         foreach ($Built as $name => $Weak) {
            $Instance = $Weak->get();
            $same[$name] = $Instance !== null && $Kept?->{$name} === $Instance;
         }
         unset($Instance);
         $ran = false;
         $late = [];
         if ($Kept instanceof Response) {
            $Tombstone = Exchange::fetch($Kept);
            $landed = [];
            foreach ($sends as $name => $Send) {
               $landed[$name] = $Send($Kept->{$name}) === $Kept;
            }
            $late = [
               'tombstone' => $Tombstone?->check(),
               'sends' => $landed,
               'paginate' => $page($Kept->Database->paginate(U170Item::class)) === $expected
                  && $Kept->Header->get('X-Total-Count') === '23',
               'defer' => $Kept->defer(static function () use (&$ran): void {
                  $ran = true;
               }) === $Kept && $Kept->deferred && $ran === false,
               'sse' => $Kept->SSE->open() === $Kept->SSE && $Kept->SSE->opened === false,
               'wait' => $Kept->wait() === $Kept,
            ];

            // @ A clone of the finished clone
            $Copy = clone $Kept;
            $late['copy'] = Exchange::fetch($Copy) === $Tombstone
               && $Copy->defer(static function () use (&$ran): void {
                  $ran = true;
               }) === $Copy
               && $ran === false
               && $Copy->JSON !== $Kept->JSON
               && $Copy->JSON->send(['late' => 'copy']) === $Copy;
            unset($Copy, $Tombstone);

            // @ Definitions the job never built, fetched first on the kept
            //   clone: every framework built-in lands on it as the job's own
            //   do, while a project resource is handed over as built — its
            //   clean(), which wipes what its construction set up, never run
            //   early
            $Lazy = $Kept->LazyXML;
            $landed = [];
            foreach ($sends as $name => $Send) {
               $landed[$name] = $Send($name === 'XML' ? $Lazy : $Kept->{"Late{$name}"}) === $Kept;
            }
            $late['lazy'] = [
               'sends' => $landed,
               'paginate' => $page($Kept->LazyDatabase->paginate(U170Item::class)) === $expected,
               'sse' => $Kept->LateSSE->open() === $Kept->LateSSE && $Kept->LateSSE->opened === false,
            ];
            $late['stateful'] = $Kept->Stateful->state;
         }
         $late['wire'] = $Receive($Client);

         yield assert(
            assertion: str_contains($wire, '{"leg":"A"}')
               && $same === array_fill_keys($names, true)
               && $late === [
                  'tombstone' => true,
                  'sends' => array_fill_keys(array_keys($sends), true),
                  'paginate' => true,
                  'defer' => true,
                  'sse' => true,
                  'wait' => true,
                  'copy' => true,
                  'lazy' => [
                     'sends' => array_fill_keys(array_keys($sends), true),
                     'paginate' => true,
                     'sse' => true,
                  ],
                  'stateful' => 'seeded',
                  'wire' => '',
               ],
            description: 'a retained clone keeps its finished tombstone and the very resources the job built: a late send of every built-in formatter lands on it, a late pagination writes its headers on it, defer() and an SSE head are refused, wait() returns, a clone of it refuses defer() too, every built-in the job never built lands on it (a send of each formatter, a pagination, a refused SSE head), a project resource first built after the job keeps the state its construction set — and nothing reaches the wire — observed: '
               . json_encode(['answered' => str_contains($wire, '{"leg":"A"}'), 'same' => $same, 'late' => $late])
         );

         // @ The kept clone is let go: nothing a late call left behind holds
         //   it — the built-ins first built after its job included — and the
         //   formatter built that way, kept past it, answers for a stand-in
         $Clone = $Kept instanceof Response ? WeakReference::create($Kept) : null;
         unset($Kept);
         $freed = [
            'freed' => $Clone !== null && $Clone->get() === null,
            'standin' => isset($Lazy) && $Inert($Lazy->send('<after/>')),
            'wire' => $Receive($Client),
         ];

         yield assert(
            assertion: $freed === ['freed' => true, 'standin' => true, 'wire' => ''],
            description: 'a retained clone let go is freed by refcount — every built-in first fetched on it after its job (each formatter, Database, SSE) holds it weakly — and the formatter built that way, kept past it, answers for a detached stand-in; nothing reaches the wire — observed: '
               . json_encode($freed)
         );
         unset($Response, $Connection, $Client, $Built, $Lazy, $Clone);

         // @@ B) Resources and the registry retained without their clone
         [$Response, $Connection, $Client] = $respond();
         $Left = [];
         $Gone = null;

         $Response->defer(static function (Response $Deferred) use ($names, &$Left, &$Gone): void {
            foreach ($names as $name) {
               $Left[$name] = $Deferred->{$name};
            }
            $Left['Resources'] = $Deferred->Resources;
            $Gone = WeakReference::create($Deferred);

            $Deferred->JSON->send(['leg' => 'B']);
         });
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         // ! Whether the clone outlived the job: before refcount freeing,
         //   what was retained kept it alive; now nothing does
         $outlived = $Gone?->get() !== null;

         $calls = [];
         foreach ($sends as $name => $Send) {
            $calls["send {$name}"] = static fn (): bool => $Inert($Send($Left[$name]));
         }
         $calls += [
            'sse' => static fn (): bool => $Left['SSE']->open() === $Left['SSE'] && $Left['SSE']->opened === false,
            'paginate' => static fn (): bool => $page($Left['Database']->paginate(U170Item::class)) === $expected,
            'query' => static fn (): bool => $Left['Database']->fetch('SELECT 1 AS answer') instanceof Result,
            'bridge' => static fn (): bool => $Inert($Left['Waiter']->await()),
            'mounted' => static fn (): bool => $Left['Resources']->fetch('Database') === $Left['Database'],
            'lazy' => static function () use ($Left, $Inert): bool {
               $Lazy = $Left['Resources']->fetch('LazyXML');
               if ($Lazy instanceof XML === false) {
                  return false;
               }
               // ! Two sends of the same lazily built instance share nothing
               $First = $Lazy->send('<first/>');
               $Second = $Lazy->send('<second/>');

               return $Inert($First)
                  && $Inert($Second)
                  && $First !== $Second
                  && [$First->Body->raw, $Second->Body->raw] === ['<first/>', '<second/>']
                  && $Left['Resources']->fetch('LazyXML') === $Lazy;
            },
            'lazy database' => static fn (): bool => $page($Left['Resources']->fetch('LazyDatabase')->paginate(U170Item::class)) === $expected,
            'lazy bridge' => static fn (): bool => $Inert($Left['Resources']->fetch('LazyWaiter')->await()),
            'mount' => static fn (): bool => $Left['Resources']->set('Extra', new U170Plain) instanceof U170Plain,
            'define' => static fn (): bool => $Left['Resources']->define('Late', static fn (object $Context): U170Plain => new U170Plain) === $Left['Resources']
               && $Left['Resources']->fetch('Late') instanceof U170Plain,
         ];
         $results = [];
         foreach ($calls as $call => $Call) {
            try {
               $results[$call] = $Call();
            }
            catch (Throwable $Throwable) {
               $results[$call] = $Throwable::class . ": {$Throwable->getMessage()}";
            }
         }
         $results['wire'] = $Receive($Client);
         $results['ambient'] = $Snapshot() === $before && $Receive($AmbientClient) === '';

         yield assert(
            assertion: str_contains($wire, '{"leg":"B"}')
               && $outlived === false
               && $results === [...array_fill_keys(array_keys($calls), true), 'wire' => '', 'ambient' => true],
            description: 'resources and the registry retained without their clone — gone with the job — answer every late call without an exception, each Response a detached stand-in, the same pagination body, the mounted instance; the ambient Response is untouched and nothing reaches either wire — observed: '
               . json_encode(['answered' => str_contains($wire, '{"leg":"B"}'), 'outlived' => $outlived, 'results' => $results])
         );
         unset($Response, $Connection, $Client, $Left, $Gone, $calls);

         // @@ C) A subclass of a built-in keeps its strong edge
         // # A View subclass registered over `View`, across two resets
         [$Response, $Connection, $Client] = $respond([
            'View' => static fn (object $Context): U170View => new U170View($Context),
         ]);
         $Subclassed = [];
         try {
            $Project = $Response->View;
            $Subclassed['view'] = [];
            foreach (['/c1', '/c2'] as $path) {
               $Response->reset($Connection, $Decode($path));
               $Subclassed['view'][] = $Project instanceof U170View && $Project->peek() === $Response;
            }
         }
         catch (Throwable $Throwable) {
            $Subclassed['view'] = $Throwable::class . ": {$Throwable->getMessage()}";
         }
         unset($Response, $Connection, $Client, $Project);

         // # A JSON subclass a deferred job built, after the job ended
         [$Response, $Connection, $Client] = $respond([
            'JSON' => static fn (object $Context): U170JSON => new U170JSON($Context),
         ]);
         $Formatter = null;
         $Clone = null;
         $Response->defer(static function (Response $Deferred) use (&$Formatter, &$Clone): void {
            $Formatter = $Deferred->JSON;
            $Clone = WeakReference::create($Deferred);

            $Deferred->JSON->send(['leg' => 'C']);
         });
         $Response->reset($Connection, $Decode('/next'));
         $wire = $Receive($Client);
         try {
            $Subclassed['json'] = $Formatter instanceof U170JSON
               && $Clone?->get() !== null
               && $Formatter->peek() === $Clone->get();
         }
         catch (Throwable $Throwable) {
            $Subclassed['json'] = $Throwable::class . ": {$Throwable->getMessage()}";
         }

         yield assert(
            assertion: str_contains($wire, '{"leg":"C"}')
               && $Subclassed === ['view' => [true, true], 'json' => true],
            description: 'a subclass of a built-in keeps the strong edge it always had: a View subclass still reads its response across two resets, a JSON subclass a deferred job built still reads the finished clone — observed: '
               . json_encode(['answered' => str_contains($wire, '{"leg":"C"}'), 'subclassed' => $Subclassed])
         );
         unset($Response, $Connection, $Client, $Formatter, $Clone);

         // @@ D) The contexts a registry hands its factories
         // ! Every context a factory is handed, held strongly: distinct
         //   objects only while all of them are alive
         $Handed = [];
         $Hand = static function (object $Context) use (&$Handed): U170Plain {
            $Handed[] = $Context;

            return new U170Plain;
         };
         $handing = ['HandA' => $Hand, 'HandB' => $Hand];

         // # Two registries kept past their clones: four fetches, four contexts
         $Registries = [];
         foreach (['/d1', '/d2'] as $path) {
            [$Response, $Connection, $Client] = $respond($handing);
            $Response->defer(static function (Response $Deferred) use (&$Registries): void {
               $Registries[] = $Deferred->Resources;

               $Deferred->JSON->send(['leg' => 'D']);
            });
            $Response->reset($Connection, $Decode($path));
            unset($Response, $Connection, $Client);
         }
         $fresh = [];
         try {
            foreach ($Registries as $Registry) {
               $Registry->fetch('HandA');
               $Registry->fetch('HandB');
            }
            $fresh = [
               'handed' => count($Handed),
               'distinct' => count(array_unique(array_map(spl_object_id(...), $Handed))),
               'inert' => array_map($Inert, $Handed),
            ];
         }
         catch (Throwable $Throwable) {
            $fresh = $Throwable::class . ": {$Throwable->getMessage()}";
         }
         $Handed = [];
         unset($Registries, $Registry);

         // # The registry of a Response subclass's clone keeps that clone
         [$Response, $Connection, $Client] = $respond($handing, new U170Response);
         $Kept = [];
         $Response->defer(static function (Response $Deferred) use (&$Kept): void {
            $Kept['Resources'] = $Deferred->Resources;
            $Kept['Clone'] = WeakReference::create($Deferred);

            $Deferred->JSON->send(['leg' => 'D subclass']);
         });
         $Response->reset($Connection, $Decode('/d3'));
         unset($Response, $Connection, $Client);
         // ! Whatever is garbage goes: only the registry can hold the clone
         gc_collect_cycles();
         $subclassed = [];
         try {
            $Clone = $Kept['Clone']?->get();
            $Kept['Resources']?->fetch('HandA');
            $subclassed = [
               'alive' => $Clone instanceof U170Response,
               'handed' => count($Handed) === 1 && $Handed[0] === $Clone,
            ];
         }
         catch (Throwable $Throwable) {
            $subclassed = $Throwable::class . ": {$Throwable->getMessage()}";
         }
         $Handed = [];
         unset($Kept, $Clone);

         yield assert(
            assertion: $fresh === ['handed' => 4, 'distinct' => 4, 'inert' => [true, true, true, true]]
               && $subclassed === ['alive' => true, 'handed' => true],
            description: 'each context a registry kept past its clone hands a factory is a fresh detached stand-in — four fetches across two registries, four distinct ones, none the ambient Response — and the registry of a Response subclass\'s clone keeps that clone through a collector run and hands it to a factory — observed: '
               . json_encode(['fresh' => $fresh, 'subclassed' => $subclassed])
         );
      }
      finally {
         if ($collectable) {
            gc_enable();
         }

         if ($Select !== null) {
            try {
               $Select->destroy();
            }
            catch (Throwable) {
               // Teardown only.
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }

         $Parked->setValue(null, $OldPool);
         $Contexts->setValue(null, $OldContexts);
         Response::$deferredTimeout = $oldTimeout;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         foreach (['Router' => $OldRouter, 'Request' => $OldAmbient, 'Response' => $OldAnswer] as $slot => $Previous) {
            if ($Previous !== null) {
               $WPI->{$slot} = $Previous;
            }
            else {
               unset($WPI->{$slot});
            }
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }

         // ! What the legs left behind is reclaimed here, not in a later case
         gc_collect_cycles();
      }
   })
);
