<?php


use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\Line;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


/**
 * M9 — the default `exceptions` reporter (registered by a Production boot) logs the message of
 * every Throwable a request raises, and PHP exceptions quote the client's bytes (a query string
 * `DateTimeImmutable` rejects, a value `BackedEnum::from()` refuses). That message must reach
 * the operator inert: no control sequence for the terminal, no line feed and no markup to forge
 * a record — shown, not dropped.
 *
 * The real Production boot runs in a forked child, so the runner keeps its statics (the
 * reporter registration latch included); the child is unconditionally terminal.
 */
return new Test(
   description: 'The default exceptions reporter logs a hostile exception message inert',
   test: function () {
      $payload = "a\r\nFORGED @.;x @#red:y \e]52;c;Zm9v\x07 \xC2\x9Bz \x9B";

      $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$Reader, $Writer] = $pair !== false ? $pair : [null, null];

      $pid = pcntl_fork();
      if ($pid === 0) {
         // # Child — a Production boot on a private copy of the statics
         try {
            // ! The live tap captures what the reporter logs: the record's message as the
            //   reporter built it, and the line a terminal would receive
            $Capture = new class (new Line(Display::MESSAGE | Display::CHANNEL | Display::SEVERITY | Display::CONTEXT)) extends Handler {
               /** @var array<int,array{channel:string,message:string,line:string}> */
               public array $captured = [];

               protected function write (string $formatted, Record $Record): bool
               {
                  $this->captured[] = [
                     'channel' => $Record->channel,
                     'message' => base64_encode($Record->message),
                     'line' => base64_encode($formatted),
                  ];
                  return true;
               }
            };
            Display::show(Display::NONE);
            Logger::$Sinks = null;
            Logger::$Tap = $Capture;

            HTTP_Server_CLI::boot(Environments::Production);
            Throwables::notify(new RuntimeException($payload));
            // ! An anonymous class — its `get_class()` name embeds a NUL and a path
            Throwables::notify(new class ('anonymous') extends RuntimeException {});
            // ! A message quoting a whole request body
            Throwables::notify(new RuntimeException(str_repeat("\x01@", 1 << 20)));

            fwrite($Writer, json_encode($Capture->captured) ?: '[]');
            fclose($Writer);
         }
         catch (Throwable) {
         }
         finally {
            // ! Hard exit — no shutdown handlers, no inherited output flush
            posix_kill(posix_getpid(), SIGKILL);
         }
      }

      // # Parent
      fclose($Writer);
      stream_set_blocking($Reader, false);
      $JSON = '';
      $deadline = time() + 15;
      while (time() < $deadline) {
         $chunk = fread($Reader, 65536);
         if (is_string($chunk) && $chunk !== '') {
            $JSON .= $chunk;
         }
         if (feof($Reader)) {
            break;
         }
         usleep(20000);
      }
      fclose($Reader);
      if ($pid > 0) {
         // ? A child that outlived the deadline never holds the run
         if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
         }
      }

      // ! The `exceptions` records, in order: hostile, anonymous, oversized
      $records = [];
      foreach ((array) json_decode($JSON, true) as $captured) {
         if (is_array($captured) && ($captured['channel'] ?? null) === 'exceptions') {
            $records[] = [
               base64_decode((string) $captured['message']),
               base64_decode((string) $captured['line']),
            ];
         }
      }
      [$message, $line] = $records[0] ?? [null, ''];

      yield assert(
         assertion: $message !== null,
         description: 'a Production boot registers the reporter and it logs the exception'
      );

      yield assert(
         assertion: $message === 'a\r\nFORGED .;x #red:y \u001b]52;c;Zm9v\u0007 \u009bz ?',
         description: 'the message is inert and complete: controls escaped, markup defused, a non-UTF-8 byte replaced — got '
            . json_encode($message)
      );

      yield assert(
         assertion: substr_count($line, "\n") === 1 && str_ends_with($line, "\n")
            && mb_check_encoding($line, 'UTF-8')
            && preg_match('/\e(?!\[[0-9;]*m)|\xC2[\x80-\x9F]|[\x00-\x09\x0B-\x1A\x1C-\x1F\x7F]/', $line) === 0,
         description: 'the terminal line is one UTF-8 record with no control sequence but SGR'
      );

      yield assert(
         assertion: str_contains($records[1][1] ?? '', '"class":"RuntimeException@anonymous"'),
         description: 'an anonymous exception is named in the context without the NUL and path get_class() embeds'
      );

      yield assert(
         assertion: strlen($records[2][0] ?? '') <= 4096 * 6 + 3 && str_ends_with($records[2][0] ?? '', '...')
            && str_contains($records[2][0] ?? '', '@') === false,
         description: 'a message quoting a whole request body is capped before it is escaped (and still defused)'
      );

      // # Pin: every exception message the server logs goes through defuse() — the Auto-TLS
      //   failure is logged by the certifier child only, out of reach of a live probe.
      //   Token-based: each `->getMessage()` outside the E2E harness (`test()`) must be the
      //   direct argument of `self::defuse(`.
      $tokens = array_values(array_filter(
         token_get_all((string) file_get_contents((string) (new ReflectionClass(HTTP_Server_CLI::class))->getFileName())),
         static fn (array|string $token): bool => is_array($token) === false
            || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
      ));
      $text = static fn (array|string $token): string => is_array($token) ? $token[1] : $token;
      $method = '';
      $depth = 0;
      $opened = -1;
      $calls = 0;
      $bare = [];
      foreach ($tokens as $index => $token) {
         // @ Track the named method the token sits in (closures inherit it)
         if (is_array($token) && $token[0] === T_FUNCTION && is_array($tokens[$index + 1]) && $tokens[$index + 1][0] === T_STRING) {
            $method = $tokens[$index + 1][1];
            $opened = -1;
         }
         $value = $text($token);
         if ($value === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            if ($opened === -1 && $method !== '') {
               $opened = $depth;
            }
         }
         else if ($value === '}') {
            if ($depth === $opened) {
               $method = '';
               $opened = -1;
            }
            $depth--;
         }

         // @ A `getMessage()` call
         if (is_array($token) && $token[0] === T_STRING && $token[1] === 'getMessage' && $text($tokens[$index - 1]) === '->') {
            if ($method === 'test') {
               continue;
            }
            $calls++;
            $wrapped = $text($tokens[$index - 3] ?? '') === '('
               && $text($tokens[$index - 4] ?? '') === 'defuse'
               && $text($tokens[$index - 5] ?? '') === '::'
               && $text($tokens[$index - 6] ?? '') === 'self';
            if ($wrapped === false) {
               $bare[] = $token[2];
            }
         }
      }
      yield assert(
         assertion: $calls >= 2 && $bare === [],
         description: 'every exception message the server logs (reporter, Auto-TLS) goes through defuse(); bare at lines: '
            . json_encode($bare)
      );
   }
);
