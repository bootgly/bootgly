<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;


/**
 * H-HSC-5 — an HTTP/1 head carries at most `Request::$maxHeaderFields` field
 * lines (100); more is answered `431 Request Header Fields Too Large`.
 *
 * The head was bounded in bytes only, and a parsed field costs a Request far
 * more than its bytes: 2,336 `ab:xy` lines (16 KiB) kept 330 KB per idle
 * keep-alive connection. The count is checked on a header-scan memo hit too,
 * so a block the scan memoized never replays past a cap lowered later (the
 * decode templates, at most 32 lines, replay without parsing); the test-only
 * `X-Bootgly-Test` line is not counted; `Request\Configs(maxHeaderFields:)`
 * sets the cap and `null` keeps it.
 */
return new Test(
   description: 'H-HSC-5: more than maxHeaderFields HTTP/1 field lines are answered 431',
   test: new Assertions(Case: function (): Generator {
      $Package = new class extends TCPPackages {
         public string $rejection = '';

         // ! Frame::parse() only ever calls reject() on this object, so the
         //   transport-bound parent constructor is deliberately skipped.
         public function __construct () {}

         public function reject (string $raw): void
         {
            $this->rejected = true;
            $this->rejection = $raw;
         }
      };

      // ! The real static memo, with a clean slate either side
      $Reflection = new ReflectionClass(Frame::class);
      $scans = $Reflection->getProperty('scans');
      $memo = $scans->getValue();
      $scans->setValue(null, []);

      $Parse = static function (string $head) use ($Package): array {
         $Package->rejected = false;
         $Package->rejection = '';
         $buffer = $head;
         $Frame = Frame::parse($Package, $buffer, strlen($head));

         return [$Frame !== null, $Package->rejected, $Package->rejection];
      };
      // ! A GET head of `$lines` field lines, Host included
      $Head = static function (int $lines, string $name, string $extra = ''): string {
         $fields = [];
         for ($index = 1; $index < $lines; $index++) {
            $fields[] = "{$name}: v{$index}\r\n";
         }
         $block = implode('', $fields);

         return "GET /fields HTTP/1.1\r\nHost: fields.example\r\n{$block}{$extra}\r\n";
      };

      $Environment = Server::$Environment;
      $cap = Request::$maxHeaderFields;
      $index = Server::$testIndexHeader;
      $OldProtocols = TCP_Server_CLI::$Protocols;
      $refusal = "HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n";

      try {
         Server::$Environment = Environments::Production;

         // @@ A) The boundary at the default cap
         yield new Assertion(
            description: 'The default cap is 100 field lines',
         )
            ->expect(Request::$maxHeaderFields)
            ->to->be(100)
            ->assert();

         yield new Assertion(
            description: '100 field lines are parsed',
         )
            ->expect($Parse($Head(100, 'x-field')))
            ->to->be([true, false, ''])
            ->assert();

         yield new Assertion(
            description: '101 field lines are answered 431',
         )
            ->expect($Parse($Head(101, 'x-field')))
            ->to->be([false, true, $refusal])
            ->assert();

         // @@ B) A memoized block does not replay past a lowered cap
         $memoized = $Head(20, 'accept');
         $warm = $Parse($memoized);
         $stored = count($scans->getValue());
         Request::$maxHeaderFields = 10;
         $lowered = $Parse($memoized);
         Request::$maxHeaderFields = $cap;

         yield new Assertion(
            description: 'A block memoized under the cap is refused once the cap is lowered',
         )
            ->expect([$warm, $stored, $lowered])
            ->to->be([[true, false, ''], 1, [false, true, $refusal]])
            ->assert();

         // @@ C) The test-only dispatch line is not a field line
         Server::$Environment = Environments::Test;

         yield new Assertion(
            description: 'The X-Bootgly-Test line is stripped before the count',
         )
            ->expect($Parse($Head(100, 'x-field', "X-Bootgly-Test: 3\r\n")))
            ->to->be([true, false, ''])
            ->assert();

         // @@ D) Request\Configs sets the cap; null keeps it
         Request::$maxHeaderFields = 100;
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);
         $Server->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
            new Request\Configs(maxHeaderFields: 7)
         );
         $configured = Request::$maxHeaderFields;
         Request::$maxHeaderFields = 55;
         $Other = new HTTP_Server_CLI(Mode: Modes::Test);
         $Other->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
            new Request\Configs(maxBodySize: Request::$maxBodySize)
         );
         $kept = Request::$maxHeaderFields;

         yield new Assertion(
            description: 'Request\Configs(maxHeaderFields:) sets the cap and null keeps the configured one',
         )
            ->expect([$configured, $kept])
            ->to->be([7, 55])
            ->assert();
      }
      finally {
         Server::$Environment = $Environment;
         Server::$testIndexHeader = $index;
         Request::$maxHeaderFields = $cap;
         TCP_Server_CLI::$Protocols = $OldProtocols;
         $scans->setValue(null, $memo);
      }
   }),
);
