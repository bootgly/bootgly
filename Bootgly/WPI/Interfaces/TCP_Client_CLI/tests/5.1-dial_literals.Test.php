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
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI;


return new Test(
   description: 'It should dial an IP literal as given, never through the resolution seam',
   test: new Assertions(Case: function (): Generator {
      // ! A loopback listener, and a seam that records every call
      $Server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      assert($Server !== false);
      $port = (int) explode(':', (string) stream_socket_get_name($Server, false))[1];

      $calls = [];
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Client->connectTimeout = 0.5;
      $Client->Resolution = static function (string $host) use (&$calls): array {
         $calls[] = $host;

         return ['127.0.0.1'];
      };

      // @ An IPv4 literal connects with no lookup
      $Client->configure(new TCP_Client_CLI\Configs(host: '127.0.0.1', port: $port));
      $Socket = $Client->connect();

      yield new Assertion(
         description: 'an IPv4 literal is dialed as given',
         fallback: 'The IPv4 literal did not connect, or went through the seam: ' . json_encode($calls)
      )
         ->expect([$Socket !== false, $calls])
         ->to->be([true, []])
         ->assert();

      // @ Literals of every other form never reach the seam either
      $Client->connectTimeout = 0.2;
      $Literals = ['[::1]', '[::ffff:127.0.0.1]', '::1', 'fe80::1%lo', '[fe80::1%25lo]'];
      foreach ($Literals as $literal) {
         $Client->configure(new TCP_Client_CLI\Configs(host: $literal, port: $port));
         @$Client->connect();
      }

      yield new Assertion(
         description: 'bracketed, mapped, bare and zoned IPv6 literals skip the seam',
         fallback: 'An IP literal was sent to the resolver: ' . json_encode($calls)
      )
         ->expect($calls)
         ->to->be([])
         ->assert();

      // @ The seam's default: none on a raw client, the resolver node on HTTP and WS
      $Raw = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $HTTP = new HTTP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $WS = new WS_Client_CLI(TCP_Client_CLI::MODE_TEST);

      yield new Assertion(
         description: 'a raw TCP client has no seam; the HTTP and WS clients resolve through the node',
         fallback: 'The default resolution seams changed!'
      )
         ->expect([$Raw->Resolution === null, $HTTP->Resolution instanceof Closure, $WS->Resolution instanceof Closure])
         ->to->be([true, true, true])
         ->assert();

      fclose($Server);
   })
);
