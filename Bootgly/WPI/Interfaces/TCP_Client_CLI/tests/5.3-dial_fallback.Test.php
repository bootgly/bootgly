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
   description: 'It should fall back to the next address and try a failed one last on the next dials',
   test: new Assertions(Case: function (): Generator {
      // ! The upstream listens on 127.0.0.2; nothing listens on 127.0.0.3 (refused)
      $Server = @stream_socket_server('tcp://127.0.0.2:0', $code, $message);
      // ? A system with only 127.0.0.1 on its loopback (macOS without an alias)
      if ($Server === false) {
         yield new Assertion(description: '127.0.0.2 cannot be bound: the fallback is not measured')->skip();

         return;
      }
      $port = (int) explode(':', (string) stream_socket_get_name($Server, false))[1];

      $answer = ['127.0.0.3', '127.0.0.2'];
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port));
      $Client->connectTimeout = 2;
      $Client->Resolution = static function () use (&$answer): array {
         return $answer;
      };
      $peer = static fn (mixed $Socket): false|string => $Socket === false ? false : stream_socket_get_name($Socket, true);

      // @ The first address refuses: the second one is dialed
      $Socket = $Client->connect();

      yield new Assertion(
         description: 'a refused address falls back to the next one, counted once',
         fallback: 'The dial did not fall back past the refused address: ' . json_encode([$peer($Socket), $Client->Connections->errors])
      )
         ->expect([$peer($Socket), $Client->Connections->errors['connection']])
         ->to->be(["127.0.0.2:{$port}", 1])
         ->assert();

      yield new Assertion(
         description: 'the refused address became a suspect, the reached one did not',
         fallback: 'The suspect memory was not updated!'
      )
         ->expect(Suspects::split(['127.0.0.3', '127.0.0.2'], $port))
         ->to->be([['127.0.0.2'], ['127.0.0.3']])
         ->assert();

      // @ The next dial tries the suspect last: no failed attempt
      $Socket = $Client->connect();

      yield new Assertion(
         description: 'the next dial goes to the fresh address first',
         fallback: 'The suspect was dialed first again: ' . json_encode([$peer($Socket), $Client->Connections->errors])
      )
         ->expect([$peer($Socket), $Client->Connections->errors['connection']])
         ->to->be(["127.0.0.2:{$port}", 1])
         ->assert();

      // @ A suspect is demoted, never skipped: alone, it is still dialed (and refuses)
      $answer = ['127.0.0.3'];
      $Socket = $Client->connect();

      yield new Assertion(
         description: 'a lone suspect is still dialed',
         fallback: 'A lone suspect was skipped: ' . json_encode($Client->Connections->errors)
      )
         ->expect([$Socket, $Client->Connections->errors['connection']])
         ->to->be([false, 2])
         ->assert();

      // @ A reached address is cleared
      Suspects::mark('127.0.0.2', $port);
      $answer = ['127.0.0.2'];
      $Client->connect();

      yield new Assertion(
         description: 'reaching a suspect clears it',
         fallback: 'A reached address stayed a suspect!'
      )
         ->expect(Suspects::split(['127.0.0.2'], $port))
         ->to->be([['127.0.0.2'], []])
         ->assert();

      // @ An address that fails synchronously (TCP to a multicast group: unreachable at once)
      $answer = ['224.0.0.1', '127.0.0.2'];
      $errors = $Client->Connections->errors['connection'];
      $Socket = $Client->connect();

      yield new Assertion(
         description: 'an address that fails before connecting falls back too',
         fallback: 'A synchronous failure ended the dial: ' . json_encode([$peer($Socket), $Client->Connections->errors['connection'] - $errors])
      )
         ->expect([$peer($Socket), $Client->Connections->errors['connection'] - $errors, Suspects::split(['224.0.0.1'], $port)[1]])
         ->to->be(["127.0.0.2:{$port}", 1, ['224.0.0.1']])
         ->assert();

      fclose($Server);
   })
);
