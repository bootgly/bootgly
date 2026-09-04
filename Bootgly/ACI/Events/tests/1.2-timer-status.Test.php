<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


final class H7LegacyTimer extends Timer
{
   /** Legacy downstream instance check method. */
   public function check (): bool
   {
      return true;
   }
}


final class H7TimerResetCapture
{
   // * Metadata
   /** Number of observer-capture destructor executions. */
   public static int $destructions = 0;


   /** Throw during observer-capture release to exercise the reset boundary. */
   public function __destruct ()
   {
      self::$destructions++;
      throw new RuntimeException('expected reset observer capture failure');
   }
}


final class H7TimerTaskCapture
{
   // * Metadata
   /** Number of timer-capture destructor executions. */
   public static int $destructions = 0;


   /** Throw during timer-capture release to exercise the deletion boundary. */
   public function __destruct ()
   {
      self::$destructions++;
      throw new RuntimeException('expected timer task capture failure');
   }
}


/** Destructor chain that recursively requests full Timer resets. */
final class H7TimerResetReleaseChain
{
   public static int $calls = 0;
   public static int $depth = 0;
   public static int $maxDepth = 0;

   // * Data
   private int $remaining;


   public function __construct (int $remaining)
   {
      $this->remaining = $remaining;
   }

   /** Publish one captured successor and request another global deletion. */
   public function __destruct ()
   {
      self::$calls++;
      self::$depth++;
      self::$maxDepth = max(self::$maxDepth, self::$depth);
      try {
         if ($this->remaining > 0) {
            $Next = new self($this->remaining - 1);
            Timer::add(
               30,
               static function () use ($Next): void {},
            );
            unset($Next);
            Timer::del();
         }
      }
      finally {
         self::$depth--;
      }
   }
}


/** Old detached capture that must not starve behind new release generations. */
final class H7TimerReleaseVictim
{
   public static int $calls = 0;


   public function __destruct ()
   {
      self::$calls++;
   }
}


/** Detached capture that continuously appends one targeted-delete successor. */
final class H7TimerReleaseSpinner
{
   public static bool $armed = true;
   public static int $calls = 0;


   public function __destruct ()
   {
      self::$calls++;
      if (self::$armed === false) {
         return;
      }
      $Next = new self;
      $timer = Timer::add(
         30,
         static function () use ($Next): void {},
      );
      unset($Next);
      if ($timer !== false) {
         Timer::del($timer);
      }
   }
}


return new Test(
   description: 'Timer status should follow deletion and one-shot completion',
   skip: function_exists('pcntl_alarm') === false,
   test: new Assertions(Case: function (): Generator {
      $Previous = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      Timer::del();
      $resetObserver = 0;
      $resetObservers = [];
      $ResetID = new ReflectionProperty(TimerReset::class, 'id');
      $Keep = new ReflectionMethod(TimerReset::class, 'keep');
      $Drop = new ReflectionMethod(TimerReset::class, 'drop');
      $originalResetID = $ResetID->getValue();

      try {
         $targeted = Timer::add(30, static function (): void {});
         $targetedStates = [
            $targeted !== false && TimerRegistry::check($targeted),
            $targeted !== false ? Timer::del($targeted) : false,
            $targeted !== false && TimerRegistry::check($targeted),
         ];

         H7TimerTaskCapture::$destructions = 0;
         $TaskCapture = new H7TimerTaskCapture;
         $capturedTimer = Timer::add(
            30,
            static function () use ($TaskCapture): void {},
         );
         unset($TaskCapture);
         $capturedTimerStates = [
            $capturedTimer !== false,
            $capturedTimer !== false ? Timer::del($capturedTimer) : false,
            H7TimerTaskCapture::$destructions,
            $capturedTimer !== false
               && TimerRegistry::check($capturedTimer),
         ];

         $global = Timer::add(30, static function (): void {});
         $beforeGlobal = $global !== false && TimerRegistry::check($global);
         Timer::del();
         $afterGlobal = $global !== false && TimerRegistry::check($global);

         $fired = false;
         $oneShot = Timer::add(
            30,
            static function () use (&$fired): void {
               $fired = true;
               throw new RuntimeException('expected one-shot fixture failure');
            },
            persistent: false,
         );
         $Tasks = new ReflectionProperty(Timer::class, 'tasks');
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $afterOneShot = $oneShot !== false && TimerRegistry::check($oneShot);

         $persistent = Timer::add(30, static function (): void {});
         $due = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $task) {
               $due[$id] = $task;
            }
         }
         $Tasks->setValue(null, [time() - 1 => $due]);
         Timer::tick();
         $persistentStates = [
            $persistent !== false && TimerRegistry::check($persistent),
            $persistent !== false ? Timer::del($persistent) : false,
            $persistent !== false && TimerRegistry::check($persistent),
         ];

         $resetCalls = 0;
         $resetTimer = false;
         $resetObserver = TimerReset::add(
            static function () use (&$resetCalls, &$resetTimer): void {
               $resetCalls++;
               $resetTimer = Timer::add(30, static function (): void {});
            },
         );
         Timer::add(30, static function (): void {});
         Timer::del();
         $resetStates = [
            $resetCalls,
            $resetTimer !== false && TimerRegistry::check($resetTimer),
         ];
         TimerReset::del($resetObserver);
         $resetObserver = 0;
         Timer::del();
         $resetStates[] = $resetTimer !== false && TimerRegistry::check($resetTimer);
         $reentrantCalls = 0;
         $resetObserver = TimerReset::add(
            static function () use (&$reentrantCalls): void {
               $reentrantCalls++;
               Timer::del();
            },
         );
         Timer::add(30, static function (): void {});
         Timer::del();
         TimerReset::del($resetObserver);
         $resetObserver = 0;
         $resetStates[] = $reentrantCalls;

         H7TimerResetReleaseChain::$calls = 0;
         H7TimerResetReleaseChain::$depth = 0;
         H7TimerResetReleaseChain::$maxDepth = 0;
         $firstResetNotifyDepth = -1;
         $resetObserver = (int) $Keep->invoke(
            null,
            static function () use (&$firstResetNotifyDepth): void {
               if ($firstResetNotifyDepth === -1) {
                  $firstResetNotifyDepth = H7TimerResetReleaseChain::$depth;
               }
            },
         );
         $ResetChain = new H7TimerResetReleaseChain(300);
         Timer::add(
            30,
            static function () use ($ResetChain): void {},
         );
         unset($ResetChain);
         Timer::del();
         $firstResetReleaseState = [
            H7TimerResetReleaseChain::$maxDepth,
            $firstResetNotifyDepth,
            H7TimerResetReleaseChain::$calls,
         ];
         if (
            $firstResetReleaseState[0] > 1
            || $firstResetReleaseState[1] !== 0
            || $firstResetReleaseState[2] > 256
         ) {
            $Drop->invoke(null, $resetObserver);
            $resetObserver = 0;
            Timer::del();
            throw new RuntimeException(
               'CONFIRMED BG-H7-8: full Timer reset release recursed before recovery.'
            );
         }
         $Drop->invoke(null, $resetObserver);
         $resetObserver = 0;
         Timer::tick();
         $ReleaseQueueProbe = new ReflectionProperty(
            Timer::class,
            'ReleaseQueue',
         );
         $tickReleaseState = [
            H7TimerResetReleaseChain::$calls,
            count($ReleaseQueueProbe->getValue()),
         ];
         Timer::del();
         $resetReleaseState = [
            ...$firstResetReleaseState,
            ...$tickReleaseState,
            H7TimerResetReleaseChain::$depth,
            H7TimerResetReleaseChain::$calls,
         ];

         H7TimerResetReleaseChain::$calls = 0;
         H7TimerResetReleaseChain::$depth = 0;
         H7TimerResetReleaseChain::$maxDepth = 0;
         $addNotifyDepth = -1;
         $resetObserver = (int) $Keep->invoke(
            null,
            static function () use (&$addNotifyDepth): void {
               if ($addNotifyDepth === -1) {
                  $addNotifyDepth = H7TimerResetReleaseChain::$depth;
               }
            },
         );
         $AddResetChain = new H7TimerResetReleaseChain(300);
         Timer::add(
            30,
            static function () use ($AddResetChain): void {},
         );
         unset($AddResetChain);
         Timer::del();
         $addFirstCalls = H7TimerResetReleaseChain::$calls;
         $Drop->invoke(null, $resetObserver);
         $resetObserver = 0;
         $continuedTimer = Timer::add(30, static function (): void {});
         $addReleaseState = [
            $addNotifyDepth,
            $addFirstCalls,
            H7TimerResetReleaseChain::$calls,
            count($ReleaseQueueProbe->getValue()),
            $continuedTimer !== false,
            $continuedTimer !== false && TimerRegistry::check($continuedTimer),
         ];
         Timer::del();

         H7TimerTaskCapture::$destructions = 0;
         $notifyTimer = false;
         $resetObservers[] = TimerReset::add(
            static function () use (&$notifyTimer): void {
               if ($notifyTimer !== false) {
                  Timer::del($notifyTimer);
               }
            },
         );
         $resetObservers[] = TimerReset::add(
            static function () use (&$notifyTimer): void {
               $Capture = new H7TimerTaskCapture;
               $notifyTimer = Timer::add(
                  30,
                  static function () use ($Capture): void {},
               );
               unset($Capture);
            },
         );
         Timer::add(30, static function (): void {});
         Timer::del();
         $notifyTargetedState = [
            $notifyTimer !== false,
            $notifyTimer !== false && TimerRegistry::check($notifyTimer),
            H7TimerTaskCapture::$destructions,
            count($ReleaseQueueProbe->getValue()),
         ];
         foreach ($resetObservers as $observer) {
            TimerReset::del($observer);
         }
         $resetObservers = [];

         H7TimerReleaseVictim::$calls = 0;
         H7TimerReleaseSpinner::$armed = true;
         H7TimerReleaseSpinner::$calls = 0;
         $resetObservers[] = TimerReset::add(
            static function (): void {
               $Spinner = new H7TimerReleaseSpinner;
               $timer = Timer::add(
                  30,
                  static function () use ($Spinner): void {},
               );
               unset($Spinner);
               if ($timer !== false) {
                  Timer::del($timer);
               }
            },
         );
         $resetObservers[] = TimerReset::add(
            static function (): void {
               $Victim = new H7TimerReleaseVictim;
               $timer = Timer::add(
                  30,
                  static function () use ($Victim): void {},
               );
               unset($Victim);
               if ($timer !== false) {
                  Timer::del($timer);
               }
            },
         );
         Timer::add(30, static function (): void {});
         Timer::del();
         $releaseFairFirst = [
            H7TimerReleaseVictim::$calls,
            H7TimerReleaseSpinner::$calls > 0,
            count($ReleaseQueueProbe->getValue()),
         ];
         H7TimerReleaseSpinner::$armed = false;
         Timer::tick();
         $releaseFairnessState = [
            ...$releaseFairFirst,
            H7TimerReleaseVictim::$calls,
            count($ReleaseQueueProbe->getValue()),
         ];
         foreach ($resetObservers as $observer) {
            TimerReset::del($observer);
         }
         $resetObservers = [];
         Timer::del();

         $causalCalls = [0, 0, 0];
         $essentialTimer = false;
         // @ Registration is low resetter -> middle owner -> high resetter;
         //   the stable snapshot dispatches them in LIFO order.
         $resetObservers[] = TimerReset::add(
            static function () use (&$causalCalls): void {
               $causalCalls[0]++;
               Timer::del();
            },
         );
         $resetObservers[] = TimerReset::add(
            static function () use (&$causalCalls, &$essentialTimer): void {
               $causalCalls[1]++;
               if (
                  $essentialTimer === false
                  || TimerRegistry::check($essentialTimer) === false
               ) {
                  $essentialTimer = Timer::add(30, static function (): void {});
               }
            },
         );
         $resetObservers[] = TimerReset::add(
            static function () use (&$causalCalls): void {
               $causalCalls[2]++;
               Timer::del();
            },
         );
         Timer::add(30, static function (): void {});
         Timer::del();
         $causalStates = [
            $causalCalls,
            $essentialTimer !== false
               && TimerRegistry::check($essentialTimer),
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];
         Timer::del();

         $mutationCalls = [0, 0, 0];
         $mutated = false;
         $mutatedObserver = 0;
         $originalObserver = TimerReset::add(
            static function () use (&$mutationCalls): void {
               $mutationCalls[0]++;
            },
         );
         $resetObservers[] = $originalObserver;
         $resetObservers[] = TimerReset::add(
            static function () use (
               &$mutated,
               &$mutatedObserver,
               &$mutationCalls,
               &$resetObservers,
               $ResetID,
               $originalObserver,
            ): void {
               $mutationCalls[1]++;
               if ($mutated === false) {
                  $mutated = true;
                  TimerReset::del($originalObserver);
                  $ResetID->setValue(
                     null,
                     $originalObserver === 1
                        ? PHP_INT_MAX
                        : $originalObserver - 1,
                  );
                  $mutatedObserver = TimerReset::add(
                     static function () use (&$mutationCalls): void {
                        $mutationCalls[2]++;
                     },
                  );
                  $resetObservers[] = $mutatedObserver;
                  TimerReset::notify();
               }
               throw new RuntimeException('expected reset observer failure');
            },
         );
         TimerReset::notify();
         $mutationStates = [
            $mutationCalls,
            $mutatedObserver > 0
               && $mutatedObserver !== $originalObserver,
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];

         H7TimerResetCapture::$destructions = 0;
         $Capture = new H7TimerResetCapture;
         $captureObserver = 0;
         $captureObserver = TimerReset::add(
            static function () use (&$captureObserver, $Capture): void {
               TimerReset::del($captureObserver);
            },
         );
         $resetObservers[] = $captureObserver;
         unset($Capture);
         TimerReset::notify();
         $captureDestructions = H7TimerResetCapture::$destructions;
         $resetObservers = [];

         $collisionCalls = [0, 0];
         $collisionObserverA = TimerReset::add(
            static function () use (&$collisionCalls): void {
               $collisionCalls[0]++;
            },
         );
         $resetObservers[] = $collisionObserverA;
         $ResetID->setValue(
            null,
            $collisionObserverA === 1
               ? PHP_INT_MAX
               : $collisionObserverA - 1,
         );
         $collisionObserverB = TimerReset::add(
            static function () use (&$collisionCalls): void {
               $collisionCalls[1]++;
            },
         );
         $resetObservers[] = $collisionObserverB;
         TimerReset::notify();
         $collisionStates = [
            $collisionObserverA > 0,
            $collisionObserverB > 0,
            $collisionObserverA !== $collisionObserverB,
            $collisionCalls,
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];

         $NotifyBudget = new ReflectionClassConstant(
            TimerReset::class,
            'NOTIFY_BUDGET',
         );
         $notifyBudget = (int) $NotifyBudget->getValue();
         $successorCalls = 0;
         $requestedSuccessors = $notifyBudget + 32;
         $RegisterSuccessor = null;
         $RegisterSuccessor = static function () use (
            &$RegisterSuccessor,
            &$resetObservers,
            &$successorCalls,
            $requestedSuccessors,
         ): void {
            $observer = 0;
            $observer = TimerReset::add(
               static function () use (
                  &$RegisterSuccessor,
                  &$resetObservers,
                  &$successorCalls,
                  &$observer,
                  $requestedSuccessors,
               ): void {
                  $successorCalls++;
                  TimerReset::del($observer);
                  if (
                     $successorCalls < $requestedSuccessors
                     && $RegisterSuccessor instanceof Closure
                  ) {
                     $RegisterSuccessor();
                     TimerReset::notify();
                  }
               },
            );
            $resetObservers[] = $observer;
         };
         $RegisterSuccessor();
         TimerReset::notify();
         $Observers = new ReflectionProperty(TimerReset::class, 'Observers');
         $Recoveries = new ReflectionProperty(TimerReset::class, 'Recoveries');
         $Notifying = new ReflectionProperty(TimerReset::class, 'notifying');
         $Pending = new ReflectionProperty(TimerReset::class, 'pending');
         $Reserved = new ReflectionProperty(TimerReset::class, 'reserved');
         $successorStates = [
            $notifyBudget,
            $successorCalls,
            $successorCalls < $requestedSuccessors,
            count($Observers->getValue()),
            $Notifying->getValue(),
            $Pending->getValue(),
            $Reserved->getValue(),
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];
         $RegisterSuccessor = null;
         gc_collect_cycles();
         $postBudgetCalls = 0;
         $postBudgetObserver = TimerReset::add(
            static function () use (&$postBudgetCalls): void {
               $postBudgetCalls++;
            },
         );
         TimerReset::notify();
         TimerReset::del($postBudgetObserver);
         $successorStates[] = $postBudgetCalls;

         $fairCalls = 0;
         $resetObservers[] = TimerReset::add(
            static function () use (&$fairCalls): void {
               $fairCalls++;
            },
         );
         for ($index = 0; $index < $notifyBudget; $index++) {
            $resetObservers[] = TimerReset::add(static function (): void {});
         }
         TimerReset::notify();
         $fairStates = [$fairCalls];
         TimerReset::notify();
         $fairStates[] = $fairCalls;
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];

         $recoveryCalls = 0;
         $throwRecoveryCalls = 0;
         $nestedRecoveryResets = 0;
         $recoveryTimer = false;
         for ($index = 0; $index < $notifyBudget + 32; $index++) {
            $resetObservers[] = TimerReset::add(static function (): void {});
         }
         $resetObservers[] = (int) $Keep->invoke(
            null,
            static function () use (&$nestedRecoveryResets): void {
               $nestedRecoveryResets++;
               Timer::del();
            },
         );
         $resetObservers[] = (int) $Keep->invoke(
            null,
            static function () use (&$recoveryCalls, &$recoveryTimer): void {
               $recoveryCalls++;
               $recoveryTimer = Timer::add(30, static function (): void {});
            },
         );
         $resetObservers[] = (int) $Keep->invoke(
            null,
            static function () use (&$throwRecoveryCalls): void {
               $throwRecoveryCalls++;
               throw new RuntimeException('expected recovery observer failure');
            },
         );
         Timer::add(30, static function (): void {});
         Timer::del();
         $recoveryStates = [
            $nestedRecoveryResets,
            $recoveryCalls,
            $throwRecoveryCalls,
            $recoveryTimer !== false
               && TimerRegistry::check($recoveryTimer),
            $Notifying->getValue(),
            $Pending->getValue(),
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];
         $recoveryStates[] = count($Recoveries->getValue());
         Timer::del();

         $recoverySuccessorCalls = 0;
         $requestedRecoverySuccessors = 600;
         $RegisterRecovery = null;
         $RegisterRecovery = static function () use (
            &$RegisterRecovery,
            &$recoverySuccessorCalls,
            &$resetObservers,
            $Keep,
            $requestedRecoverySuccessors,
         ): void {
            $observer = 0;
            $observer = (int) $Keep->invoke(
               null,
               static function () use (
                  &$RegisterRecovery,
                  &$recoverySuccessorCalls,
                  &$resetObservers,
                  &$observer,
                  $requestedRecoverySuccessors,
               ): void {
                  $recoverySuccessorCalls++;
                  TimerReset::del($observer);
                  if (
                     $recoverySuccessorCalls < $requestedRecoverySuccessors
                     && $RegisterRecovery instanceof Closure
                  ) {
                     $RegisterRecovery();
                     TimerReset::notify();
                  }
               },
            );
            $resetObservers[] = $observer;
         };
         $RegisterRecovery();
         TimerReset::notify();
         $recoveryChainStates = [
            $recoverySuccessorCalls,
            $recoverySuccessorCalls < $requestedRecoverySuccessors,
            $Notifying->getValue(),
            $Pending->getValue(),
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];
         $RegisterRecovery = null;
         gc_collect_cycles();
         $recoveryChainStates[] = count($Recoveries->getValue());

         $RecoveryBudget = new ReflectionClassConstant(
            TimerReset::class,
            'RECOVERY_BUDGET',
         );
         $recoveryBudget = (int) $RecoveryBudget->getValue();
         $recoveryBudgetCalls = 0;
         for ($index = 0; $index < $recoveryBudget + 4; $index++) {
            $resetObservers[] = (int) $Keep->invoke(
               null,
               static function () use (&$recoveryBudgetCalls): void {
                  $recoveryBudgetCalls++;
               },
            );
         }
         TimerReset::notify();
         $recoveryBudgetStates = [
            $recoveryBudget,
            $recoveryBudgetCalls,
            count($Recoveries->getValue()),
            $Notifying->getValue(),
            $Pending->getValue(),
         ];
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         $resetObservers = [];
         $recoveryBudgetStates[] = count($Recoveries->getValue());

         $publicDeleteRecoveryCalls = 0;
         $publicDeleteRecovery = (int) $Keep->invoke(
            null,
            static function () use (&$publicDeleteRecoveryCalls): void {
               $publicDeleteRecoveryCalls++;
            },
         );
         TimerReset::del($publicDeleteRecovery);
         $publicDeleteRetainedBefore = isSet(
            $Recoveries->getValue()[$publicDeleteRecovery]
         );
         TimerReset::notify();
         $publicDeleteRetainedAfter = isSet(
            $Recoveries->getValue()[$publicDeleteRecovery]
         );
         $Drop->invoke(null, $publicDeleteRecovery);
         $publicDeleteRecoveryStates = [
            $publicDeleteRetainedBefore,
            $publicDeleteRecoveryCalls,
            $publicDeleteRetainedAfter,
            count($Recoveries->getValue()),
         ];

         $snapshotTimerA = Timer::add(30, static function (): void {});
         $snapshotTimerB = Timer::add(30, static function (): void {});
         $snapshot = TimerRegistry::snapshot();
         $snapshotStates = [
            $snapshotTimerA !== false
               && in_array($snapshotTimerA, $snapshot, true),
            $snapshotTimerB !== false
               && in_array($snapshotTimerB, $snapshot, true),
            $snapshotTimerA !== false ? Timer::del($snapshotTimerA) : false,
            $snapshotTimerA !== false
               && in_array($snapshotTimerA, $snapshot, true),
            $snapshotTimerA !== false
               && TimerRegistry::check($snapshotTimerA),
         ];
         if ($snapshotTimerB !== false) {
            Timer::del($snapshotTimerB);
         }
         $remainingAlarm = pcntl_alarm(0);
         $LegacyTimer = new H7LegacyTimer;
         $ReleaseQueue = new ReflectionProperty(Timer::class, 'ReleaseQueue');
         $DeletionDepth = new ReflectionProperty(Timer::class, 'deletionDepth');
         $ResetPending = new ReflectionProperty(Timer::class, 'resetPending');
         $ResetNotifying = new ReflectionProperty(Timer::class, 'resetNotifying');
         $deletionStates = [
            count($ReleaseQueue->getValue()),
            $DeletionDepth->getValue(),
            $ResetPending->getValue(),
            $ResetNotifying->getValue(),
         ];

         yield new Assertion(description: 'timer status tracks deletion and completion')
            ->expect(
               [
                  $targetedStates,
                  $capturedTimerStates,
                  $beforeGlobal,
                  $afterGlobal,
                  $fired,
                  $afterOneShot,
                  $persistentStates,
                  $resetStates,
                  $resetReleaseState,
                  $addReleaseState,
                  $notifyTargetedState,
                  $releaseFairnessState,
                  $causalStates,
                  $mutationStates,
                  $captureDestructions,
                  $collisionStates,
                  $successorStates,
                  $fairStates,
                  $recoveryStates,
                  $recoveryChainStates,
                  $recoveryBudgetStates,
                  $publicDeleteRecoveryStates,
                  $snapshotStates,
                  $deletionStates,
                  $remainingAlarm,
                  TimerRegistry::check(0),
                  $LegacyTimer->check(),
               ],
               Op::Identical,
               [
                  [true, true, false],
                  [true, true, 1, false],
                  true,
                  false,
                  true,
                  false,
                  [true, true, false],
                  [1, true, false, 1],
                  [1, 0, 256, 301, 0, 0, 301],
                  [0, 256, 301, 0, true, true],
                  [true, false, 1, 0],
                  [1, true, 1, 1, 0],
                  [[1, 2, 1], true],
                  [[1, 1, 1], true],
                  1,
                  [true, true, true, [1, 1]],
                  [$notifyBudget, $notifyBudget, true, 1, false, false, [], 1],
                  [0, 1],
                  [1, 2, 2, true, false, false, 0],
                  [1, true, false, false, 0],
                  [8, 8, 12, false, false, 0],
                  [true, 1, true, 0],
                  [true, true, true, true, false],
                  [0, 0, false, false],
                  0,
                  false,
                  true,
               ],
            )
            ->assert();
      }
      finally {
         H7TimerReleaseSpinner::$armed = false;
         $ResetID->setValue(null, $originalResetID);
         if ($resetObserver > 0) {
            $Drop->invoke(null, $resetObserver);
            $resetObserver = 0;
         }
         foreach ($resetObservers as $observer) {
            $Drop->invoke(null, $observer);
         }
         try {
            Timer::tick();
            Timer::del();
         }
         catch (Throwable) {
            // Preserve the original assertion/fixture failure during teardown.
         }
         pcntl_signal(SIGALRM, $Previous === false ? SIG_DFL : $Previous);
      }
   })
);
