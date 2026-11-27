<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Faults;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Suspects;


return new Test(
   description: 'It should share the budget among the addresses of a parked dial, and end it on a local fault',
   test: new Assertions(Case: function (): Generator {
      // ! A blackhole on 127.0.0.4 (a full accept queue drops new SYNs), the upstream on 127.0.0.2
      $Context = stream_context_create(['socket' => ['backlog' => 0]]);
      $Blackhole = @stream_socket_server('tcp://127.0.0.4:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Context);
      // ? A system with only 127.0.0.1 on its loopback (macOS without an alias)
      if ($Blackhole === false) {
         yield new Assertion(description: '127.0.0.4 cannot be bound: the parked walk is not measured')->skip();

         return;
      }
      $port = (int) explode(':', (string) stream_socket_get_name($Blackhole, false))[1];
      $Fill = stream_socket_client("tcp://127.0.0.4:{$port}", $code, $message, 1);
      $Server = @stream_socket_server("tcp://127.0.0.2:{$port}", $code, $message);
      if ($Server === false || $Fill === false) {
         yield new Assertion(description: "port {$port} is taken on 127.0.0.2: the parked walk is not measured")->skip();

         return;
      }

      // ! A client on an adopted reactor, parking through a wait bridge; a minimal driver waits
      //   on what the dial parks on until its deadline, then resumes it
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $dial = static function (Closure $Wait) use ($Host, $port): array {
         $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_EMBEDDED);
         $Client->react($Host->Event);
         $Client->schedule($Wait);
         $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port));
         $Client->connectTimeout = 3;
         $Client->Resolution = static fn (): array => ['127.0.0.4', '127.0.0.2'];

         $Socket = false;
         $parks = 0;
         $start = microtime(true);
         $Fiber = new Fiber(static function () use ($Client, &$Socket): void {
            $Socket = $Client->connect();
         });
         $value = $Fiber->start();
         // @@ Drive the parked dial
         while ($Fiber->isTerminated() === false) {
            $parks++;
            if ($value instanceof Readiness && is_resource($value->socket)) {
               $wait = max(0.0, $value->deadline - microtime(true));
               $read = [];
               $write = [];
               if ($value->flag === Scheduler::SCHEDULE_WRITE) {
                  $write = [$value->socket];
               }
               else {
                  $read = [$value->socket];
               }
               $except = null;
               @stream_select($read, $write, $except, (int) $wait, (int) (($wait - (int) $wait) * 1_000_000));
            }
            $value = $Fiber->resume();
         }

         return [
            'peer' => $Socket === false ? false : stream_socket_get_name($Socket, true),
            'elapsed' => microtime(true) - $start,
            'parks' => $parks,
            'errors' => $Client->Connections->errors['connection'],
            'Fault' => $Client->Connections->Fault,
         ];
      };

      // @ Parked: the silent address gets its share (2 of 3 seconds), the next one connects
      $result = $dial(static fn (mixed $value = null): mixed => Fiber::suspend($value));

      yield new Assertion(
         description: 'a parked dial gives a silent address its share, then connects to the next one',
         fallback: 'The parked walk did not share the budget: ' . json_encode([$result['peer'], round($result['elapsed'], 3), $result['parks'], $result['errors']])
      )
         ->expect([
            $result['peer'],
            $result['elapsed'] >= 1.9 && $result['elapsed'] < 2.9,
            $result['parks'] > 0,
            $result['errors'],
         ])
         ->to->be(["127.0.0.2:{$port}", true, true, 1])
         ->assert();

      yield new Assertion(
         description: 'the silent address of a parked dial became a suspect',
         fallback: 'A silent address was not marked on the parked path!'
      )
         ->expect(Suspects::split(['127.0.0.4', '127.0.0.2'], $port))
         ->to->be([['127.0.0.2'], ['127.0.0.4']])
         ->assert();
      Suspects::clear('127.0.0.4', $port);

      // @ A wait bridge that never suspends: a local fault, the walk ends at the first address
      $result = $dial(static fn (mixed $value = null): mixed => null);

      yield new Assertion(
         description: 'a local fault ends the walk: one error, no next address, no suspect',
         fallback: 'A local fault did not end the walk: ' . json_encode([$result['peer'], $result['errors'], $result['Fault']?->name, Suspects::split(['127.0.0.4'], $port)[1]])
      )
         ->expect([$result['peer'], $result['errors'], $result['Fault'], Suspects::split(['127.0.0.4', '127.0.0.2'], $port)[1]])
         ->to->be([false, 1, Faults::Local, []])
         ->assert();

      fclose($Server);
      fclose($Fill);
      fclose($Blackhole);
   })
);
