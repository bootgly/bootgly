<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\WS\Deflater;
use Bootgly\WPI\Modules\WS\Inflater;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/Client.php';


/**
 * H-WS-1 — over the wire, the server answers permessage-deflate with both
 * no-context-takeover flags, every compressed reply decodes on its own, and a
 * client that keeps its compression window gets 1007.
 */
return new Test(
   description: 'It should negotiate permessage-deflate without context takeover and compress each message on its own',
   skip: extension_loaded('zlib') === false,

   test: new Assertions(Case: function (): Generator {
      /** Decode a compressed payload with a fresh inflater. */
      $Fresh = static function (string $wire): string|int|false {
         $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);

         return $Inflator === false
            ? false
            : Inflater::inflate($Inflator, $wire, 1 << 20);
      };
      $key = base64_encode(random_bytes(16));
      $head = Client::raw(
         "GET /e2e HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
         . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n"
         . "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n\r\n"
      );
      yield new Assertion(description: 'the 101 answers both no-context-takeover flags')
         ->expect(str_contains($head, "\r\nSec-WebSocket-Extensions: permessage-deflate; server_no_context_takeover; client_no_context_takeover\r\n"))
         ->to->be(true)
         ->assert();

      // @ Two identical compressed messages from a client that drops its window
      $Socket = Client::open('permessage-deflate');
      yield new Assertion(description: 'compressed handshake established')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();
      if ($Socket === false) {
         return;
      }
      $text = 'compressed ' . bin2hex(random_bytes(32));
      $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => -1]);
      $replies = [];
      for ($index = 0; $index < 2; $index++) {
         $wire = $Deflator === false ? '' : (string) Deflater::deflate($Deflator, $text, false);
         fwrite($Socket, Client::mask(0x1, $wire, true));
         $reply = Client::read($Socket);
         $replies[] = [$reply['rsv1'] ?? null, $Fresh($reply['payload'] ?? '')];
      }
      yield new Assertion(description: 'each compressed reply decodes on its own')
         ->expect($replies)
         ->to->be([[true, "echo: {$text}"], [true, "echo: {$text}"]])
         ->assert();
      fclose($Socket);

      // @ A client that keeps its window refers to its previous message
      $Socket = Client::open('permessage-deflate');
      yield new Assertion(description: 'second compressed handshake established')
         ->expect($Socket !== false)
         ->to->be(true)
         ->assert();
      if ($Socket === false) {
         return;
      }
      $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => -1]);
      $first = $Deflator === false ? '' : (string) Deflater::deflate($Deflator, $text, true);
      fwrite($Socket, Client::mask(0x1, $first, true));
      $reply = Client::read($Socket);
      $second = $Deflator === false ? '' : (string) Deflater::deflate($Deflator, $text, true);
      fwrite($Socket, Client::mask(0x1, $second, true));
      yield new Assertion(description: 'a client keeping its window is closed with 1007')
         ->expect([$Fresh($reply['payload'] ?? ''), Client::close($Socket)])
         ->to->be(["echo: {$text}", 1007])
         ->assert();
      fclose($Socket);
   })
);
