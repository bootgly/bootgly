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
use function strlen;
use function substr;
use DateTimeImmutable;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


// ! Column definition builder
$column = static function (string $name, int $type): string {
   return "\x03def\x02db\x05table\x05table"
      . chr(strlen($name)) . $name
      . chr(strlen($name)) . $name
      . "\x0C" . pack('v', 45) . pack('V', 255) . chr($type) . pack('v', 0) . "\x00\x00\x00";
};


return new Specification(
   description: 'MySQL: COM_STMT_PREPARE/EXECUTE with typed binds and binary row decoding',
   test: function () use ($column) {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $Moment = new DateTimeImmutable('2026-07-08 10:20:30.000000');
      $Operation = $MySQL->query(
         'SELECT name FROM samples WHERE id = ? AND active = ? AND score > ? AND tag = ? AND created < ? AND note = ?',
         [7, true, 1.5, 'x', $Moment, null]
      );

      yield assert(
         assertion: $Operation->prepared === false && $Operation->write[4] === "\x16",
         description: 'A statement cache miss writes COM_STMT_PREPARE first'
      );

      $MySQL->advance($Operation);
      $prepare = (string) fread($server, 8192);

      yield assert(
         assertion: substr($prepare, 5) === 'SELECT name FROM samples WHERE id = ? AND active = ? AND score > ? AND tag = ? AND created < ? AND note = ?',
         description: 'COM_STMT_PREPARE carries the SQL text'
      );

      // @ prepare-OK — statement id 3, 1 column, 6 parameters (+EOFs: classic protocol)
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $prepared = "\x00" . pack('V', 3) . pack('v', 1) . pack('v', 6) . "\x00" . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));

      for ($index = 0; $index < 6; $index++) {
         fwrite($server, $MySQL->Encoder->frame($column("p{$index}", Decoder::TYPE_VAR_STRING), 2 + $index));
      }

      fwrite($server, $MySQL->Encoder->frame($eof, 8));
      fwrite($server, $MySQL->Encoder->frame($column('name', Decoder::TYPE_VAR_STRING), 9));
      fwrite($server, $MySQL->Encoder->frame($eof, 10));
      $MySQL->advance($Operation);

      // ! Expected COM_STMT_EXECUTE payload
      $expected = "\x17"
         . pack('V', 3)
         . "\x00"
         . pack('V', 1)
         . chr(0b00100000)                                  // NULL bitmap — parameter 6
         . "\x01"                                           // new-params-bound
         . chr(Decoder::TYPE_LONGLONG) . "\0"
         . chr(Decoder::TYPE_TINY) . "\0"
         . chr(Decoder::TYPE_DOUBLE) . "\0"
         . chr(Decoder::TYPE_VAR_STRING) . "\0"
         . chr(Decoder::TYPE_VAR_STRING) . "\0"
         . chr(Decoder::TYPE_NULL) . "\0"
         . pack('P', 7)
         . chr(1)
         . pack('e', 1.5)
         . chr(1) . 'x'
         . chr(26) . '2026-07-08 10:20:30.000000';
      $execute = (string) fread($server, 8192);

      yield assert(
         assertion: substr($execute, 4) === $expected,
         description: 'COM_STMT_EXECUTE binds typed values with the NULL bitmap'
      );

      yield assert(
         assertion: $MySQL->statements['SELECT name FROM samples WHERE id = ? AND active = ? AND score > ? AND tag = ? AND created < ? AND note = ?']['statement'] === 3
            && $Operation->prepared && $Operation->state === OperationStates::Reading,
         description: 'The prepare-OK caches the statement metadata'
      );

      // @ Binary result set — 1 column, one row, classic EOF terminators
      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame($column('name', Decoder::TYPE_VAR_STRING), 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame("\x00\x00\x05apple", 4));
      fwrite($server, $MySQL->Encoder->frame($eof, 5));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->finished && $Operation->error === null
            && $Operation->Result?->rows === [['name' => 'apple']]
            && $Operation->Result->status === 'SELECT 1',
         description: 'Binary rows decode through the column metadata'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
