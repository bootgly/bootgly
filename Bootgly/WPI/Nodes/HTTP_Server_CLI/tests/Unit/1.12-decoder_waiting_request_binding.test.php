<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

/**
 * Regression — body continuations must resolve the OWNING Request bound at
 * the decoder install site, never the worker-global cell: another connection
 * may replace or claim that cell between transport reads. Here connection B
 * claims the worker cell while connection A's Content-Length body is paused;
 * A's remaining bytes must land in A's Request and restore it as the global
 * for the response cycle.
 */

return new Specification(
   description: 'It should append paused body bytes to the owning Request even after the worker cell is replaced',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $OldServer = $WPI->Server ?? null;
      $OldRequest = $WPI->Request ?? null;

      if ($OldServer === null) {
         /** @var HTTP_Server_CLI $Server */
         $Server = (new ReflectionClass(HTTP_Server_CLI::class))->newInstanceWithoutConstructor();
         $WPI->Server = $Server;
      }

      try {
         $Package = new class extends ServerPackages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };

         // ! Connection A: POST with Content-Length 10, paused after 4 bytes.
         $RequestA = new Request;
         $RequestA->Body->waiting = true;
         $RequestA->Body->length = 10;
         $RequestA->Body->downloaded = 4;
         $RequestA->Body->raw = 'ABCD';

         $Waiting = new Decoder_Waiting;
         $Waiting->init();
         $Waiting->Request = $RequestA;
         $Package->Decoder = $Waiting;

         // ! Connection B decodes meanwhile: the worker-global cell now holds
         //   a different Request.
         $RequestB = new Request;
         $WPI->Request = $RequestB;

         // @ Connection A's remaining body bytes arrive.
         $State = $Waiting->decode($Package, 'EFGHIJ', 6);

         yield new Assertion(
            description: 'The paused body completes into the owning Request',
         )
            ->expect([
               $State,
               $RequestA->Body->raw,
               $RequestA->Body->downloaded,
               $RequestA->Body->waiting,
               $Package->consumed,
            ])
            ->to->be([States::Complete, 'ABCDEFGHIJ', 10, false, 6])
            ->assert();

         yield new Assertion(
            description: 'The other connection\'s Request stays untouched',
         )
            ->expect([$RequestB->Body->raw, $RequestB->Body->waiting])
            ->to->be(['', false])
            ->assert();

         yield new Assertion(
            description: 'Completion restores the owning Request as the worker global and uninstalls the decoder',
         )
            ->expect([HTTP_Server_CLI::$Request === $RequestA, $Package->Decoder])
            ->to->be([true, null])
            ->assert();
      }
      finally {
         if ($OldRequest !== null) {
            $WPI->Request = $OldRequest;
         }
         if ($OldServer !== null) {
            $WPI->Server = $OldServer;
         }
      }
   })
);
