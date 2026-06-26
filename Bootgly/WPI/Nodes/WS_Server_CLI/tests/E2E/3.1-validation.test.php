<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/Client.php';


return new Specification(
   description: 'It should close with RFC codes on invalid UTF-8, bad close codes, and non-minimal lengths',
   test: new Assertions(Case: function (): Generator {
      // @ Invalid UTF-8 in a text message -> close 1007.
      $Socket = Client::open();
      fwrite($Socket, Client::mask(0x1, "\xff\xfe\xfd"));
      yield new Assertion(description: 'invalid UTF-8 text closes 1007')
         ->expect(Client::close($Socket))
         ->to->be(1007)
         ->assert();
      fclose($Socket);

      // @ Reserved close code (1005) -> close 1002.
      $Socket = Client::open();
      fwrite($Socket, Client::mask(0x8, pack('n', 1005)));
      yield new Assertion(description: 'reserved close code closes 1002')
         ->expect(Client::close($Socket))
         ->to->be(1002)
         ->assert();
      fclose($Socket);

      // @ Non-minimal 16-bit length form for a 5-byte payload -> close 1002.
      $Socket = Client::open();
      $key = random_bytes(4);
      $payload = 'hello';
      $frame = chr(0x81) . chr(0x80 | 126) . pack('n', 5) . $key
         . ($payload ^ substr(str_repeat($key, 2), 0, 5));
      fwrite($Socket, $frame);
      yield new Assertion(description: 'non-minimal length closes 1002')
         ->expect(Client::close($Socket))
         ->to->be(1002)
         ->assert();
      fclose($Socket);
   })
);
