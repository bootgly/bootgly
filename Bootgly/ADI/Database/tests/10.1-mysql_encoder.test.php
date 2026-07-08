<?php

use function chr;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;


return new Specification(
   description: 'MySQL: encoder frames packets and builds handshake/command payloads',
   test: function () {
      $Encoder = new Encoder;

      // # Framing
      yield assert(
         assertion: $Encoder->frame('abc', 0) === "\x03\x00\x00\x00abc",
         description: 'Packets carry a 3-byte little-endian length and the sequence id'
      );

      yield assert(
         assertion: $Encoder->frame('ping', 3) === "\x04\x00\x00\x03ping",
         description: 'The sequence id lands in the fourth header byte'
      );

      $huge = str_repeat('a', 0xFFFFFF);
      $framed = $Encoder->frame($huge, 0);

      yield assert(
         assertion: strlen($framed) === 0xFFFFFF + 8
            && substr($framed, 0, 4) === "\xFF\xFF\xFF\x00"
            && substr($framed, -4) === "\x00\x00\x00\x01",
         description: '16 MB payloads split into a full packet plus an empty continuation'
      );

      // # COM_QUERY
      yield assert(
         assertion: $Encoder->encode(Encoder::QUERY, 'SELECT 1') === "\x09\x00\x00\x00\x03SELECT 1",
         description: 'COM_QUERY packets restart the sequence and prefix the command byte'
      );

      // # SSLRequest
      $capabilities = Capabilities::PROTOCOL_41 | Capabilities::SSL;
      $ssl = $Encoder->encode(Encoder::SSL, ['capabilities' => $capabilities], 1);

      yield assert(
         assertion: strlen($ssl) === 36
            && substr($ssl, 0, 4) === "\x20\x00\x00\x01"
            && substr($ssl, 4, 4) === pack('V', $capabilities)
            && $ssl[12] === chr(Capabilities::CHARSET_UTF8MB4)
            && substr($ssl, 13) === str_repeat("\0", 23),
         description: 'SSLRequest is a 32-byte payload with capabilities, max packet and charset'
      );

      // # HandshakeResponse41
      $Config = new Config([
         'driver' => 'mysql',
         'database' => 'bootgly',
         'username' => 'root',
         'password' => 'secret',
      ]);
      $capabilities = Capabilities::PROTOCOL_41
         | Capabilities::PLUGIN_AUTH
         | Capabilities::PLUGIN_AUTH_LENENC
         | Capabilities::CONNECT_WITH_DB;
      $auth = str_repeat("\x5A", 20);
      $response = $Encoder->encode(Encoder::RESPONSE, [
         'capabilities' => $capabilities,
         'auth' => $auth,
         'plugin' => 'mysql_native_password',
         'config' => $Config,
      ], 1);
      $payload = substr($response, 4);

      yield assert(
         assertion: substr($payload, 0, 4) === pack('V', $capabilities)
            && substr($payload, 32, 5) === "root\0"
            && $payload[37] === "\x14"
            && substr($payload, 38, 20) === $auth
            && substr($payload, 58, 8) === "bootgly\0"
            && substr($payload, 66) === "mysql_native_password\0",
         description: 'HandshakeResponse41 carries credentials, database and the plugin name'
      );

      // # Auth continuation
      yield assert(
         assertion: $Encoder->encode(Encoder::AUTH, "\x02", 3) === "\x01\x00\x00\x03\x02",
         description: 'Authentication continuations frame raw payload bytes'
      );
   }
);
