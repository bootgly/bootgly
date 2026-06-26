<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/../E2E/Client.php';


return new Specification(
   description: 'It should validate UTF-8 incrementally (fail-fast), reassemble split multibyte, and fragment outbound messages',
   test: new Assertions(Case: function (): Generator {
      // @ A valid multibyte sequence split across two fragments reassembles.
      //   "h" + 0xC3 (lead of é) | 0xA9 (trail) + "llo"  ==  "héllo"
      $split = Client::open();
      fwrite($split, Client::mask(0x1, "h\xC3", false, false));
      fwrite($split, Client::mask(0x0, "\xA9llo", false, true));
      $message = Client::message($split);
      yield new Assertion(description: 'multibyte split across fragments reassembles')
         ->expect($message['payload'] ?? '')
         ->to->be("h\xC3\xA9llo")
         ->assert();
      fclose($split);

      // @ An invalid byte mid-stream fails fast with close 1007 on the first
      //   fragment, before the (never-sent) final fragment.
      $bad = Client::open();
      fwrite($bad, Client::mask(0x1, "valid\xFF", false, false));
      yield new Assertion(description: 'invalid UTF-8 mid-stream fails fast with 1007')
         ->expect(Client::close($bad))
         ->to->be(1007)
         ->assert();
      fclose($bad);

      // @ An incomplete multibyte at message end (dangling lead byte) -> 1007.
      $partial = Client::open();
      fwrite($partial, Client::mask(0x1, "h\xC3", false, true));
      yield new Assertion(description: 'incomplete multibyte at message end closes 1007')
         ->expect(Client::close($partial))
         ->to->be(1007)
         ->assert();
      fclose($partial);

      // @ Outbound fragmentation: the server sends a 300-byte message as 100-byte
      //   fragments; the client reassembles it.
      $frag = Client::open();
      fwrite($frag, Client::mask(0x1, 'frag', false, true));
      $reassembled = Client::message($frag);
      yield new Assertion(description: 'server outbound fragmentation reassembles client-side')
         ->expect(strlen($reassembled['payload'] ?? ''))
         ->to->be(300)
         ->assert();
      fclose($frag);
   })
);
