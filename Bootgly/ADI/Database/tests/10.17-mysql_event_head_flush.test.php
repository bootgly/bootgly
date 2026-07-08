<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function chr;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_pair;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Specification(
   description: 'MySQL: a sibling pump flushes the re-armed head (event-driven wake-up)',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      // ! Two cold-cache commands on one connection: both encode COM_STMT_PREPARE
      $First = $MySQL->query('UPDATE a SET x = ?', [1]);
      $Second = $MySQL->query('UPDATE a SET x = ?', [2]);

      $MySQL->advance($First);
      $MySQL->advance($Second);

      yield assert(
         assertion: substr((string) fread($server, 8192), 4, 1) === "\x16",
         description: 'Only the head COM_STMT_PREPARE reaches the wire'
      );

      // @ Server answers the prepare — definition + EOFs (no DEPRECATE_EOF here)
      $prepared = "\x00" . pack('V', 77) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));

      // @ Only the SIBLING advances (event-driven callers wake on socket reads):
      //   its pump consumes the prepare-OK, which re-arms the head with the
      //   COM_STMT_EXECUTE — the read path must flush it without a head advance,
      //   or the request-response socket goes silent forever (wake-up deadlock).
      $MySQL->advance($Second);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: substr($wire, 4, 5) === "\x17" . pack('V', 77),
         description: 'The sibling pump pushes the re-armed head EXECUTE to the wire'
      );

      // @ The head then resolves and the sibling takes the wire
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Second);

      yield assert(
         assertion: $First->finished && $First->error === null
            && str_contains((string) fread($server, 8192), "\x16"),
         description: 'The head resolves and the sibling command is written next'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
