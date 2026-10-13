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
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;


/**
 * H-HSC-5 — a memoized HTTP/1 head carries at most `Decoder_::MEMOIZED` (32)
 * field lines.
 *
 * The connection key and template (L0), the shared decode cache (L1) and the
 * header-scan memo keep a head in worker memory beyond its request, outside
 * every ledger, and a parsed field costs far more than its bytes. Real
 * repeated heads stay well below 32 lines; a head of 33 standard fields is
 * memoized nowhere — and its Request owns its head, so the encoder drops it
 * once the response is written.
 */

if (! class_exists('U159Connection', false)) {
   class U159Connection extends Connection
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
   description: 'H-HSC-5: a memoized HTTP/1 head carries at most 32 field lines',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U159 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      // ! A bodyless GET of `$lines` standard field lines, Host included
      $Head = static function (int $lines, string $target): string {
         $fields = [];
         for ($index = 1; $index < $lines; $index++) {
            $fields[] = "accept: text/v{$index}\r\n";
         }
         $block = implode('', $fields);

         return "GET {$target} HTTP/1.1\r\nHost: memo.example\r\n{$block}\r\n";
      };
      $Inputs = static function (): array {
         $statics = new ReflectionMethod(Decoder_::class, 'decode')->getStaticVariables();

         return is_array($statics['inputs'] ?? null) ? $statics['inputs'] : [];
      };

      $Environment = Server::$Environment;
      $Reflection = new ReflectionClass(Frame::class);
      $scans = $Reflection->getProperty('scans');
      $memo = $scans->getValue();

      try {
         // # Decode caches: L0 connection key and shared L1
         $Connection = new U159Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};
         $Decoder = new Decoder_;

         yield new Assertion(
            description: 'The memo bound is 32 field lines',
         )
            ->expect(Decoder_::MEMOIZED)
            ->to->be(32)
            ->assert();

         $within = $Head(32, '/u159-within');
         $Package->changed = true;
         $stateWithin = $Decoder->decode($Package, $within, strlen($within));
         $keyWithin = $Package->known;
         $cachedWithin = isSet($Inputs()[$within]);

         $beyond = $Head(33, '/u159-beyond');
         $Package->changed = true;
         $stateBeyond = $Decoder->decode($Package, $beyond, strlen($beyond));
         $keyBeyond = $Package->known;
         $cachedBeyond = isSet($Inputs()[$beyond]);

         yield new Assertion(
            description: 'A 32-line head keys the connection and enters the shared decode cache',
         )
            ->expect([$stateWithin, $keyWithin === $within, $cachedWithin])
            ->to->be([States::Complete, true, true])
            ->assert();

         yield new Assertion(
            description: 'A 33-line head is decoded but memoized in neither decode cache',
         )
            ->expect([$stateBeyond, $keyBeyond, $cachedBeyond, $Package->Template])
            ->to->be([States::Complete, '', false, null])
            ->assert();

         // # Header-scan memo (production only)
         Server::$Environment = Environments::Production;
         $scans->setValue(null, []);
         $Probe = new class extends TCPPackages {
            // ! Frame::parse() only ever calls reject() on this object
            public function __construct () {}
         };
         $Parse = static function (string $head) use ($Probe): bool {
            $Probe->rejected = false;
            $buffer = $head;

            return Frame::parse($Probe, $buffer, strlen($head)) !== null
               && $Probe->rejected === false;
         };

         $parsedWithin = $Parse($Head(32, '/scan-within'));
         $storedWithin = count($scans->getValue());
         $parsedBeyond = $Parse($Head(33, '/scan-beyond'));
         $storedBeyond = count($scans->getValue());

         yield new Assertion(
            description: 'The header-scan memo stores a 32-line block and not a 33-line one',
         )
            ->expect([$parsedWithin, $storedWithin, $parsedBeyond, $storedBeyond])
            ->to->be([true, 1, true, 1])
            ->assert();
      }
      finally {
         Server::$Environment = $Environment;
         $scans->setValue(null, $memo);
         fclose($Socket);
      }
   }),
);
