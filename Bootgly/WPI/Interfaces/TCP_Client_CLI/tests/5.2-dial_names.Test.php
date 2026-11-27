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


return new Test(
   description: 'It should resolve a name through the seam and dial the address it returns',
   test: new Assertions(Case: function (): Generator {
      // ! The upstream listens on 127.0.0.2 only: dialing `localhost` by name never reaches it
      $Server = @stream_socket_server('tcp://127.0.0.2:0', $code, $message);
      // ? A system with only 127.0.0.1 on its loopback (macOS without an alias)
      if ($Server === false) {
         yield new Assertion(description: '127.0.0.2 cannot be bound: the dial by name is not measured')->skip();

         return;
      }
      $port = (int) explode(':', (string) stream_socket_get_name($Server, false))[1];

      $calls = [];
      $answer = ['127.0.0.2'];
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Client->configure(new TCP_Client_CLI\Configs(host: 'localhost', port: $port));
      $Client->connectTimeout = 2;
      $Client->Resolution = static function (string $host, null|Closure $Wait, null|int $deadline, null|object $Reactor) use (&$calls, &$answer): array {
         $calls[] = ['host' => $host, 'Wait' => $Wait, 'deadline' => $deadline, 'Reactor' => $Reactor];

         return $answer;
      };

      // @ Dial by name
      $before = (int) hrtime(true);
      $Socket = $Client->connect();
      $peer = $Socket === false ? false : stream_socket_get_name($Socket, true);

      yield new Assertion(
         description: 'the dial reaches the address the seam returned, not the name',
         fallback: "The dial did not reach 127.0.0.2:{$port}: " . json_encode($peer)
      )
         ->expect($peer)
         ->to->be("127.0.0.2:{$port}")
         ->assert();

      $Call = $calls[0] ?? [];
      yield new Assertion(
         description: 'the seam gets the configured name, the dial budget and the client reactor — no wait bridge for an owned client',
         fallback: 'The seam arguments changed: ' . json_encode([count($calls), $Call['host'] ?? null, $Call['deadline'] ?? null, $before])
      )
         ->expect(
            count($calls) === 1
            && $Call['host'] === 'localhost'
            && $Call['Wait'] === null
            && $Call['Reactor'] === $Client->Event
            && $Call['deadline'] > $before
            && $Call['deadline'] <= $before + 2_000_000_000 + 50_000_000
         )
         ->to->be(true)
         ->assert();

      // @ Only IP literals from the answer are dialed, each once
      $calls = [];
      $answer = ["127.0.0.2:{$port}/", 'localhost', '', '127.0.0.2', '127.0.0.2'];
      $errors = $Client->Connections->errors['connection'];
      $Socket = $Client->connect();
      $peer = $Socket === false ? false : stream_socket_get_name($Socket, true);

      yield new Assertion(
         description: 'entries that are not IP literals are dropped, duplicates dialed once',
         fallback: 'A malformed answer entry was dialed: ' . json_encode([$peer, $Client->Connections->errors['connection'] - $errors])
      )
         ->expect([$peer, $Client->Connections->errors['connection'] - $errors])
         ->to->be(["127.0.0.2:{$port}", 0])
         ->assert();

      // @ An answer with no literal fails the dial at once, counted once
      $answer = ['localhost', 'not an address'];
      $errors = $Client->Connections->errors['connection'];
      $Socket = @$Client->connect();

      yield new Assertion(
         description: 'an answer without addresses fails the dial, counted once',
         fallback: 'An empty answer was dialed or miscounted!'
      )
         ->expect([$Socket, $Client->Connections->errors['connection'] - $errors])
         ->to->be([false, 1])
         ->assert();

      // @ The wait bridge reaches the seam only where the dial itself parks: an adopted
      //   reactor, a bridge, a Fiber
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      $Gate = static function (TCP_Client_CLI $Dialer, bool $fiber) use (&$calls, &$answer): array {
         $calls = [];
         $answer = [];
         if ($fiber) {
            new Fiber(static function () use ($Dialer): void {
               @$Dialer->connect();
            })->start();
         }
         else {
            @$Dialer->connect();
         }
         $Call = $calls[0] ?? [];

         return $Call === [] ? ['none', 'none'] : [$Call['Wait'], $Call['Reactor']];
      };
      $Owned = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Owned->configure(new TCP_Client_CLI\Configs(host: 'localhost', port: $port));
      $Owned->Resolution = $Client->Resolution;
      $Owned->schedule($Bridge);
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Adopted = new TCP_Client_CLI(TCP_Client_CLI::MODE_EMBEDDED);
      $Adopted->react($Host->Event);
      $Adopted->schedule($Bridge);
      $Adopted->configure(new TCP_Client_CLI\Configs(host: 'localhost', port: $port));
      $Adopted->Resolution = $Client->Resolution;

      yield new Assertion(
         description: 'the seam gets the bridge only on an adopted reactor, inside a Fiber, with that reactor',
         fallback: 'A lookup could park where the dial cannot!'
      )
         ->expect([
            $Gate($Owned, true)[0] === null,
            $Gate($Adopted, false)[0] === null,
            $Gate($Adopted, true) === [$Bridge, $Host->Event],
         ])
         ->to->be([true, true, true])
         ->assert();
      $answer = ['127.0.0.2'];

      // @ An IPv6 answer is dialed in brackets
      $V6 = @stream_socket_server('tcp://[::1]:0', $code, $message);
      if ($V6 === false) {
         yield new Assertion(description: 'IPv6 loopback is unavailable here: the bracketed dial is not measured')->skip();
      }
      else {
         $port6 = (int) substr((string) stream_socket_get_name($V6, false), strrpos((string) stream_socket_get_name($V6, false), ':') + 1);
         $Client->configure(new TCP_Client_CLI\Configs(host: 'localhost', port: $port6));
         $answer = ['::1'];
         $Socket = $Client->connect();
         $peer = $Socket === false ? false : stream_socket_get_name($Socket, true);

         yield new Assertion(
            description: 'an IPv6 address is dialed bracketed',
            fallback: 'The IPv6 address was not dialed: ' . json_encode($peer)
         )
            ->expect($peer)
            ->to->be("[::1]:{$port6}")
            ->assert();

         fclose($V6);
      }

      fclose($Server);
   })
);
