<?php

use function chr;
use function count;
use function pack;
use function str_repeat;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Specification(
   description: 'MySQL: decoder reassembles packets and parses protocol payloads',
   test: function () {
      $Decoder = new Decoder;

      // # Incremental framing
      $Messages = $Decoder->decode("\x03\x00");

      yield assert(
         assertion: $Messages === [],
         description: 'Partial headers stay buffered'
      );

      $Messages = $Decoder->decode("\x00\x00ab");

      yield assert(
         assertion: $Messages === [],
         description: 'Partial payloads stay buffered'
      );

      $Messages = $Decoder->decode('c');

      yield assert(
         assertion: count($Messages) === 1
            && $Messages[0]->sequence === 0
            && $Messages[0]->payload === 'abc',
         description: 'Split feeds reassemble into one packet'
      );

      $Messages = $Decoder->decode("\x01\x00\x00\x01x\x01\x00\x00\x02y");

      yield assert(
         assertion: count($Messages) === 2
            && $Messages[0]->payload === 'x' && $Messages[0]->sequence === 1
            && $Messages[1]->payload === 'y' && $Messages[1]->sequence === 2,
         description: 'Multiple packets in one feed decode in order'
      );

      // # 16 MB continuation
      $Fragmented = new Decoder;
      $chunk = str_repeat('a', 0xFFFFFF);
      $Messages = $Fragmented->decode("\xFF\xFF\xFF\x00{$chunk}\x03\x00\x00\x01end");

      yield assert(
         assertion: count($Messages) === 1
            && strlen($Messages[0]->payload) === 0xFFFFFF + 3,
         description: '16 MB packets coalesce with their continuation'
      );

      // # Greeting — MySQL 8 flavor
      $capabilities = Capabilities::PROTOCOL_41
         | Capabilities::SSL
         | Capabilities::SECURE_CONNECTION
         | Capabilities::PLUGIN_AUTH
         | Capabilities::DEPRECATE_EOF;
      $greeting = "\x0A"
         . "8.4.2\0"
         . pack('V', 42)
         . 'ABCDEFGH' . "\0"
         . pack('v', $capabilities & 0xFFFF)
         . chr(Capabilities::CHARSET_UTF8MB4)
         . pack('v', Capabilities::STATUS_AUTOCOMMIT)
         . pack('v', $capabilities >> 16)
         . chr(21)
         . str_repeat("\0", 10)
         . 'IJKLMNOPQRST' . "\0"
         . "caching_sha2_password\0";
      $fields = $Decoder->read($greeting, 'greeting');

      yield assert(
         assertion: $fields['version'] === '8.4.2'
            && $fields['thread'] === 42
            && $fields['nonce'] === 'ABCDEFGHIJKLMNOPQRST'
            && $fields['capabilities'] === $capabilities
            && $fields['plugin'] === 'caching_sha2_password',
         description: 'Greeting parses version, thread id, split nonce, capabilities and plugin'
      );

      // # Greeting — MariaDB flavor
      $mariadb = "\x0A"
         . "5.5.5-11.4.2-MariaDB\0"
         . pack('V', 7)
         . '12345678' . "\0"
         . pack('v', (Capabilities::PROTOCOL_41 | Capabilities::SECURE_CONNECTION | Capabilities::PLUGIN_AUTH) & 0xFFFF)
         . chr(45)
         . pack('v', 0)
         . pack('v', (Capabilities::PROTOCOL_41 | Capabilities::SECURE_CONNECTION | Capabilities::PLUGIN_AUTH) >> 16)
         . chr(21)
         . str_repeat("\0", 10)
         . 'ABCDEFGHIJKL' . "\0"
         . "mysql_native_password\0";
      $fields = $Decoder->read($mariadb, 'greeting');

      yield assert(
         assertion: $fields['version'] === '5.5.5-11.4.2-MariaDB'
            && $fields['nonce'] === '12345678ABCDEFGHIJKL'
            && $fields['plugin'] === 'mysql_native_password',
         description: 'MariaDB greetings parse with the 5.5.5- version prefix'
      );

      // # OK / ERR / EOF
      $ok = "\x00\xFC\x10\x27\x03" . pack('v', Capabilities::STATUS_AUTOCOMMIT) . pack('v', 1);
      $fields = $Decoder->read($ok, 'ok');

      yield assert(
         assertion: $fields['affected'] === 10000
            && $fields['inserted'] === 3
            && $fields['status'] === Capabilities::STATUS_AUTOCOMMIT
            && $fields['warnings'] === 1,
         description: 'OK packets parse length-encoded affected rows and insert id'
      );

      $error = "\xFF" . pack('v', 1064) . '#42000' . 'You have an error in your SQL syntax';
      $fields = $Decoder->read($error, 'error');

      yield assert(
         assertion: $fields['code'] === 1064
            && $fields['state'] === '42000'
            && $fields['message'] === 'You have an error in your SQL syntax',
         description: 'ERR packets parse the code, SQL state and message'
      );

      $eof = "\xFE" . pack('v', 2) . pack('v', Capabilities::STATUS_MORE_RESULTS);
      $fields = $Decoder->read($eof, 'eof');

      yield assert(
         assertion: $fields['warnings'] === 2 && $fields['status'] === Capabilities::STATUS_MORE_RESULTS,
         description: 'EOF packets parse warnings and status flags'
      );

      // # Column definition
      $column = "\x03def"
         . "\x02db"
         . "\x06fruits"
         . "\x06fruits"
         . "\x04name"
         . "\x04name"
         . "\x0C"
         . pack('v', 45)
         . pack('V', 1020)
         . chr(Decoder::TYPE_VAR_STRING)
         . pack('v', Decoder::FLAG_UNSIGNED)
         . "\x00"
         . "\x00\x00";
      $fields = $Decoder->read($column, 'column');

      yield assert(
         assertion: $fields['name'] === 'name'
            && $fields['type'] === Decoder::TYPE_VAR_STRING
            && $fields['flags'] === Decoder::FLAG_UNSIGNED
            && $fields['length'] === 1020,
         description: 'Column definitions parse name, type, flags and length'
      );

      // # Text rows + length-encoded edges
      $columns = [
         ['name' => 'a', 'type' => Decoder::TYPE_LONG, 'flags' => 0],
         ['name' => 'b', 'type' => Decoder::TYPE_VAR_STRING, 'flags' => 0],
         ['name' => 'c', 'type' => Decoder::TYPE_VAR_STRING, 'flags' => 0],
      ];
      $long = str_repeat('x', 251);
      $row = "\x02" . '42'
         . "\xFB"
         . "\xFC" . pack('v', 251) . $long;
      $values = $Decoder->read($row, 'row', $columns);

      yield assert(
         assertion: $values === ['42', null, $long],
         description: 'Text rows decode 0xFB NULL cells and 2-byte length-encoded strings'
      );

      // # Prepared statement OK
      $prepared = "\x00" . pack('V', 7) . pack('v', 2) . pack('v', 3) . "\x00" . pack('v', 0);
      $fields = $Decoder->read($prepared, 'prepared');

      yield assert(
         assertion: $fields['statement'] === 7
            && $fields['columns'] === 2
            && $fields['parameters'] === 3,
         description: 'COM_STMT_PREPARE responses parse statement id and counts'
      );

      // # Binary rows — unsigned BIGINT precision above PHP_INT_MAX
      $columns = [
         ['name' => 'edge', 'type' => Decoder::TYPE_LONGLONG, 'flags' => Decoder::FLAG_UNSIGNED],
         ['name' => 'max', 'type' => Decoder::TYPE_LONGLONG, 'flags' => Decoder::FLAG_UNSIGNED],
         ['name' => 'fits', 'type' => Decoder::TYPE_LONGLONG, 'flags' => Decoder::FLAG_UNSIGNED],
      ];
      $binary = "\x00" . "\x00"
         . "\x00\x00\x00\x00\x00\x00\x00\x80"
         . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
         . pack('P', 42);
      $values = $Decoder->read($binary, 'binary', $columns);

      yield assert(
         assertion: $values === ['9223372036854775808', '18446744073709551615', 42],
         description: 'Unsigned BIGINT binary values beyond PHP_INT_MAX stay exact decimal strings'
      );
   }
);
