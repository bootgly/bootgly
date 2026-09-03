<?php


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\AccessLog;


/**
 * The target is client-controlled and the message is rendered through
 * `Template\Escaped`: every `@` and every byte outside printable ASCII enter
 * the message `%XX`-encoded, the target is capped, the query stays out — and
 * the context keeps the raw values. Severity follows the outcome.
 */
return new Test(
   description: 'AccessLog neutralizes client-controlled text in the message, keeps the raw values in the context and grades the line by outcome',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      $Capture = new class extends Handler {
         /** @var array<int,Record> */
         public array $Records = [];
         protected function write (string $formatted, Record $Record): bool
         {
            $this->Records[] = $Record;
            return true;
         }
      };
      $AccessLog = new AccessLog;
      $AccessLog->Logger->Handlers = new Handlers;
      $AccessLog->Logger->Handlers->push($Capture);

      $probe = function (string $URI, int $code = 200, string $method = 'GET') use ($createMocks, $AccessLog, $Capture): Record {
         [$Request, $Response] = $createMocks(requestProps: ['URI' => $URI, 'protocol' => 'HTTP/1.1', 'method' => $method]);
         $Capture->Records = [];
         $AccessLog->process($Request, $Response, static fn (object $Request, object $Response): object => $Response(code: $code, body: 'x'));

         return $Capture->Records[0];
      };

      try {
         // @ `@` and the Output directive it would open, a space, a control byte
         $Record = $probe("/a@#red:b c\x1b");
         yield new Assertion(
            description: 'Every `@`, space and control byte leaves the message %XX-encoded',
            fallback: $Record->message
         )
            ->expect(preg_match('/^GET \/a%40#red:b%20c%1B → 200 in [0-9.]+ms@\.;$/', $Record->message) === 1)
            ->to->be(true)
            ->assert();
         yield new Assertion(
            description: 'The context keeps the raw target',
         )
            ->expect($Record->context['URI'])
            ->to->be("/a@#red:b c\x1b")
            ->assert();

         // @ The method is client-controlled too
         $Record = $probe('/m', method: 'G@T');
         yield new Assertion(
            description: 'A `@` in the method is neutralized in the message and raw in the context',
            fallback: $Record->message
         )
            ->expect(str_starts_with($Record->message, 'G%40T /m → ') && $Record->context['method'] === 'G@T')
            ->to->be(true)
            ->assert();

         // @ UTF-8
         $Record = $probe('/café');
         yield new Assertion(
            description: 'Non-ASCII bytes are %XX-encoded, byte by byte',
            fallback: $Record->message
         )
            ->expect(str_starts_with($Record->message, 'GET /caf%C3%A9 → '))
            ->to->be(true)
            ->assert();

         // @ The cap
         $long = '/' . str_repeat('a', 200);
         $Record = $probe($long);
         $matches = [];
         preg_match('/^GET (\S+) → /u', $Record->message, $matches);
         yield new Assertion(
            description: 'A long target is capped at LIMIT characters, ending with an ellipsis',
            fallback: $Record->message
         )
            ->expect(mb_strlen($matches[1] ?? '') === AccessLog::LIMIT && str_ends_with($matches[1] ?? '', '…'))
            ->to->be(true)
            ->assert();
         yield new Assertion(
            description: 'The context keeps the whole target',
         )
            ->expect($Record->context['URI'])
            ->to->be($long)
            ->assert();

         // @ The query
         $Record = $probe('/p?token=secret@x');
         yield new Assertion(
            description: 'The query stays out of the message AND the context by default',
            fallback: $Record->message
         )
            ->expect(str_starts_with($Record->message, 'GET /p → ') && $Record->context['URI'] === '/p')
            ->to->be(true)
            ->assert();

         // @ Severity by outcome
         $levels = [];
         foreach ([200, 302, 404, 503] as $code) {
            $levels[$code] = $probe('/level', $code)->Level;
         }
         yield new Assertion(
            description: '2xx and 3xx are info, 4xx warning, 5xx error',
            fallback: json_encode(array_map(static fn (Levels $Level): string => $Level->name, $levels))
         )
            ->expect($levels === [200 => Levels::Info, 302 => Levels::Info, 404 => Levels::Warning, 503 => Levels::Error])
            ->to->be(true)
            ->assert();
      }
      finally {
         Display::$segments = $saved;
      }
   })
);
