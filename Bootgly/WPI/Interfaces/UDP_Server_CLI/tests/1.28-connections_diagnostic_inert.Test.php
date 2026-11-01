<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\CLI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\Stream;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


/**
 * M9: a UDP worker's `connections` diagnostic (its SIGIOT command) prints an
 * explicit scalar allowlist — never the bytes a peer sent nor the reply kept
 * for it.
 *
 * The pre-fix command walked every property of each mirror entry through an
 * `(array)` cast. The peer's last datagram (`input`) and the reply (`output`)
 * reached the operator's terminal through the Logger, which keeps line feeds
 * and complete SGR, renders Output markup and writes bytes that are not UTF-8
 * as they are: a datagram could forge a log record, recolour the terminal,
 * send a raw C1 CSI and leak a secret, and the cast printed the
 * `\u0000`-mangled names of the private and protected properties.
 *
 * One peer is admitted through a real Router turn that feeds it a hostile
 * datagram; a hostile reply is kept in its `output`. Every planted form carries
 * a printable token, so it is found in any rendering — raw, escaped
 * (`\u001b`), scrubbed (`?`) or rendered (SGR). The diagnostic runs through
 * the exact-scope registered command, with the server's Logger writing only
 * to php://temp:
 *
 *   A) the fixture: the datagram reached the peer's `input` byte for byte;
 *   B) control: the pre-fix walk over the same peer — its `ip` and mirror key
 *      rewritten too — prints every planted form, so the checks below can fail;
 *   C) the diagnostic completes, prints none of them in any form and holds only
 *      the allowlisted fields, the two buffers by their lengths;
 *   D) a rewritten `ip` shows as UNKNOWN and the header numbers the peer
 *      instead of printing its mirror key;
 *   E) a mirror entry whose property read throws stops the diagnostic: run()
 *      returns false, nothing escapes it nor the SIGIOT dispatch, and the
 *      partial output lacks the completion line.
 */
return new Test(
   description: 'M9: the UDP `connections` diagnostic prints only allowlisted metadata — never a peer\'s datagram or reply, raw or escaped — and a failing mirror entry makes run() return false without escaping',
   test: function () {
      // ! What a hostile peer sends, and the reply an application keeps for it
      $datagram = implode(' ', [
         'M9-UDP-SECRET-5C21',
         "OSC[\e]52;c;TTktVURQ\x07]",
         "C1[\u{9D}52;c;TTktQzE=\u{9C}]",
         'MARKUP[@#red:M9-UDP-MARKUP@;]',
         "\n[2026-10-30T00:00:00+00:00] UDP.Server.CLI.INFO: M9-UDP-FORGED record",
         "CSI[\x9B2J M9-UDP-CSI]",
         "SGR[\e[41;97mM9-UDP-SGR\e[0m]",
      ]);
      $reply = implode(' ', [
         'M9-UDP-REPLY-SECRET-9A47',
         "OSC[\e]52;c;TTktUkVQTFk=\x07]",
         'MARKUP[@#red:M9-UDP-REPLY-MARKUP@;]',
         "\n[2026-10-30T00:00:01+00:00] UDP.Server.CLI.INFO: M9-UDP-REPLY-FORGED record",
      ]);
      // ! An `ip` and a mirror key rewritten by application code
      $IP = "203.0.113.7\e]52;c;TTktSVA=\x07 @#red:M9-UDP-IP@;";
      $key = "198.51.100.9:9\e]52;c;TTktS0VZ\x07 @#red:M9-UDP-KEY@;";
      // ! Every planted form: a printable token found in any rendering, the
      //   live forms a terminal would act on, and a cast-mangled name
      $forms = [
         'secret' => 'M9-UDP-SECRET-5C21',
         'OSC 52' => 'TTktVURQ',
         'C1 OSC 52' => 'TTktQzE=',
         'markup' => 'M9-UDP-MARKUP',
         'forged record' => 'M9-UDP-FORGED',
         'C1 CSI' => 'M9-UDP-CSI',
         'SGR' => 'M9-UDP-SGR',
         'reply' => 'M9-UDP-REPLY',
         'rewritten IP' => 'M9-UDP-IP',
         'mirror key' => 'M9-UDP-KEY',
         'raw C1 CSI byte' => "\x9B2J",
         'raw SGR' => "\e[41;97m",
         'rendered markup' => "\e[31mM9-UDP-MARKUP",
         'line feed before a forged record' => "\n[2026-10-30T00:00:00+00:00]",
         'mangled property name' => '\u0000',
      ];
      // ! The allowlist, in the order the diagnostic prints it
      $allowlist = [
         'status', 'IP', 'port', 'expiration', 'started', 'used', 'writes', 'timers',
         'input bytes', 'output bytes', 'consumed bytes', 'rejected', 'decoder', 'encoder', 'decoded',
      ];
      $completion = 'Connections diagnostic complete.';

      /** The labels of the planted forms found in a capture. */
      $Find = static function (string $capture) use ($forms): array {
         return array_keys(array_filter(
            $forms,
            static fn (string $form): bool => str_contains($capture, $form)
         ));
      };
      /**
       * The capture as plain text — the Logger's own SGR removed — and its
       * lines: headers, `key: value` fields and any other line.
       */
      $Read = static function (string $capture): array {
         $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $capture);
         $headers = [];
         $fields = [];
         $lines = [];
         foreach (explode("\n", $plain) as $line) {
            if ($line === '') {
               continue;
            }

            if (preg_match('/^(?:Worker|Peer) #\d+:$/', $line) === 1) {
               $headers[] = $line;
            }
            else if (preg_match('/^ ([A-Za-z ]+): (.*)$/', $line, $match) === 1) {
               $fields[] = [$match[1], $match[2]];
            }
            else {
               $lines[] = $line;
            }
         }

         return [$plain, $headers, $fields, $lines];
      };

      // ! Statics this case swaps
      $EventProperty = new ReflectionProperty(UDP_Server_CLI::class, 'Event');
      $PreviousEvent = $EventProperty->isInitialized() ? UDP_Server_CLI::$Event : null;
      $PreviousSinks = Logger::$Sinks;
      $PreviousTap = Logger::$Tap;
      $segments = Display::$segments;
      $debug = Vars::$debug;
      $print = Vars::$print;
      $exit = Vars::$exit;
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      $PreviousHandler = isSet(SAPI::$Handler) ? SAPI::$Handler : null;
      $PreviousDecoder = UDP_Server_CLI::$Decoder;
      Timer::init(static function (): void {});
      // ! Nothing reaches the runner's terminal nor a global sink
      Display::show(Display::NONE);
      Logger::$Sinks = null;
      Logger::$Tap = null;
      $Server = null;
      $ServerHandlers = null;
      $Socket = false;
      $Source = false;
      $Stub = null;

      try {
         Timer::del();
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new Configs(host: '127.0.0.1', port: 19982, workers: 1));
         $ServerHandlers = $Server->Logger->Handlers;
         UDP_Server_CLI::$Decoder = null;
         SAPI::$Handler = static fn (string $input): string => 'M9-UDP-ACK';
         $Socket = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $Source = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         if ($Socket === false || $Source === false) {
            throw new RuntimeException("Could not bind the loopback UDP sockets: {$message}");
         }
         stream_set_blocking($Socket, false);
         (new ReflectionProperty($Server, 'Socket'))->setValue($Server, $Socket);
         $target = (string) stream_socket_get_name($Socket, false);
         $origin = (string) stream_socket_get_name($Source, false);
         [, $port] = explode(':', $origin);
         // ! The command the worker's SIGIOT runs, registered for this exact server
         $Command = CLI->Commands->find('connections', From: $Server);

         // # A) One real readiness turn admits the peer and feeds it the datagram
         stream_socket_sendto($Source, $datagram, 0, $target);
         $read = [$Socket];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 2) === 1) {
            $Server->Connections->Router->reading($Socket);
         }
         $Mirror = Connections::$Connections;
         $Connection = $Mirror[$origin] ?? null;

         yield assert(
            assertion: $Command !== null
               && $Connection instanceof Connection
               && $Mirror === [$origin => $Connection]
               && $Connection->input === $datagram,
            description: 'fixture: a real Router turn admitted the hostile peer, whose `input` holds the datagram byte for byte, and the `connections` command is registered for this server'
         );
         // ? Only reached with assertions that do not throw
         if ($Command === null || $Connection instanceof Connection === false) {
            return;
         }

         // ! The transport keeps no reply; an Encoder or the application may
         //   retain one in `output` — the diagnostic reads whatever is there
         $Connection->output = $reply;

         /**
          * Run one diagnostic with the server's records captured in memory.
          *
          * @return array{mixed,null|Throwable,string} Returned value, Throwable, capture.
          */
         $Capture = static function (Closure $Run) use ($Server): array {
            $sink = fopen('php://temp', 'w+b');
            if ($sink === false) {
               throw new RuntimeException('Could not open the capture stream.');
            }
            $Handlers = new Handlers;
            $Handlers->push(new Stream($sink));
            $Server->Logger->Handlers = $Handlers;
            Display::show(Display::MESSAGE);
            $returned = null;
            $Thrown = null;

            try {
               $returned = $Run();
            }
            catch (Throwable $Throwable) {
               $Thrown = $Throwable;
            }
            finally {
               Display::show(Display::NONE);
            }

            rewind($sink);
            $capture = (string) stream_get_contents($sink);
            fclose($sink);

            return [$returned, $Thrown, $capture];
         };
         /** Run $Run over the same peer with its `ip` and its mirror key rewritten. */
         $Rewrite = static function (Closure $Run) use ($Connection, $Mirror, $IP, $key): mixed {
            $address = $Connection->ip;
            $Connection->ip = $IP;
            Connections::$Connections = [$key => $Connection];

            try {
               return $Run();
            }
            finally {
               $Connection->ip = $address;
               Connections::$Connections = $Mirror;
            }
         };
         $Diagnose = static fn (): bool => $Command->run();
         $Dispatch = static function () use ($Server): bool {
            $Server->handle(SIGIOT);

            return true;
         };
         // ! Control: the pre-fix diagnostic, reduced to what made it leak —
         //   every property of each mirror entry through an `(array)` cast,
         //   objects and the shared socket skipped
         $Walk = static function () use ($Server): bool {
            foreach (Connections::$Connections as $peer => $Peer) {
               $Server->Logger->log(info: "Peer #{$peer}:" . PHP_EOL);

               foreach ((array) $Peer as $name => $value) {
                  if (is_object($value) || $name === 'Socket') {
                     continue;
                  }

                  $value = is_array($value) ? count($value) : $value;
                  $Server->Logger->log(debug: "@:notice: {$name}: @; ");
                  $Server->Logger->log(debug: "{$value}" . PHP_EOL);
               }
            }

            return true;
         };

         // # B) Control: the pre-fix walk prints every planted form
         [, , $witness] = $Rewrite(static fn (): array => $Capture($Walk));
         $missed = implode(', ', array_diff(array_keys($forms), $Find($witness))) ?: 'none';

         yield assert(
            assertion: $Find($witness) === array_keys($forms),
            description: "control: the pre-fix `(array)` walk over the same peer prints every planted form — tokens, the raw C1 CSI byte, raw SGR, rendered markup, a forged record and mangled names (missed: {$missed})"
         );

         // # C) The diagnostic over the hostile peer
         [$returned, $Thrown, $capture] = $Capture($Diagnose);
         [$plain, $headers, $fields, $lines] = $Read($capture);
         $values = array_column($fields, 1, 0);
         $thrown = $Thrown === null ? 'nothing' : $Thrown::class;

         yield assert(
            assertion: $returned === true && $Thrown === null
               && str_ends_with($plain, "{$completion}\n"),
            description: "the diagnostic over the hostile peer completes: run() returns true and ends with the completion line ({$thrown} thrown)"
         );

         $found = implode(', ', $Find($capture)) ?: 'none';

         yield assert(
            assertion: $Find($capture) === []
               && preg_match('/[^\x20-\x7E\n]/', $plain) === 0
               && array_diff($lines, [$completion]) === [],
            description: "no planted form reaches the diagnostic, raw or escaped: its text is printable ASCII and every line is a header, a field or the completion line (found: {$found})"
         );

         yield assert(
            assertion: count($headers) === 2 && $headers[1] === 'Peer #1:'
               && array_column($fields, 0) === $allowlist
               && ($values['status'] ?? null) === 'ESTABLISHED'
               && ($values['IP'] ?? null) === '127.0.0.1'
               && ($values['port'] ?? null) === $port
               && ($values['input bytes'] ?? null) === (string) strlen($datagram)
               && ($values['output bytes'] ?? null) === (string) strlen($reply),
            description: 'the peer shows exactly the allowlisted fields — no property name, mangled or not — with its true status, IP and port, and the datagram and the reply only by their lengths'
         );

         // # D) The same peer, its `ip` and mirror key rewritten
         [$returned, $Thrown, $capture] = $Rewrite(static fn (): array => $Capture($Diagnose));
         [, $headers, $fields] = $Read($capture);
         $values = array_column($fields, 1, 0);
         $found = implode(', ', $Find($capture)) ?: 'none';

         yield assert(
            assertion: $returned === true && $Thrown === null
               && count($headers) === 2 && $headers[1] === 'Peer #1:'
               && ($values['IP'] ?? null) === 'UNKNOWN'
               && $Find($capture) === [],
            description: "an `ip` rewritten to markup and OSC 52 shows as UNKNOWN, and a mirror key carrying the same never reaches the header, which numbers the peer (found: {$found})"
         );

         // # E) A mirror entry whose first property read throws: a Connection
         //      that never ran its constructor, after the admitted peer
         $Stub = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         Connections::$Connections = [$origin => $Connection, 'M9-UDP-STUB' => $Stub];

         try {
            [$returned, $Thrown, $capture] = $Capture($Diagnose);
            [, $Propagated, $dispatched] = $Capture($Dispatch);
         }
         finally {
            Connections::$Connections = $Mirror;
         }
         $thrown = $Thrown === null ? 'nothing' : $Thrown::class;

         yield assert(
            assertion: $returned === false && $Thrown === null,
            description: "a mirror entry whose property read throws makes run() return false, and nothing escapes it ({$thrown} thrown)"
         );

         [$plain, $headers, $fields] = $Read($capture);
         $found = implode(', ', $Find($capture)) ?: 'none';

         yield assert(
            assertion: str_contains($plain, $completion) === false
               && end($headers) === 'Peer #2:'
               && count($fields) === count($allowlist)
               && $Find($capture) === [],
            description: "the contained failure is told apart from a complete diagnostic: the output stops at the failing entry, lacks the completion line and stays inert (found: {$found})"
         );

         $propagated = $Propagated === null ? 'nothing' : $Propagated::class;

         yield assert(
            assertion: $Propagated === null
               && str_contains($dispatched, 'Peer #2:')
               && str_contains($dispatched, $completion) === false,
            description: "through the real SIGIOT dispatch the same entry reaches the command and the worker's signal callback returns normally ({$propagated} propagated)"
         );
      }
      finally {
         // ! Every run put the real mirror back: close its peer (a server
         //   that was never built never initialized the mirror)
         if ($Server !== null) {
            foreach (Connections::$Connections as $Connection) {
               $Connection->close();
            }
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         unset($Connection, $Mirror, $Stub, $Rewrite, $Capture, $Diagnose, $Dispatch, $Walk, $Command);
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         pcntl_alarm(0);
         if ($Server !== null && $ServerHandlers !== null) {
            $Server->Logger->Handlers = $ServerHandlers;
         }
         if ($Source !== false) {
            @fclose($Source);
         }
         if ($Socket !== false) {
            @fclose($Socket);
         }
         if ($PreviousHandler !== null) {
            SAPI::$Handler = $PreviousHandler;
         }
         if ($PreviousEvent !== null) {
            UDP_Server_CLI::$Event = $PreviousEvent;
         }
         UDP_Server_CLI::$Decoder = $PreviousDecoder;
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         Logger::$Sinks = $PreviousSinks;
         Logger::$Tap = $PreviousTap;
         Vars::$debug = $debug;
         Vars::$print = $print;
         Vars::$exit = $exit;
         Display::show($segments);
      }
   }
);
