<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\KV;


return new Test(
   description: 'KV(Redis): an unsolicited RESP3 push frame consumes no reply slot',
   test: function () {
      /**
       * Opens a KV pool of one connection over a socketpair standing in for the
       * server, so every frame under test is written by hand and no live Redis
       * is needed.
       *
       * @return array{KV, resource}
       */
      $open = static function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $KV = new KV([
            'driver'  => 'redis',
            'timeout' => 2.0,
            'pool'    => ['min' => 0, 'max' => 1],
         ]);
         $KV->Connection->attach($client);

         return [$KV, $server];
      };
      // ! The invalidation Redis pushes to a connection with client-side
      //   caching on — the frame that produced this defect on a live server.
      $invalidate = ">2\r\n\$10\r\ninvalidate\r\n*1\r\n\$5\r\nkv2:k\r\n";

      // # A push that arrives before the replies shifts nothing
      //   Matched positionally, it takes the first command's slot and every
      //   later command answers with its predecessor's reply — permanently,
      //   because one extra reply stays in flight for the connection's life.
      [$KV, $server] = $open();

      $A = $KV->command('ECHO', ['ANSWER-A']);
      $B = $KV->command('ECHO', ['ANSWER-B']);
      $KV->advance($A);
      $KV->advance($B);
      fread($server, 8192);

      fwrite($server, $invalidate . "+ANSWER-A\r\n" . "+ANSWER-B\r\n");
      $KV->advance($A);

      yield assert(
         assertion: $A->finished
            && $A->error === null
            && $A->response === 'ANSWER-A'
            && $B->finished
            && $B->error === null
            && $B->response === 'ANSWER-B',
         description: 'Each pipelined command keeps its own reply across a push, found: '
            . json_encode([$A->response, $B->response])
      );

      fclose($server);
      $KV->Connection->disconnect();

      // # A subscribe confirmation IS a reply, and still resolves its command
      //   Under RESP3 the answer to SUBSCRIBE is itself a push frame. Routing
      //   every push out of band would leave this command unresolved until its
      //   deadline — the reason the decoder marks the type instead of removing
      //   the frame from the stream.
      [$KV, $server] = $open();

      $Subscribe = $KV->command('SUBSCRIBE', ['kv2:chan']);
      $KV->advance($Subscribe);
      fread($server, 8192);

      fwrite($server, ">3\r\n\$9\r\nsubscribe\r\n\$8\r\nkv2:chan\r\n:1\r\n");
      $KV->advance($Subscribe);

      yield assert(
         assertion: $Subscribe->finished
            && $Subscribe->error === null
            && $Subscribe->response === ['subscribe', 'kv2:chan', 1],
         description: 'A subscribe confirmation resolves the command that asked for it, found: '
            . json_encode($Subscribe->response)
      );

      fclose($server);
      $KV->Connection->disconnect();

      // # A push during the handshake spends no preamble slot either
      //   The preamble counter discards one reply per AUTH/SELECT sent. A push
      //   counted there leaves the last preamble reply to be read as the
      //   command's answer, which is the same shift one message later.
      [$KV, $server] = $open();

      $Command = $KV->command('ECHO', ['ANSWER-C']);
      $KV->advance($Command);
      fread($server, 8192);

      $Protocol = $Command->Protocol;
      $Skip = new ReflectionProperty($Protocol, 'skip');
      $Skip->setValue($Protocol, 2);

      fwrite($server, $invalidate . "+OK\r\n" . "+OK\r\n" . "+ANSWER-C\r\n");
      $KV->advance($Command);

      yield assert(
         assertion: $Command->finished
            && $Command->error === null
            && $Command->response === 'ANSWER-C'
            && $Skip->getValue($Protocol) === 0,
         description: 'A push arriving mid-handshake consumes no preamble slot, found: '
            . json_encode([$Command->response, $Skip->getValue($Protocol)])
      );

      fclose($server);
      $KV->Connection->disconnect();

      // # …and an ordinary array reply is left alone
      //   The routing keys on the frame type, never on the shape of its
      //   payload: an LRANGE answering the same items must still resolve.
      [$KV, $server] = $open();

      $Range = $KV->command('LRANGE', ['kv2:list', '0', '-1']);
      $KV->advance($Range);
      fread($server, 8192);

      fwrite($server, "*2\r\n\$10\r\ninvalidate\r\n\$5\r\nkv2:k\r\n");
      $KV->advance($Range);

      yield assert(
         assertion: $Range->finished
            && $Range->error === null
            && $Range->response === ['invalidate', 'kv2:k'],
         description: 'An array reply that looks like a push still resolves its command, found: '
            . json_encode($Range->response)
      );

      fclose($server);
      $KV->Connection->disconnect();
   }
);
