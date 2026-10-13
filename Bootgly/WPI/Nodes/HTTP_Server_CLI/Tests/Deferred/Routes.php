<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred;


use const GET;
use const POST;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function clearstatcache;
use function count;
use function fclose;
use function file_get_contents;
use function gc_collect_cycles;
use function is_file;
use function is_string;
use function microtime;
use function spl_object_id;
use function stream_set_blocking;
use function stream_socket_pair;
use Generator;
use RuntimeException;
use WeakReference;

use Bootgly\ACI\Events\Readiness;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * Worker-side routes shared by every deferred-work spec.
 *
 * Mounted from each spec's handler, so a side request that lands on another
 * spec's slot still routes. Every deferral here suspends once before it reads
 * the request or writes the Session: the synchronous cycle ends at that first
 * `wait()`, which is exactly when the live per-connection Request is scrubbed.
 */
final class Routes
{
   // * Metadata
   /**
    * The snapshot of the last `/deferred/weak` deferral, held weakly: it must
    * be gone once that deferral answered — a pooled Fiber must not keep it.
    *
    * @var null|WeakReference<Request>
    */
   private static null|WeakReference $Weak = null;


   /**
    * Mount every deferred-work route on the spec's Router.
    *
    * @return Generator<int,mixed>
    */
   public static function mount (Router $Router): Generator
   {
      yield $Router->route('/deferred/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
      // @ Which Request object served this request
      yield $Router->route('/deferred/identify', static function (Request $Request, Response $Response) {
         $Response->JSON->send(['id' => spl_object_id($Request)]);

         return $Response;
      }, GET);

      // @ The request handed to the work as its second argument after the
      //   first wait(): the snapshot `defer()` captured for this generation
      //   (the same object as `$Response->Request`)
      yield $Router->route('/deferred/fields/:id', static function (Request $Request, Response $Response) use ($Router) {
         return $Response->defer(static function (Response $Response, Request $Snapshot) use ($Router): void {
            $Response->wait();
            $Response->JSON->send([
               'snapshot' => (array) $Snapshot->fields,
               'same' => $Snapshot === $Response->Request,
               'method' => $Snapshot->method,
               'params' => (string) ($Router->Route->Params->id ?? '')
            ]);
         });
      }, POST);

      // @ The upload map and the admitted credentials the snapshot carries:
      //   header-derived Basic credentials AND the values a middleware wrote
      //   by hand, which no header can re-derive after the cycle ends
      yield $Router->route('/deferred/credentials', static function (Request $Request, Response $Response) {
         // ! Middleware-style admission: nothing on the wire backs these.
         $Request->token = 'INJECTED-TOKEN';
         $Request->tokenHeaders = ['x-fixture' => 'injected-header'];

         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            $Response->wait();
            $filename = $Snapshot->files['upload']['name'] ?? '';
            // ! CUSTODY: the temp path the snapshot owns must still be
            //   readable — the live Request must NOT have purged it when the
            //   synchronous cycle ended.
            $tmp = $Snapshot->files['upload']['tmp_name'] ?? '';
            $tmp = is_string($tmp) ? $tmp : '';
            clearstatcache();
            $stored = $tmp !== '' && is_file($tmp);
            $Response->JSON->send([
               'username' => $Snapshot->username,
               'password' => $Snapshot->password,
               'token' => $Snapshot->token,
               'headers' => $Snapshot->tokenHeaders,
               'files' => count($Snapshot->files),
               'filename' => is_string($filename) ? $filename : '',
               'stored' => $stored,
               'bytes' => $stored ? (string) file_get_contents($tmp) : ''
            ]);
         });
      }, POST);

      // @ A closure that declared an OPTIONAL second parameter of another
      //   type before BG-15 keeps its default: the snapshot is handed only to
      //   a second parameter that accepts a Request
      yield $Router->route('/deferred/optional', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, int $attempt = 0): void {
            $Response->wait();
            $Response->JSON->send(['attempt' => $attempt]);
         });
      }, GET);

      // @ A Session write BEFORE a nested defer(): persisted at the handoff
      //   itself — the child owns the answer, and may never give one
      yield $Router->route('/deferred/session/nested/leave', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Outer, Request $Snapshot): void {
            $Outer->wait();
            $Session = $Snapshot->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('nested', 'yes');
            $Outer->defer(static function (Response $Child): void {
               self::park($Child, 10.0);
               $Child->JSON->send(['left' => false]);
            });
         });
      }, GET);

      // @ A nested defer() FIRST, then a Session write inside the child that
      //   answers: persisted by the child's own save point
      yield $Router->route('/deferred/session/nested/after', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Outer, Request $Snapshot): void {
            $Outer->wait();
            $Outer->defer(static function (Response $Child, Request $ChildSnapshot): void {
               $Child->wait();
               $Session = $ChildSnapshot->Session;
               if ($Session === null) {
                  throw new RuntimeException('Deferred fixture lost the session snapshot.');
               }
               $Session->set('after', 'yes');
               $Child->JSON->send(['after' => true]);
            });
         });
      }, GET);

      // @ The live per-connection Request is REUSED while this deferral parks:
      //   a second request on the same keep-alive connection is decoded into
      //   it and, once answered, its head is dropped — so a `use ($Request)`
      //   describes no request at all
      yield $Router->route('/deferred/reuse', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot) use ($Request): void {
            self::park($Response, 0.6);
            $Response->JSON->send([
               'snapshot' => $Snapshot->URI,
               'live' => $Request->URI,
               'live_queries' => $Request->queries,
               'live_id' => spl_object_id($Request)
            ]);
         });
      }, GET);

      // @ The snapshot's own head after the live Request dropped its copy:
      //   headers, cookies, URI and query of the request the work answers
      yield $Router->route('/deferred/head', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            self::park($Response, 0.6);
            $Response->JSON->send([
               'echo' => $Snapshot->Header->get('X-Echo'),
               'cookie' => $Snapshot->Cookies->get('who'),
               'URI' => $Snapshot->URI,
               'query' => $Snapshot->queries['q'] ?? null
            ]);
         });
      }, GET);
      // @ The live per-connection Request once the deferral returned, with no
      //   later request on the connection: its head is already dropped
      yield $Router->route('/deferred/live', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response) use ($Request): void {
            self::park($Response, 0.3);
            $Response->JSON->send([
               'live' => true,
               'echo' => $Request->Header->get('X-Echo'),
               'URI' => $Request->URI
            ]);
         });
      }, GET);
      // @ A deferral that answers; its snapshot must not outlive it
      yield $Router->route('/deferred/weak', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            self::$Weak = WeakReference::create($Snapshot);
            self::park($Response, 0.1);
            $Response->JSON->send(['answered' => true]);
         });
      }, GET);
      yield $Router->route('/deferred/weak/check', static function (Request $Request, Response $Response) {
         gc_collect_cycles();
         $Response->JSON->send([
            'created' => self::$Weak !== null,
            'alive' => self::$Weak?->get() !== null
         ]);

         return $Response;
      }, GET);
      // @ The same reads on a synchronous request
      yield $Router->route('/deferred/echo', static function (Request $Request, Response $Response) {
         $Response->JSON->send([
            'echo' => $Request->Header->get('X-Echo'),
            'cookie' => $Request->Cookies->get('who'),
            'URI' => $Request->URI,
            'query' => $Request->queries['q'] ?? null
         ]);

         return $Response;
      }, GET);
      // @ An event stream mounted in a SYNCHRONOUS route: its ticks run after
      //   the response head was written and read the route's live Request
      //   (`use ($Request)`) — SSE takes no snapshot, so that Request keeps
      //   its head while the stream runs (`Request::pin()`)
      yield $Router->route('/deferred/sse/live', static function (Request $Request, Response $Response) {
         $ticks = 0;
         $SSE = $Response->SSE;
         $SSE->heartbeat = 0;
         $SSE->open(
            Tick: static function (SSE $SSE) use ($Request, &$ticks): void {
               $ticks++;
               $SSE->send([
                  'tick' => $ticks,
                  'URI' => $Request->URI,
                  'query' => $Request->queries['q'] ?? null,
                  'echo' => $Request->Header->get('X-Echo'),
                  'cookie' => $Request->Cookies->get('who'),
                  'token' => $Request->token
               ]);
               // ? Two ticks, one interval apart, then the stream ends
               if ($ticks >= 2) {
                  $SSE->close();
               }
            },
            interval: 1
         );

         return $Response;
      }, GET);

      // @ The request the ROUTE received, captured by `use ()`: the live
      //   per-connection object the encoder scrubs once the cycle ends
      yield $Router->route('/deferred/outer/:id', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response) use ($Request): void {
            $Response->wait();
            $Response->JSON->send([
               'outer' => (array) $Request->fields
            ]);
         });
      }, POST);

      // @ Synchronous seed: establishes the session (and its cookie) with no
      //   deferral involved
      yield $Router->route('/deferred/session/seed', static function (Request $Request, Response $Response) {
         $Session = $Request->Session;
         if ($Session === null) {
            throw new RuntimeException('Deferred fixture could not build the session.');
         }
         $Session->set('sync', 'seed');
         $Response->JSON->send(['seeded' => true]);

         return $Response;
      }, GET);

      // @ A Session write after the first wait() — the synchronous cycle
      //   already persisted the session before this work ran
      yield $Router->route('/deferred/session/write', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Session = $Response->Request?->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('deferred', 'yes');
            $Response->JSON->send(['written' => true]);
         });
      }, GET);

      // @ A Session write after the first wait(), then the work throws: the
      //   error response must still carry the persisted write
      yield $Router->route('/deferred/session/throw', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Session = $Response->Request?->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('errored', 'yes');
            throw new RuntimeException('deferred-throw');
         });
      }, GET);

      // @ A Session write after the first wait(), then a handoff to SSE: the
      //   generation settles from inside the work, before the deferral's own
      //   pre-encode save point
      yield $Router->route('/deferred/session/sse', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            $Response->wait();
            $Session = $Snapshot->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('sse', 'yes');
            $SSE = $Response->SSE;
            $SSE->open();
            $SSE->send(['sse' => true]);
            $SSE->close();
         });
      }, GET);

      // @ A Session write after the first wait(), then a park the client
      //   abandons: the generation is cancelled, never answered
      yield $Router->route('/deferred/session/leave', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            $Response->wait();
            $Session = $Snapshot->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('left', 'yes');
            self::park($Response, 10.0);
            $Response->JSON->send(['left' => false]);
         });
      }, GET);

      // @ Synchronous read-back of whatever the previous requests persisted
      yield $Router->route('/deferred/session/read', static function (Request $Request, Response $Response) {
         $Session = $Request->Session;
         if ($Session === null) {
            throw new RuntimeException('Deferred fixture could not build the session.');
         }
         $Response->JSON->send([
            'sync' => $Session->get('sync'),
            'deferred' => $Session->get('deferred'),
            'errored' => $Session->get('errored'),
            'sse' => $Session->get('sse'),
            'left' => $Session->get('left'),
            'nested' => $Session->get('nested'),
            'after' => $Session->get('after'),
            'recovered' => $Session->get('recovered')
         ]);

         return $Response;
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   }

   /**
    * Park the deferral on a socket pair that never turns readable.
    */
   public static function park (Response $Response, float $seconds): void
   {
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         throw new RuntimeException('Deferred fixture could not allocate a socket pair.');
      }
      [$Never, $Hold] = $Pair;
      stream_set_blocking($Never, false);
      try {
         $Response->wait(Readiness::read($Never, microtime(true) + $seconds));
      }
      finally {
         fclose($Never);
         fclose($Hold);
      }
   }
}
