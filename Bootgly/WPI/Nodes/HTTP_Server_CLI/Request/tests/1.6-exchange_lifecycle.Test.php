<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use RuntimeException;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request as HTTPRequest;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Test(
   description: 'Exchange should notify terminal observers exactly once and contain observer failures',
   test: new Assertions(Case: function (): \Generator {
      $Exchange = new Exchange;
      $Response = new Response(202);
      $Weak = \WeakReference::create($Response);
      $calls = [];
      $nested = null;
      $initial = $Exchange->check();
      $unobserved = $Exchange->inspect();

      $registered = [
         $Exchange->observe(
            static function (Exchange $Observed, null|int $code) use (
               &$calls,
               &$nested,
               $Exchange,
            ): void {
               $calls[] = [
                  'observer' => 'first',
                  'exchange' => $Observed === $Exchange,
                  'code' => $code,
               ];

               // ! Terminal state is committed before observers run, so a
               //   re-entrant completion cannot notify the snapshot twice.
               $Observed->disconnect();
               $nested = $Observed->finish(null);
            },
         ),
         $Exchange->observe(
            static function (Exchange $Observed, null|int $code) use (
               &$calls,
               $Exchange,
            ): void {
               $calls[] = [
                  'observer' => 'throwing',
                  'exchange' => $Observed === $Exchange,
                  'code' => $code,
               ];

               throw new RuntimeException('contained exchange observer');
            },
         ),
         $Exchange->observe(
            static function (Exchange $Observed, null|int $code) use (
               &$calls,
               $Exchange,
            ): void {
               $calls[] = [
                  'observer' => 'survivor',
                  'exchange' => $Observed === $Exchange,
                  'code' => $code,
               ];
            },
         ),
      ];
      $observed = $Exchange->inspect();

      $finished = $Exchange->finish($Response);
      $terminal = $Exchange->check();
      $terminalObserved = $Exchange->inspect();
      unset($Response);
      gc_collect_cycles();
      $releasedBeforeReplay = $Weak->get() === null;
      $lateCalls = [];
      $late = $Exchange->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$lateCalls,
            $Exchange,
         ): void {
            $lateCalls[] = [
               'exchange' => $Observed === $Exchange,
               'code' => $code,
            ];

            throw new RuntimeException('contained late exchange observer');
         },
      );
      gc_collect_cycles();
      $releasedAfterReplay = $Weak->get() === null;
      $duplicate = $Exchange->finish(null);
      $Exchange->disconnect();

      yield new Assertion(
         description: 'finish commits once, contains failures and replays only the terminal status',
      )
         ->expect([
            'initial' => $initial,
            'unobserved' => $unobserved,
            'registered' => $registered,
            'observed' => $observed,
            'terminal_observed' => $terminalObserved,
            'finished' => $finished,
            'terminal' => $terminal,
            'nested' => $nested,
            'late' => $late,
            'late_calls' => $lateCalls,
            'released_before_replay' => $releasedBeforeReplay,
            'released_after_replay' => $releasedAfterReplay,
            'duplicate' => $duplicate,
            'calls' => $calls,
         ])
         ->to->be([
            'initial' => false,
            'unobserved' => false,
            'registered' => [true, true, true],
            'observed' => true,
            'terminal_observed' => false,
            'finished' => true,
            'terminal' => true,
            'nested' => false,
            'late' => false,
            'late_calls' => [[
               'exchange' => true,
               'code' => 202,
            ]],
            'released_before_replay' => true,
            'released_after_replay' => true,
            'duplicate' => false,
            'calls' => [
               ['observer' => 'first', 'exchange' => true, 'code' => 202],
               ['observer' => 'throwing', 'exchange' => true, 'code' => 202],
               ['observer' => 'survivor', 'exchange' => true, 'code' => 202],
            ],
         ])
         ->assert();

      $Registry = new Exchange;
      $Owner = new HTTPRequest;
      $Alias = new HTTPRequest;
      $SourceWeak = \WeakReference::create($Owner);
      $registryCodes = [];
      $registryRegistered = $Registry->observe(
         static function (Exchange $Observed, null|int $code) use (
            $Alias,
            &$registryCodes,
            $Registry,
         ): void {
            $registryCodes[] = [
               'exchange' => $Observed === $Registry,
               'code' => $code,
               'alias_purged' => Exchange::fetch($Alias) === null,
            ];
         },
      );

      Exchange::admit($Owner, $Registry);
      $admitted = Exchange::fetch($Owner) === $Registry;
      $shared = Exchange::share($Owner, $Alias) === $Registry;
      $sourceOwned = Exchange::fetch($Owner) === $Registry;
      $aliasOwned = Exchange::fetch($Alias) === $Registry;

      // ! A missing source cannot erase an existing target generation.
      $Missing = new HTTPRequest;
      $Existing = new HTTPRequest;
      $Other = new Exchange;
      Exchange::admit($Existing, $Other);
      $missingShare = Exchange::share($Missing, $Existing);
      $existingPreserved = Exchange::fetch($Existing) === $Other;

      unset($Owner);
      gc_collect_cycles();
      $sourceReleased = $SourceWeak->get() === null;
      $aliasSurvived = Exchange::fetch($Alias) === $Registry
         && $Registry->check() === false;

      $Terminal = new Response(207);
      $TerminalWeak = \WeakReference::create($Terminal);
      $finishedRegistry = $Registry->finish($Terminal);
      $aliasPurged = Exchange::fetch($Alias) === null;
      $TerminalAlias = new HTTPRequest;
      Exchange::admit($TerminalAlias, $Registry);
      $terminalAdmitRejected = Exchange::fetch($TerminalAlias) === null;
      $duplicateRegistry = $Registry->finish(null);
      unset($Terminal);
      gc_collect_cycles();
      $terminalReleased = $TerminalWeak->get() === null;

      yield new Assertion(
         description: 'registry shares one exchange across weak Request aliases without moving its source',
      )
         ->expect([
            'registered' => $registryRegistered,
            'admitted' => $admitted,
            'shared' => $shared,
            'source_owned' => $sourceOwned,
            'alias_owned' => $aliasOwned,
            'missing_share' => $missingShare,
            'existing_preserved' => $existingPreserved,
            'source_released' => $sourceReleased,
            'alias_survived' => $aliasSurvived,
            'finished' => $finishedRegistry,
            'alias_purged' => $aliasPurged,
            'terminal_admit_rejected' => $terminalAdmitRejected,
            'duplicate' => $duplicateRegistry,
            'terminal_released' => $terminalReleased,
            'codes' => $registryCodes,
         ])
         ->to->be([
            'registered' => true,
            'admitted' => true,
            'shared' => true,
            'source_owned' => true,
            'alias_owned' => true,
            'missing_share' => null,
            'existing_preserved' => true,
            'source_released' => true,
            'alias_survived' => true,
            'finished' => true,
            'alias_purged' => true,
            'terminal_admit_rejected' => true,
            'duplicate' => false,
            'terminal_released' => true,
            'codes' => [[
               'exchange' => true,
               'code' => 207,
               'alias_purged' => true,
            ]],
         ])
         ->assert();

      $Snapshot = new Exchange;
      $SnapshotOwner = new \stdClass;
      $SnapshotWeak = \WeakReference::create($SnapshotOwner);
      Exchange::track($SnapshotOwner, $Snapshot);
      $snapshotActive = Exchange::fetch($SnapshotOwner) === $Snapshot;
      $snapshotFinished = $Snapshot->finish(new Response(204));
      $snapshotTerminal = Exchange::fetch($SnapshotOwner) === $Snapshot
         && $Snapshot->check();
      Exchange::track($SnapshotOwner, null);
      $snapshotCleared = Exchange::fetch($SnapshotOwner) === null;
      // @ A terminal tombstone may be published again for a retained clone.
      Exchange::track($SnapshotOwner, $Snapshot);
      $snapshotRestored = Exchange::fetch($SnapshotOwner) === $Snapshot;
      unset($SnapshotOwner);
      gc_collect_cycles();

      yield new Assertion(
         description: 'weak lifecycle snapshots retain terminal state until their owner is cleared or collected',
      )
         ->expect([
            'active' => $snapshotActive,
            'finished' => $snapshotFinished,
            'terminal' => $snapshotTerminal,
            'cleared' => $snapshotCleared,
            'restored' => $snapshotRestored,
            'owner_released' => $SnapshotWeak->get() === null,
         ])
         ->to->be([
            'active' => true,
            'finished' => true,
            'terminal' => true,
            'cleared' => true,
            'restored' => true,
            'owner_released' => true,
         ])
         ->assert();

      $ContextOwner = new Response;
      $WrongContextOwner = new Response;
      $ContextRequest = new HTTPRequest;
      $ContextExchange = new Exchange;
      /** @var Connection $ContextConnection */
      $ContextConnection = (new \ReflectionClass(Connection::class))
         ->newInstanceWithoutConstructor();
      $ContextPackage = new class($ContextConnection) extends TCPPackages {};

      Exchange::bind(
         $ContextOwner,
         $ContextPackage,
         $ContextRequest,
         $ContextExchange,
      );
      $WrongCapture = Exchange::capture($WrongContextOwner);
      $CapturedContext = Exchange::capture($ContextOwner);
      $SecondCapture = Exchange::capture($ContextOwner);

      yield new Assertion(
         description: 'pre-reset context capture is owner-scoped, exact and one-shot',
      )
         ->expect([
            'wrong_owner' => $WrongCapture,
            'package' => $CapturedContext['Package'] ?? null,
            'request' => $CapturedContext['Request'] ?? null,
            'exchange' => $CapturedContext['Exchange'] ?? null,
            'second_capture' => $SecondCapture,
         ])
         ->to->be([
            'wrong_owner' => null,
            'package' => $ContextPackage,
            'request' => $ContextRequest,
            'exchange' => $ContextExchange,
            'second_capture' => null,
         ])
         ->assert();

      $ReentrantOwner = new HTTPRequest;
      $RequestedOwner = new HTTPRequest;
      $Previous = new Exchange;
      $Requested = new Exchange;
      Exchange::admit($ReentrantOwner, $Previous);
      Exchange::admit($RequestedOwner, $Requested);
      $previousCodes = [];
      $interveningCodes = [];
      $Intervening = null;
      $publishedDuringDrain = null;
      $unpublishedBeforeReentry = null;
      $previousRegistered = $Previous->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$Intervening,
            &$interveningCodes,
            &$previousCodes,
            &$publishedDuringDrain,
            &$unpublishedBeforeReentry,
            $Previous,
            $ReentrantOwner,
         ): void {
            $previousCodes[] = [
               'exchange' => $Observed === $Previous,
               'code' => $code,
            ];
            $unpublishedBeforeReentry = Exchange::fetch($ReentrantOwner) === null;

            // ! Re-enter admission while the displaced exchange is draining.
            //   The outer admission must drain this intervening exchange too.
            $Intervening = new Exchange;
            $Intervening->observe(
               static function (
                  Exchange $InterveningObserved,
                  null|int $interveningCode,
               ) use (&$interveningCodes, $Intervening): void {
                  $interveningCodes[] = [
                     'exchange' => $InterveningObserved === $Intervening,
                     'code' => $interveningCode,
                  ];
               },
            );
            Exchange::admit($ReentrantOwner, $Intervening);
            $publishedDuringDrain = Exchange::fetch($ReentrantOwner)
               === $Intervening;
         },
      );

      Exchange::admit($ReentrantOwner, $Requested);
      $finalPublished = Exchange::fetch($ReentrantOwner) === $Requested;
      $sourceStillPublished = Exchange::fetch($RequestedOwner) === $Requested;
      $requestedFinished = $Requested->finish(new Response(201));
      $TerminalOwner = new HTTPRequest;
      Exchange::admit($TerminalOwner, $Requested);

      yield new Assertion(
         description: 'admit drains re-entrant exchanges before publishing and purges terminal aliases',
      )
         ->expect([
            'registered' => $previousRegistered,
            'unpublished_before_reentry' => $unpublishedBeforeReentry,
            'published_during_drain' => $publishedDuringDrain,
            'previous_terminal' => $Previous->check(),
            'previous_codes' => $previousCodes,
            'intervening_created' => $Intervening instanceof Exchange,
            'intervening_terminal' => $Intervening?->check(),
            'intervening_codes' => $interveningCodes,
            'final_published' => $finalPublished,
            'source_still_published' => $sourceStillPublished,
            'requested_finished' => $requestedFinished,
            'requested_terminal' => $Requested->check(),
            'owner_purged' => Exchange::fetch($ReentrantOwner) === null,
            'source_purged' => Exchange::fetch($RequestedOwner) === null,
            'terminal_admit_rejected' => Exchange::fetch($TerminalOwner) === null,
         ])
         ->to->be([
            'registered' => true,
            'unpublished_before_reentry' => true,
            'published_during_drain' => true,
            'previous_terminal' => true,
            'previous_codes' => [[
               'exchange' => true,
               'code' => null,
            ]],
            'intervening_created' => true,
            'intervening_terminal' => true,
            'intervening_codes' => [[
               'exchange' => true,
               'code' => null,
            ]],
            'final_published' => true,
            'source_still_published' => true,
            'requested_finished' => true,
            'requested_terminal' => true,
            'owner_purged' => true,
            'source_purged' => true,
            'terminal_admit_rejected' => true,
         ])
         ->assert();

      $LastOwner = new HTTPRequest;
      $Last = new Exchange;
      $lastCodes = [];
      $lastRegistered = $Last->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$lastCodes,
            $Last,
         ): void {
            $lastCodes[] = [
               'exchange' => $Observed === $Last,
               'code' => $code,
            ];
         },
      );
      Exchange::admit($LastOwner, $Last);
      $lastReleased = Exchange::release($LastOwner) === $Last;
      $lastOwnerCleared = Exchange::fetch($LastOwner) === null;
      $lastTerminal = $Last->check();
      $LastDuplicate = Exchange::release($LastOwner);

      $ReusableOwner = new HTTPRequest;
      $CapturedAlias = new HTTPRequest;
      $Captured = new Exchange;
      $capturedCodes = [];
      $capturedRegistered = $Captured->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$capturedCodes,
            $Captured,
         ): void {
            $capturedCodes[] = [
               'exchange' => $Observed === $Captured,
               'code' => $code,
            ];
         },
      );
      Exchange::admit($ReusableOwner, $Captured);
      $capturedShared = Exchange::share($ReusableOwner, $CapturedAlias)
         === $Captured;
      $capturedReleased = Exchange::release($ReusableOwner) === $Captured;
      $reusableCleared = Exchange::fetch($ReusableOwner) === null;
      $capturedPreserved = Exchange::fetch($CapturedAlias) === $Captured;
      $capturedActive = $Captured->check() === false;
      $capturedFinished = $Captured->finish(new Response(203));
      $capturedPurged = Exchange::fetch($CapturedAlias) === null;

      $ReleaseOwner = new HTTPRequest;
      $PreviousRelease = new Exchange;
      $InterveningRelease = null;
      $releaseCodes = [];
      $publishedDuringRelease = null;
      $releaseRegistered = $PreviousRelease->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$InterveningRelease,
            &$publishedDuringRelease,
            &$releaseCodes,
            $PreviousRelease,
            $ReleaseOwner,
         ): void {
            $releaseCodes[] = [
               'owner' => 'previous',
               'exchange' => $Observed === $PreviousRelease,
               'code' => $code,
            ];

            $InterveningRelease = new Exchange;
            $InterveningRelease->observe(
               static function (
                  Exchange $InterveningObserved,
                  null|int $interveningCode,
               ) use (&$releaseCodes, $InterveningRelease): void {
                  $releaseCodes[] = [
                     'owner' => 'intervening',
                     'exchange' => $InterveningObserved === $InterveningRelease,
                     'code' => $interveningCode,
                  ];
               },
            );
            Exchange::admit($ReleaseOwner, $InterveningRelease);
            $publishedDuringRelease = Exchange::fetch($ReleaseOwner)
               === $InterveningRelease;
         },
      );
      Exchange::admit($ReleaseOwner, $PreviousRelease);
      $reentrantReleased = Exchange::release($ReleaseOwner) === $PreviousRelease;

      yield new Assertion(
         description: 'release completes the last alias, preserves captures and drains re-entrant owners',
      )
         ->expect([
            'last_registered' => $lastRegistered,
            'last_released' => $lastReleased,
            'last_owner_cleared' => $lastOwnerCleared,
            'last_terminal' => $lastTerminal,
            'last_duplicate' => $LastDuplicate,
            'last_codes' => $lastCodes,
            'captured_registered' => $capturedRegistered,
            'captured_shared' => $capturedShared,
            'captured_released' => $capturedReleased,
            'reusable_cleared' => $reusableCleared,
            'captured_preserved' => $capturedPreserved,
            'captured_active' => $capturedActive,
            'captured_finished' => $capturedFinished,
            'captured_purged' => $capturedPurged,
            'captured_codes' => $capturedCodes,
            'release_registered' => $releaseRegistered,
            'published_during_release' => $publishedDuringRelease,
            'reentrant_released' => $reentrantReleased,
            'previous_terminal' => $PreviousRelease->check(),
            'intervening_created' => $InterveningRelease instanceof Exchange,
            'intervening_terminal' => $InterveningRelease?->check(),
            'release_owner_cleared' => Exchange::fetch($ReleaseOwner) === null,
            'release_codes' => $releaseCodes,
         ])
         ->to->be([
            'last_registered' => true,
            'last_released' => true,
            'last_owner_cleared' => true,
            'last_terminal' => true,
            'last_duplicate' => null,
            'last_codes' => [[
               'exchange' => true,
               'code' => null,
            ]],
            'captured_registered' => true,
            'captured_shared' => true,
            'captured_released' => true,
            'reusable_cleared' => true,
            'captured_preserved' => true,
            'captured_active' => true,
            'captured_finished' => true,
            'captured_purged' => true,
            'captured_codes' => [[
               'exchange' => true,
               'code' => 203,
            ]],
            'release_registered' => true,
            'published_during_release' => true,
            'reentrant_released' => true,
            'previous_terminal' => true,
            'intervening_created' => true,
            'intervening_terminal' => true,
            'release_owner_cleared' => true,
            'release_codes' => [
               ['owner' => 'previous', 'exchange' => true, 'code' => null],
               ['owner' => 'intervening', 'exchange' => true, 'code' => null],
            ],
         ])
         ->assert();

      $orphanCodes = [];
      $OrphanOwner = new HTTPRequest;
      $OrphanAlias = new HTTPRequest;
      $Orphan = new Exchange;
      $orphanRegistered = $Orphan->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$orphanCodes,
         ): void {
            $orphanCodes[] = [
               'terminal' => $Observed->check(),
               'code' => $code,
            ];
         },
      );
      Exchange::admit($OrphanOwner, $Orphan);
      $orphanShared = Exchange::share($OrphanOwner, $OrphanAlias) === $Orphan;
      $OrphanWeak = \WeakReference::create($Orphan);
      unset($Orphan);
      $heldWhileOwned = $OrphanWeak->get() instanceof Exchange;
      unset($OrphanOwner);
      gc_collect_cycles();
      $heldByAlias = $OrphanWeak->get() instanceof Exchange;
      unset($OrphanAlias);
      gc_collect_cycles();
      $orphanReleased = $OrphanWeak->get() === null;

      yield new Assertion(
         description: 'the last collected Request alias terminalizes its orphaned exchange with null',
      )
         ->expect([
            'registered' => $orphanRegistered,
            'shared' => $orphanShared,
            'held_while_owned' => $heldWhileOwned,
            'held_by_alias' => $heldByAlias,
            'released' => $orphanReleased,
            'codes' => $orphanCodes,
         ])
         ->to->be([
            'registered' => true,
            'shared' => true,
            'held_while_owned' => true,
            'held_by_alias' => true,
            'released' => true,
            'codes' => [[
               'terminal' => true,
               'code' => null,
            ]],
         ])
         ->assert();

      $Cancelled = new Exchange;
      $terminals = [];
      $cancelInitial = $Cancelled->check();
      $cancelRegistered = $Cancelled->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$terminals,
            $Cancelled,
         ): void {
            $terminals[] = [
               'exchange' => $Observed === $Cancelled,
               'code' => $code,
            ];
         },
      );

      $Cancelled->disconnect();
      $cancelTerminal = $Cancelled->check();
      $Cancelled->disconnect();
      $cancelFinished = $Cancelled->finish(new Response(500));
      $cancelLateCalls = [];
      $cancelLate = $Cancelled->observe(
         static function (Exchange $Observed, null|int $code) use (
            &$cancelLateCalls,
            $Cancelled,
         ): void {
            $cancelLateCalls[] = [
               'exchange' => $Observed === $Cancelled,
               'code' => $code,
            ];
         },
      );

      yield new Assertion(
         description: 'disconnect reports one null terminal and rejects every later transition',
      )
         ->expect([
            'initial' => $cancelInitial,
            'registered' => $cancelRegistered,
            'terminal' => $cancelTerminal,
            'finished' => $cancelFinished,
            'late' => $cancelLate,
            'late_calls' => $cancelLateCalls,
            'terminals' => $terminals,
         ])
         ->to->be([
            'initial' => false,
            'registered' => true,
            'terminal' => true,
            'finished' => false,
            'late' => false,
            'late_calls' => [[
               'exchange' => true,
               'code' => null,
            ]],
            'terminals' => [[
               'exchange' => true,
               'code' => null,
            ]],
         ])
         ->assert();
   }),
);
