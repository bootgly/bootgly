<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Events.php';


/**
 * A Listener object (not a Closure) dispatched via handle().
 */
final class EventsCollectingListener implements Listener
{
   /** @var array<mixed> */
   public array $payload = [];

   public function handle (Emission $Emission): void
   {
      $this->payload = $Emission->payload;
   }
}


return new Specification(
   description: 'Emitter: a Listener object is dispatched through handle()',
   test: function () {
      $Emitter = new Emitter();

      $Listener = new EventsCollectingListener();
      $Emitter->listen(Events::Alpha, $Listener);

      $Emitter->emit(Events::Alpha, 'value', 7);

      yield assert(
         assertion: $Listener->payload === ['value', 7],
         description: 'Listener::handle() receives the Emission payload'
      );
   }
);
