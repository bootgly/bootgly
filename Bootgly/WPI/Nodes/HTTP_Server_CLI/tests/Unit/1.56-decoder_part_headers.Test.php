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
 * Regression (DEC-2/3/4) — a multipart part's header block is parsed by its
 * grammar (RFC 7578 §4.2 over RFC 9110 field lines), not by fixed-shape
 * regexes:
 *
 * - OWS after the colon is optional (`Content-Disposition:form-data`) — DEC-2;
 * - `Content-Disposition` parameters come in any order, quoted or not, with
 *   extra parameters ignored — DEC-3;
 * - the part's `Content-Type` survives whatever line order it came in — DEC-4;
 * - a part without a `form-data` disposition or a non-empty `name`, a repeated
 *   disposition/type/`name`/`filename`, or a line that is not a field makes the
 *   request a 400 before any temp file exists for that part.
 *
 * File parts carry an empty body: this socketless drive has no worker
 * `Downloads` counter, so a write would fail the reserve — the record's shape
 * is what these cases pin.
 */

if (! class_exists('U156Connection', false)) {
   class U156Connection extends Connection
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
         $this->id = 1560;
      }
   }
}


return new Test(
   description: 'It should parse multipart part headers by their grammar and refuse malformed parts',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U156 probe stream opens')
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

      $snapshot = static function (): array {
         if (is_dir(BOOTGLY_UPLOADS_DIR) === false) {
            return [];
         }

         return array_values(array_diff(scandir(BOOTGLY_UPLOADS_DIR) ?: [], ['.', '..', '.gitkeep']));
      };
      $baseline = $snapshot();

      try {
         $Connection = new U156Connection($Socket);
         $n = 0;

         // ! One request per case; a query-bearing target is never L1-cached
         $drive = function (array $parts) use ($Connection, &$n, $snapshot, $baseline): array {
            $n++;
            $boundary = '----u156';
            $body = '';
            foreach ($parts as [$headers, $content]) {
               $body .= "--{$boundary}\r\n{$headers}\r\n\r\n{$content}\r\n";
            }
            $body .= "--{$boundary}--\r\n";
            $wire = "POST /u156?n={$n} HTTP/1.1\r\nHost: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
               . 'Content-Length: ' . strlen($body) . "\r\n\r\n{$body}";

            $Package = new class($Connection) extends TCPPackages {};
            $Package->changed = true;
            $State = (new Decoder_)->decode($Package, $wire, strlen($wire));

            /** @var null|Request $Request */
            $Request = $Package->decoded;
            $files = $Request instanceof Request ? $Request->files : [];
            $fields = $Request instanceof Request ? $Request->fields : [];

            // @ What is left on disk once the case is read, then removed
            $left = array_values(array_diff($snapshot(), $baseline));
            foreach ($left as $entry) {
               @unlink(BOOTGLY_UPLOADS_DIR . $entry);
            }

            return [$State === States::Rejected || $Package->rejected, $files, $fields, count($left)];
         };
         $shape = static fn (null|array $file): null|array => $file === null ? null : [
            $file['name'] ?? null,
            $file['type'] ?? null,
            $file['error'] ?? null,
         ];

         // @@ Parts that decode — [label, headers, expected file key, expected record shape]
         $files = [
            // # Controls — the shapes every mainstream client sends
            ['canonical order', "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"\r\nContent-Type: image/png", 'avatar', ['a.png', 'image/png', 0]],
            ['two spaces after the colon', "Content-Disposition:  form-data; name=\"avatar\"; filename=\"a.png\"\r\nContent-Type:  image/png", 'avatar', ['a.png', 'image/png', 0]],
            ['upper-case names', "CONTENT-DISPOSITION: FORM-DATA; NAME=\"avatar\"; FILENAME=\"a.png\"\r\nCONTENT-TYPE: image/png", 'avatar', ['a.png', 'image/png', 0]],
            ['a Windows path is sanitized', "Content-Disposition: form-data; name=\"avatar\"; filename=\"C:\\dir\\a.png\"", 'avatar', ['C__dir_a.png', '', 0]],
            ['filename="" is the no-file record', "Content-Type: image/png\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"\"", 'avatar', ['', '', UPLOAD_ERR_NO_FILE]],
            // # DEC-4 — the type survives any line order
            ['Content-Type before Content-Disposition', "Content-Type: image/png\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"", 'avatar', ['a.png', 'image/png', 0]],
            // # DEC-2 — OWS after the colon is optional
            ['no space after the colons', "Content-Disposition:form-data; name=\"avatar\"; filename=\"a.png\"\r\nContent-Type:image/png", 'avatar', ['a.png', 'image/png', 0]],
            ['HTAB after the colon', "Content-Disposition:\tform-data; name=\"avatar\"; filename=\"a.png\"", 'avatar', ['a.png', '', 0]],
            // # DEC-3 — parameters are a list
            ['filename before name', "Content-Disposition: form-data; filename=\"a.png\"; name=\"avatar\"\r\nContent-Type: image/png", 'avatar', ['a.png', 'image/png', 0]],
            ['no space after the semicolons', "Content-Disposition: form-data;name=\"avatar\";filename=\"a.png\"", 'avatar', ['a.png', '', 0]],
            ['an extra parameter between them', "Content-Disposition: form-data; name=\"avatar\"; size=\"9\"; filename=\"a.png\"", 'avatar', ['a.png', '', 0]],
            ['unquoted token values', "Content-Disposition: form-data; name=avatar; filename=a.png", 'avatar', ['a.png', '', 0]],
            ['a trailing semicolon', "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.png\";", 'avatar', ['a.png', '', 0]],
         ];
         foreach ($files as [$label, $headers, $key, $expected]) {
            [$rejected, $decoded] = $drive([[$headers, '']]);

            yield new Assertion(description: "{$label} decodes as the file `{$key}`: " . json_encode([$rejected, $shape($decoded[$key] ?? null)]))
               ->expect([$rejected, $shape($decoded[$key] ?? null)])
               ->to->be([false, $expected])
               ->assert();
         }

         // @@ Text fields — [label, headers, expected field key]
         $fields = [
            ['a quoted name carrying `;`', 'Content-Disposition: form-data; name="a;b"', 'a;b'],
            ['a quoted-pair in the name', 'Content-Disposition: form-data; name="q\\"x"', 'q"x'],
            ['an upper-case unquoted name', 'Content-Disposition: form-data; NAME=note', 'note'],
            ['filename* alone is not a file', "Content-Disposition: form-data; name=\"avatar\"; filename*=UTF-8''a.png", 'avatar'],
         ];
         foreach ($fields as [$label, $headers, $key]) {
            [$rejected, $decodedFiles, $decodedFields] = $drive([[$headers, 'v']]);

            yield new Assertion(description: "{$label} decodes as the field `{$key}`: " . json_encode([$rejected, $decodedFields, array_keys($decodedFiles)]))
               ->expect([$rejected, $decodedFields[$key] ?? null, $decodedFiles])
               ->to->be([false, 'v', []])
               ->assert();
         }

         // @@ Malformed parts — refused before any temp file is left behind
         $smuggle = "Content-Disposition: form-data; name=\"s\"\r\n\r\nsmuggled";
         $malformed = [
            ['a part without a name', 'Content-Disposition: form-data; filename="a.png"'],
            ['an empty name', 'Content-Disposition: form-data; name=""; filename="a.png"'],
            ['an attachment disposition', 'Content-Disposition: attachment; name="avatar"'],
            ['no Content-Disposition', 'Content-Type: image/png'],
            ['a repeated Content-Disposition', "Content-Disposition: form-data; name=\"a\"\r\nContent-Disposition: form-data; name=\"b\""],
            ['a repeated Content-Type', "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"\r\nContent-Type: image/png\r\nContent-Type: text/html"],
            ['a repeated name', 'Content-Disposition: form-data; name="a"; name="b"; filename="a.png"'],
            ['a repeated filename', 'Content-Disposition: form-data; name="avatar"; filename="a.png"; filename="b.php"'],
            ['a folded line', "Content-Disposition: form-data;\r\n name=\"avatar\""],
            ['a line without a colon', "Content-Disposition: form-data; name=\"avatar\"\r\ngarbage"],
            ['a control byte in a value', "Content-Disposition: form-data; name=\"av\x01atar\""],
            ['an unterminated quoted string', 'Content-Disposition: form-data; name="avatar'],
            ['a folded line carrying a colon', "Content-Disposition: form-data; name=\"avatar\"\r\n\tx: y"],
            ['an empty field name', "Content-Disposition: form-data; name=\"avatar\"\r\n: y"],
            ['a space inside a field name', "Content-Disposition: form-data; name=\"avatar\"\r\nContent Type: image/png"],
            ['whitespace before `=`', 'Content-Disposition: form-data; name ="avatar"'],
            ['garbage after a parameter', 'Content-Disposition: form-data; name="avatar" x'],
            ['a parameter without a value', 'Content-Disposition: form-data; name="avatar"; filename'],
            ['an empty token value', 'Content-Disposition: form-data; name="avatar"; filename='],
            // ! A header-shaped value behind it: a part skipped as unnamed
            //   would have its value read as the next part's headers,
            //   smuggling the field `s` in
            ['an empty name on a text field', 'Content-Disposition: form-data; name=""', $smuggle],
            ['no Content-Disposition, a header-shaped value', 'Content-Type: text/plain', $smuggle],
            ['a disposition type with a suffix', 'Content-Disposition: form-data-x; name="avatar"'],
         ];
         foreach ($malformed as $entry) {
            [$label, $headers] = $entry;
            [$rejected, , , $left] = $drive([[$headers, $entry[2] ?? '']]);

            yield new Assertion(description: "{$label} is refused, nothing left on disk: " . json_encode([$rejected, $left]))
               ->expect([$rejected, $left])
               ->to->be([true, 0])
               ->assert();
         }

         // @ A good file part, then an unnamed one — the whole request is refused
         //   and the first part's temp file does not outlive it
         [$rejected, , , $left] = $drive([
            ["Content-Disposition: form-data; name=\"first\"; filename=\"a.png\"\r\nContent-Type: image/png", ''],
            ['Content-Disposition: form-data; filename="b.png"', ''],
         ]);

         yield new Assertion(description: 'A malformed later part refuses the request and leaves nothing: ' . json_encode([$rejected, $left]))
            ->expect([$rejected, $left])
            ->to->be([true, 0])
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
