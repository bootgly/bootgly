<?php


use const BOOTGLY_STORAGE_DIR;
use const UPLOAD_ERR_NO_FILE;

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
 * Regression (DEC-1) — an empty file input (`filename=""`, what every browser
 * sends when the user picks no file) must decode to the no-file record PHP's
 * own rfc1867 parser produces for the identical bytes:
 *
 *    ['name' => '', 'tmp_name' => '', 'size' => 0,
 *     'error' => UPLOAD_ERR_NO_FILE, 'type' => '']
 *
 * and must never touch the filesystem — no temp inode, no fd, no `mkdir`.
 * The decoder used to substitute the `'upload'` placeholder for the empty
 * filename and commit the part with `error = 0` plus a real 0-byte temp file,
 * so the canonical app gate `error === UPLOAD_ERR_OK` passed and
 * `Request::store()` — whose own guard exists to skip non-uploads —
 * truncated whatever the destination path held.
 *
 * The empty RAW filename is the discriminator, not the byte count: a part
 * with a real filename and a 0-byte body is a genuine upload (pinned by E2E
 * 1.20.7), and a NON-empty filename that sanitizes down to empty still takes
 * the `'upload'` placeholder (pinned by Security 07.02).
 */

if (! class_exists('U128Connection', false)) {
   class U128Connection extends Connection
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
   description: 'It should report an empty multipart file input as UPLOAD_ERR_NO_FILE',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U128 probe stream opens')
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
         $Connection = new U128Connection($Socket);
         $n = 0;

         // ! A query-bearing target is never L1-cached, so every decode below
         //   reaches the miss path and builds a fresh Request.
         $drive = function (string $wire) use ($Connection): array {
            $Package = new class($Connection) extends TCPPackages {};
            $Package->changed = true;

            $state = (new Decoder_)->decode($Package, $wire, strlen($wire));

            /** @var Request $Request */
            $Request = $Package->decoded;

            return [$Request, $state];
         };

         $multipart = function (string $disposition, string $content) use (&$n): string {
            $n++;
            $boundary = '----u128';
            $body = "--{$boundary}\r\n"
               . "Content-Disposition: form-data; {$disposition}\r\n"
               . "Content-Type: application/octet-stream\r\n"
               . "\r\n"
               . "{$content}\r\n"
               . "--{$boundary}--\r\n";

            return "POST /u128?n={$n} HTTP/1.1\r\nHost: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
               . "Content-Length: " . strlen($body) . "\r\n"
               . "\r\n"
               . $body;
         };

         $tempDir = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
         $snapshot = static function () use ($tempDir): array {
            if (is_dir($tempDir) === false) {
               return [];
            }

            return array_values(array_diff(scandir($tempDir) ?: [], ['.', '..']));
         };

         $noFile = [
            'name' => '',
            'tmp_name' => '',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
            'type' => '',
         ];

         // @@ A) The browser empty-input shape: filename="", empty part body.
         $before = $snapshot();
         [$Request, $state] = $drive($multipart('name="avatar"; filename=""', ''));
         $created = count($snapshot()) - count($before);
         $record = $Request->files['avatar'] ?? null;

         yield new Assertion(
            description: 'An empty file input decodes to the no-file record — observed: '
               . json_encode($record)
         )
            ->expect([$state, $record, $Request->fields])
            ->to->be([States::Complete, $noFile, []])
            ->assert();

         yield new Assertion(
            description: 'An empty file input creates no temp inode — created: ' . $created
         )
            ->expect($created)
            ->to->be(0)
            ->assert();

         // @@ B) Control — a real filename still takes the upload path: the
         //    record keeps its sanitized name and a published tmp_name. The
         //    end-to-end control (error 0, bytes on disk) is E2E 1.20.9: this
         //    socketless drive has no worker `Downloads` counter, so the
         //    write-time aggregate reserve cannot be exercised here.
         [$Request] = $drive($multipart('name="doc"; filename="a.txt"', 'abc'));
         $record = $Request->files['doc'] ?? null;
         $tmp = is_array($record) ? (string) ($record['tmp_name'] ?? '') : '';

         yield new Assertion(
            description: 'A real filename still decodes as an upload with a published tmp_name — observed: '
               . json_encode($record)
         )
            ->expect([
               $record['name'] ?? null,
               $record['type'] ?? null,
               $tmp !== '',
            ])
            ->to->be(['a.txt', 'application/octet-stream', true])
            ->assert();

         if ($tmp !== '' && is_file($tmp)) {
            @unlink($tmp);
         }

         // @@ C) Adversarial — filename="" with body bytes is still no file:
         //    the bytes are discarded and no inode appears.
         $before = $snapshot();
         [$Request] = $drive($multipart('name="sneak"; filename=""', 'xyz'));
         $created = count($snapshot()) - count($before);
         $record = $Request->files['sneak'] ?? null;

         yield new Assertion(
            description: 'An empty filename with body bytes still yields the no-file record — observed: '
               . json_encode($record) . ' created: ' . $created
         )
            ->expect([$record, $created])
            ->to->be([$noFile, 0])
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
