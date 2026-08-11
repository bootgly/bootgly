<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Test(
   description: 'Pool: a cancel that never reached the server reconciles the wire',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'driver' => 'mysql',
            'secure' => ['mode' => 'disable'],
            'timeout' => 30.0,
            'pool' => [
               'min' => 0,
               'max' => 1,
            ],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      $frame = fn (string $payload, int $sequence): string =>
         substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;
      $column = fn (string $name, int $type): string =>
         "\x03def\x02db\x05table\x05table"
         . chr(strlen($name)) . $name
         . chr(strlen($name)) . $name
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr($type) . pack('v', 0) . "\x00\x00\x00";
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);

      // # A cancel that cannot be delivered leaves the command on the wire
      [$Database, $server] = $connect();
      $Head = $Database->query('SELECT secret FROM tenant_a');
      $Sibling = $Database->query('SELECT id FROM tenant_b');
      $Database->advance($Head);
      $Database->advance($Sibling);
      fread($server, 8192);

      // @ The side channel needs the greeting thread id, which this session
      //   never negotiated: cancel() fails and the command keeps answering.
      $Database->cancel($Head);

      yield assert(
         assertion: $Head->state === OperationStates::Failed
            && str_contains($Head->error ?? '', 'thread id'),
         description: 'A cancel that cannot be delivered fails the operation'
      );

      fwrite($server, $frame("\x01", 1));
      fwrite($server, $frame($column('secret', Decoder::TYPE_VAR_STRING), 2));
      fwrite($server, $frame($eof, 3));
      fwrite($server, $frame("\x09TOPSECRET", 4));
      fwrite($server, $frame($eof, 5));
      $Database->advance($Sibling);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Head->state === OperationStates::Failed
            && $Head->Result === null
            && $Head->rows === []
            && $Head->columns === [],
         description: 'The answer to a cancelled command never resolves it'
      );

      yield assert(
         assertion: str_contains($wire, 'SELECT id FROM tenant_b')
            && $Database->Connection->connected,
         description: 'The sibling takes the wire on a session the cancel left usable'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # With nobody left to drain it, the session goes instead of the slot
      [$Database, $server] = $connect();
      $Alone = $Database->query('SELECT secret FROM tenant_a');
      $Database->advance($Alone);
      fread($server, 8192);

      $Database->cancel($Alone);

      yield assert(
         assertion: $Database->Connection->connected === false
            && $Database->Pool->created === 0
            && $Database->Pool->busy === [],
         description: 'A cancelled command with no reader gives its pool slot back'
      );

      fclose($server);
   }
);
