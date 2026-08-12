<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Test(
   description: 'MySQL: a length-encoded 64-bit generated id stays exact past the sign bit',
   test: function () {
      $Decoder = new Decoder;

      // ! OK packet — 0x00, lenenc affected rows, lenenc last insert id, status,
      //   warnings. The 0xFE form carries the id as 8 little-endian bytes.
      $confirm = fn (string $id): string =>
         "\x00" . "\x01" . "\xFE" . $id
         . pack('v', Capabilities::STATUS_AUTOCOMMIT) . pack('v', 0);

      // # The sign bit
      $fields = $Decoder->read($confirm("\x00\x00\x00\x00\x00\x00\x00\x80"), 'ok');

      yield assert(
         assertion: $fields['inserted'] === '9223372036854775808',
         description: 'A generated id at 2^63 decodes exactly instead of sign-overflowing'
      );

      $fields = $Decoder->read($confirm("\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF"), 'ok');

      yield assert(
         assertion: $fields['inserted'] === '18446744073709551615',
         description: 'The largest unsigned 64-bit generated id decodes exactly'
      );

      // # Below the sign bit the value stays an int — a blanket stringification
      //   would satisfy the yields above and break every existing consumer.
      $fields = $Decoder->read($confirm("\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x7F"), 'ok');

      yield assert(
         assertion: $fields['inserted'] === PHP_INT_MAX,
         description: 'A generated id at PHP_INT_MAX is still an int'
      );

      $fields = $Decoder->read($confirm("\x01\x00\x00\x00\x00\x00\x00\x00"), 'ok');

      yield assert(
         assertion: $fields['inserted'] === 1 && $fields['affected'] === 1,
         description: 'A small id carried in the 8-byte form is still an int'
      );

      // # The two counters decode independently
      $mixed = "\x00" . "\xFC\x10\x27" . "\xFE" . "\x00\x00\x00\x00\x00\x00\x00\x80"
         . pack('v', Capabilities::STATUS_AUTOCOMMIT) . pack('v', 1);
      $fields = $Decoder->read($mixed, 'ok');

      yield assert(
         assertion: $fields['affected'] === 10000
            && $fields['inserted'] === '9223372036854775808'
            && $fields['warnings'] === 1,
         description: 'Affected rows and the generated id decode independently of each other'
      );

      // # Control — the short length-encoded forms are untouched
      $short = "\x00" . "\xFC\x10\x27" . "\x03" . pack('v', Capabilities::STATUS_AUTOCOMMIT) . pack('v', 1);
      $fields = $Decoder->read($short, 'ok');

      yield assert(
         assertion: $fields['affected'] === 10000 && $fields['inserted'] === 3,
         description: 'The 0xFC and single-byte forms keep decoding as ints'
      );


      // # A length-encoded string cannot be addressed by a 64-bit length
      $cursor = 0;
      $thrown = null;

      try {
         $Decoder->slice("\xFE" . "\x00\x00\x00\x00\x00\x00\x00\x80" . 'abcdef', $cursor, true);
      }
      catch (Throwable $Throwable) {
         $thrown = $Throwable;
      }

      yield assert(
         assertion: $thrown instanceof InvalidArgumentException,
         description: 'A string length beyond the sign bit is rejected instead of emptying the value'
      );

      yield assert(
         assertion: $cursor === 0,
         description: 'The rejected read leaves the cursor untouched instead of driving it negative'
      );

      $cursor = 0;
      $thrown = null;

      try {
         $Decoder->slice("\x40" . 'abcdef', $cursor, true);
      }
      catch (Throwable $Throwable) {
         $thrown = $Throwable;
      }

      yield assert(
         assertion: $thrown instanceof InvalidArgumentException,
         description: 'A string length that overruns the packet is rejected instead of truncating'
      );

      // # Controls — the string forms that do address the payload still work
      $cursor = 0;

      yield assert(
         assertion: $Decoder->slice("\x03abc", $cursor, true) === 'abc' && $cursor === 4,
         description: 'A single-byte length still slices the value and advances the cursor'
      );

      $cursor = 0;

      yield assert(
         assertion: $Decoder->slice("\xFB", $cursor) === null && $cursor === 1,
         description: 'The 0xFB NULL form is unchanged'
      );

      $cursor = 0;

      yield assert(
         assertion: $Decoder->slice("\xFD\x03\x00\x00abc", $cursor, true) === 'abc' && $cursor === 7,
         description: 'The 0xFD three-byte length form is unchanged'
      );


      // # The rejection reaches the caller as a dead session, not as a throw
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $Operation = $MySQL->query('SELECT 1');
      $MySQL->advance($Operation);
      fread($server, 65536);

      // @ A column count, then a definition whose first length-encoded string
      //   claims 2^63 bytes — corruption arriving mid-result-set.
      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame("\xFE\x00\x00\x00\x00\x00\x00\x00\x80" . 'junk', 2));

      $escaped = null;

      try {
         $MySQL->advance($Operation);
      }
      catch (Throwable $Throwable) {
         $escaped = $Throwable;
      }

      yield assert(
         assertion: $escaped === null,
         description: 'A corrupt result-set packet does not throw out of advance()'
      );

      yield assert(
         assertion: $Operation->finished && $Operation->error !== null && $Operation->quarantine,
         description: 'The corrupt packet fails and quarantines the operation instead'
      );

      yield assert(
         assertion: is_resource($Connection->socket) === false,
         description: 'The unresynchronizable session is dropped'
      );

      fclose($server);
   }
);
