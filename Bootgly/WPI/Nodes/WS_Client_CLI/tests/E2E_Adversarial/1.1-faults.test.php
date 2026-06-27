<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Specification(
   description: 'It should reject malformed server frames and tear the connection down (not surface them)',
   test: new Assertions(Case: function (): Generator {
      // @ Run one scenario: connect, send the selector, return how the client reacted.
      $run = function (string $selector): array {
         $result = ['connected' => false, 'message' => false, 'closing' => false];
         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(host: '127.0.0.1', port: 8088, compression: false);
         $Client->on(Events::Connected, function ($Session) use ($selector, &$result) {
            $result['connected'] = true;
            $Session->send($selector);
         });
         $Client->on(Events::MessageReceived, function ($Session, $Message) use (&$result) {
            $result['message'] = true;
            $Session->close();
         });
         $Client->on(Events::Disconnected, function ($Session) use (&$result) {
            // `closing` is set by the framing decoder's fault path — true here means
            // the client actively rejected the frame (not merely a TCP EOF).
            $result['closing'] = $Session->closing;
         });
         $Client->connect('/');

         return $result;
      };

      foreach (['masked', 'rsv', 'closecode', 'oversized'] as $scenario) {
         $result = $run($scenario);

         yield new Assertion(description: "{$scenario}: client completed the handshake")
            ->expect($result['connected'])
            ->to->be(true)
            ->assert();

         yield new Assertion(description: "{$scenario}: the malformed frame was NOT surfaced as a message")
            ->expect($result['message'])
            ->to->be(false)
            ->assert();

         yield new Assertion(description: "{$scenario}: the client rejected it and closed (fault, not EOF)")
            ->expect($result['closing'])
            ->to->be(true)
            ->assert();
      }
   })
);
