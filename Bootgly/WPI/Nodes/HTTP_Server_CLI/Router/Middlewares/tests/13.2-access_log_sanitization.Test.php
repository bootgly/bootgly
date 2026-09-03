<?php


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Formatters\Line;
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
            ->expect(preg_match('/^GET \/a%40#red:b%20c%1B → 200 in [0-9.]+ms$/', $Record->message) === 1)
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
            ->expect(mb_strlen($matches[1] ?? '') <= AccessLog::LIMIT && str_ends_with($matches[1] ?? '', '…'))
            ->to->be(true)
            ->assert();
         yield new Assertion(
            description: 'The context keeps the whole target',
         )
            ->expect($Record->context['URI'])
            ->to->be($long)
            ->assert();

         // @ A cap landing inside an escape must not leave half of one
         $Record = $probe('/' . str_repeat('a', 98) . str_repeat('é', 20));
         $matches = [];
         preg_match('/^GET (\S+) → /u', $Record->message, $matches);
         $target = $matches[1] ?? '';
         yield new Assertion(
            description: 'A target capped in the middle of a %XX escape drops the whole escape',
            fallback: $target
         )
            ->expect(
               str_ends_with($target, '…')
               && strlen($target) <= AccessLog::LIMIT + 2
               && preg_match('/%[0-9A-F]?…$/', $target) !== 1
            )
            ->to->be(true)
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

         // @ A target that is not valid UTF-8 must not cost the record: the
         //   JSON encoder would refuse the whole envelope
         $Record = $probe("/a\xC3\x28b");
         $Encoded = new JSON;
         $decoded = json_decode($Encoded->format($Record), true);
         yield new Assertion(
            description: 'A non-UTF-8 target is stored encoded, flagged, and the JSON record still carries every field',
            fallback: json_encode(['context' => $Record->context, 'json' => $Encoded->format($Record)])
         )
            ->expect(
               ($Record->context['URI'] ?? null) === '/a%C3(b'
               && ($Record->context['encoded'] ?? null) === true
               && is_array($decoded)
               && ($decoded['channel'] ?? null) === 'HTTP.Server.CLI.access'
               && ($decoded['context']['peer'] ?? null) === '127.0.0.1'
               && ($decoded['context']['code'] ?? null) === 200
            )
            ->to->be(true)
            ->assert();

         // @ The same guarantee for a record the middleware did not build: the
         //   log formatters substitute what they cannot encode instead of
         //   dropping the envelope — every channel keeps its records
         $Foreign = new Record(Levels::Warning, 'chan', "boom \xC3\x28", ['URI' => "/a\xC3\x28b", 'code' => 404]);
         $document = json_decode(trim(new JSON()->format($Foreign)), true);
         Display::show(Display::MESSAGE, Display::CONTEXT);
         $dumped = new Line()->format($Foreign);
         Display::show(Display::MESSAGE);
         yield new Assertion(
            description: 'A record carrying bytes that are not UTF-8 survives both formatters, substituted, never collapsed',
            fallback: json_encode(['document' => $document, 'dumped' => $dumped], JSON_INVALID_UTF8_SUBSTITUTE)
         )
            ->expect(
               is_array($document)
               && ($document['channel'] ?? null) === 'chan'
               && ($document['level'] ?? null) === 'WARNING'
               && ($document['context']['code'] ?? null) === 404
               && str_contains((string) ($document['context']['URI'] ?? ''), "\u{FFFD}")
               && str_contains($dumped, '"code":404')
            )
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
