<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


return new Specification(
   description: 'It should cancel the expiration timer and release the Connection on close()',
   test: new Assertions(Case: function (): Generator {
      $Connection = null;
      $Client = null;
      $Accepted = null;
      $Listener = null;
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context)
         ? TCP_Server_CLI::$context
         : null;

      try {
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
         $Connections = new Connections($Server);
         TCP_Server_CLI::$Event = new Select($Connections);

         // ! A real established loopback pair — Connection requires a socket
         //   with a peer name (unix socketpairs have none and self-close on
         //   construct); explicit timeouts so a broken pair fails fast instead
         //   of blocking on default_socket_timeout
         $Listener = stream_socket_server('tcp://127.0.0.1:0');
         if ($Listener === false) {
            throw new RuntimeException('Could not create the loopback listener.');
         }
         $address = stream_socket_get_name($Listener, false);
         if ($address === false) {
            throw new RuntimeException('Could not resolve the loopback listener address.');
         }
         $Client = stream_socket_client("tcp://{$address}", $code, $message, 2.0);
         if ($Client === false) {
            throw new RuntimeException("Could not connect the loopback client: {$code} {$message}");
         }
         $Accepted = stream_socket_accept($Listener, 2.0);
         if ($Accepted === false) {
            throw new RuntimeException('Could not accept the loopback client.');
         }
         $peer = stream_socket_get_name($Accepted, true);
         if ($peer === false) {
            throw new RuntimeException('Could not resolve the accepted peer identity.');
         }
         [$IP, $port] = Peer::parse($peer);

         yield new Assertion(
            description: 'the loopback pair is established',
         )
            ->expect(
               $IP !== ''
               && $port > 0
            )
            ->to->be(true)
            ->assert();

         $TasksProperty = new ReflectionProperty(Timer::class, 'tasks');
         $Registered = static function (array $ids) use ($TasksProperty): bool {
            $tasks = $TasksProperty->getValue();
            if (! is_array($tasks)) {
               return false;
            }

            foreach ($tasks as $bucket) {
               if (! is_array($bucket)) {
                  return false;
               }

               foreach ($ids as $id) {
                  if (array_key_exists($id, $bucket)) {
                     return true;
                  }
               }
            }
            return false;
         };

         $Connection = new Connection($Accepted, $IP, $port);
         $ids = $Connection->timers;
         $baseline = TCP_Server_CLI::$pendingBytes;

         yield new Assertion(
            description: 'construct registers the persistent expiration timer',
         )
            ->expect($ids !== [] && $Registered($ids))
            ->to->be(true)
            ->assert();

         // ! Simulate already-admitted output ownership. close() must clear the
         //   self-cyclic Connection synchronously; waiting for GC would leave the
         //   worker budget and response wire pinned after every timeout/EOF.
         $Connection->pendingBuffer = str_repeat('P', 2048);
         $Connection->pendingResponses = [[
            'buffer' => str_repeat('Q', 512),
            'uploads' => [],
         ]];
         $Connection->uploading = [['file' => '/not-opened']];
         $Connection->stagedUploading = [['file' => '/not-opened-either']];
         $Connection->uploadAwaiting = true;
         $Connection->writeRegistered = true;
         $reserved = $Connection->Buffers->reserve(2560);

         yield new Assertion(
            description: 'the close fixture owns exact retained output before teardown',
         )
            ->expect(
               $reserved
               && $Connection->Buffers->retained === 2560
               && TCP_Server_CLI::$pendingBytes === $baseline + 2560
            )
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
            ->expect($Connection->timers === [] && $Registered($ids) === false)
            ->to->be(true)
            ->assert();

         yield new Assertion(
            description: 'close() synchronously clears every output owner and ledger byte',
         )
            ->expect(
               $Connection->pendingBuffer === ''
               && $Connection->pendingOffset === 0
               && $Connection->pendingResponses === []
               && $Connection->uploading === []
               && $Connection->stagedUploading === []
               && $Connection->uploadAwaiting === false
               && $Connection->writeRegistered === false
               && $Connection->Buffers->retained === 0
               && TCP_Server_CLI::$pendingBytes === $baseline
            )
            ->to->be(true)
            ->assert();

         $Connection->close();

         yield new Assertion(
            description: 'a duplicate close cannot credit the worker ledger twice',
         )
            ->expect(
               $Connection->Buffers->retained === 0
               && TCP_Server_CLI::$pendingBytes === $baseline
            )
            ->to->be(true)
            ->assert();

         $Weak = WeakReference::create($Connection);
         unset($Connection);
         $Connection = null;
         gc_collect_cycles();

         yield new Assertion(
            description: 'the closed Connection is released (no timer retention)',
         )
            ->expect($Weak->get() === null)
            ->to->be(true)
            ->assert();
      }
      finally {
         $Connection?->close();
         foreach ([$Accepted, $Client, $Listener] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
         Timer::del();
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }
   })
);
