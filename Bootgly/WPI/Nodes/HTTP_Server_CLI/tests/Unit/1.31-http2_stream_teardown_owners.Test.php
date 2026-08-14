<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;


return new Test(
   description: 'HTTP/2 Stream should notify teardown owners once while honoring detach and containment',
   test: new Assertions(Case: function (): Generator {
      $Bodies = new Bodies(64, 64);
      $Stream = new Stream(1, 65_535, 65_535, $Bodies);

      $Prototype = new class implements Disconnecting {
         public int $calls = 0;
         public bool $throws = false;

         public function disconnect (): void
         {
            $this->calls++;
            if ($this->throws) {
               throw new RuntimeException('contained HTTP/2 stream owner');
            }
         }
      };
      $Primary = clone $Prototype;
      $Primary->throws = true;
      $Throwing = clone $Prototype;
      $Throwing->throws = true;
      $Survivor = clone $Prototype;
      $Detached = clone $Prototype;
      $OwnerB = clone $Prototype;
      $OwnerB->throws = true;
      $Reentrant = new class($Stream, $OwnerB) implements Disconnecting {
         public int $calls = 0;
         public bool $ownerCleared = false;

         public function __construct (
            private Stream $Stream,
            private Disconnecting $OwnerB,
         ) {}

         public function disconnect (): void
         {
            $this->calls++;
            $this->ownerCleared = $this->Stream->Owner === null;

            // ! closed is committed before callbacks, so Owner B observes
            //   terminal state immediately and recursive close is a no-op.
            Ownership::attach($this->Stream, $this->OwnerB);
            $this->Stream->close();
         }
      };
      $Self = new class($Stream) implements Disconnecting {
         public int $calls = 0;

         public function __construct (private Stream $Stream) {}

         public function disconnect (): void
         {
            $this->calls++;
            Ownership::attach($this->Stream, $this);
         }
      };

      $Stream->Owner = $Primary;
      Ownership::attach($Stream, $Reentrant);
      Ownership::attach($Stream, $Self);
      Ownership::attach($Stream, $Throwing);
      Ownership::attach($Stream, $Survivor);
      Ownership::attach($Stream, $Survivor);
      Ownership::attach($Stream, $Detached);
      Ownership::detach($Stream, $Detached);

      $reserved = $Bodies->reserve(4);
      $Stream->body = 'body';
      $Stream->close();
      $first = [
         'primary' => $Primary->calls,
         'reentrant' => $Reentrant->calls,
         'self' => $Self->calls,
         'owner_b' => $OwnerB->calls,
         'throwing' => $Throwing->calls,
         'survivor' => $Survivor->calls,
         'detached' => $Detached->calls,
         'owner_cleared_before_callbacks' => $Reentrant->ownerCleared,
         'body_released' => $reserved && $Bodies->retained === 0,
      ];

      $Stream->close();
      $Late = new class($Stream) implements Disconnecting {
         public int $calls = 0;

         public function __construct (private Stream $Stream) {}

         public function disconnect (): void
         {
            $this->calls++;
            Ownership::attach($this->Stream, $this);
            throw new RuntimeException('contained late HTTP/2 stream owner');
         }
      };
      Ownership::attach($Stream, $Late);
      Ownership::detach($Stream, $Late);
      Ownership::attach($Stream, $Late);
      Ownership::attach($Stream, $OwnerB);

      yield new Assertion(
         description: 'close contains failures and terminalizes re-entrant and late owners exactly once',
      )
         ->expect([
            'first' => $first,
            'duplicate' => [
               'primary' => $Primary->calls,
               'reentrant' => $Reentrant->calls,
               'self' => $Self->calls,
               'owner_b' => $OwnerB->calls,
               'throwing' => $Throwing->calls,
               'survivor' => $Survivor->calls,
               'detached' => $Detached->calls,
               'late' => $Late->calls,
               'owner_cleared' => $Stream->Owner === null,
               'body_retained' => $Bodies->retained,
            ],
         ])
         ->to->be([
            'first' => [
               'primary' => 1,
               'reentrant' => 1,
               'self' => 1,
               'owner_b' => 1,
               'throwing' => 1,
               'survivor' => 1,
               'detached' => 0,
               'owner_cleared_before_callbacks' => true,
               'body_released' => true,
            ],
            'duplicate' => [
               'primary' => 1,
               'reentrant' => 1,
               'self' => 1,
               'owner_b' => 1,
               'throwing' => 1,
               'survivor' => 1,
               'detached' => 0,
               'late' => 1,
               'owner_cleared' => true,
               'body_retained' => 0,
            ],
         ])
         ->assert();
   }),
);
