<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security regression H1 (2026-08-01) — the default Shared Cache session
 * backend must not let a stale request recreate an invalidated authenticated
 * ID, overwrite an in-place logout, or restore old auth state during touch.
 *
 * The live HTTP route owns a fresh SysV segment and an independent session
 * secret. Two Session objects load the same authenticated record before one
 * regenerates it. Further same-ID fixtures cover a nonempty partial logout and
 * the fetch/update/touch interleaving. Every stale mutation must fail closed,
 * while the winning snapshots and an unrelated-ID control remain readable.
 */
return new Test(
   description: 'Shared Cache sessions must reject stale auth writes and touches',
   Separator: new Separator(line: true),
   skip: extension_loaded('sysvshm') === false
      || extension_loaded('sysvsem') === false,

   request: static function (): string {
      return "GET /h1/session-cache-stale-writer HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h1/session-cache-stale-writer', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Evidence = [
            'fixture_error' => '',
            'backend' => [],
            'control' => [],
            'preconditions' => [],
            'attack' => [],
            'regenerate_attack' => [],
            'downgrade_attack' => [],
            'downgrade_regenerate_attack' => [],
            'touch_attack' => [],
            'cleanup' => [],
         ];
         /** @var array<int,Session> $Sessions */
         $Sessions = [];
         $Handler = null;
         $CacheResource = null;
         $Driver = null;
         $TouchCache = null;

         $Find = static function (string $path, int $key): bool {
            foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
               $fields = preg_split('/\s+/', trim($line));
               if (is_array($fields) && (int) ($fields[0] ?? 0) === $key) {
                  return true;
               }
            }

            return false;
         };

         do {
            $segment = random_int(0x20000000, 0x6fffffff);
         }
         while (
            $Find('/proc/sysvipc/shm', $segment)
            || $Find('/proc/sysvipc/sem', $segment)
         );

         $PreviousHandler = Handler::$instance;
         $SessionReflection = new ReflectionClass(Session::class);
         $NeedSaveProperty = $SessionReflection->getProperty('needSave');

         try {
            // ! Omitting `driver` exercises Cache's production default
            // selection (`shared`) while the explicit segment and secret keep
            // this fixture isolated from the suite worker's normal sessions.
            $Handler = new Cache([
               'segment' => $segment,
               'size' => 262_144,
               'permissions' => 0600,
               'secret' => bin2hex(random_bytes(32)),
            ]);
            $CacheReflection = new ReflectionObject($Handler);
            $CacheProperty = $CacheReflection->getProperty('Cache');
            /** @var CacheResource $CacheResource */
            $CacheResource = $CacheProperty->getValue($Handler);
            $Driver = $CacheResource->Driver;
            Handler::$instance = $Handler;

            $Evidence['backend'] = [
               'default_shared' => $Driver instanceof Shared,
               'segment' => $segment,
            ];

            // # Positive control: an unrelated ID completes a genuine Session
            // write/read round trip through this exact encrypted Shared cache.
            $controlID = bin2hex(random_bytes(16));
            $ControlSeed = new Session($controlID);
            $Sessions[] = $ControlSeed;
            $ControlSeed->set('identity', 'control');
            $ControlSeed->save();

            $ControlRead = new Session($controlID);
            $Sessions[] = $ControlRead;
            $Evidence['control'] = [
               'loaded' => $ControlRead->loaded,
               'identity' => $ControlRead->get('identity'),
               'wire_present' => $Handler->read($controlID) !== false,
            ];

            // # Seed authenticated state, then let two independent in-flight
            // requests retain the same pre-revocation view.
            $oldID = bin2hex(random_bytes(16));
            $Seed = new Session($oldID);
            $Sessions[] = $Seed;
            $Seed->set('identity', 'victim');
            $Seed->set('role', 'admin');
            $Seed->save();

            $Stale = new Session($oldID);
            $Sessions[] = $Stale;
            $Revoker = new Session($oldID);
            $Sessions[] = $Revoker;

            // # Production regeneration must delete the old cache key and
            // create a readable replacement carrying the authenticated state.
            $Revoker->regenerate();
            $newID = $Revoker->id;
            $Revoker->save();
            $oldAbsentAfterRegenerate = $Handler->read($oldID) === false;

            $FreshBefore = new Session($newID);
            $Sessions[] = $FreshBefore;
            $Evidence['preconditions'] = [
               'stale_loaded' => $Stale->loaded,
               'stale_identity' => $Stale->get('identity'),
               'revoker_loaded' => $Revoker->loaded,
               'rotated' => $newID !== $oldID,
               'old_absent_after_regenerate' => $oldAbsentAfterRegenerate,
               'fresh_loaded' => $FreshBefore->loaded,
               'fresh_identity' => $FreshBefore->get('identity'),
               'fresh_role' => $FreshBefore->get('role'),
            ];

            // # Adversarial interleaving: mutate only after regeneration won,
            // then reach the normal Session::save() Cache persistence sink.
            $staleSaveError = '';
            try {
               $Stale->set('late', 'completed');
               $Stale->save();
            }
            catch (Throwable $Throwable) {
               $staleSaveError = $Throwable::class . ': ' . $Throwable->getMessage();
            }

            $oldPresentAfterStaleSave = $Handler->read($oldID) !== false;
            $Replayed = new Session($oldID);
            $Sessions[] = $Replayed;
            $FreshAfter = new Session($newID);
            $Sessions[] = $FreshAfter;
            $Evidence['attack'] = [
               'stale_save_error' => $staleSaveError,
               'old_present_after_stale_save' => $oldPresentAfterStaleSave,
               'replayed_loaded' => $Replayed->loaded,
               'replayed_identity' => $Replayed->get('identity'),
               'replayed_role' => $Replayed->get('role'),
               'replayed_late' => $Replayed->get('late'),
               'fresh_loaded_after' => $FreshAfter->loaded,
               'fresh_identity_after' => $FreshAfter->get('identity'),
               'fresh_role_after' => $FreshAfter->get('role'),
               'fresh_late_after' => $FreshAfter->get('late'),
            ];

            // # Second authenticated ID: once the winning request has rotated
            // the shared record, a stale request must not use regenerate() to
            // mint a different live ID carrying its pre-revocation privileges.
            $rotateOldID = bin2hex(random_bytes(16));
            $RotateSeed = new Session($rotateOldID);
            $Sessions[] = $RotateSeed;
            $RotateSeed->set('identity', 'victim');
            $RotateSeed->set('role', 'admin');
            $RotateSeed->set('flow', 'stale-regenerate');
            $RotateSeed->save();

            $RotateStale = new Session($rotateOldID);
            $Sessions[] = $RotateStale;
            $RotateWinner = new Session($rotateOldID);
            $Sessions[] = $RotateWinner;
            $rotateStaleLoaded = $RotateStale->loaded;
            $rotateStaleIdentity = $RotateStale->get('identity');
            $rotateStaleRole = $RotateStale->get('role');

            $RotateWinner->regenerate();
            $winnerID = $RotateWinner->id;
            $RotateWinner->save();
            $rotateOldAbsentAfterWinner = $Handler->read($rotateOldID) === false;

            $WinnerBefore = new Session($winnerID);
            $Sessions[] = $WinnerBefore;

            $staleRegenerateError = '';
            try {
               $RotateStale->regenerate();
               $staleRegeneratedID = $RotateStale->id;
               // @ Mirror the normal response-tail persistence that follows
               // application-level regeneration.
               $RotateStale->save();
            }
            catch (Throwable $Throwable) {
               $staleRegenerateError = $Throwable::class . ': ' . $Throwable->getMessage();
               $staleRegeneratedID = $RotateStale->id;
            }

            $staleRegeneratedPresent = $Handler->read($staleRegeneratedID) !== false;
            $StaleRegenerated = new Session($staleRegeneratedID);
            $Sessions[] = $StaleRegenerated;
            $WinnerAfter = new Session($winnerID);
            $Sessions[] = $WinnerAfter;
            $Evidence['regenerate_attack'] = [
               'stale_loaded_before' => $rotateStaleLoaded,
               'stale_identity_before' => $rotateStaleIdentity,
               'stale_role_before' => $rotateStaleRole,
               'winner_loaded_before' => $WinnerBefore->loaded,
               'winner_identity_before' => $WinnerBefore->get('identity'),
               'winner_role_before' => $WinnerBefore->get('role'),
               'winner_rotated' => $winnerID !== $rotateOldID,
               'old_absent_after_winner' => $rotateOldAbsentAfterWinner,
               'stale_regenerate_error' => $staleRegenerateError,
               'stale_id_changed' => $staleRegeneratedID !== $rotateOldID,
               'stale_id_distinct_from_winner' => $staleRegeneratedID !== $winnerID,
               'stale_identity_after' => $RotateStale->get('identity'),
               'stale_role_after' => $RotateStale->get('role'),
               'stale_new_present_after_save' => $staleRegeneratedPresent,
               'stale_replayed_loaded' => $StaleRegenerated->loaded,
               'stale_replayed_identity' => $StaleRegenerated->get('identity'),
               'stale_replayed_role' => $StaleRegenerated->get('role'),
               'stale_replayed_flow' => $StaleRegenerated->get('flow'),
               'winner_loaded_after' => $WinnerAfter->loaded,
               'winner_identity_after' => $WinnerAfter->get('identity'),
               'winner_role_after' => $WinnerAfter->get('role'),
               'winner_flow_after' => $WinnerAfter->get('flow'),
            ];

            // # Partial logout on a nonempty Session: cart data deliberately
            // keeps the same ID live after identity/admin removal. A stale
            // snapshot must not replace that downgraded record with old auth.
            $downgradeID = bin2hex(random_bytes(16));
            $DowngradeSeed = new Session($downgradeID);
            $Sessions[] = $DowngradeSeed;
            $DowngradeSeed->set('identity', 'victim');
            $DowngradeSeed->set('role', 'admin');
            $DowngradeSeed->set('cart', 'sku-42:2');
            $DowngradeSeed->save();

            $DowngradeStale = new Session($downgradeID);
            $Sessions[] = $DowngradeStale;
            $Downgrader = new Session($downgradeID);
            $Sessions[] = $Downgrader;
            $downgradeStaleLoaded = $DowngradeStale->loaded;
            $downgradeStaleIdentity = $DowngradeStale->get('identity');
            $downgradeStaleRole = $DowngradeStale->get('role');

            $Downgrader->delete('identity');
            $Downgrader->delete('role');
            $Downgrader->set('auth_state', 'anonymous');
            $Downgrader->save();

            $DowngradedBefore = new Session($downgradeID);
            $Sessions[] = $DowngradedBefore;

            $downgradeStaleSaveError = '';
            try {
               $DowngradeStale->set('late', 'stale-after-partial-logout');
               $DowngradeStale->save();
            }
            catch (Throwable $Throwable) {
               $downgradeStaleSaveError = $Throwable::class . ': '
                  . $Throwable->getMessage();
            }

            $DowngradedAfter = new Session($downgradeID);
            $Sessions[] = $DowngradedAfter;
            $Evidence['downgrade_attack'] = [
               'stale_loaded_before' => $downgradeStaleLoaded,
               'stale_identity_before' => $downgradeStaleIdentity,
               'stale_role_before' => $downgradeStaleRole,
               'downgraded_loaded_before' => $DowngradedBefore->loaded,
               'downgraded_identity_before' => $DowngradedBefore->get('identity'),
               'downgraded_role_before' => $DowngradedBefore->get('role'),
               'downgraded_cart_before' => $DowngradedBefore->get('cart'),
               'downgraded_state_before' => $DowngradedBefore->get('auth_state'),
               'stale_save_error' => $downgradeStaleSaveError,
               'same_id_live_after' => $Handler->read($downgradeID) !== false,
               'final_loaded' => $DowngradedAfter->loaded,
               'final_identity' => $DowngradedAfter->get('identity'),
               'final_role' => $DowngradedAfter->get('role'),
               'final_cart' => $DowngradedAfter->get('cart'),
               'final_state' => $DowngradedAfter->get('auth_state'),
               'final_late' => $DowngradedAfter->get('late'),
            ];

            // # In-place downgrade followed by stale regenerate: compare-and-
            // revoke must reject the old authenticated revision. Otherwise the
            // stale request can delete the newer anonymous record and migrate
            // its cached admin snapshot into a fresh live ID.
            $downgradeRegenerateID = bin2hex(random_bytes(16));
            $DowngradeRegenerateSeed = new Session($downgradeRegenerateID);
            $Sessions[] = $DowngradeRegenerateSeed;
            $DowngradeRegenerateSeed->set('identity', 'victim');
            $DowngradeRegenerateSeed->set('role', 'admin');
            $DowngradeRegenerateSeed->set('cart', 'sku-77:3');
            $DowngradeRegenerateSeed->save();

            $DowngradeRegenerateStale = new Session($downgradeRegenerateID);
            $Sessions[] = $DowngradeRegenerateStale;
            $DowngradeRegenerator = new Session($downgradeRegenerateID);
            $Sessions[] = $DowngradeRegenerator;
            $downgradeRegenerateStaleLoaded = $DowngradeRegenerateStale->loaded;
            $downgradeRegenerateStaleIdentity = $DowngradeRegenerateStale->get('identity');
            $downgradeRegenerateStaleRole = $DowngradeRegenerateStale->get('role');

            $DowngradeRegenerator->delete('identity');
            $DowngradeRegenerator->delete('role');
            $DowngradeRegenerator->set('auth_state', 'anonymous');
            $DowngradeRegenerator->save();

            $DowngradeRegeneratedBefore = new Session($downgradeRegenerateID);
            $Sessions[] = $DowngradeRegeneratedBefore;

            $downgradeRegenerateError = '';
            try {
               $DowngradeRegenerateStale->regenerate();
               $downgradeRegeneratedStaleID = $DowngradeRegenerateStale->id;
               $DowngradeRegenerateStale->save();
            }
            catch (Throwable $Throwable) {
               $downgradeRegenerateError = $Throwable::class . ': '
                  . $Throwable->getMessage();
               $downgradeRegeneratedStaleID = $DowngradeRegenerateStale->id;
            }

            $downgradeRegeneratedOriginalPresent =
               $Handler->read($downgradeRegenerateID) !== false;
            $downgradeRegeneratedStalePresent =
               $Handler->read($downgradeRegeneratedStaleID) !== false;
            $DowngradeRegeneratedOriginal = new Session($downgradeRegenerateID);
            $Sessions[] = $DowngradeRegeneratedOriginal;
            $DowngradeRegeneratedStale = new Session($downgradeRegeneratedStaleID);
            $Sessions[] = $DowngradeRegeneratedStale;
            $Evidence['downgrade_regenerate_attack'] = [
               'stale_loaded_before' => $downgradeRegenerateStaleLoaded,
               'stale_identity_before' => $downgradeRegenerateStaleIdentity,
               'stale_role_before' => $downgradeRegenerateStaleRole,
               'downgraded_loaded_before' => $DowngradeRegeneratedBefore->loaded,
               'downgraded_identity_before' => $DowngradeRegeneratedBefore->get('identity'),
               'downgraded_role_before' => $DowngradeRegeneratedBefore->get('role'),
               'downgraded_cart_before' => $DowngradeRegeneratedBefore->get('cart'),
               'downgraded_state_before' => $DowngradeRegeneratedBefore->get('auth_state'),
               'stale_regenerate_error' => $downgradeRegenerateError,
               'stale_id_changed' => $downgradeRegeneratedStaleID
                  !== $downgradeRegenerateID,
               'stale_identity_after' => $DowngradeRegenerateStale->get('identity'),
               'stale_role_after' => $DowngradeRegenerateStale->get('role'),
               'original_id_live_after' => $downgradeRegeneratedOriginalPresent,
               'original_loaded_after' => $DowngradeRegeneratedOriginal->loaded,
               'original_identity_after' => $DowngradeRegeneratedOriginal->get('identity'),
               'original_role_after' => $DowngradeRegeneratedOriginal->get('role'),
               'original_cart_after' => $DowngradeRegeneratedOriginal->get('cart'),
               'original_state_after' => $DowngradeRegeneratedOriginal->get('auth_state'),
               'stale_new_present_after' => $downgradeRegeneratedStalePresent,
               'stale_new_loaded_after' => $DowngradeRegeneratedStale->loaded,
               'stale_new_identity_after' => $DowngradeRegeneratedStale->get('identity'),
               'stale_new_role_after' => $DowngradeRegeneratedStale->get('role'),
               'stale_new_cart_after' => $DowngradeRegeneratedStale->get('cart'),
            ];

            // # Stale touch: deterministically insert a same-ID auth update
            // after Cache::touch() fetches the old sealed value but before its
            // persistence step. The facade shares the genuine Shared driver.
            $touchID = bin2hex(random_bytes(16));
            $TouchSeed = new Session($touchID);
            $Sessions[] = $TouchSeed;
            $TouchSeed->set('identity', 'victim');
            $TouchSeed->set('role', 'admin');
            $TouchSeed->set('auth_version', 1);
            $TouchSeed->set('cart', 'sku-99:1');
            $TouchSeed->save();

            $TouchStale = new Session($touchID);
            $Sessions[] = $TouchStale;
            $TouchUpdater = new Session($touchID);
            $Sessions[] = $TouchUpdater;
            $touchStaleLoaded = $TouchStale->loaded;
            $touchStaleRole = $TouchStale->get('role');
            $touchStaleVersion = $TouchStale->get('auth_version');

            $TouchUpdater->set('role', 'member');
            $TouchUpdater->set('auth_version', 2);

            $TouchCache = new class($CacheResource) extends CacheResource {
               public bool $armed = false;
               public bool $triggered = false;
               public null|Closure $Hook = null;


               public function __construct (CacheResource $Original)
               {
                  $this->Config = $Original->Config;
                  $this->Drivers = $Original->Drivers;
                  $this->Driver = $Original->Driver;
                  $this->prefix = $Original->Config->prefix;
               }

               public function fetch (string $key): mixed
               {
                  $value = parent::fetch($key);
                  if ($this->armed && $this->Hook !== null) {
                     $this->armed = false;
                     $this->triggered = true;
                     ($this->Hook)();
                  }

                  return $value;
               }

               public function renew (string $key, int $TTL = 0): bool
               {
                  if ($this->armed && $this->Hook !== null) {
                     $this->armed = false;
                     $this->triggered = true;
                     ($this->Hook)();
                  }

                  return parent::renew($key, $TTL);
               }
            };
            $CacheProperty->setValue($Handler, $TouchCache);
            $TouchCache->Hook = static function () use ($TouchUpdater): void {
               $TouchUpdater->save();
            };
            $TouchCache->armed = true;

            $staleTouchError = '';
            try {
               $staleTouchResult = $Handler->touch($touchID);
            }
            catch (Throwable $Throwable) {
               $staleTouchResult = false;
               $staleTouchError = $Throwable::class . ': ' . $Throwable->getMessage();
            }
            $TouchCache->Hook = null;

            $TouchFinal = new Session($touchID);
            $Sessions[] = $TouchFinal;
            $Evidence['touch_attack'] = [
               'stale_loaded_before' => $touchStaleLoaded,
               'stale_role_before' => $touchStaleRole,
               'stale_version_before' => $touchStaleVersion,
               'updater_loaded' => $TouchUpdater->loaded,
               'hook_triggered' => $TouchCache->triggered,
               'touch_error' => $staleTouchError,
               'touch_result' => $staleTouchResult,
               'same_id_live_after' => $Handler->read($touchID) !== false,
               'final_loaded' => $TouchFinal->loaded,
               'final_identity' => $TouchFinal->get('identity'),
               'final_role' => $TouchFinal->get('role'),
               'final_version' => $TouchFinal->get('auth_version'),
               'final_cart' => $TouchFinal->get('cart'),
            ];
         }
         catch (Throwable $Throwable) {
            $Evidence['fixture_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            // ! Never let a failed future save retry from a destructor after
            // the isolated backend has been restored or removed.
            if ($TouchCache instanceof CacheResource) {
               $TouchCache->Hook = null;
               $TouchCache->armed = false;
            }
            foreach ($Sessions as $SessionObject) {
               try {
                  $NeedSaveProperty->setValue($SessionObject, false);
               }
               catch (Throwable) {
                  // The primary evidence already records any fixture failure.
               }
            }
            unset(
               $SessionObject,
               $ControlSeed,
               $ControlRead,
               $Seed,
               $Stale,
               $Revoker,
               $FreshBefore,
               $Replayed,
               $FreshAfter,
               $RotateSeed,
               $RotateStale,
               $RotateWinner,
               $WinnerBefore,
               $StaleRegenerated,
               $WinnerAfter,
               $DowngradeSeed,
               $DowngradeStale,
               $Downgrader,
               $DowngradedBefore,
               $DowngradedAfter,
               $DowngradeRegenerateSeed,
               $DowngradeRegenerateStale,
               $DowngradeRegenerator,
               $DowngradeRegeneratedBefore,
               $DowngradeRegeneratedOriginal,
               $DowngradeRegeneratedStale,
               $TouchSeed,
               $TouchStale,
               $TouchUpdater,
               $TouchFinal,
            );
            $Sessions = [];

            Handler::$instance = $PreviousHandler;
            if ($Driver instanceof Shared) {
               $Driver->destroy();
            }
            $Handler = null;
            $CacheResource = null;
            $TouchCache = null;
            $Driver = null;

            $Evidence['cleanup'] = [
               'shared_memory_removed' => $Find('/proc/sysvipc/shm', $segment) === false,
               'semaphore_removed' => $Find('/proc/sysvipc/sem', $segment) === false,
            ];
         }

         return $Response(
            code: 200,
            body: 'H1-CACHE-EVIDENCE:' . json_encode($Evidence, JSON_UNESCAPED_SLASHES),
         );
      }, GET);
   },

   test: static function (string $response): bool|string {
      $marker = 'H1-CACHE-EVIDENCE:';
      $offset = strpos($response, $marker);
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || $offset === false
      ) {
         Vars::$labels = ['H1 Cache native-harness response'];
         dump(json_encode($response));

         return 'H1 Cache fixture failed: the native HTTP control route did not complete.';
      }

      $Evidence = json_decode(
         trim(substr($response, $offset + strlen($marker))),
         true,
      );
      if (is_array($Evidence) === false) {
         Vars::$labels = ['H1 Cache malformed evidence', 'H1 Cache native response'];
         dump(json_encode($response));

         return 'H1 Cache fixture failed: the route returned malformed evidence.';
      }
      if (($Evidence['fixture_error'] ?? '') !== '') {
         Vars::$labels = ['H1 Cache fixture error', 'H1 Cache evidence'];
         dump($Evidence['fixture_error'], json_encode($Evidence));

         return 'H1 Cache fixture failed before the stale-writer boundary: '
            . $Evidence['fixture_error'];
      }

      $backend = $Evidence['backend'] ?? [];
      $cleanup = $Evidence['cleanup'] ?? [];
      if (($backend['default_shared'] ?? false) !== true) {
         Vars::$labels = ['H1 Cache backend evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the default session Cache did not select Shared.';
      }
      if (
         ($cleanup['shared_memory_removed'] ?? false) !== true
         || ($cleanup['semaphore_removed'] ?? false) !== true
      ) {
         Vars::$labels = ['H1 Cache cleanup evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the isolated Shared IPC resources remained allocated.';
      }

      $control = $Evidence['control'] ?? [];
      if (
         ($control['loaded'] ?? false) !== true
         || ($control['identity'] ?? null) !== 'control'
         || ($control['wire_present'] ?? false) !== true
      ) {
         Vars::$labels = ['H1 Cache unrelated-session control', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache control failed: the encrypted Shared Session did not complete an unrelated round trip.';
      }

      $preconditions = $Evidence['preconditions'] ?? [];
      if (
         ($preconditions['stale_loaded'] ?? false) !== true
         || ($preconditions['stale_identity'] ?? null) !== 'victim'
         || ($preconditions['revoker_loaded'] ?? false) !== true
         || ($preconditions['rotated'] ?? false) !== true
         || ($preconditions['old_absent_after_regenerate'] ?? false) !== true
         || ($preconditions['fresh_loaded'] ?? false) !== true
         || ($preconditions['fresh_identity'] ?? null) !== 'victim'
         || ($preconditions['fresh_role'] ?? null) !== 'admin'
      ) {
         Vars::$labels = ['H1 Cache race preconditions', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: it did not prove a loaded stale request, old-ID revocation, and valid rotated control.';
      }

      $attack = $Evidence['attack'] ?? [];
      if (($attack['stale_save_error'] ?? '') !== '') {
         Vars::$labels = ['H1 Cache stale-writer sink error', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the stale writer did not complete the save '
            . 'boundary cleanly: ' . $attack['stale_save_error'];
      }

      $resurrected = ($attack['old_present_after_stale_save'] ?? false) === true
         && ($attack['replayed_loaded'] ?? false) === true
         && ($attack['replayed_identity'] ?? null) === 'victim'
         && ($attack['replayed_role'] ?? null) === 'admin'
         && ($attack['replayed_late'] ?? null) === 'completed';

      if ($resurrected) {
         Vars::$labels = ['H1 Cache stale-writer resurrection', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'CONFIRMED H1: a stale Session loaded before regenerate() recreated '
            . 'the invalidated Shared Cache-backed ID and restored identity=victim, role=admin.';
      }

      if (
         ($attack['old_present_after_stale_save'] ?? false) !== false
         || ($attack['replayed_loaded'] ?? false) !== false
         || ($attack['fresh_loaded_after'] ?? false) !== true
         || ($attack['fresh_identity_after'] ?? null) !== 'victim'
         || ($attack['fresh_role_after'] ?? null) !== 'admin'
         || ($attack['fresh_late_after'] ?? null) !== null
      ) {
         Vars::$labels = ['H1 Cache unexpected postcondition', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache probe reached an unexpected partial state; revocation safety was not established.';
      }

      $regenerate = $Evidence['regenerate_attack'] ?? [];
      if (
         ($regenerate['stale_loaded_before'] ?? false) !== true
         || ($regenerate['stale_identity_before'] ?? null) !== 'victim'
         || ($regenerate['stale_role_before'] ?? null) !== 'admin'
         || ($regenerate['winner_loaded_before'] ?? false) !== true
         || ($regenerate['winner_identity_before'] ?? null) !== 'victim'
         || ($regenerate['winner_role_before'] ?? null) !== 'admin'
         || ($regenerate['winner_rotated'] ?? false) !== true
         || ($regenerate['old_absent_after_winner'] ?? false) !== true
         || ($regenerate['stale_id_distinct_from_winner'] ?? false) !== true
      ) {
         Vars::$labels = ['H1 Cache stale-regenerate preconditions', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the stale-regenerate race did not establish two loaded snapshots and a valid winning rotation.';
      }
      if (($regenerate['stale_regenerate_error'] ?? '') !== '') {
         Vars::$labels = ['H1 Cache stale-regenerate sink error', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: stale regenerate/save did not complete cleanly: '
            . $regenerate['stale_regenerate_error'];
      }

      $migrated = ($regenerate['stale_new_present_after_save'] ?? false) === true
         && ($regenerate['stale_replayed_loaded'] ?? false) === true
         && ($regenerate['stale_replayed_identity'] ?? null) === 'victim'
         && ($regenerate['stale_replayed_role'] ?? null) === 'admin'
         && ($regenerate['stale_replayed_flow'] ?? null) === 'stale-regenerate';

      if ($migrated) {
         Vars::$labels = ['H1 Cache stale-regenerate migration', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'CONFIRMED H1: a stale Shared Cache Session regenerated after '
            . 'revocation and migrated identity=victim, role=admin into a new live ID.';
      }

      if (
         ($regenerate['stale_identity_after'] ?? null) !== null
         || ($regenerate['stale_role_after'] ?? null) !== null
         || ($regenerate['stale_new_present_after_save'] ?? false) !== false
         || ($regenerate['stale_replayed_loaded'] ?? false) !== false
         || ($regenerate['winner_loaded_after'] ?? false) !== true
         || ($regenerate['winner_identity_after'] ?? null) !== 'victim'
         || ($regenerate['winner_role_after'] ?? null) !== 'admin'
         || ($regenerate['winner_flow_after'] ?? null) !== 'stale-regenerate'
      ) {
         Vars::$labels = ['H1 Cache stale-regenerate postcondition', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache probe reached an unexpected stale-regenerate state; fail-closed migration was not established.';
      }

      $downgrade = $Evidence['downgrade_attack'] ?? [];
      if (
         ($downgrade['stale_loaded_before'] ?? false) !== true
         || ($downgrade['stale_identity_before'] ?? null) !== 'victim'
         || ($downgrade['stale_role_before'] ?? null) !== 'admin'
         || ($downgrade['downgraded_loaded_before'] ?? false) !== true
         || ($downgrade['downgraded_identity_before'] ?? null) !== null
         || ($downgrade['downgraded_role_before'] ?? null) !== null
         || ($downgrade['downgraded_cart_before'] ?? null) !== 'sku-42:2'
         || ($downgrade['downgraded_state_before'] ?? null) !== 'anonymous'
      ) {
         Vars::$labels = ['H1 Cache partial-logout preconditions', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: partial logout did not preserve a live cart-only Session before the stale save.';
      }
      if (($downgrade['stale_save_error'] ?? '') !== '') {
         Vars::$labels = ['H1 Cache partial-logout sink error', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the partial-logout stale save did not complete cleanly: '
            . $downgrade['stale_save_error'];
      }

      $restored = ($downgrade['same_id_live_after'] ?? false) === true
         && ($downgrade['final_loaded'] ?? false) === true
         && ($downgrade['final_identity'] ?? null) === 'victim'
         && ($downgrade['final_role'] ?? null) === 'admin';

      if ($restored) {
         Vars::$labels = ['H1 Cache partial-logout restoration', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'CONFIRMED H1: a stale Shared Cache Session restored identity=victim, '
            . 'role=admin after partial logout while cart data kept the same ID live.';
      }

      if (
         ($downgrade['same_id_live_after'] ?? false) !== true
         || ($downgrade['final_loaded'] ?? false) !== true
         || ($downgrade['final_identity'] ?? null) !== null
         || ($downgrade['final_role'] ?? null) !== null
         || ($downgrade['final_cart'] ?? null) !== 'sku-42:2'
         || ($downgrade['final_state'] ?? null) !== 'anonymous'
         || ($downgrade['final_late'] ?? null) !== null
      ) {
         Vars::$labels = ['H1 Cache partial-logout postcondition', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache probe reached an unexpected partial-logout state; in-place auth downgrade was not preserved.';
      }

      $downgradeRegenerate = $Evidence['downgrade_regenerate_attack'] ?? [];
      if (
         ($downgradeRegenerate['stale_loaded_before'] ?? false) !== true
         || ($downgradeRegenerate['stale_identity_before'] ?? null) !== 'victim'
         || ($downgradeRegenerate['stale_role_before'] ?? null) !== 'admin'
         || ($downgradeRegenerate['downgraded_loaded_before'] ?? false) !== true
         || ($downgradeRegenerate['downgraded_identity_before'] ?? null) !== null
         || ($downgradeRegenerate['downgraded_role_before'] ?? null) !== null
         || ($downgradeRegenerate['downgraded_cart_before'] ?? null) !== 'sku-77:3'
         || ($downgradeRegenerate['downgraded_state_before'] ?? null) !== 'anonymous'
      ) {
         Vars::$labels = [
            'H1 Cache downgrade/regenerate preconditions',
            'H1 Cache evidence',
         ];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the stale-regenerate probe did not establish a newer live anonymous snapshot.';
      }
      if (($downgradeRegenerate['stale_regenerate_error'] ?? '') !== '') {
         Vars::$labels = [
            'H1 Cache downgrade/regenerate sink error',
            'H1 Cache evidence',
         ];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: stale regenerate/save after the downgrade did not complete cleanly: '
            . $downgradeRegenerate['stale_regenerate_error'];
      }

      $downgradeMigrated =
         ($downgradeRegenerate['original_id_live_after'] ?? false) === false
         && ($downgradeRegenerate['stale_new_present_after'] ?? false) === true
         && ($downgradeRegenerate['stale_new_loaded_after'] ?? false) === true
         && ($downgradeRegenerate['stale_new_identity_after'] ?? null) === 'victim'
         && ($downgradeRegenerate['stale_new_role_after'] ?? null) === 'admin';

      if ($downgradeMigrated) {
         Vars::$labels = [
            'H1 Cache downgrade/regenerate migration',
            'H1 Cache evidence',
         ];
         dump(json_encode($Evidence));

         return 'CONFIRMED H1: a stale Shared Cache Session regenerated after '
            . 'an in-place auth downgrade, revoked the newer anonymous record, '
            . 'and migrated identity=victim, role=admin into a new live ID.';
      }

      if (
         ($downgradeRegenerate['stale_id_changed'] ?? false) !== true
         || ($downgradeRegenerate['stale_identity_after'] ?? null) !== null
         || ($downgradeRegenerate['stale_role_after'] ?? null) !== null
         || ($downgradeRegenerate['original_id_live_after'] ?? false) !== true
         || ($downgradeRegenerate['original_loaded_after'] ?? false) !== true
         || ($downgradeRegenerate['original_identity_after'] ?? null) !== null
         || ($downgradeRegenerate['original_role_after'] ?? null) !== null
         || ($downgradeRegenerate['original_cart_after'] ?? null) !== 'sku-77:3'
         || ($downgradeRegenerate['original_state_after'] ?? null) !== 'anonymous'
         || ($downgradeRegenerate['stale_new_present_after'] ?? false) !== false
         || ($downgradeRegenerate['stale_new_loaded_after'] ?? false) !== false
         || ($downgradeRegenerate['stale_new_identity_after'] ?? null) !== null
         || ($downgradeRegenerate['stale_new_role_after'] ?? null) !== null
         || ($downgradeRegenerate['stale_new_cart_after'] ?? null) !== null
      ) {
         Vars::$labels = [
            'H1 Cache downgrade/regenerate postcondition',
            'H1 Cache evidence',
         ];
         dump(json_encode($Evidence));

         return 'H1 Cache probe reached an unexpected downgrade/regenerate state; exact-revision revocation was not established.';
      }

      $touch = $Evidence['touch_attack'] ?? [];
      if (
         ($touch['stale_loaded_before'] ?? false) !== true
         || ($touch['stale_role_before'] ?? null) !== 'admin'
         || ($touch['stale_version_before'] ?? null) !== 1
         || ($touch['updater_loaded'] ?? false) !== true
         || ($touch['hook_triggered'] ?? false) !== true
      ) {
         Vars::$labels = ['H1 Cache stale-touch preconditions', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: touch did not fetch the old snapshot before the same-ID auth update ran.';
      }
      if (($touch['touch_error'] ?? '') !== '') {
         Vars::$labels = ['H1 Cache stale-touch sink error', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache fixture failed: the stale touch did not complete cleanly: '
            . $touch['touch_error'];
      }

      $touchRestored = ($touch['touch_result'] ?? false) === true
         && ($touch['same_id_live_after'] ?? false) === true
         && ($touch['final_loaded'] ?? false) === true
         && ($touch['final_identity'] ?? null) === 'victim'
         && ($touch['final_role'] ?? null) === 'admin'
         && ($touch['final_version'] ?? null) === 1;

      if ($touchRestored) {
         Vars::$labels = ['H1 Cache stale-touch restoration', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'CONFIRMED H1: Cache::touch fetched an authenticated Shared Session, '
            . 'a same-ID downgrade committed, and the stale touch restored role=admin.';
      }

      if (
         ($touch['touch_result'] ?? false) !== true
         || ($touch['same_id_live_after'] ?? false) !== true
         || ($touch['final_loaded'] ?? false) !== true
         || ($touch['final_identity'] ?? null) !== 'victim'
         || ($touch['final_role'] ?? null) !== 'member'
         || ($touch['final_version'] ?? null) !== 2
         || ($touch['final_cart'] ?? null) !== 'sku-99:1'
      ) {
         Vars::$labels = ['H1 Cache stale-touch postcondition', 'H1 Cache evidence'];
         dump(json_encode($Evidence));

         return 'H1 Cache probe reached an unexpected stale-touch state; the newer auth record was not preserved.';
      }

      return true;
   },
);
