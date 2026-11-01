<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use const STREAM_CLIENT_CONNECT;
use function array_diff;
use function array_merge;
use function array_values;
use function assert;
use function bin2hex;
use function chr;
use function count;
use function fclose;
use function feof;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_encode;
use function microtime;
use function mkdir;
use function pack;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rmdir;
use function scandir;
use function str_contains;
use function str_repeat;
use function str_replace;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use function unlink;
use function usleep;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


/**
 * The client demos (`projects/Demo/{HTTP,HTTPS,WS}_Client_CLI`) show a
 * server's reply as text and never obey it. Each demo is started through the
 * REAL launcher of a temporary kit — whose `projects/` is empty, so the
 * framework's shipped demo is the one that runs — against a hostile peer: a
 * child PHP process on 127.0.0.1, on a port the kernel picks, that answers
 * with an OSC 52 clipboard write, a DCS and an APC query, a CSI erase, an SGR
 * that conceals what follows, an OSC 52 in its C1 form (U+009D), Output markup
 * and a stray 0x9B (the 8-bit CSI, which is not UTF-8) — and, in a body or a
 * WS text message, a CR, a TAB and an LF. The HTTPS peer serves
 * `@/certificates/localhost.*`.
 *
 * - A plain socket client reads the peer's reply first: every introducer
 *   arrives raw. Without this control every "nothing raw" leg would also pass
 *   against a peer that sent nothing hostile.
 * - Each display site shows the reply as text: a control is the escape
 *   `json_encode()` writes (`\u001b`, `\u009d`), the markup loses its `@`, the
 *   stray byte is `?`. A body or a WS message keeps its TAB and LF, never its
 *   CR (`\r`): a raw one would let the reply overwrite the line it is on.
 * - Once the SGR the demo writes itself is set aside — its reset, underline
 *   and five colours, never one of the reply's — its output holds no control
 *   but TAB and LF, no C1 code point, is valid UTF-8 and emits no SGR from the
 *   markup.
 *
 * The display sites are every place a demo shows what the server chose: the
 * HTTP status reason and body, the HTTPS reason, header values and body — run
 * twice, since a body over 500 bytes is shown through a call of its own — and
 * each WS message. The reason phrase carries the C1 OSC, the markup and the
 * stray byte only: the client refuses a 7-bit control there (RFC 9112). The
 * line controls ride in the bodies and the WS text message only: the client
 * refuses a reply whose head holds a bare CR or LF. The WS demo runs without
 * `WS_ONCE`: the peer sends a text and a binary message, then a close, and
 * only the binary one can carry the stray byte (a text message must be UTF-8 —
 * the client closes 1007 on one that is not). The protocol, the header names
 * (tokens) and the WS subprotocol (one the demo never offers is refused)
 * cannot carry a payload.
 */
return new Test(
   description: 'The client demos show a hostile server reply as text: visible escapes, no C1, no stray byte, no live markup',
   // ? Nothing to spawn
   skip: function_exists('proc_open') === false,
   test: function () {
      // ! The hostile payloads, each labelled, and the text each must show
      $payloads = [
         // # OSC 52 — writes the clipboard
         'OSC52' => "\e]52;c;SGVsbG8=\x07",
         // # DCS XTGETTCAP — makes the terminal answer
         'DCS'   => "\eP+q544e\e\\",
         // # APC — a kitty graphics query
         'APC'   => "\e_Gi=1;a=q\e\\",
         // # CSI — erases the screen
         'CSI'   => "\e[2J",
         // # SGR — conceals what follows (a complete SGR: `Controls::escape()` keeps one only when asked)
         'SGR'   => "\e[8m",
         // # OSC 52 in its C1 form: U+009D … U+009C
         'C1OSC' => "\u{9D}52;c;SGVsbG8=\u{9C}",
         // # Output markup
         'MARK'  => '@#red:MARK@;',
      ];
      $visible = [
         'OSC52' => '\u001b]52;c;SGVsbG8=\u0007',
         'DCS'   => '\u001bP+q544e\u001b\\',
         'APC'   => '\u001b_Gi=1;a=q\u001b\\',
         'CSI'   => '\u001b[2J',
         'SGR'   => '\u001b[8m',
         'C1OSC' => '\u009d52;c;SGVsbG8=\u009c',
         'MARK'  => '#red:MARK;',
      ];
      $all = '';
      $shown = '';
      foreach ($payloads as $label => $bytes) {
         $all .= "{$label}[{$bytes}]";
         $shown .= "{$label}[{$visible[$label]}]";
      }
      // ! A stray 0x9B — the 8-bit CSI; not UTF-8, so only a scrub turns it into `?`
      $stray = "STRAY[\x9B31m]";
      $strayed = 'STRAY[?31m]';
      // ! The line controls a body or a WS message may hold: its CR shows as
      //   `\r` (a raw one returns the cursor, so the reply could overwrite the
      //   line), its TAB and LF stay as they are
      $lines = "CR[\r]TAB[\t]LF[\n]";
      $lined = "CR[\\r]TAB[\t]LF[\n]";

      // ! The HTTP reply — the reason phrase carries no 7-bit control (the
      //   client refuses one there); a header and the body carry them all
      $reason = "OK C1OSC[{$payloads['C1OSC']}]MARK[{$payloads['MARK']}]{$stray}";
      $reasoned = "OK C1OSC[{$visible['C1OSC']}]MARK[{$visible['MARK']}]{$strayed}";
      $body = "BODY {$all}{$stray}{$lines}";
      $length = strlen($body);
      $head = "HTTP/1.1 200 {$reason}\r\nContent-Type: text/plain\r\nX-Evil: {$all}{$stray}\r\n";
      $response = "{$head}Content-Length: {$length}\r\nConnection: close\r\n\r\n{$body}";
      // ! The same reply with a body over 500 bytes — the HTTPS demo shows
      //   its first 500 bytes and `...`, through a call of its own
      $filler = str_repeat('x', 600);
      $kept = str_repeat('x', 500 - $length);
      $overflow = strlen("{$body}{$filler}");
      $long = "{$head}Content-Length: {$overflow}\r\nConnection: close\r\n\r\n{$body}{$filler}";
      // ! The WS reply — a text message with the line controls and a binary
      //   one (only a binary one may carry a byte that is not UTF-8), then a
      //   close (1000)
      $Frame = static function (string $opcode, string $data): string {
         $length = strlen($data);
         $extended = pack('n', $length);
         $size = $length < 126 ? chr($length) : "\x7E{$extended}";

         // :
         return "{$opcode}{$size}{$data}";
      };
      $text = $Frame("\x81", "server says: {$all}{$lines}");
      $binary = $Frame("\x82", "server sends: {$all}{$stray}");
      $frames = "{$text}{$binary}\x88\x02\x03\xE8";

      // ! Each run: the demo, its peer, the reply that peer sends, how the
      //   demo is pointed at the peer's port, and the text each of its display
      //   sites shows
      $HTTPS = static fn (int $port): array => [["https://127.0.0.1:{$port}/"], []];
      $demos = [
         'HTTP_Client_CLI' => [
            'project' => 'HTTP_Client_CLI',
            'mode' => 'http',
            'reply' => $response,
            'start' => static fn (int $port): array => [[], ['HTTP_HOST' => '127.0.0.1', 'HTTP_PORT' => "{$port}"]],
            'sites' => ["200 {$reasoned}", "BODY {$shown}{$strayed}{$lined}"],
         ],
         'HTTPS_Client_CLI' => [
            'project' => 'HTTPS_Client_CLI',
            'mode' => 'https',
            'reply' => $response,
            'start' => $HTTPS,
            'sites' => ["200 {$reasoned}", "x-evil: {$shown}{$strayed}", "BODY {$shown}{$strayed}{$lined}\n"],
         ],
         'HTTPS_Client_CLI (a body over 500 bytes)' => [
            'project' => 'HTTPS_Client_CLI',
            'mode' => 'https',
            'reply' => $long,
            'start' => $HTTPS,
            'sites' => ["200 {$reasoned}", "x-evil: {$shown}{$strayed}", "BODY {$shown}{$strayed}{$lined}{$kept}...\n"],
         ],
         'WS_Client_CLI' => [
            'project' => 'WS_Client_CLI',
            'mode' => 'ws',
            'reply' => $frames,
            'start' => static fn (int $port): array => [[], ['WS_HOST' => '127.0.0.1', 'PORT' => "{$port}", 'WS_NOCOMPRESS' => '1']],
            'sites' => ["server says: {$shown}{$lined}", "server sends: {$shown}{$strayed}"],
         ],
      ];

      // ! A human run (agent markers would change the contract) whose demo
      //   settings are only the ones each demo is given below
      $environment = getenv();
      foreach ([
         ...array_merge(...array_values(Agent::MARKERS)),
         'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY',
         'HTTP_HOST', 'HTTP_PORT', 'PORT',
         'WS_AUTH', 'WS_HOST', 'WS_MESSAGE', 'WS_NOCOMPRESS', 'WS_ONCE', 'WS_ORIGIN',
         'WS_PATH', 'WS_RECONNECT', 'WS_RECONNECT_DELAY', 'WS_TLS',
      ] as $variable) {
         unset($environment[$variable]);
      }

      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      // ! An output without the SGR the demo writes itself: reset (0), the
      //   launcher footer's underline (4) and bright black (90), the demo's
      //   green, yellow, cyan and white (32, 33, 36, 37). Any other SGR — the
      //   reply's conceal (8) — stays, and so does its ESC
      $Strip = static fn (string $output): string => (string) preg_replace('/\e\[(?:0|4|3[2367]|90)m/', '', $output);
      // ! An excerpt for a failing description — controls escaped, and no `@`
      //   left to open markup in the report that renders it
      $Excerpt = static fn (string $output): string => str_replace('@', '(at)', (string) json_encode(
         substr($output, -900),
         JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
      ));
      // ! Every introducer of the payloads, raw
      $Raw = static fn (string $reply): bool => str_contains($reply, "\e]52;c;")
         && str_contains($reply, "\eP+q544e\e\\")
         && str_contains($reply, "\e_Gi=1;a=q\e\\")
         && str_contains($reply, "\e[2J")
         && str_contains($reply, "\e[8m")
         && str_contains($reply, "\u{9D}52;c;")
         && str_contains($reply, '@#red:MARK@;')
         && str_contains($reply, "\x9B31m")
         && str_contains($reply, "CR[\r]");
      // ! A plain socket client: one request, then the bytes as they arrive —
      //   until the whole reply is in, the peer closes or 10 s pass without a byte
      $Ask = static function (string $mode, int $port, string $whole): string {
         $scheme = $mode === 'https' ? 'ssl' : 'tcp';
         $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
         $Socket = @stream_socket_client("{$scheme}://127.0.0.1:{$port}", $code, $error, 10, STREAM_CLIENT_CONNECT, $context);
         // ?
         if ($Socket === false) {
            return '';
         }

         stream_set_timeout($Socket, 10);
         $fields = $mode === 'ws'
            ? "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13"
            : 'Connection: close';
         fwrite($Socket, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n{$fields}\r\n\r\n");
         $reply = '';
         // @@ Closing first lets the peer, which reads until EOF, go on at once
         while (str_contains($reply, $whole) === false && feof($Socket) === false) {
            $chunk = fread($Socket, 65536);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($Socket)['timed_out'] === true)) {
               break;
            }
            $reply .= $chunk;
         }
         fclose($Socket);

         // :
         return $reply;
      };

      $directory = Temporaries::reserve('demo-reply-inert');
      $kit = "{$directory}/kit";
      $peer = "{$directory}/peer.php";
      // ! The real launcher of the kit: `project Demo/<Name> start …`, stdin
      //   closed, stdout and stderr apart, and a deadline — a demo that hangs
      //   is killed and reported as status -1. `?` per stray byte on every
      //   host: the demo scrubs with ext-mbstring's `mb_scrub()`, which writes
      //   whatever `mbstring.substitute_character` holds (php.ini reaches the
      //   child), so the child runs with it pinned to 63
      $Boot = static function (string $name, array $arguments, array $variables) use ($kit, $environment): array {
         /** @var list<string> $arguments */
         /** @var array<string,string> $variables */
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'mbstring.substitute_character=63', "{$kit}/bootgly", 'project', "Demo/{$name}", 'start', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $kit,
            [...$environment, ...$variables]
         );
         // ? A demo that never started would make every "nothing raw" leg true
         if (is_resource($Process) === false) {
            throw new RuntimeException("The {$name} demo could not be spawned.");
         }

         /** @var array<int,resource> $pipes */
         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $streams = [1 => '', 2 => ''];
         $open = [1 => $pipes[1], 2 => $pipes[2]];
         $expired = false;
         $deadline = microtime(true) + 60;
         // @@ Drain both streams until they close or the deadline passes
         while ($open !== []) {
            if (microtime(true) > $deadline) {
               $expired = true;

               break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
               break;
            }

            foreach ($open as $index => $pipe) {
               $chunk = fread($pipe, 65536);
               if ($chunk !== false) {
                  $streams[$index] .= $chunk;
               }
               if (feof($pipe) === true) {
                  fclose($pipe);
                  unset($open[$index]);
               }
            }
         }
         foreach ($open as $pipe) {
            fclose($pipe);
         }
         // @@ Reap it — a demo closes its pipes a moment before it exits. The
         //    first status that reports the exit is the only one holding its
         //    code (`proc_close()` then returns -1)
         $status = -1;
         while ($expired === false) {
            $state = proc_get_status($Process);
            if ($state['running'] === false) {
               $status = $state['exitcode'];

               break;
            }
            if (microtime(true) > $deadline) {
               $expired = true;

               break;
            }
            usleep(20000);
         }
         // ? Past the deadline — kill it
         if ($expired === true) {
            proc_terminate($Process, 9);
         }
         proc_close($Process);

         // :
         return [$status, $streams[1], $streams[2]];
      };

      // ! Every peer started — stopped by its own handle in `finally`
      $Peers = [];
      try {
         // ! Kit — an entry point naming its directory the working one, nothing else
         $root = BOOTGLY_ROOT_DIR;
         $certificate = "{$root}@/certificates/localhost.cert.pem";
         $key = "{$root}@/certificates/localhost.key.pem";
         mkdir($kit, 0o700);
         file_put_contents("{$kit}/bootgly", <<<PHP
         <?php
         define('BOOTGLY_WORKING_BASE', __DIR__);
         define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);
         (include '{$root}autoboot.php') || exit(1);

         PHP);
         // ! The hostile peer — prints its port, then answers two connections
         //   (the control, then the demo) with the reply it was given, raw
         file_put_contents($peer, <<<'PHP'
         <?php
         // argv: <http|https|ws> <reply, hex> <certificate> <key>
         [, $mode, $hex, $certificate, $key] = $argv;
         $reply = (string) hex2bin($hex);
         $context = stream_context_create($mode === 'https'
            ? ['ssl' => ['local_cert' => $certificate, 'local_pk' => $key, 'verify_peer' => false]]
            : []);
         $scheme = $mode === 'https' ? 'ssl' : 'tcp';
         $Server = stream_socket_server("{$scheme}://127.0.0.1:0", $code, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
         if ($Server === false) {
            fwrite(STDERR, "listen: {$error}\n");
            exit(1);
         }
         $address = (string) stream_socket_get_name($Server, false);
         $port = substr($address, strrpos($address, ':') + 1);
         fwrite(STDOUT, "{$port}\n");
         fflush(STDOUT);
         for ($served = 0; $served < 2; $served++) {
            $Client = stream_socket_accept($Server, 60);
            if ($Client === false) {
               fwrite(STDERR, "accept {$served} failed\n");
               exit(2);
            }
            stream_set_timeout($Client, 10);
            $request = '';
            while (str_contains($request, "\r\n\r\n") === false) {
               $chunk = fread($Client, 8192);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $request .= $chunk;
            }
            if ($mode === 'ws') {
               $nonce = preg_match('/^Sec-WebSocket-Key: *(\S+)/mi', $request, $match) === 1 ? $match[1] : '';
               $accept = base64_encode(sha1("{$nonce}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
               fwrite($Client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
               usleep(200000);
            }
            fwrite($Client, $reply);
            // @@ Until the client is done (EOF, or 5 s without a byte): a byte
            //    it sent left unread would turn the close into a reset, which
            //    may discard the reply before the client has read it
            stream_set_timeout($Client, 5);
            while (feof($Client) === false) {
               $chunk = fread($Client, 8192);
               if ($chunk === false || ($chunk === '' && stream_get_meta_data($Client)['timed_out'] === true)) {
                  break;
               }
            }
            fclose($Client);
         }

         PHP);

         // @@ One demo at a time, each against its own peer
         foreach ($demos as $name => $demo) {
            $mode = $demo['mode'];
            $log = "{$directory}/{$mode}.log";
            $Peer = proc_open(
               [PHP_BINARY, '-d', 'opcache.jit=0', $peer, $mode, bin2hex($demo['reply']), $certificate, $key],
               [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
               $pipes
            );
            // ? A peer that never started fails its demo loudly, not vacuously
            if (is_resource($Peer) === false) {
               throw new RuntimeException("The {$mode} peer could not be spawned.");
            }
            /** @var array<int,resource> $pipes */
            $Peers[] = [$Peer, $pipes];

            // @ Its first line is the port it listens on
            $line = '';
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 15;
            while (str_contains($line, "\n") === false && microtime(true) < $deadline) {
               $read = [$pipes[1]];
               $write = null;
               $except = null;
               if (stream_select($read, $write, $except, 0, 200000) > 0) {
                  $chunk = fread($pipes[1], 64);
                  if ($chunk === false || ($chunk === '' && feof($pipes[1]) === true)) {
                     break;
                  }
                  $line .= $chunk;
               }
            }
            $port = (int) trim($line);

            // @ The control — a plain socket client reads what the demo will read
            $raw = $port > 0 ? $Ask($mode, $port, $demo['reply']) : '';
            $logged = is_file($log) ? (string) file_get_contents($log) : '';
            $refused = $Raw($raw) && str_contains($raw, $demo['reply'])
               ? ''
               : " — got {$Excerpt($raw)}, peer log {$Excerpt($logged)}";

            // # Leg 1 — the peer is hostile: its reply carries every introducer raw
            yield assert(
               assertion: $refused === '',
               description: "{$name}: the peer on 127.0.0.1:{$port}, read by a plain socket client, replies with every payload raw (ESC ] 52, ESC P, ESC _, ESC [ 2J, ESC [ 8m, U+009D, Output markup, a stray 0x9B, a CR){$refused}"
            );

            // @ The demo, pointed at the same peer
            [$arguments, $variables] = $demo['start']($port);
            [$status, $stdout, $stderr] = $Boot($demo['project'], $arguments, $variables);
            $output = "{$stdout}{$stderr}";
            $plain = $Strip($stdout);
            $sites = count($demo['sites']);
            // ! The reply reached every display site, in whatever form — so no
            //   "nothing raw" leg below can pass on a demo that showed nothing
            $reached = substr_count($plain, 'MARK[') === $sites;

            $missing = [];
            foreach ($demo['sites'] as $site) {
               if (str_contains($plain, $site) === false) {
                  $missing[] = $site;
               }
            }
            $unseen = match (true) {
               $missing !== [] => " — missing {$Excerpt(implode(' | ', $missing))} in {$Excerpt($output)}",
               $status !== 0 => " — got {$Excerpt($output)}",
               default => ''
            };

            // # Leg 2 — each display site shows the reply as text
            yield assert(
               assertion: $unseen === '',
               description: "{$name}: the demo answers (exit {$status}) and each of its {$sites} display sites shows the reply as text — controls as \\u001b / \\u009d / \\r escapes, a body's or message's TAB and LF kept, the markup defused, ? for the stray byte{$unseen}"
            );

            // # Leg 3 — no control but the demo's own SGR, TAB and LF
            $found = preg_match('/[\x00-\x08\x0B-\x1F\x7F].{0,40}/s', $Strip($output), $match) === 0
               ? ''
               : " — found {$Excerpt($match[0] ?? '')}";
            yield assert(
               assertion: $reached && $found === '',
               description: "{$name}: once the demo's own SGR (its reset, underline and five colours) is set aside, its output holds no ESC and no other control but TAB and LF — no OSC, DCS, APC, CSI or SGR of the reply can start, no CR can return the cursor{$found}"
            );

            // # Leg 4 — no C1 code point
            yield assert(
               assertion: $reached && preg_match('/\xC2[\x80-\x9F]/', $output) === 0,
               description: "{$name}: no C1 code point (U+0080 to U+009F, the UTF-8 form of an 8-bit control) reaches the output"
            );

            // # Leg 5 — no stray byte: the output is UTF-8
            yield assert(
               assertion: $reached && preg_match('//u', $output) === 1,
               description: "{$name}: the output is valid UTF-8 — the stray 0x9B (an 8-bit CSI to a terminal not in UTF-8) never reaches it"
            );

            // # Leg 6 — the reply's markup is inert
            yield assert(
               assertion: $reached
                  && str_contains($output, "\e[31m") === false
                  && substr_count($plain, 'MARK[#red:MARK;]') === $sites,
               description: "{$name}: the Output markup of the reply is inert at each display site — no SGR 31 is emitted and the text reads #red:MARK;"
            );
         }
      }
      finally {
         // @ Stop every peer still running — by its own handle, never by name
         foreach ($Peers as [$Peer, $pipes]) {
            if (is_resource($Peer) === false) {
               continue;
            }
            if (proc_get_status($Peer)['running'] === true) {
               proc_terminate($Peer, 9);
            }
            foreach ($pipes as $pipe) {
               if (is_resource($pipe) === true) {
                  fclose($pipe);
               }
            }
            proc_close($Peer);
         }
         $Erase($directory);
      }
   }
);
