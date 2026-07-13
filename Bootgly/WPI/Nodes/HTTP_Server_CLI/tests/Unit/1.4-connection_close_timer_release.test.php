<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


return new Specification(
   description: 'It should cancel the expiration timer and release the Connection on close()',
   test: new Assertions(Case: function (): Generator {
      // ! Hermetic timer state — suites sharing this process may have left
      //   tasks behind (same defensive pattern as 1.1/1.2)
      Timer::del();

      // ! Hermetic server statics — a TLS suite that ran earlier in this
      //   process leaves $context['ssl'] set, which would make the plain
      //   loopback Connection below block ~60s in a doomed TLS handshake
      //   and close before registering its timer
      TCP_Server_CLI::$context = [];

      // ! Boot the statics Connection->close() needs (no event loop run)
      $Server = new TCP_Server_CLI;
      TCP_Server_CLI::$Event = new Select(new Connections($Server));

      // ! A real established loopback pair — Connection requires a socket
      //   with a peer name (unix socketpairs have none and self-close on
      //   construct); explicit timeouts so a broken pair fails fast instead
      //   of blocking on default_socket_timeout
      $Listener = stream_socket_server('tcp://127.0.0.1:0');
      $address = stream_socket_get_name($Listener, false);
      $Client = stream_socket_client("tcp://{$address}", $code, $message, 2.0);
      $Accepted = $Client !== false ? stream_socket_accept($Listener, 2.0) : false;

      yield new Assertion(
         description: 'the loopback pair is established',
      )
         ->expect($Client !== false && $Accepted !== false)
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

      $Connection = new Connection($Accepted);
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
      //   timer lives, __destruct() can never run and every closed
      //   connection is retained for the worker lifetime
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
         description: 'the closed Connection is released (no timer retention)',
      )
         ->expect($Weak->get() === null)
         ->to->be(true)
         ->assert();

      // @ Cleanup
      if ($Client !== false) {
         fclose($Client);
      }
      fclose($Listener);
      Timer::del();
   })
);
