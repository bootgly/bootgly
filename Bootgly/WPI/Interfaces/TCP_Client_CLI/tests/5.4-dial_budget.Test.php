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
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Suspects;


return new Test(
   description: 'It should spend one budget on the lookup and the dial, sharing it among the addresses',
   test: new Assertions(Case: function (): Generator {
      // ! A blackhole on 127.0.0.4: a listener whose accept queue is full drops new SYNs
      $Context = stream_context_create(['socket' => ['backlog' => 0]]);
      $Blackhole = @stream_socket_server('tcp://127.0.0.4:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Context);
      // ? A system with only 127.0.0.1 on its loopback (macOS without an alias)
      if ($Blackhole === false) {
         yield new Assertion(description: '127.0.0.4 cannot be bound: the shared budget is not measured')->skip();

         return;
      }
      $port = (int) explode(':', (string) stream_socket_get_name($Blackhole, false))[1];
      $Fill = stream_socket_client("tcp://127.0.0.4:{$port}", $code, $message, 1);
      // ! The upstream on 127.0.0.2, same port
      $Server = @stream_socket_server("tcp://127.0.0.2:{$port}", $code, $message);
      if ($Server === false || $Fill === false) {
         yield new Assertion(description: "port {$port} is taken on 127.0.0.2: the shared budget is not measured")->skip();

         return;
      }

      $answer = ['127.0.0.4', '127.0.0.2'];
      $sleep = 0;
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port));
      $Client->Resolution = static function () use (&$answer, &$sleep): array {
         if ($sleep > 0) {
            usleep($sleep);
         }

         return $answer;
      };

      // @ Two addresses, 3 seconds: the silent one gets 2 (the floor), the next one the rest
      $Client->connectTimeout = 3;
      $start = microtime(true);
      $Socket = $Client->connect();
      $elapsed = microtime(true) - $start;
      $peer = $Socket === false ? false : stream_socket_get_name($Socket, true);

      yield new Assertion(
         description: 'a silent address gets its share, then the next address connects inside the budget',
         fallback: 'The silent address took the whole budget: ' . json_encode([$peer, round($elapsed, 3)])
      )
         ->expect($peer === "127.0.0.2:{$port}" && $elapsed >= 1.9 && $elapsed < 2.9)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'the silent address became a suspect',
         fallback: 'A silent address was not marked!'
      )
         ->expect(Suspects::split(['127.0.0.4'], $port)[1])
         ->to->be(['127.0.0.4'])
         ->assert();
      Suspects::clear('127.0.0.4', $port);

      // @ The lookup spends the same budget as the dial
      $Client->connectTimeout = 0.5;
      $answer = ['127.0.0.4'];
      $sleep = 400_000;
      $start = microtime(true);
      $Socket = $Client->connect();
      $elapsed = microtime(true) - $start;

      yield new Assertion(
         description: 'the budget is taken before the lookup: lookup and dial end within it',
         fallback: 'The dial got a fresh budget after the lookup: ' . json_encode([$Socket !== false, round($elapsed, 3)])
      )
         ->expect($Socket === false && $elapsed < 0.9)
         ->to->be(true)
         ->assert();

      // @ A lookup that spends the whole budget ends the dial without dialing (a fresh client: no
      //   connection stage ran, so no fault is recorded)
      $Spent = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Spent->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port));
      $Spent->connectTimeout = 0.5;
      $Spent->Resolution = $Client->Resolution;
      $sleep = 600_000;
      $start = microtime(true);
      $Socket = $Spent->connect();
      $elapsed = microtime(true) - $start;

      yield new Assertion(
         description: 'a lookup past the budget fails the dial at once, counted once, no address dialed',
         fallback: 'The dial went on past an expired budget: ' . json_encode([round($elapsed, 3), $Spent->Connections->errors['connection'], $Spent->Connections->Fault?->name])
      )
         ->expect([$Socket, $elapsed < 0.9, $Spent->Connections->errors['connection'], $Spent->Connections->Fault])
         ->to->be([false, true, 1, null])
         ->assert();
      Suspects::clear('127.0.0.4', $port);

      // @ The families alternate, starting with the first address's (RFC 8305)
      $Interleave = new ReflectionMethod(TCP_Client_CLI::class, 'interleave');

      yield new Assertion(
         description: 'the address families alternate',
         fallback: 'The families were not interleaved!'
      )
         ->expect([
            $Interleave->invoke($Client, ['2001:db8::1', '2001:db8::2', '2001:db8::3', '192.0.2.1']),
            $Interleave->invoke($Client, ['192.0.2.1', '192.0.2.2', '2001:db8::1']),
            $Interleave->invoke($Client, []),
         ])
         ->to->be([
            ['2001:db8::1', '192.0.2.1', '2001:db8::2', '2001:db8::3'],
            ['192.0.2.1', '2001:db8::1', '192.0.2.2'],
            [],
         ])
         ->assert();

      fclose($Server);
      fclose($Fill);
      fclose($Blackhole);
   })
);
