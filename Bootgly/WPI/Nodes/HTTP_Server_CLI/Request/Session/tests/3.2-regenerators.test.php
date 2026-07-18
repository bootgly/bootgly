<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Regenerators;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Specification(
   description: 'Session Regenerators execute before stoppable application observers',
   test: function () {
      $PreviousEmitter = Emitter::$Instance;
      $PreviousHandler = Handler::$instance;
      $PreviousResponse = HTTP_Server_CLI::$Response ?? null;

      try {
         Emitter::$Instance = new Emitter;
         Handler::$instance = new Cache(new CacheResource([
            'driver' => 'file',
            'path' => sys_get_temp_dir() . '/bootgly-session-regenerators-' . uniqid(),
            'prefix' => 'session:',
         ]));
         HTTP_Server_CLI::$Response = new Response;

         $OwnerA = new stdClass;
         $OwnerB = new stdClass;
         $Failure = new RuntimeException('Session regenerator test failure');

         Regenerators::register(
            $OwnerA,
            static function (Session $Session): void {
               $Session->set('replaced-owner-callback', true);
            }
         );
         Regenerators::register(
            $OwnerA,
            static function (Session $Session) use ($Failure): void {
               $order = $Session->get('regenerator-order', []);
               $order[] = 'owner-a';
               $Session->set('regenerator-order', $order);

               if ($Session->get('regenerator-throw') === true) {
                  throw $Failure;
               }
            }
         );
         Regenerators::preserve(
            'session-regenerators-unit',
            static function (Session $Session): void {
               if ($Session->get('regenerator-unit') !== true) {
                  return;
               }

               $order = $Session->get('regenerator-order', []);
               $order[] = 'persistent';
               $Session->set('regenerator-order', $order);
            }
         );
         Regenerators::register(
            $OwnerB,
            static function (Session $Session): void {
               $order = $Session->get('regenerator-order', []);
               $order[] = 'owner-b';
               $Session->set('regenerator-order', $order);
            }
         );

         Emitter::$Instance->listen(
            Events::Regenerate,
            static function (Emission $Emission): void {
               $Session = $Emission->payload[2] ?? null;
               if ($Session instanceof Session) {
                  $order = $Session->get('regenerator-order', []);
                  $order[] = 'application-event';
                  $Session->set('regenerator-order', $order);
               }
               $Emission->stop();
            }
         );

         $Session = new Session(bin2hex(random_bytes(16)));
         $oldId = $Session->id;
         $Session->set('regenerator-unit', true);
         $Session->set('regenerator-throw', true);

         $Caught = null;
         try {
            $Session->regenerate();
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         yield assert(
            assertion: $Caught === $Failure
               && $Session->get('regenerator-order') === ['owner-a', 'persistent', 'owner-b'],
            description: 'later invariants complete before the first callback failure is rethrown'
         );

         $Session->set('regenerator-throw', false);
         $Session->set('regenerator-order', []);
         $Session->regenerate();

         yield assert(
            assertion: $Session->id !== $oldId
               && $Session->get('replaced-owner-callback') === null
               && $Session->get('regenerator-order') === [
                  'owner-a',
                  'persistent',
                  'owner-b',
                  'application-event',
               ],
            description: 'callbacks replace in place and precede ordinary application observers'
         );
      }
      finally {
         Emitter::$Instance = $PreviousEmitter;
         Handler::$instance = $PreviousHandler;
         if ($PreviousResponse instanceof Response) {
            HTTP_Server_CLI::$Response = $PreviousResponse;
         }
      }
   }
);
