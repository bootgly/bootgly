<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;


// ? M2 — the response head is parsed strictly: a malformed status line, field line,
//   Content-Length or Transfer-Encoding fails the response instead of being guessed at,
//   a head past the cap fails whatever maxResponseBytes allows, and the Connection field
//   is read as a token list (HCLI-16).
return new Test(
   description: 'It should frame HTTP/1.x responses strictly and refuse what cannot be trusted',
   test: function () {
      // ! One fresh decoder per shape — the static cache is keyed on the whole buffer
      $decode = static function (string $buffer, string $method = 'GET'): null|array {
         return (new Decoder_)->decode($buffer, strlen($buffer), $method);
      };
      $refused = static fn (null|array $parsed, string $status): bool => $parsed !== null
         && ($parsed['failed'] ?? false) === true
         && $parsed['status'] === $status;
      $shape = static fn (string $head, string $body = 'hello'): string => "{$head}\r\n\r\n{$body}";

      // # Header-less heads: the status line followed by the blank line itself
      $interim = $decode("HTTP/1.1 102 Processing\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
      $plain = $decode("HTTP/1.0 200 OK\r\n\r\nbody");
      $empty = $decode("HTTP/1.1 204 No Content\r\n\r\nNEXT");
      yield assert(
         assertion: ($interim['headerRaw'] ?? null) === '' && ($interim['consumed'] ?? null) === 27
            && ($interim['interim'] ?? null) === true
            && ($plain['headerRaw'] ?? null) === '' && ($plain['bodyRaw'] ?? null) === 'body'
            && ($empty['headerRaw'] ?? null) === '' && ($empty['consumed'] ?? null) === 27,
         description: 'a head with no field lines has an empty header section (never the next message)'
      );

      // # Status line
      $accepted = [
         'HTTP/1.1 200 OK', 'HTTP/1.1 200', 'HTTP/1.1 200 ', 'HTTP/1.0 404 Not Found',
         'HTTP/1.2 200 OK', "HTTP/1.1 200 A\tB", 'HTTP/1.1 599 Custom', 'HTTP/1.1 600 High', 'HTTP/1.1 999 Weird',
      ];
      $invalid = [
         'HTTP/9.9 999 Weird', 'HTTP/1.1 -5 Neg', 'SSH-2.0 100 x', 'HTTP/1.1  200 OK', 'http/1.1 200 OK',
         'HTTP/1.1 099 Low', 'HTTP/1.1 000 Zero', 'HTTP/1.1 2000 OK', 'HTTP/1.1 20a OK', 'HTTP/1.1 200OK',
         "HTTP/1.1 200 O\x01K", 'garbage', 'HTTP/1.1',
      ];
      $wrong = [];
      foreach ($accepted as $line) {
         $parsed = $decode($shape("{$line}\r\nContent-Length: 5"));
         if (($parsed['code'] ?? 0) < 100 || isSet($parsed['failed'])) {
            $wrong[] = "accepted: {$line}";
         }
      }
      foreach ($invalid as $line) {
         if ($refused($decode($shape("{$line}\r\nContent-Length: 5")), 'Invalid Response') === false) {
            $wrong[] = "invalid: {$line}";
         }
      }
      $unknown = $decode($shape('HTTP/1.1 999 Weird\r\nContent-Length: 5'));
      yield assert(
         assertion: $wrong === [] && ($unknown['code'] ?? null) === 999 && ($unknown['bodyRaw'] ?? null) === 'hello',
         description: 'status line: HTTP/1.DIGIT SP 3DIGIT (100-999; 6xx+ framed like 5xx, code kept) [SP reason]; wrong: ' . json_encode($wrong)
      );

      // # Line endings
      yield assert(
         assertion: $refused($decode("HTTP/1.1 200 OK\r\nX-A: 1\nContent-Length: 0\r\n\r\n"), 'Invalid Response')
            && $refused($decode("HTTP/1.1 200 OK\r\nX-A: 1\rContent-Length: 0\r\n\r\n"), 'Invalid Response'),
         description: 'a bare LF or a bare CR in the head is refused'
      );

      // # Content-Length
      $lengths = [
         '5' => 5, '005' => 5, '5, 5' => 5, "5,\t5" => 5, '0' => 0,
      ];
      $bad = ['-1', '5abc', '+3', '3, 50', '5,,5', '', ' ', '0x5', '99999999999999999999', '9223372036854775808', '1e3'];
      $wrong = [];
      foreach ($lengths as $value => $length) {
         $parsed = $decode($shape("HTTP/1.1 200 OK\r\nContent-Length: {$value}", str_repeat('x', $length)));
         if (($parsed['bodyLength'] ?? null) !== $length || ($parsed['bodyWaiting'] ?? true) !== false) {
            $wrong[] = "accepted: {$value}";
         }
      }
      foreach ($bad as $value) {
         if ($refused($decode($shape("HTTP/1.1 200 OK\r\nContent-Length: {$value}")), 'Invalid Response') === false) {
            $wrong[] = "invalid: {$value}";
         }
      }
      $maximum = $decode($shape("HTTP/1.1 200 OK\r\nContent-Length: 9223372036854775807"));
      yield assert(
         assertion: $wrong === [] && ($maximum['bodyLength'] ?? null) === PHP_INT_MAX,
         description: 'Content-Length: 1*DIGIT, overflow-checked, list elements equal; wrong: ' . json_encode($wrong)
      );

      yield assert(
         assertion: $refused($decode($shape("HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Length: 99")), 'Invalid Response')
            && ($decode($shape("HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Length: 05"))['bodyRaw'] ?? null) === 'hello',
         description: 'duplicate Content-Length lines must carry the same number'
      );

      // # Field-line syntax
      yield assert(
         assertion: $refused($decode($shape("HTTP/1.1 200 OK\r\nContent-Length : 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nX Y: 1\r\nContent-Length: 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nNoColon\r\nContent-Length: 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\n: empty\r\nContent-Length: 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nX-A: a\0b\r\nContent-Length: 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\n X-A: 1\r\nContent-Length: 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nX\0Y: 1\r\nContent-Length: 5")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nContent-Length\v: 99\r\nContent-Length: 5")), 'Invalid Response'),
         description: 'whitespace before the colon, a name that is not a token (NUL, VT), no colon, NUL in a value, a leading fold: refused'
      );

      // # obs-fold is unfolded to SP before interpretation
      $folded = $decode($shape("HTTP/1.1 200 OK\r\nX-A: 1\r\n folded\r\nContent-Length: 5"));
      yield assert(
         assertion: ($folded['headerRaw'] ?? null) === "X-A: 1 folded\r\nContent-Length: 5"
            && ($folded['bodyRaw'] ?? null) === 'hello'
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n 5")), 'Invalid Response'),
         description: 'obs-fold is replaced with SP (and a folded Content-Length is still validated)'
      );

      // # Transfer-Encoding
      $both = $decode("HTTP/1.1 200 OK\r\nContent-Length: 3\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n");
      $merged = $decode("HTTP/1.1 200 OK\r\nTransfer-Encoding: gzip\r\nTransfer-Encoding: chunked\r\n\r\n");
      yield assert(
         assertion: ($both['chunked'] ?? null) === true && ($both['closeConnection'] ?? null) === true
            && ($merged['chunked'] ?? null) === true
            && $refused($decode("HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n"), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nTransfer-Encoding: ")), 'Invalid Response')
            && $refused($decode($shape("HTTP/1.1 200 OK\r\nTransfer-Encoding: ,")), 'Invalid Response'),
         description: 'TE wins over CL and closes the connection; codings merge; HTTP/1.0 + TE and an empty TE are refused'
      );

      // # Connection — a token list (HCLI-16)
      $close = [
         'Connection: close', 'Connection:close', "Connection:\tclose", 'Connection: TE, close', 'CONNECTION: Close',
         'Connection: keep-alive, close',
      ];
      $keep = ['Proxy-Connection: close', 'X-Connection: close', 'Connection: closed', 'Connection: keep-alive'];
      $wrong = [];
      foreach ($close as $field) {
         if (($decode($shape("HTTP/1.1 200 OK\r\n{$field}\r\nContent-Length: 5"))['closeConnection'] ?? false) !== true) {
            $wrong[] = "close: {$field}";
         }
      }
      foreach ($keep as $field) {
         if (($decode($shape("HTTP/1.1 200 OK\r\n{$field}\r\nContent-Length: 5"))['closeConnection'] ?? true) !== false) {
            $wrong[] = "keep: {$field}";
         }
      }
      $old = $decode($shape("HTTP/1.0 200 OK\r\nContent-Length: 5"));
      $oldKept = $decode($shape("HTTP/1.0 200 OK\r\nConnection: Keep-Alive\r\nContent-Length: 5"));
      $head = $decode("HTTP/1.1 302 Found\r\nLocation: /next\r\nConnection:close\r\n\r\n", 'HEAD');
      $noContent = $decode("HTTP/1.1 204 No Content\r\nConnection: TE, close\r\n\r\n");
      yield assert(
         assertion: $wrong === []
            && ($old['closeConnection'] ?? false) === true && ($oldKept['closeConnection'] ?? true) === false
            && ($head['closeConnection'] ?? false) === true && ($noContent['closeConnection'] ?? false) === true,
         description: 'Connection tokens decide close — on no-body responses too; wrong: ' . json_encode($wrong)
      );

      // # No-body responses: framing fields are syntax-checked, never used for framing
      yield assert(
         assertion: $refused($decode("HTTP/1.1 204 No Content\r\nContent-Length: -1\r\n\r\n"), 'Invalid Response')
            && ($decode("HTTP/1.1 304 Not Modified\r\nContent-Length: 1000\r\n\r\n")['consumed'] ?? null) === 51
            && ($decode("HTTP/1.1 304 Not Modified\r\nContent-Length: 1000\r\n\r\n")['bodyWaiting'] ?? null) === false,
         description: 'a no-body response rejects a malformed Content-Length but is not framed by a valid one'
      );

      // # The static cache keeps small responses only (a large head, Set-Cookie included, is never retained)
      $large = "HTTP/1.1 204 No Content\r\nSet-Cookie: " . str_repeat('s', 4096) . "\r\n\r\n";
      $small = "HTTP/1.1 204 No Content\r\nX-Cached: 1\r\n\r\n";
      // ! Small heads followed by a large leftover in the same read — the key
      //   is the WHOLE buffer, so the leftover must never be retained with it
      $interimLeftover = "HTTP/1.1 102 Processing\r\n\r\nHTTP/1.1 200 OK\r\nX-Pad: " . str_repeat('p', 61440);
      $pipelined = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nokHTTP/1.1 200 OK\r\nX-Pad: " . str_repeat('p', 61440);
      $decode($large);
      $decode($small);
      $decode($interimLeftover);
      $decode($pipelined);
      /** @var array<string,mixed> $cache */
      $cache = (new ReflectionMethod(Decoder_::class, 'decode'))->getStaticVariables()['cache'] ?? [];
      yield assert(
         assertion: isSet($cache[$small])
            && isSet($cache[$large]) === false
            && isSet($cache[$interimLeftover]) === false
            && isSet($cache[$pipelined]) === false,
         description: 'the decode cache stores small buffers only — never a small head with a large leftover'
      );

      // # Header-section cap — whatever maxResponseBytes allows
      $pad = str_repeat('X-Pad: ' . str_repeat('a', 1017) . "\r\n", 64);
      $open = "HTTP/1.1 200 OK\r\n{$pad}";
      $closed = "HTTP/1.1 200 OK\r\n{$pad}Content-Length: 0\r\n\r\n";
      $fits = "HTTP/1.1 200 OK\r\n" . substr($pad, 0, 1026 * 60) . "Content-Length: 0\r\n\r\n";
      $leftover = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello" . "HTTP/1.1 200 OK\r\n{$pad}";
      yield assert(
         assertion: $decode(substr($open, 0, 60000)) === null
            && $refused($decode($open), 'Response Header Fields Too Large')
            && $refused($decode($closed), 'Response Header Fields Too Large')
            && ($decode($fits)['code'] ?? null) === 200
            && ($decode($leftover)['consumed'] ?? null) === 43,
         description: 'a head past 64 KiB is refused, terminated or not; a complete response with a large leftover is not'
      );
   }
);
