<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle;


use const GET;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function count;
use function fclose;
use function microtime;
use function spl_object_id;
use function stream_set_blocking;
use function stream_socket_pair;
use Fiber;
use Generator;
use ReflectionProperty;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Events\Readiness;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * Worker-side routes shared by every idle-reaper spec.
 *
 * Mounted from each spec's handler, so a side request that lands on another
 * spec's slot still routes. The evidence lives on a static: the suite runs one
 * worker, and `/idle/report` reads back what a parked deferral left behind.
 */
final class Routes
{
   // * Data
   // # Timeout evidence (/idle/timeout/per-call)
   public static null|int|float $caught = null;
   public static bool $settled = false;
   // # Abandoned-park evidence (/idle/leave)
   public static int $parked = 0;
   public static int $resumed = 0;
   public static int $abandoned = 0;
   public static int $released = 0;
   /** @var array<int,int> */
   public static array $leaves = [];
   // # Pooled-Fiber evidence (/idle/quick)
   /** @var array<int,int> */
   public static array $quicks = [];


   /**
    * Mount every idle-reaper route on the spec's Router.
    *
    * @return Generator<int,mixed>
    */
   public static function mount (Router $Router): Generator
   {
      yield $Router->route('/idle/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);

      // @ A deferral parked on a socket that never turns readable — the
      //   shape the idle reaper used to cut at 15 s
      yield $Router->route('/idle/park', static function (Request $Request, Response $Response) {
         $seconds = (float) ($Request->queries['seconds'] ?? 1);
         $protocol = $Request->protocol;

         return $Response->defer(static function (Response $Response) use ($seconds, $protocol): void {
            $started = microtime(true);
            self::park($Response, $seconds);
            $Response->JSON->send([
               'started' => $started,
               'finished' => microtime(true),
               'protocol' => $protocol
            ]);
         });
      }, GET);

      // @ The server-wide budget — read by defer() at arming time
      yield $Router->route('/idle/timeout/global', static function (Request $Request, Response $Response) {
         Response::$deferredTimeout = 1;
         try {
            return $Response->defer(static function (Response $Response): void {
               self::park($Response, 10.0);
               $Response->JSON->send(['parked' => 10]);
            });
         }
         finally {
            Response::$deferredTimeout = 0;
         }
      }, GET);

      // @ The per-call budget; the app observes the timeout and lets it go
      yield $Router->route('/idle/timeout/per-call', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            try {
               self::park($Response, 10.0);
               $Response->JSON->send(['parked' => 10]);
            }
            catch (Timeout $Timeout) {
               self::$caught = $Timeout->timeout;
               throw $Timeout;
            }
            finally {
               self::$settled = true;
            }
         }, timeout: 1);
      }, GET);

      // @ The per-call budget; the app answers itself
      yield $Router->route('/idle/timeout/handled', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            try {
               self::park($Response, 10.0);
               $Response->JSON->send(['parked' => 10]);
            }
            catch (Timeout) {
               $Response(code: 202, body: 'handled');
            }
         }, timeout: 1);
      }, GET);

      // @ A deferral the client abandons mid-park: never resumed, its finally
      //   must still run promptly — and its catch never
      yield $Router->route('/idle/leave', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            // ! Record the Fiber without keeping it: a local held across the
            //   park would pin the Fiber to its own stack (see Response::wait)
            $Current = Fiber::getCurrent();
            self::$leaves[] = $Current === null ? 0 : spl_object_id($Current);
            unset($Current);
            try {
               self::$parked++;
               self::park($Response, 10.0);
               self::$resumed++;
               $Response->JSON->send(['left' => false]);
            }
            catch (Throwable) {
               self::$abandoned++;
            }
            finally {
               self::$released++;
            }
         });
      }, GET);

      // @ A deferral that answers at once — its Fiber returns to the pool
      yield $Router->route('/idle/quick', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Current = Fiber::getCurrent();
            self::$quicks[] = $Current === null ? 0 : spl_object_id($Current);
            unset($Current);
            $Response->JSON->send(['quick' => true]);
         });
      }, GET);

      // @ A budgeted deferral that completes in time — its deadline must be
      //   armed while it parks and disarmed once it settles
      yield $Router->route('/idle/budgeted', static function (Request $Request, Response $Response) {
         $seconds = (float) ($Request->queries['seconds'] ?? 1);

         return $Response->defer(static function (Response $Response) use ($seconds): void {
            self::park($Response, $seconds);
            $Response->JSON->send(['budgeted' => $seconds]);
         }, timeout: 30);
      }, GET);

      // @ The worker reactor's one-shot timers (monotonic + wall), by count
      yield $Router->route('/idle/timers', static function (Request $Request, Response $Response) {
         $Event = TCP_Server_CLI::$Event;
         $monotonic = 0;
         $wall = 0;
         if ($Event instanceof Select) {
            $monotonic = count((array) (new ReflectionProperty(Select::class, 'MonotonicTimers'))->getValue($Event));
            $wall = count((array) (new ReflectionProperty(Select::class, 'Timers'))->getValue($Event));
         }
         $Response->JSON->send(['monotonic' => $monotonic, 'wall' => $wall]);

         return $Response;
      }, GET);

      yield $Router->route('/idle/report', static function (Request $Request, Response $Response) {
         $Response->JSON->send([
            'caught' => self::$caught,
            'finally' => self::$settled,
            'leave' => [
               'parked' => self::$parked,
               'resumed' => self::$resumed,
               'caught' => self::$abandoned,
               'finally' => self::$released,
               'fibers' => self::$leaves
            ],
            'quick' => ['fibers' => self::$quicks]
         ]);

         return $Response;
      }, GET);

      yield $Router->route('/idle/reset', static function (Request $Request, Response $Response) {
         self::$caught = null;
         self::$settled = false;
         self::$parked = 0;
         self::$resumed = 0;
         self::$abandoned = 0;
         self::$released = 0;
         self::$leaves = [];
         self::$quicks = [];

         return $Response(body: 'reset');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   }

   /**
    * Park the deferral on a socket that never turns readable.
    */
   private static function park (Response $Response, float $seconds): void
   {
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         throw new RuntimeException('Idle fixture could not allocate a socket pair.');
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
