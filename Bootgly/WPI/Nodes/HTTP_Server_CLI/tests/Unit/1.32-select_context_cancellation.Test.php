<?php


use Bootgly\ACI\Events\Contextualizing;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections\Packages;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


return new Test(
   description: 'Select must isolate cancellation generations across every dispatch batch',
   test: new Assertions(Case: function (): Generator {
      $Server = new TCP_Server_CLI;
      $Connections = new Connections($Server);

      $Legacy = new class implements Contextualizing {
         public int $bindings = 0;

         public function bind (
            Fiber $Fiber,
            Closure $Enter,
            Closure $Leave,
         ): void {
            $this->bindings++;
         }
      };
      $LegacyFiber = new Fiber(static function (): void {});
      $Legacy->bind(
         $LegacyFiber,
         static function (): void {},
         static function (): void {},
      );
      $Compatible = new class($Connections) extends Select {
         public int $bindings = 0;

         public function bind (
            Fiber $Fiber,
            Closure $Enter,
            Closure $Leave,
         ): void {
            $this->bindings++;
            parent::bind($Fiber, $Enter, $Leave);
         }
      };
      $CompatibleFiber = new Fiber(static function (): void {});
      $Compatible->bind(
         $CompatibleFiber,
         static function (): void {},
         static function (): void {},
      );

      yield new Assertion(
         description: 'three-argument Contextualizing compatibility does not add public cancellation methods',
      )
         ->expect([
            'legacy' => $Legacy instanceof Contextualizing,
            'legacy_bindings' => $Legacy->bindings,
            'select' => $Compatible instanceof Contextualizing,
            'select_bindings' => $Compatible->bindings,
            'own' => method_exists($Compatible, 'own'),
            'drop' => method_exists($Compatible, 'drop'),
         ])
         ->to->be([
            'legacy' => true,
            'legacy_bindings' => 1,
            'select' => true,
            'select_bindings' => 1,
            'own' => false,
            'drop' => false,
         ])
         ->assert();

      $Destroyed = new Select($Connections);
      $destroyed = [0, 0];
      $DestroyFiberA = new Fiber(static function (): void {
         Fiber::suspend();
      });
      $DestroyFiberB = new Fiber(static function (): void {
         Fiber::suspend();
      });
      $DestroyTokenA = Cancellation::open($DestroyFiberA);
      $DestroyTokenB = Cancellation::open($DestroyFiberB);
      $DestroyTokenA->observe(
         static function () use (&$destroyed): void {
            $destroyed[0]++;
            throw new RuntimeException('contained selector cancellation');
         },
      );
      $DestroyTokenB->observe(
         static function () use (&$destroyed): void {
            $destroyed[1]++;
         },
      );
      $destroyWaitA = $DestroyFiberA->start();
      $destroyWaitB = $DestroyFiberB->start();
      $Destroyed->bind(
         $DestroyFiberA,
         static function (): void {},
         static function (): void {},
      );
      $Destroyed->bind(
         $DestroyFiberB,
         static function (): void {},
         static function (): void {},
      );
      $scheduledDestroy = [
         $Destroyed->schedule($DestroyFiberA, $destroyWaitA),
         $Destroyed->schedule($DestroyFiberB, $destroyWaitB),
      ];
      $Destroyed->destroy();
      $Destroyed->destroy();

      yield new Assertion(
         description: 'destroy cancels each active generation once and contains observer failures',
      )
         ->expect([
            'scheduled' => $scheduledDestroy,
            'cancelled' => $destroyed,
            'terminal' => [$DestroyTokenA->check(), $DestroyTokenB->check()],
         ])
         ->to->be([
            'scheduled' => [true, true],
            'cancelled' => [1, 1],
            'terminal' => [true, true],
         ])
         ->assert();

      // ! Tick dispatch copies its queue before the first callback. A must be
      //   unable to cancel B and still let that stale copied entry execute.
      $Tick = new Select($Connections);
      $TickPair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($TickPair === false) {
         throw new RuntimeException('Could not create the tick cancellation fixture.');
      }
      $TickOwner = new class implements Packages {
         public int $reads = 0;

         public function reading (
            &$Socket,
            null|int $length = null,
            null|int $timeout = null,
         ): bool {
            $this->reads++;
            fread($Socket, 1);

            return true;
         }

         public function writing (&$Socket, null|int $length = null): bool
         {
            return true;
         }

         public function read (&$Socket): void {}

         public function write (&$Socket, null|int $length = null): bool
         {
            return true;
         }
      };
      $tickRan = [false, false];
      $TickTokenB = null;
      $TickFiberA = new Fiber(static function () use (
         $Tick,
         &$tickRan,
         &$TickTokenB,
      ): void {
         Fiber::suspend();
         $tickRan[0] = true;
         $TickTokenB?->cancel();
         $Tick->loop = false;
      });
      $TickFiberB = new Fiber(static function () use (&$tickRan): void {
         Fiber::suspend();
         $tickRan[1] = true;
      });

      try {
         $tickWaitA = $TickFiberA->start();
         $tickWaitB = $TickFiberB->start();
         $TickTokenA = Cancellation::open($TickFiberA);
         $TickTokenB = Cancellation::open($TickFiberB);
         $Tick->bind(
            $TickFiberA,
            static function (): void {},
            static function (): void {},
         );
         $Tick->bind(
            $TickFiberB,
            static function (): void {},
            static function (): void {},
         );
         $tickAdded = $Tick->add($TickPair[0], Select::EVENT_READ, $TickOwner);
         $tickScheduled = [
            $Tick->schedule($TickFiberA, $tickWaitA),
            $Tick->schedule($TickFiberB, $tickWaitB),
         ];
         $tickWritten = fwrite($TickPair[1], 'T');
         $Tick->loop();
         $TickTokenA->finish();

         yield new Assertion(
            description: 'a tick callback cannot execute a later canceled entry from the copied batch',
         )
            ->expect([
               'added' => $tickAdded,
               'scheduled' => $tickScheduled,
               'written' => $tickWritten,
               'ran' => $tickRan,
               'cancelled' => $TickTokenB->check(),
               'owner_reads' => $TickOwner->reads,
            ])
            ->to->be([
               'added' => true,
               'scheduled' => [true, true],
               'written' => 1,
               'ran' => [true, false],
               'cancelled' => true,
               'owner_reads' => 1,
            ])
            ->assert();
      }
      finally {
         $Tick->destroy();
         fclose($TickPair[0]);
         fclose($TickPair[1]);
      }

      // ! The same copied-batch invariant is required for read readiness.
      $Reading = new Select($Connections);
      $ReadPair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($ReadPair === false) {
         throw new RuntimeException('Could not create the read cancellation fixture.');
      }
      $readRan = [false, false];
      $ReadTokenB = null;
      $ReadFiberA = new Fiber(static function () use (
         $Reading,
         &$readRan,
         &$ReadTokenB,
      ): void {
         Fiber::suspend();
         $readRan[0] = true;
         $ReadTokenB?->cancel();
         $Reading->loop = false;
      });
      $ReadFiberB = new Fiber(static function () use (&$readRan): void {
         Fiber::suspend();
         $readRan[1] = true;
      });

      try {
         $readWaitA = $ReadFiberA->start();
         $readWaitB = $ReadFiberB->start();
         $ReadTokenA = Cancellation::open($ReadFiberA);
         $ReadTokenB = Cancellation::open($ReadFiberB);
         $Reading->bind(
            $ReadFiberA,
            static function (): void {},
            static function (): void {},
         );
         $Reading->bind(
            $ReadFiberB,
            static function (): void {},
            static function (): void {},
         );
         $readScheduled = [
            $Reading->schedule($ReadFiberA, $ReadPair[0]),
            $Reading->schedule($ReadFiberB, $ReadPair[0]),
         ];
         $readWritten = fwrite($ReadPair[1], 'R');
         $Reading->loop();
         $ReadTokenA->finish();

         yield new Assertion(
            description: 'one ready read waiter can cancel a sibling without executing its stale batch entry',
         )
            ->expect([
               'scheduled' => $readScheduled,
               'written' => $readWritten,
               'ran' => $readRan,
               'cancelled' => $ReadTokenB->check(),
            ])
            ->to->be([
               'scheduled' => [true, true],
               'written' => 1,
               'ran' => [true, false],
               'cancelled' => true,
            ])
            ->assert();
      }
      finally {
         $Reading->destroy();
         fclose($ReadPair[0]);
         fclose($ReadPair[1]);
      }

      // ! Write-ready waiters use an independent queue and need the same
      //   generation snapshot as read and tick dispatch.
      $Writing = new Select($Connections);
      $WritePair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($WritePair === false) {
         throw new RuntimeException('Could not create the write cancellation fixture.');
      }
      $writeRan = [false, false];
      $WriteTokenB = null;
      $WriteFiberA = new Fiber(static function () use (
         $Writing,
         &$writeRan,
         &$WriteTokenB,
      ): void {
         Fiber::suspend();
         $writeRan[0] = true;
         $WriteTokenB?->cancel();
         $Writing->loop = false;
      });
      $WriteFiberB = new Fiber(static function () use (&$writeRan): void {
         Fiber::suspend();
         $writeRan[1] = true;
      });

      try {
         $writeWaitA = $WriteFiberA->start();
         $writeWaitB = $WriteFiberB->start();
         $WriteTokenA = Cancellation::open($WriteFiberA);
         $WriteTokenB = Cancellation::open($WriteFiberB);
         $Writing->bind(
            $WriteFiberA,
            static function (): void {},
            static function (): void {},
         );
         $Writing->bind(
            $WriteFiberB,
            static function (): void {},
            static function (): void {},
         );
         $writeScheduled = [
            $Writing->schedule($WriteFiberA, Readiness::write($WritePair[0])),
            $Writing->schedule($WriteFiberB, Readiness::write($WritePair[0])),
         ];
         $Writing->loop();
         $WriteTokenA->finish();

         yield new Assertion(
            description: 'one ready write waiter can cancel a sibling without executing its stale batch entry',
         )
            ->expect([
               'scheduled' => $writeScheduled,
               'ran' => $writeRan,
               'cancelled' => $WriteTokenB->check(),
            ])
            ->to->be([
               'scheduled' => [true, true],
               'ran' => [true, false],
               'cancelled' => true,
            ])
            ->assert();
      }
      finally {
         $Writing->destroy();
         fclose($WritePair[0]);
         fclose($WritePair[1]);
      }

      // ! Deadline expiry also dispatches a copied waiter batch. Its selector
      //   slot remains owned by the independent persistent package.
      $Expiring = new Select($Connections);
      $ExpirePair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($ExpirePair === false) {
         throw new RuntimeException('Could not create the expiry cancellation fixture.');
      }
      $ExpireOwner = new class implements Packages {
         public int $reads = 0;

         public function reading (
            &$Socket,
            null|int $length = null,
            null|int $timeout = null,
         ): bool {
            $this->reads++;

            return true;
         }

         public function writing (&$Socket, null|int $length = null): bool
         {
            return true;
         }

         public function read (&$Socket): void {}

         public function write (&$Socket, null|int $length = null): bool
         {
            return true;
         }
      };
      $expireRan = [false, false];
      $ExpireTokenB = null;
      $ExpireFiberA = new Fiber(static function () use (
         $Expiring,
         &$expireRan,
         &$ExpireTokenB,
      ): void {
         Fiber::suspend();
         $expireRan[0] = true;
         $ExpireTokenB?->cancel();
         $Expiring->loop = false;
      });
      $ExpireFiberB = new Fiber(static function () use (&$expireRan): void {
         Fiber::suspend();
         $expireRan[1] = true;
      });

      try {
         $deadline = microtime(true) - 1.0;
         $expireWaitA = $ExpireFiberA->start();
         $expireWaitB = $ExpireFiberB->start();
         $ExpireTokenA = Cancellation::open($ExpireFiberA);
         $ExpireTokenB = Cancellation::open($ExpireFiberB);
         $Expiring->bind(
            $ExpireFiberA,
            static function (): void {},
            static function (): void {},
         );
         $Expiring->bind(
            $ExpireFiberB,
            static function (): void {},
            static function (): void {},
         );
         $expireAdded = $Expiring->add(
            $ExpirePair[0],
            Select::EVENT_READ,
            $ExpireOwner,
         );
         $expireScheduled = [
            $Expiring->schedule(
               $ExpireFiberA,
               Readiness::read($ExpirePair[0], $deadline),
            ),
            $Expiring->schedule(
               $ExpireFiberB,
               Readiness::read($ExpirePair[0], $deadline),
            ),
         ];
         $Expiring->loop();
         $ExpireTokenA->finish();

         $Reflection = new ReflectionClass($Expiring);
         $ReadsProperty = $Reflection->getProperty('reads');
         $ReadingProperty = $Reflection->getProperty('reading');
         $Reads = $ReadsProperty->getValue($Expiring);
         $ReadingOwners = $ReadingProperty->getValue($Expiring);
         $expireID = (int) $ExpirePair[0];

         yield new Assertion(
            description: 'deadline expiry isolates sibling cancellation and preserves a base read owner',
         )
            ->expect([
               'added' => $expireAdded,
               'scheduled' => $expireScheduled,
               'ran' => $expireRan,
               'cancelled' => $ExpireTokenB->check(),
               'socket_retained' => ($Reads[$expireID] ?? null) === $ExpirePair[0],
               'owner_retained' => ($ReadingOwners[$expireID] ?? null) === $ExpireOwner,
               'owner_reads' => $ExpireOwner->reads,
            ])
            ->to->be([
               'added' => true,
               'scheduled' => [true, true],
               'ran' => [true, false],
               'cancelled' => true,
               'socket_retained' => true,
               'owner_retained' => true,
               'owner_reads' => 0,
            ])
            ->assert();
      }
      finally {
         $Expiring->del($ExpirePair[0], Select::EVENT_READ);
         $Expiring->destroy();
         fclose($ExpirePair[0]);
         fclose($ExpirePair[1]);
      }

      // ! Normal readiness must resume transient waiters and still dispatch
      //   and retain the persistent package owners sharing each descriptor.
      $Owned = new Select($Connections);
      $OwnedReadPair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      $OwnedWritePair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($OwnedReadPair === false || $OwnedWritePair === false) {
         throw new RuntimeException('Could not create the base-owner readiness fixtures.');
      }
      $Owner = new class implements Packages {
         public null|Select $Selector = null;
         public int $reads = 0;
         public int $writes = 0;

         public function reading (
            &$Socket,
            null|int $length = null,
            null|int $timeout = null,
         ): bool {
            $this->reads++;
            fread($Socket, 1);

            return true;
         }

         public function writing (&$Socket, null|int $length = null): bool
         {
            $this->writes++;
            if ($this->Selector !== null) {
               $this->Selector->loop = false;
            }

            return true;
         }

         public function read (&$Socket): void
         {
            $this->reads++;
         }

         public function write (&$Socket, null|int $length = null): bool
         {
            $this->writes++;

            return true;
         }
      };
      $Owner->Selector = $Owned;
      $ownerResumed = [false, false];
      $OwnerReadFiber = new Fiber(static function () use (&$ownerResumed): void {
         Fiber::suspend();
         $ownerResumed[0] = true;
      });
      $OwnerWriteFiber = new Fiber(static function () use (&$ownerResumed): void {
         Fiber::suspend();
         $ownerResumed[1] = true;
      });

      try {
         $ownerReadWait = $OwnerReadFiber->start();
         $ownerWriteWait = $OwnerWriteFiber->start();
         $OwnerReadToken = Cancellation::open($OwnerReadFiber);
         $OwnerWriteToken = Cancellation::open($OwnerWriteFiber);
         $Owned->bind(
            $OwnerReadFiber,
            static function (): void {},
            static function (): void {},
         );
         $Owned->bind(
            $OwnerWriteFiber,
            static function (): void {},
            static function (): void {},
         );
         $ownerAdded = [
            $Owned->add($OwnedReadPair[0], Select::EVENT_READ, $Owner),
            $Owned->add($OwnedWritePair[0], Select::EVENT_WRITE, $Owner),
         ];
         $ownerScheduled = [
            $Owned->schedule($OwnerReadFiber, $OwnedReadPair[0]),
            $Owned->schedule(
               $OwnerWriteFiber,
               Readiness::write($OwnedWritePair[0]),
            ),
         ];
         $ownerWritten = fwrite($OwnedReadPair[1], 'O');
         $Owned->loop();
         $OwnerReadToken->finish();
         $OwnerWriteToken->finish();

         $Reflection = new ReflectionClass($Owned);
         $ReadsProperty = $Reflection->getProperty('reads');
         $WritesProperty = $Reflection->getProperty('writes');
         $ReadingProperty = $Reflection->getProperty('reading');
         $WritingProperty = $Reflection->getProperty('writing');
         $Reads = $ReadsProperty->getValue($Owned);
         $Writes = $WritesProperty->getValue($Owned);
         $ReadingOwners = $ReadingProperty->getValue($Owned);
         $WritingOwners = $WritingProperty->getValue($Owned);
         $readID = (int) $OwnedReadPair[0];
         $writeID = (int) $OwnedWritePair[0];

         yield new Assertion(
            description: 'normal waiter readiness dispatches and preserves independent base owners',
         )
            ->expect([
               'added' => $ownerAdded,
               'scheduled' => $ownerScheduled,
               'written' => $ownerWritten,
               'resumed' => $ownerResumed,
               'callbacks' => [$Owner->reads, $Owner->writes],
               'read_retained' => ($Reads[$readID] ?? null) === $OwnedReadPair[0]
                  && ($ReadingOwners[$readID] ?? null) === $Owner,
               'write_retained' => ($Writes[$writeID] ?? null) === $OwnedWritePair[0]
                  && ($WritingOwners[$writeID] ?? null) === $Owner,
            ])
            ->to->be([
               'added' => [true, true],
               'scheduled' => [true, true],
               'written' => 1,
               'resumed' => [true, true],
               'callbacks' => [1, 1],
               'read_retained' => true,
               'write_retained' => true,
            ])
            ->assert();
      }
      finally {
         $Owned->del($OwnedReadPair[0], Select::EVENT_READ);
         $Owned->del($OwnedWritePair[0], Select::EVENT_WRITE);
         $Owned->destroy();
         fclose($OwnedReadPair[0]);
         fclose($OwnedReadPair[1]);
         fclose($OwnedWritePair[0]);
         fclose($OwnedWritePair[1]);
      }

      // ! Admission itself can execute a signal/callback through check(). If
      //   that callback settles the generation, queue() must not leave the
      //   subsequently appended stale waiter or selector descriptor behind.
      $Admitting = new class($Connections) extends Select {
         public null|Cancellation $Token = null;
         public int $checks = 0;

         protected function check ($Socket, int $flag): bool
         {
            $this->checks++;
            $this->Token?->cancel();

            return true;
         }
      };
      $AdmissionPair = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($AdmissionPair === false) {
         throw new RuntimeException('Could not create the admission cancellation fixture.');
      }
      $AdmissionFiber = new Fiber(static function (): void {
         Fiber::suspend();
      });

      try {
         $admissionWait = $AdmissionFiber->start();
         $AdmissionToken = Cancellation::open($AdmissionFiber);
         $Admitting->Token = $AdmissionToken;
         $Admitting->bind(
            $AdmissionFiber,
            static function (): void {},
            static function (): void {},
         );
         $admissionScheduled = $Admitting->schedule(
            $AdmissionFiber,
            $AdmissionPair[0],
         );

         $Reflection = new ReflectionClass(Select::class);
         $BindingsProperty = $Reflection->getProperty('Bindings');
         $AwaitingProperty = $Reflection->getProperty('awaitingReads');
         $DeadlinesProperty = $Reflection->getProperty('awaitingReadDeadlines');
         $ReadsProperty = $Reflection->getProperty('reads');
         $Bindings = $BindingsProperty->getValue($Admitting);
         $AwaitingReads = $AwaitingProperty->getValue($Admitting);
         $ReadDeadlines = $DeadlinesProperty->getValue($Admitting);
         $Reads = $ReadsProperty->getValue($Admitting);

         yield new Assertion(
            description: 'cancellation during selector admission leaves no stale waiter or descriptor',
         )
            ->expect([
               'scheduled' => $admissionScheduled,
               'checks' => $Admitting->checks,
               'terminal' => $AdmissionToken->check(),
               'published' => Cancellation::fetch($AdmissionFiber),
               'bindings' => count($Bindings),
               'awaiting_reads' => count($AwaitingReads),
               'read_deadlines' => count($ReadDeadlines),
               'reads' => count($Reads),
               'suspended' => $AdmissionFiber->isSuspended(),
            ])
            ->to->be([
               'scheduled' => false,
               'checks' => 1,
               'terminal' => true,
               'published' => null,
               'bindings' => 0,
               'awaiting_reads' => 0,
               'read_deadlines' => 0,
               'reads' => 0,
               'suspended' => true,
            ])
            ->assert();
      }
      finally {
         $Admitting->destroy();
         fclose($AdmissionPair[0]);
         fclose($AdmissionPair[1]);
      }

      $Detached = new Select($Connections);
      $detached = 0;
      $DetachFiber = new Fiber(static function (): void {
         Fiber::suspend(Select::DETACH);
      });
      $DetachToken = Cancellation::open($DetachFiber);
      $DetachToken->observe(
         static function () use (&$detached): void {
            $detached++;
         },
      );
      $detachWait = $DetachFiber->start();
      $Detached->bind(
         $DetachFiber,
         static function (): void {},
         static function (): void {},
      );
      $detachScheduled = $Detached->schedule($DetachFiber, $detachWait);
      $Detached->destroy();

      yield new Assertion(
         description: 'the pooled-Fiber DETACH sentinel releases without cancelling its generation',
      )
         ->expect([
            'scheduled' => $detachScheduled,
            'suspended' => $DetachFiber->isSuspended(),
            'terminal' => $DetachToken->check(),
            'observed' => $detached,
         ])
         ->to->be([
            'scheduled' => false,
            'suspended' => true,
            'terminal' => false,
            'observed' => 0,
         ])
         ->assert();
   }),
);
