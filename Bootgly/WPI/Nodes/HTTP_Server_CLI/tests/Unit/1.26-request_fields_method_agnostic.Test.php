<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

/**
 * Regression (REQ-1) — `$Request->fields` dispatches on the media type of the
 * decoded body, never on the request method. The hook used to open with
 * `$this->method === 'POST'`, a leftover from when the property mirrored
 * `$_POST`, so a PUT/PATCH/DELETE carrying a JSON or urlencoded body returned
 * `[]` while `Body->raw` was complete — and `CSRF` (which reads the `_token`
 * form field) and `Validator(Sources::Fields)` answered 403/422 to correct
 * requests.
 *
 * The three conjuncts that DO belong in the hook are pinned here too:
 *  - `_fields === []`  — the multipart row, whose fields the streaming decoder
 *    already wrote, must survive untouched;
 *  - `! Body->streaming` — same row, from the other direction: a streaming
 *    body has no `raw` to re-parse;
 *  - `Body->length > 0` — a body-less request must never reach `receive()`.
 *    `Body->input` is the witness: it is `null` after decode and only
 *    `receive()` materializes it, so a `null` there proves the hook
 *    short-circuited.
 */

if (! class_exists('U126Connection', false)) {
   class U126Connection extends Connection
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


return new Test(
   description: 'It should parse the request body on its media type, not on the request method',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U126 probe stream opens')
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
         $Connection = new U126Connection($Socket);
         $n = 0;

         // ! A query-bearing target is never L1-cached, so every decode below
         //   reaches the miss path and builds a fresh Request.
         $drive = function (string $head, null|string $fed = null) use ($Connection): array {
            $Package = new class($Connection) extends TCPPackages {};
            $Package->changed = true;

            $state = (new Decoder_)->decode($Package, $head, strlen($head));

            if ($fed !== null && $Package->Decoder !== null) {
               $state = $Package->Decoder->decode($Package, $fed, strlen($fed));
            }

            /** @var Request $Request */
            $Request = $Package->decoded;

            return [$Request, $state];
         };

         $wire = function (
            string $method, string $type, string $body, array $headers = []
         ) use (&$n): string {
            $n++;
            $extra = '';
            foreach ($headers as $name => $value) {
               $extra .= "{$name}: {$value}\r\n";
            }

            return "{$method} /u126?n={$n} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: {$type}\r\n"
               . $extra
               . "Content-Length: " . strlen($body) . "\r\n"
               . "\r\n"
               . $body;
         };

         // @@ The method matrix — identical bytes, four methods, two media types.
         $JSON = '{"name":"bootgly","age":7}';
         $FORM = 'name=bootgly&age=7';

         $decodedJSON = ['name' => 'bootgly', 'age' => 7];
         $decodedFORM = ['name' => 'bootgly', 'age' => '7'];

         $parsed = [];
         $states = [];
         foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            [$Request, $state] = $drive($wire($method, 'application/json', $JSON));
            $states[] = $state;
            $parsed["{$method}:json"] = $Request->fields;

            [$Request, $state] = $drive($wire($method, 'application/x-www-form-urlencoded', $FORM));
            $states[] = $state;
            $parsed["{$method}:form"] = $Request->fields;
         }

         yield new Assertion(
            description: 'Every method decodes its body to States::Complete'
         )
            ->expect(array_unique(array_column(
               array_map(fn (States $State): array => ['name' => $State->name], $states),
               'name'
            )))
            ->to->be(['Complete'])
            ->assert();

         yield new Assertion(
            description: 'PUT/PATCH/DELETE parse a JSON body exactly as POST does'
         )
            ->expect([
               $parsed['POST:json'],
               $parsed['PUT:json'],
               $parsed['PATCH:json'],
               $parsed['DELETE:json'],
            ])
            ->to->be([$decodedJSON, $decodedJSON, $decodedJSON, $decodedJSON])
            ->assert();

         yield new Assertion(
            description: 'PUT/PATCH/DELETE parse an urlencoded body exactly as POST does'
         )
            ->expect([
               $parsed['POST:form'],
               $parsed['PUT:form'],
               $parsed['PATCH:form'],
               $parsed['DELETE:form'],
            ])
            ->to->be([$decodedFORM, $decodedFORM, $decodedFORM, $decodedFORM])
            ->assert();

         // @@ Chunked — `Body->length` only lands when the terminal chunk does
         //    (`Decoder_Chunked::decode()`), so this is what proves the hook's
         //    presence gate covers a body with no Content-Length.
         $n++;
         [$Request, $state] = $drive(
            "PUT /u126?n={$n} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "Transfer-Encoding: chunked\r\n\r\n",
            "1a\r\n{$JSON}\r\n0\r\n\r\n"
         );

         yield new Assertion(
            description: 'A chunked PUT body reaches fields once the terminal chunk lands'
         )
            ->expect([$state, $Request->Body->length, $Request->fields])
            ->to->be([States::Complete, strlen($JSON), $decodedJSON])
            ->assert();

         // @@ Multipart — the streaming decoder owns `fields`; the hook must not
         //    re-parse (there is no `raw`) nor overwrite what it wrote.
         $n++;
         $boundary = '----u126';
         $multipart = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "bootgly\r\n"
            . "--{$boundary}--\r\n";
         [$Request, $state] = $drive(
            "PUT /u126?n={$n} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . strlen($multipart) . "\r\n\r\n"
            . $multipart
         );

         yield new Assertion(
            description: 'A streaming multipart body keeps the decoder-written fields'
         )
            ->expect([
               $state,
               $Request->Body->streaming,
               $Request->Body->raw,
               $Request->fields,
            ])
            ->to->be([States::Complete, true, '', ['name' => 'bootgly']])
            ->assert();

         // @@ Isolate `! Body->streaming`. The row above cannot pin it: its
         //    `_fields` is already populated, so `_fields === []` blocks the
         //    hook on its own. A multipart body carrying NO parts leaves
         //    `_fields` empty with `streaming` true and `length > 0`, so the
         //    streaming conjunct is the only thing left holding the gate shut —
         //    and `Body->input` staying `null` is the proof it held.
         $n++;
         $bare = "--{$boundary}--\r\n";
         [$RequestBare, $state] = $drive(
            "PUT /u126?n={$n} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . strlen($bare) . "\r\n\r\n"
            . $bare
         );
         $fieldsBare = $RequestBare->fields;

         yield new Assertion(
            description: 'A partless multipart body never reaches receive() either'
         )
            ->expect([
               $state,
               $RequestBare->Body->streaming,
               $RequestBare->Body->length,
               $fieldsBare,
               $RequestBare->Body->input,
            ])
            ->to->be([States::Complete, true, strlen($bare), [], null])
            ->assert();

         // @@ Isolate `_fields === []`. `Decoder_Downloading` publishes its
         //    parts through the public setter, so an assignment must always
         //    win over the body the hook could re-parse.
         $n++;
         [$RequestSet] = $drive($wire('PUT', 'application/json', '{"from":"body"}'));
         $RequestSet->fields = ['from' => 'setter'];

         yield new Assertion(
            description: 'An assigned fields value is never replaced by re-parsing the body'
         )
            ->expect([$RequestSet->Body->raw, $RequestSet->fields])
            ->to->be(['{"from":"body"}', ['from' => 'setter']])
            ->assert();

         // @@ Media types the parser refuses to guess stay empty on every method.
         $unknown = [];
         foreach (['POST', 'DELETE'] as $method) {
            [$Request] = $drive($wire($method, 'application/octet-stream', $FORM));
            $unknown[] = $Request->fields;
         }

         yield new Assertion(
            description: 'An unknown Content-Type yields no fields, whatever the method'
         )
            ->expect($unknown)
            ->to->be([[], []])
            ->assert();

         // @@ Body-less and empty bodies must never enter `receive()`.
         $n++;
         [$RequestGET] = $drive("GET /u126?n={$n} HTTP/1.1\r\nHost: localhost\r\n\r\n");
         $fieldsGET = $RequestGET->fields;

         [$RequestEmpty] = $drive($wire('PUT', 'application/json', ''));
         $fieldsEmpty = $RequestEmpty->fields;

         yield new Assertion(
            description: 'A body-less GET and an empty PUT body short-circuit before receive()'
         )
            ->expect([
               $fieldsGET,
               $RequestGET->Body->length,
               $RequestGET->Body->input,
               $fieldsEmpty,
               $RequestEmpty->Body->length,
               $RequestEmpty->Body->input,
            ])
            ->to->be([[], null, null, [], 0, null])
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
