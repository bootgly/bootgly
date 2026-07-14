<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


return new Specification(
   description: 'It should cancel the expiration timer and release the UDP Connection on close()',
   test: new Assertions(Case: function (): Generator {
      // ! Hermetic timer state — suites sharing this process may have left
      //   tasks behind
      Timer::del();

      // ! Boot the statics close() touches (typed statics start uninitialized)
      Connections::$Connections = [];
      Connections::$blacklist = [];
      // ! The expiration timer is gated by the stats flag on UDP
      Connections::$stats = true;

      // ! A real bound UDP socket — the server socket is shared by every
      //   peer; the Connection only borrows it
      $Socket = stream_socket_server(
         'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
      );

      yield new Assertion(
         description: 'the shared UDP socket is bound',
      )
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();

      $tasksProperty = new ReflectionProperty(Timer::class, 'tasks');
      $registered = static function (array $ids) use ($tasksProperty): bool {
         foreach ($tasksProperty->getValue() as $bucket) {
            foreach ($ids as $id) {
               if (array_key_exists($id, $bucket)) {
                  return true;
               }
            }
         }
         return false;
      };

      $Connection = new Connection($Socket, '127.0.0.1:53000');
      $ids = $Connection->timers;

      yield new Assertion(
         description: 'construct registers the persistent expiration timer',
      )
         ->expect($ids !== [] && $registered($ids))
         ->to->be(true)
         ->assert();

      $Connection->close();

      // @ The first close() transition must cancel the timer — the task map
      //   is a static GC root holding [$Connection, 'expire']: while the
      //   timer lives, __destruct() can never run and every closed peer
      //   Connection is retained for the worker lifetime
      yield new Assertion(
         description: 'close() cancels the expiration timer',
      )
         ->expect($Connection->timers === [] && $registered($ids) === false)
         ->to->be(true)
         ->assert();

      $Weak = WeakReference::create($Connection);
      unset($Connection);
      gc_collect_cycles();

      yield new Assertion(
         description: 'the closed UDP Connection is released (no timer retention)',
      )
         ->expect($Weak->get() === null)
         ->to->be(true)
         ->assert();

      // @ Cleanup
      if ($Socket !== false) {
         fclose($Socket);
      }
      Connections::$stats = false;
      Timer::del();
   })
);
