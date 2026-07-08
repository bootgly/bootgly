<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function chr;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Specification(
   description: 'MySQL: prepared OK/ERR semantics keep the session usable',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);
      // ! The session behaves as authenticated for this attached-socket test
      $MySQL->Authentication->authenticated = true;

      $eof = "\xFE" . pack('v', 0) . pack('v', 0);

      // # ERR at prepare — syntax error fails the operation
      $Broken = $MySQL->query('UPDATE broken SET = ?', [1]);
      $MySQL->advance($Broken);
      fread($server, 8192);

      fwrite($server, $MySQL->Encoder->frame("\xFF" . pack('v', 1064) . '#42000' . 'syntax error near =', 1));
      $MySQL->advance($Broken);

      yield assert(
         assertion: $Broken->finished
            && $Broken->error === '1064: syntax error near ='
            && $MySQL->statements === []
            && $MySQL->check() === false,
         description: 'A prepare error fails the operation without caching metadata'
      );

      yield assert(
         assertion: $Connection->state === ConnectionStates::Ready,
         description: 'The session stays Ready after a command error'
      );

      // # The connection keeps serving commands after the failure
      $Update = $MySQL->query('UPDATE tasks SET done = ? WHERE id = ?', [true, 4]);
      $MySQL->advance($Update);
      fread($server, 8192);

      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      $prepared = "\x00" . pack('V', 5) . pack('v', 0) . pack('v', 2) . "\x00" . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($definition, 3));
      fwrite($server, $MySQL->Encoder->frame($eof, 4));
      $MySQL->advance($Update);
      fread($server, 8192);

      // @ OK — 5 affected rows, last insert id 2
      fwrite($server, $MySQL->Encoder->frame("\x00\x05\x02" . pack('v', 0) . pack('v', 0), 1));
      $MySQL->advance($Update);

      yield assert(
         assertion: $Update->finished && $Update->error === null
            && $Update->Result?->status === 'UPDATE 5'
            && $Update->Result->affected === 5
            && $Update->Result->inserted === 2,
         description: 'Prepared OK packets resolve with affected rows and Result->inserted'
      );

      // # ERR mid-result fails but the next command still runs
      $Select = $MySQL->query('SELECT slow FROM things WHERE id = ?', [1]);
      $MySQL->advance($Select);
      fread($server, 8192);

      $prepared = "\x00" . pack('V', 6) . pack('v', 1) . pack('v', 1) . "\x00" . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame($definition, 4));
      fwrite($server, $MySQL->Encoder->frame($eof, 5));
      $MySQL->advance($Select);
      fread($server, 8192);

      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame("\xFF" . pack('v', 1317) . '#70100' . 'Query execution was interrupted', 4));
      $MySQL->advance($Select);

      yield assert(
         assertion: $Select->finished
            && $Select->error === '1317: Query execution was interrupted'
            && $MySQL->check() === false,
         description: 'Errors inside a result set fail the operation and free the queue'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
