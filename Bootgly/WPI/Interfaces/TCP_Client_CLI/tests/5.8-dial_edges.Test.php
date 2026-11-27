<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Faults;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Suspects;


return new Test(
   description: 'It should bound the lookup by the earlier deadline, end the walk when the budget expires, and blame no address for a local fault',
   test: new Assertions(Case: function (): Generator {
      // ! A blackhole on 127.0.0.4 (a full accept queue drops new SYNs), the upstream on 127.0.0.2
      $Context = stream_context_create(['socket' => ['backlog' => 0]]);
      $Blackhole = @stream_socket_server('tcp://127.0.0.4:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Context);
      // ? A system with only 127.0.0.1 on its loopback (macOS without an alias)
      if ($Blackhole === false) {
         yield new Assertion(description: '127.0.0.4 cannot be bound: the dial edges are not measured')->skip();

         return;
      }
      $port = (int) explode(':', (string) stream_socket_get_name($Blackhole, false))[1];
      $Fill = stream_socket_client("tcp://127.0.0.4:{$port}", $code, $message, 1);
      $Server = @stream_socket_server("tcp://127.0.0.2:{$port}", $code, $message);
      if ($Server === false || $Fill === false) {
         yield new Assertion(description: "port {$port} is taken on 127.0.0.2: the dial edges are not measured")->skip();

         return;
      }

      $answer = ['127.0.0.2'];
      $seen = null;
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port));
      $Client->Resolution = static function (string $host, null|Closure $Wait, null|int $deadline) use (&$answer, &$seen): array {
         $seen = $deadline;

         return $answer;
      };

      // @ The wall deadline is the earlier one: the lookup gets it, on the monotonic clock (the wall
      //   clock's microsecond float and a second clock read put it up to ~1 µs past 0.3 s)
      $Client->connectTimeout = 5;
      $Client->deadline = microtime(true) + 0.3;
      $before = (int) hrtime(true);
      $Socket = $Client->connect();
      $ahead = $seen === null ? null : $seen - $before;

      yield new Assertion(
         description: 'the lookup is bounded by the earlier of the deadline and the connect timeout',
         fallback: 'The lookup got another deadline: ' . json_encode([$ahead, $Socket !== false])
      )
         ->expect([$Socket !== false, $ahead !== null && $ahead > 200_000_000 && $ahead <= 301_000_000])
         ->to->be([true, true])
         ->assert();

      // @ A far or infinite wall deadline with no connect timeout: the lookup's deadline is the
      //   clock's last tick, never an overflowed one
      $Client->connectTimeout = 0;
      $results = [];
      foreach ([1e10, INF] as $far) {
         $Client->deadline = microtime(true) + $far;
         $Socket = @$Client->connect();
         $results[] = [$Socket !== false, $seen];
      }
      $Client->deadline = null;

      yield new Assertion(
         description: 'a far deadline lets the dial connect, its lookup bounded by the last tick',
         fallback: 'A far deadline failed the dial: ' . json_encode($results)
      )
         ->expect($results)
         ->to->be([[true, PHP_INT_MAX], [true, PHP_INT_MAX]])
         ->assert();

      // @ A connect timeout beyond the nanosecond range, on a literal and on a name
      $Client->connectTimeout = 1e10;
      $Socket = @$Client->connect();
      $Client->configure(new TCP_Client_CLI\Configs(host: '127.0.0.2', port: $port));
      $Literal = @$Client->connect();
      $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port));

      yield new Assertion(
         description: 'a connect timeout beyond the nanosecond range connects, by name and by literal',
         fallback: 'A huge connect timeout failed the dial: ' . json_encode([$Socket !== false, $Literal !== false, $Client->Connections->Fault?->name])
      )
         ->expect([$Socket !== false, $Literal !== false])
         ->to->be([true, true])
         ->assert();

      // @ The whole budget is the silent address's share: it expires, the walk ends there
      // @@ Drain the dials above from the upstream's queue: only this walk may reach it now
      while (@stream_socket_accept($Server, 0) !== false);
      $Client->connectTimeout = 1.5;
      $answer = ['127.0.0.4', '127.0.0.2'];
      $errors = $Client->Connections->errors['connection'];
      $start = microtime(true);
      $Socket = @$Client->connect();
      $elapsed = microtime(true) - $start;
      $walked = @stream_socket_accept($Server, 0.1) !== false;

      yield new Assertion(
         description: 'an expired budget ends the walk: one error, no next address, no suspect',
         fallback: 'The walk went on past an expired budget: ' . json_encode([$Socket !== false, round($elapsed, 3), $Client->Connections->errors['connection'] - $errors, $Client->Connections->Fault?->name, $walked])
      )
         ->expect([
            $Socket,
            $elapsed >= 1.4 && $elapsed < 2.2,
            $Client->Connections->errors['connection'] - $errors,
            $Client->Connections->Fault,
            $walked,
            Suspects::split(['127.0.0.4', '127.0.0.2'], $port)[1],
         ])
         ->to->be([false, true, 1, Faults::Expired, false, []])
         ->assert();

      // @ The process out of descriptors: no socket for any address, and none is blamed
      if (function_exists('posix_setrlimit') === false) {
         yield new Assertion(description: 'posix is unavailable: a dial out of descriptors is not measured')->skip();
      }
      else {
         $Client->connectTimeout = 2;
         $answer = ['127.0.0.3', '127.0.0.2'];
         $errors = $Client->Connections->errors['connection'];
         $limits = posix_getrlimit();
         assert(is_array($limits));
         $hard = $limits['hard openfiles'] === 'unlimited' ? -1 : (int) $limits['hard openfiles'];
         $soft = $limits['soft openfiles'] === 'unlimited' ? -1 : (int) $limits['soft openfiles'];
         $Held = [];
         try {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, 64, $hard);
            // @@ Spend every descriptor left
            while (($Handle = @fopen('/dev/null', 'r')) !== false) {
               $Held[] = $Handle;
            }
            $Socket = @$Client->connect();
         }
         finally {
            foreach ($Held as $Handle) {
               fclose($Handle);
            }
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard);
         }

         yield new Assertion(
            description: 'out of descriptors, every address fails at once and none becomes a suspect',
            fallback: 'A local fault was blamed on an address: ' . json_encode([$Socket !== false, $Client->Connections->errors['connection'] - $errors, Suspects::split(['127.0.0.3', '127.0.0.2'], $port)[1]])
         )
            ->expect([$Socket, $Client->Connections->errors['connection'] - $errors, Suspects::split(['127.0.0.3', '127.0.0.2'], $port)[1]])
            ->to->be([false, 2, []])
            ->assert();
      }

      fclose($Server);
      fclose($Fill);
      fclose($Blackhole);
   })
);
