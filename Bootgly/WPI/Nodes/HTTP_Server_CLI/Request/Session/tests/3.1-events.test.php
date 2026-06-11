<?php

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


return new Specification(
   description: 'Session\Events: Start fires on construct; Regenerate/Destroy dispatch as Event identities',
   test: function () {
      // ! Fresh bus — Session emits through the shared Emitter::$Instance
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      // ! Real (file) session handler so the constructor can read/establish
      $dir = sys_get_temp_dir() . '/bootgly-session-events-' . uniqid();
      Handler::$instance = new Cache(new CacheResource([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 'session:',
      ]));

      $events = [];
      $Emitter->listen(Events::Start, function (Emission $Emission) use (&$events) {
         $events[] = ['start', $Emission->payload];
      });
      $Emitter->listen(Events::Regenerate, function (Emission $Emission) use (&$events) {
         $events[] = ['regenerate', $Emission->payload];
      });
      $Emitter->listen(Events::Destroy, function (Emission $Emission) use (&$events) {
         $events[] = ['destroy', $Emission->payload];
      });

      // @ Start — fired by the real Session constructor
      $id = bin2hex(random_bytes(16));
      $Session = new Session($id);
      yield assert(
         assertion: $events === [['start', [$id]]],
         description: 'Session.Start fires once on construct with [id]'
      );

      // @ Regenerate / Destroy — Event identities reach their listeners with payload
      $Emitter->emit(Events::Regenerate, 'old', 'new');
      $Emitter->emit(Events::Destroy, $id);
      yield assert(
         assertion: $events === [
            ['start', [$id]],
            ['regenerate', ['old', 'new']],
            ['destroy', [$id]],
         ],
         description: 'Regenerate/Destroy dispatch to their listeners with payload'
      );

      yield assert(
         assertion: Events::Start instanceof Event && Events::Destroy instanceof Event,
         description: 'Session events implement Bootgly\ABI\Event'
      );

      // ! Restore shared state for any later suite
      Emitter::$Instance = new Emitter();
      Handler::$instance = null;
   }
);
