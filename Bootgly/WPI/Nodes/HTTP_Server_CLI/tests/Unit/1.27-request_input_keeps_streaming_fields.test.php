<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Validators\Required;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\BodyParser;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Validator\Sources;

/**
 * Regression (REQ-2) — `receive()` must honor the streaming invariant the
 * `$fields` hook already carries.
 *
 * A `multipart/form-data` body has no `Body->raw` to re-parse:
 * `Decoder_Downloading` publishes its parts through `$Request->fields` /
 * `$Request->files` and never writes `Body->raw`/`Body->input`. Reading
 * `$Request->input` therefore materialized an EMPTY input, `input()` returned
 * `[]` rather than `null`, and the `!== null` guard let that `[]` overwrite the
 * decoder's map — an upload form silently kept its files and lost every text
 * field.
 *
 * The shipped `BodyParser` does exactly that read (`BodyParser.php:64`), so
 * mounting it in front of `Validator(Sources::Fields)` turned a valid upload
 * into a 422. That chain is asserted here with a REAL decoded Request, because
 * `Router/Middlewares/tests/0.mock.php` hands the middlewares a `stdClass`
 * whose `fields` is a plain array — the suite structurally cannot see this.
 */

if (! class_exists('U127Connection', false)) {
   class U127Connection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 1;
      }
   }
}


return new Specification(
   description: 'It should keep the streaming decoder fields when the request input is read',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U127 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      // ! Prime the worker-global cells the body decoders read.
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;
      if (! isset($WPI->Server)) {
         /** @var HTTP_Server_CLI $Server */
         $Server = (new ReflectionClass(HTTP_Server_CLI::class))->newInstanceWithoutConstructor();
         $WPI->Server = $Server;
      }
      HTTP_Server_CLI::$Request = new Request;
      $WPI->Request = &HTTP_Server_CLI::$Request;

      try {
         $Connection = new U127Connection($Socket);
         $n = 0;
         $BOUNDARY = '----u127';
         $MULTIPART = "multipart/form-data; boundary={$BOUNDARY}";

         $drive = function (string $raw) use ($Connection): Request {
            $Package = new class($Connection) extends TCPPackages {};
            $Package->changed = true;
            (new Decoder_)->decode($Package, $raw, strlen($raw));

            /** @var Request $Request */
            $Request = $Package->decoded;
            return $Request;
         };

         $wire = function (string $type, string $body) use (&$n): string {
            $n++;

            return "POST /u127?n={$n} HTTP/1.1\r\nHost: localhost\r\n"
               . "Content-Type: {$type}\r\n"
               . "Content-Length: " . strlen($body) . "\r\n\r\n"
               . $body;
         };

         $parts = function (array $fields) use ($BOUNDARY): string {
            $body = '';
            foreach ($fields as $name => $value) {
               $body .= "--{$BOUNDARY}\r\n"
                  . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                  . "{$value}\r\n";
            }

            return $body . "--{$BOUNDARY}--\r\n";
         };

         $FIELDS = ['name' => 'Rodrigo', 'email' => 'r@example.com'];

         // @@ The defect itself: a read of `input` must not disturb `fields`.
         $Request = $drive($wire($MULTIPART, $parts($FIELDS)));
         $before = $Request->fields;
         $input = $Request->input;

         yield new Assertion(
            description: 'Reading $Request->input leaves the multipart fields intact'
         )
            ->expect([
               $Request->Body->streaming,
               $before,
               $input,
               $Request->fields,
            ])
            ->to->be([true, $FIELDS, '', $FIELDS])
            ->assert();

         // @@ `receive($key)` looked its key up in the map it had just wiped.
         $Request = $drive($wire($MULTIPART, $parts($FIELDS)));

         yield new Assertion(
            description: 'receive($key) resolves against the decoder map on a multipart body'
         )
            ->expect([$Request->receive('name'), $Request->fields])
            ->to->be(['Rodrigo', $FIELDS])
            ->assert();

         // @@ The shipped chain that turns a valid upload into a 422.
         $Validator = new Validator(rules: ['name' => new Required], Source: Sources::Fields);
         $BodyParser = new BodyParser;
         $next = fn (object $Rq, object $Rs): object => $Rs(code: 200, body: 'HANDLER-REACHED');

         $answers = [];
         foreach (
            [
               [$MULTIPART, $parts(['name' => 'Rodrigo'])],
               ['application/x-www-form-urlencoded', 'name=Rodrigo'],
               ['application/json', '{"name":"Rodrigo"}'],
            ] as [$type, $payload]
         ) {
            $Request = $drive($wire($type, $payload));
            /** @var Response $Answer */
            $Answer = $BodyParser->process(
               $Request,
               new Response,
               fn (object $Rq, object $Rs): object => $Validator->process($Rq, $Rs, $next)
            );
            $answers[] = $Answer->code;
         }

         yield new Assertion(
            description: 'BodyParser -> Validator(Fields) admits multipart, urlencoded and JSON alike'
         )
            ->expect($answers)
            ->to->be([200, 200, 200])
            ->assert();

         // @@ Controls — non-streaming bodies must round-trip through receive()
         //    exactly as before, and an unparsed media type still yields [].
         $received = [];
         foreach (
            [
               ['application/x-www-form-urlencoded', 'name=Rodrigo&age=7'],
               ['application/json', '{"name":"Rodrigo","age":7}'],
               ['text/plain', 'hello'],
            ] as [$type, $payload]
         ) {
            $Request = $drive($wire($type, $payload));
            $received[] = [$Request->receive(), $Request->input, $Request->fields];
         }

         yield new Assertion(
            description: 'Non-streaming bodies keep parsing through receive() unchanged'
         )
            ->expect($received)
            ->to->be([
               [
                  ['name' => 'Rodrigo', 'age' => '7'],
                  'name=Rodrigo&age=7',
                  ['name' => 'Rodrigo', 'age' => '7'],
               ],
               [
                  ['name' => 'Rodrigo', 'age' => 7],
                  '{"name":"Rodrigo","age":7}',
                  ['name' => 'Rodrigo', 'age' => 7],
               ],
               [[], 'hello', []],
            ])
            ->assert();

         // @@ Control — a multipart body that carries no text part stays empty,
         //    and reading `input` still returns '' for every multipart shape.
         $Request = $drive($wire($MULTIPART, $parts([])));
         $emptyBefore = $Request->fields;

         yield new Assertion(
            description: 'A multipart body with no text part is unaffected either way'
         )
            ->expect([$emptyBefore, $Request->input, $Request->fields, $Request->files])
            ->to->be([[], '', [], []])
            ->assert();
      }
      finally {
         if ($OldRequest !== null) {
            $WPI->Request = $OldRequest;
         }
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }
   })
);
