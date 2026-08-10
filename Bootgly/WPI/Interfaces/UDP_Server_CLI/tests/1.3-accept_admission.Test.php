<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


return new Test(
   description: 'It should admit a new peer without touching the overloaded Socket by reference and reject blacklisted peers before allocating',
   test: new Assertions(Case: function (): Generator {
      $Socket = null;
      $segments = Display::$segments;
      Display::show(Display::NONE);

      try {
         // ! Hermetic timer state — earlier suites in this process may have
         //   left tasks behind
         Timer::del();

         // ! A real master in Test mode (no fork, no bind — proven by 1.2)
         //   whose protected Socket is injected with a real bound UDP socket:
         //   accept() reads it through __get()
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(host: '127.0.0.1', port: 19997, workers: 1);

         $Socket = stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );

         yield new Assertion(
            description: 'the shared UDP server socket is bound',
         )
            ->expect($Socket !== false)
            ->to->be(true)
            ->assert();
         if ($Socket === false) {
            return;
         }

         $SocketProperty = new ReflectionProperty($Server, 'Socket');
         $SocketProperty->setValue($Server, $Socket);

         // ! Fresh registry/blacklist/stats — the Connections constructor
         //   resets every shared static ($stats defaults to true, so an
         //   admitted peer arms its persistent expire() timer)
         $Connections = new Connections($Server);

         $tasksProperty = new ReflectionProperty(Timer::class, 'tasks');

         // # Case A — first datagram from a brand-new, admitted peer
         $timers = count($tasksProperty->getValue());
         $thrown = null;
         $Connection = null;
         try {
            $Connection = $Connections->accept('127.0.0.1:45678');
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         $observed = [
            'thrown' => $thrown,
            'connection' => $Connection instanceof Connection,
            'registered' => isSet(Connections::$Connections['127.0.0.1:45678']),
            'timers' => count($tasksProperty->getValue()) - $timers,
         ];

         yield new Assertion(
            description: 'a new peer is admitted: no escalated notice, a'
               . ' registered Connection and its expire() timer armed',
            fallback: 'accept() blew up or admitted nothing: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'thrown' => null,
                  'connection' => true,
                  'registered' => true,
                  'timers' => 1,
               ],
            )
            ->assert();

         // # Case B — a blacklisted peer must be rejected BEFORE any
         //   allocation: no Connection, no timer residue in Timer::$tasks
         Connections::$blacklist['127.0.0.2'] = true;
         $timers = count($tasksProperty->getValue());
         $errors = Connections::$errors['connection'];
         $thrown = null;
         $Rejected = false;
         try {
            $Rejected = $Connections->accept('127.0.0.2:1234');
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         $observed = [
            'thrown' => $thrown,
            'rejected' => $Rejected,
            'errors' => Connections::$errors['connection'] - $errors,
            'timers' => count($tasksProperty->getValue()) - $timers,
            'registered' => isSet(Connections::$Connections['127.0.0.2:1234']),
         ];

         yield new Assertion(
            description: 'a blacklisted peer is rejected with the error'
               . ' accounted and zero allocation residue',
            fallback: 'The rejection leaked state or threw: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'thrown' => null,
                  'rejected' => null,
                  'errors' => 1,
                  'timers' => 0,
                  'registered' => false,
               ],
            )
            ->assert();
      }
      finally {
         // @ Cleanup
         foreach (Connections::$Connections as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         Timer::del();

         if ($Socket !== null && $Socket !== false) {
            @fclose($Socket);
         }

         Display::show($segments);
      }
   })
);
