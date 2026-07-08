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
use DateTimeImmutable;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


// ! Column definition builder
$column = static function (string $name, int $type, int $flags = 0): string {
   return "\x03def\x02db\x05table\x05table"
      . chr(strlen($name)) . $name
      . chr(strlen($name)) . $name
      . "\x0C" . pack('v', 45) . pack('V', 255) . chr($type) . pack('v', $flags) . "\x00\x00\x00";
};
// ! Text row builder — length-prefixed cells, null for 0xFB
$row = static function (null|string ...$cells): string {
   $payload = '';

   foreach ($cells as $cell) {
      $payload .= $cell === null ? "\xFB" : chr(strlen($cell)) . $cell;
   }

   return $payload;
};
$boot = static function (): array {
   [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   stream_set_blocking($client, false);
   stream_set_blocking($server, false);

   $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
   $Connection = new Connection($Config);
   $Connection->attach($client);
   $MySQL = new MySQL($Config, $Connection);

   return [$MySQL, $server, $Connection];
};


return new Specification(
   description: 'MySQL: text protocol result sets, cast matrix, errors and OK-only commands',
   test: function () use ($column, $row, $boot) {
      // # Classic EOF flow (no DEPRECATE_EOF negotiated) + cast matrix
      [$MySQL, $server, $Connection] = $boot();

      $Operation = $MySQL->query('SELECT * FROM casts');
      $MySQL->advance($Operation);

      yield assert(
         assertion: fread($server, 8192) === "\x14\x00\x00\x00\x03SELECT * FROM casts",
         description: 'COM_QUERY writes directly on an authenticated connection'
      );

      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame("\x06", 1));
      fwrite($server, $MySQL->Encoder->frame($column('id', Decoder::TYPE_LONG), 2));
      fwrite($server, $MySQL->Encoder->frame($column('big', Decoder::TYPE_LONGLONG, Decoder::FLAG_UNSIGNED), 3));
      fwrite($server, $MySQL->Encoder->frame($column('ratio', Decoder::TYPE_DOUBLE), 4));
      fwrite($server, $MySQL->Encoder->frame($column('price', Decoder::TYPE_NEWDECIMAL), 5));
      fwrite($server, $MySQL->Encoder->frame($column('created', Decoder::TYPE_DATETIME), 6));
      fwrite($server, $MySQL->Encoder->frame($column('note', Decoder::TYPE_VAR_STRING), 7));
      fwrite($server, $MySQL->Encoder->frame($eof, 8));
      fwrite($server, $MySQL->Encoder->frame(
         $row('7', '18446744073709551615', '2.5', '19.90', '2026-07-07 12:30:45', null),
         9
      ));
      fwrite($server, $MySQL->Encoder->frame($eof, 10));
      $MySQL->advance($Operation);

      $Result = $Operation->Result;
      $cells = $Result?->row ?? [];

      yield assert(
         assertion: $Operation->finished && $Operation->error === null
            && $Result?->status === 'SELECT 1'
            && $cells['id'] === 7
            && $cells['big'] === '18446744073709551615'
            && $cells['ratio'] === 2.5
            && $cells['price'] === '19.90'
            && $cells['created'] instanceof DateTimeImmutable
            && $cells['created']->format('Y-m-d H:i:s') === '2026-07-07 12:30:45'
            && $cells['note'] === null,
         description: 'Text values cast to int/float/DateTimeImmutable; unsigned overflow and decimals stay strings'
      );

      fclose($server);
      $Connection->disconnect();

      // # ERR packet fails the operation with code and message
      [$MySQL, $server, $Connection] = $boot();
      $Operation = $MySQL->query('SELECT broken');
      $MySQL->advance($Operation);
      fread($server, 8192);

      fwrite($server, $MySQL->Encoder->frame("\xFF" . pack('v', 1054) . '#42S22' . "Unknown column 'broken'", 1));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->finished
            && $Operation->error === "1054: Unknown column 'broken'"
            && $MySQL->check() === false,
         description: 'ERR packets fail the operation and clear the request queue'
      );

      fclose($server);
      $Connection->disconnect();

      // # OK-only command carries affected rows and the insert id
      [$MySQL, $server, $Connection] = $boot();
      $Operation = $MySQL->query("INSERT INTO fruits (name) VALUES ('fig'), ('date'), ('plum')");
      $MySQL->advance($Operation);
      fread($server, 8192);

      $ok = "\x00\x03\x09" . pack('v', 0) . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->finished
            && $Operation->Result?->status === 'INSERT 0 3'
            && $Operation->Result->affected === 3
            && $Operation->Result->inserted === 9,
         description: 'OK packets resolve with affected rows, the command tag and Result->inserted'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
