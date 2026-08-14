<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;


return new Test(
   description: 'Cancellation should isolate scheduled generations and notify terminal observers once',
   test: new Assertions(Case: function (): Generator {
      $Owner = new stdClass;
      $OwnerWeak = WeakReference::create($Owner);
      $Token = Cancellation::open($Owner);
      $normalCalls = [];
      $nestedCancel = null;
      $registered = [
         $Token->observe(
            static function (Cancellation $Observed, bool $cancelled) use (
               &$nestedCancel,
               &$normalCalls,
               $Token,
            ): void {
               $normalCalls[] = [
                  'observer' => 'first',
                  'token' => $Observed === $Token,
                  'cancelled' => $cancelled,
               ];

               // ! State is terminal before replay; re-entrant cancellation
               //   cannot change a normal completion or notify twice.
               $nestedCancel = $Observed->cancel();
            },
         ),
         $Token->observe(
            static function (Cancellation $Observed, bool $cancelled) use (
               &$normalCalls,
               $Token,
            ): void {
               $normalCalls[] = [
                  'observer' => 'throwing',
                  'token' => $Observed === $Token,
                  'cancelled' => $cancelled,
               ];

               throw new RuntimeException('contained normal cancellation observer');
            },
         ),
         $Token->observe(
            static function (Cancellation $Observed, bool $cancelled) use (
               &$normalCalls,
               $Token,
            ): void {
               $normalCalls[] = [
                  'observer' => 'survivor',
                  'token' => $Observed === $Token,
                  'cancelled' => $cancelled,
               ];
            },
         ),
      ];
      $opened = Cancellation::fetch($Owner) === $Token;
      $initial = $Token->check();
      $finished = $Token->finish();
      $terminal = $Token->check();
      $ownerPurged = Cancellation::fetch($Owner) === null;
      $lateCalls = [];
      $late = $Token->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$lateCalls,
            $Token,
         ): void {
            $lateCalls[] = [
               'token' => $Observed === $Token,
               'cancelled' => $cancelled,
            ];

            throw new RuntimeException('contained late cancellation observer');
         },
      );
      $duplicateFinish = $Token->finish();
      $duplicateCancel = $Token->cancel();
      unset($Owner);
      gc_collect_cycles();

      yield new Assertion(
         description: 'finish commits once, contains observers and replays normal terminal state',
      )
         ->expect([
            'opened' => $opened,
            'initial' => $initial,
            'registered' => $registered,
            'finished' => $finished,
            'terminal' => $terminal,
            'owner_purged' => $ownerPurged,
            'nested_cancel' => $nestedCancel,
            'late' => $late,
            'late_calls' => $lateCalls,
            'duplicate_finish' => $duplicateFinish,
            'duplicate_cancel' => $duplicateCancel,
            'owner_released' => $OwnerWeak->get() === null,
            'calls' => $normalCalls,
         ])
         ->to->be([
            'opened' => true,
            'initial' => false,
            'registered' => [true, true, true],
            'finished' => true,
            'terminal' => true,
            'owner_purged' => true,
            'nested_cancel' => false,
            'late' => false,
            'late_calls' => [[
               'token' => true,
               'cancelled' => false,
            ]],
            'duplicate_finish' => false,
            'duplicate_cancel' => false,
            'owner_released' => true,
            'calls' => [
               ['observer' => 'first', 'token' => true, 'cancelled' => false],
               ['observer' => 'throwing', 'token' => true, 'cancelled' => false],
               ['observer' => 'survivor', 'token' => true, 'cancelled' => false],
            ],
         ])
         ->assert();

      $GenerationOwner = new stdClass;
      $First = Cancellation::open($GenerationOwner);
      $firstCalls = [];
      $firstRegistered = $First->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$firstCalls,
            $First,
         ): void {
            $firstCalls[] = [
               'token' => $Observed === $First,
               'cancelled' => $cancelled,
            ];
         },
      );
      $Second = Cancellation::open($GenerationOwner);
      $replacementPublished = Cancellation::fetch($GenerationOwner) === $Second;
      $firstTerminal = $First->check();
      $secondInitial = $Second->check();

      $Alias = new stdClass;
      Cancellation::link($Alias, $Second);
      $aliasLinked = Cancellation::fetch($Alias) === $Second;
      Cancellation::link($Alias, $Second);
      $sameLinkActive = $Second->check() === false;
      $secondCalls = [];
      $secondRegistered = $Second->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$secondCalls,
            $Alias,
            $GenerationOwner,
            $Second,
         ): void {
            $secondCalls[] = [
               'token' => $Observed === $Second,
               'cancelled' => $cancelled,
               'owner_purged' => Cancellation::fetch($GenerationOwner) === null,
               'explicit_purged' => Cancellation::fetch($Alias) === null,
            ];
         },
      );
      $secondFinished = $Second->finish();

      yield new Assertion(
         description: 'opening a new owner generation cancels only the replaced token and supports aliases',
      )
         ->expect([
            'first_registered' => $firstRegistered,
            'replacement_published' => $replacementPublished,
            'first_terminal' => $firstTerminal,
            'first_calls' => $firstCalls,
            'second_initial' => $secondInitial,
            'alias_linked' => $aliasLinked,
            'same_link_active' => $sameLinkActive,
            'second_registered' => $secondRegistered,
            'second_finished' => $secondFinished,
            'second_terminal' => $Second->check(),
            'second_calls' => $secondCalls,
            'owner_alias' => Cancellation::fetch($GenerationOwner) === $Second,
            'explicit_alias' => Cancellation::fetch($Alias) === $Second,
         ])
         ->to->be([
            'first_registered' => true,
            'replacement_published' => true,
            'first_terminal' => true,
            'first_calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'second_initial' => false,
            'alias_linked' => true,
            'same_link_active' => true,
            'second_registered' => true,
            'second_finished' => true,
            'second_terminal' => true,
            'second_calls' => [[
               'token' => true,
               'cancelled' => false,
               'owner_purged' => true,
               'explicit_purged' => true,
            ]],
            'owner_alias' => false,
            'explicit_alias' => false,
         ])
         ->assert();

      $LinkOwner = new stdClass;
      $ReplacementOwner = new stdClass;
      $LinkedFirst = Cancellation::open($LinkOwner);
      $LinkedSecond = Cancellation::open($ReplacementOwner);
      $linkCalls = [];
      $LinkedFirst->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$linkCalls,
            $LinkedFirst,
         ): void {
            $linkCalls[] = [
               'token' => $Observed === $LinkedFirst,
               'cancelled' => $cancelled,
            ];
         },
      );
      Cancellation::link($LinkOwner, $LinkedSecond);
      $ownerReplaced = Cancellation::fetch($LinkOwner) === $LinkedSecond;
      $sourcePreserved = Cancellation::fetch($ReplacementOwner) === $LinkedSecond;
      $linkedFinished = $LinkedSecond->finish();

      yield new Assertion(
         description: 'link replacement cancels the displaced generation and publishes the replacement alias',
      )
         ->expect([
            'first_terminal' => $LinkedFirst->check(),
            'first_calls' => $linkCalls,
            'owner_replaced' => $ownerReplaced,
            'source_preserved' => $sourcePreserved,
            'replacement_finished' => $linkedFinished,
            'replacement_terminal' => $LinkedSecond->check(),
            'owner_purged' => Cancellation::fetch($LinkOwner) === null,
            'source_purged' => Cancellation::fetch($ReplacementOwner) === null,
         ])
         ->to->be([
            'first_terminal' => true,
            'first_calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'owner_replaced' => true,
            'source_preserved' => true,
            'replacement_finished' => true,
            'replacement_terminal' => true,
            'owner_purged' => true,
            'source_purged' => true,
         ])
         ->assert();

      $ClaimOwner = new stdClass;
      $ClaimSource = new stdClass;
      $ClaimFirst = Cancellation::open($ClaimOwner);
      $ClaimSecond = Cancellation::open($ClaimSource);
      $claimedPrevious = Cancellation::claim($ClaimOwner, $ClaimSecond);
      $secondClaimed = Cancellation::fetch($ClaimOwner) === $ClaimSecond;
      $firstRemainedActive = $ClaimFirst->check() === false;
      $secondRemainedActive = $ClaimSecond->check() === false;
      $restoredPrevious = Cancellation::claim($ClaimOwner, $ClaimFirst);
      $firstRestored = Cancellation::fetch($ClaimOwner) === $ClaimFirst;
      $sourcePreservedAfterClaim = Cancellation::fetch($ClaimSource) === $ClaimSecond;
      $ClaimSecond->finish();
      $terminalClaim = Cancellation::claim($ClaimOwner, $ClaimSecond);
      $terminalClaimRejected = Cancellation::fetch($ClaimOwner) === $ClaimFirst;
      $ClaimFirst->finish();

      yield new Assertion(
         description: 'claim moves a selection lease without terminalizing it and supports rollback',
      )
         ->expect([
            'claimed_previous' => $claimedPrevious === $ClaimFirst,
            'second_claimed' => $secondClaimed,
            'first_active' => $firstRemainedActive,
            'second_active' => $secondRemainedActive,
            'restored_previous' => $restoredPrevious === $ClaimSecond,
            'first_restored' => $firstRestored,
            'source_preserved' => $sourcePreservedAfterClaim,
            'terminal_claim_previous' => $terminalClaim === $ClaimFirst,
            'terminal_claim_rejected' => $terminalClaimRejected,
         ])
         ->to->be([
            'claimed_previous' => true,
            'second_claimed' => true,
            'first_active' => true,
            'second_active' => true,
            'restored_previous' => true,
            'first_restored' => true,
            'source_preserved' => true,
            'terminal_claim_previous' => true,
            'terminal_claim_rejected' => true,
         ])
         ->assert();

      $ReentrantOwner = new stdClass;
      $RequestedOwner = new stdClass;
      $Previous = Cancellation::open($ReentrantOwner);
      $Requested = Cancellation::open($RequestedOwner);
      $previousCalls = [];
      $interveningCalls = [];
      $Intervening = null;
      $publishedDuringDrain = null;
      $unpublishedBeforeReentry = null;
      $previousRegistered = $Previous->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$Intervening,
            &$interveningCalls,
            &$previousCalls,
            &$publishedDuringDrain,
            &$unpublishedBeforeReentry,
            $Previous,
            $ReentrantOwner,
         ): void {
            $previousCalls[] = [
               'token' => $Observed === $Previous,
               'cancelled' => $cancelled,
            ];
            $unpublishedBeforeReentry = Cancellation::fetch($ReentrantOwner)
               === null;

            // ! Re-enter owner publication from the displaced generation's
            //   terminal observer. The outer link must drain this generation
            //   before it publishes the caller's requested token.
            $Intervening = Cancellation::open($ReentrantOwner);
            $publishedDuringDrain = Cancellation::fetch($ReentrantOwner)
               === $Intervening;
            $Intervening->observe(
               static function (
                  Cancellation $InterveningObserved,
                  bool $interveningCancelled,
               ) use (&$interveningCalls, $Intervening): void {
                  $interveningCalls[] = [
                     'token' => $InterveningObserved === $Intervening,
                     'cancelled' => $interveningCancelled,
                  ];
               },
            );
         },
      );

      Cancellation::link($ReentrantOwner, $Requested);
      $finalPublished = Cancellation::fetch($ReentrantOwner) === $Requested;
      $sourceStillPublished = Cancellation::fetch($RequestedOwner) === $Requested;
      $requestedFinished = $Requested->finish();
      $TerminalAlias = new stdClass;
      Cancellation::link($TerminalAlias, $Requested);

      yield new Assertion(
         description: 'link drains re-entrant generations before publishing and terminal aliases stay purged',
      )
         ->expect([
            'registered' => $previousRegistered,
            'unpublished_before_reentry' => $unpublishedBeforeReentry,
            'published_during_drain' => $publishedDuringDrain,
            'previous_terminal' => $Previous->check(),
            'previous_calls' => $previousCalls,
            'intervening_created' => $Intervening instanceof Cancellation,
            'intervening_terminal' => $Intervening?->check(),
            'intervening_calls' => $interveningCalls,
            'final_published' => $finalPublished,
            'source_still_published' => $sourceStillPublished,
            'requested_finished' => $requestedFinished,
            'requested_terminal' => $Requested->check(),
            'owner_purged' => Cancellation::fetch($ReentrantOwner) === null,
            'source_purged' => Cancellation::fetch($RequestedOwner) === null,
            'terminal_link_rejected' => Cancellation::fetch($TerminalAlias) === null,
         ])
         ->to->be([
            'registered' => true,
            'unpublished_before_reentry' => true,
            'published_during_drain' => true,
            'previous_terminal' => true,
            'previous_calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'intervening_created' => true,
            'intervening_terminal' => true,
            'intervening_calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'final_published' => true,
            'source_still_published' => true,
            'requested_finished' => true,
            'requested_terminal' => true,
            'owner_purged' => true,
            'source_purged' => true,
            'terminal_link_rejected' => true,
         ])
         ->assert();

      $CancelledOwner = new stdClass;
      $Cancelled = Cancellation::open($CancelledOwner);
      $cancelCalls = [];
      $cancelRegistered = $Cancelled->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$cancelCalls,
            $Cancelled,
         ): void {
            $cancelCalls[] = [
               'token' => $Observed === $Cancelled,
               'cancelled' => $cancelled,
            ];
         },
      );
      $cancelled = $Cancelled->cancel();
      $Cancelled->disconnect();
      $cancelLateCalls = [];
      $cancelLate = $Cancelled->observe(
         static function (Cancellation $Observed, bool $wasCancelled) use (
            &$cancelLateCalls,
            $Cancelled,
         ): void {
            $cancelLateCalls[] = [
               'token' => $Observed === $Cancelled,
               'cancelled' => $wasCancelled,
            ];
         },
      );

      $DisconnectedOwner = new stdClass;
      $Disconnected = Cancellation::open($DisconnectedOwner);
      $disconnectCalls = [];
      $Disconnected->observe(
         static function (Cancellation $Observed, bool $cancelled) use (
            &$disconnectCalls,
            $Disconnected,
         ): void {
            $disconnectCalls[] = [
               'token' => $Observed === $Disconnected,
               'cancelled' => $cancelled,
            ];
         },
      );
      $Disconnected->disconnect();
      $disconnectDuplicate = $Disconnected->cancel();

      yield new Assertion(
         description: 'cancel and disconnect each publish one cancellation terminal state',
      )
         ->expect([
            'registered' => $cancelRegistered,
            'cancelled' => $cancelled,
            'terminal' => $Cancelled->check(),
            'calls' => $cancelCalls,
            'late' => $cancelLate,
            'late_calls' => $cancelLateCalls,
            'owner_purged' => Cancellation::fetch($CancelledOwner) === null,
            'disconnect_terminal' => $Disconnected->check(),
            'disconnect_duplicate' => $disconnectDuplicate,
            'disconnect_calls' => $disconnectCalls,
            'disconnect_owner_purged' => Cancellation::fetch($DisconnectedOwner) === null,
         ])
         ->to->be([
            'registered' => true,
            'cancelled' => true,
            'terminal' => true,
            'calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'late' => false,
            'late_calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'owner_purged' => true,
            'disconnect_terminal' => true,
            'disconnect_duplicate' => false,
            'disconnect_calls' => [[
               'token' => true,
               'cancelled' => true,
            ]],
            'disconnect_owner_purged' => true,
         ])
         ->assert();
   }),
);
