<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC H1 (2026-08-01) — a request that loaded an authenticated
 * session before regeneration must never recreate the invalidated ID.
 *
 * The live HTTP route installs an isolated real File handler, then schedules
 * the race deterministically with independent Session objects. The old record
 * must be absent immediately after regenerate(), and it must remain absent
 * after the stale object's normal save() sink. A regenerated-session control
 * and an unrelated-ID round trip prove that the handler remains functional.
 */
return new Test(
   description: 'Stale session writers must not resurrect an invalidated authenticated ID',
   Separator: new Separator(line: true),

   request: static function (): string {
      return "GET /h1/session-stale-writer HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h1/session-stale-writer', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Evidence = [
            'fixture_error' => '',
            'control' => [],
            'preconditions' => [],
            'attack' => [],
         ];
         $root = sys_get_temp_dir() . '/bootgly-h1-' . bin2hex(random_bytes(12));
         /** @var array<int,Session> $Sessions */
         $Sessions = [];
         $Handler = null;

         $Cleanup = null;
         $Cleanup = static function (string $path) use (&$Cleanup): void {
            if (is_link($path) || is_file($path)) {
               @unlink($path);
               return;
            }
            if (is_dir($path) === false) {
               return;
            }

            @chmod($path, 0700);
            foreach (@scandir($path) ?: [] as $entry) {
               if ($entry === '.' || $entry === '..') {
                  continue;
               }
               $Cleanup($path . DIRECTORY_SEPARATOR . $entry);
            }
            @rmdir($path);
         };

         $FileReflection = new ReflectionClass(File::class);
         $PathProperty = $FileReflection->getProperty('path');
         $SecretProperty = $FileReflection->getProperty('secret');
         $SecretPathProperty = $FileReflection->getProperty('secretPath');
         $previousPath = (string) $PathProperty->getValue();
         $previousSecret = (string) $SecretProperty->getValue();
         $previousSecretPath = (string) $SecretPathProperty->getValue();
         $PreviousHandler = Handler::$instance;

         $SessionReflection = new ReflectionClass(Session::class);
         $NeedSaveProperty = $SessionReflection->getProperty('needSave');

         try {
            $PathProperty->setValue(null, '');
            $SecretProperty->setValue(null, '');
            $SecretPathProperty->setValue(null, '');

            $sessionPath = $root . '/sessions';
            $Handler = new File(['save_path' => $sessionPath]);
            Handler::$instance = $Handler;

            // # Positive control: an unrelated ID completes a genuine Session
            // write/read round trip through this exact File handler.
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

            // # Seed one authenticated server-issued record, then give two
            // independent in-flight requests the same pre-revocation view.
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

            // # Invalidate the old ID through the production regeneration path
            // and prove both deletion and preservation under the fresh ID.
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

            // # Adversarial interleaving: the stale request mutates only after
            // regeneration destroyed its ID, then reaches the normal save sink.
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
         }
         catch (Throwable $Throwable) {
            $Evidence['fixture_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            // ! A future secure handler may reject stale save() with an
            // exception before needSave is cleared. Prevent a destructor retry
            // while keeping every destructor on this isolated handler/root.
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
            );
            $Sessions = [];

            Handler::$instance = $PreviousHandler;
            $PathProperty->setValue(null, $previousPath);
            $SecretProperty->setValue(null, $previousSecret);
            $SecretPathProperty->setValue(null, $previousSecretPath);
            $Handler = null;
            $Cleanup($root);
         }

         return $Response(
            code: 200,
            body: 'H1-EVIDENCE:' . json_encode($Evidence, JSON_UNESCAPED_SLASHES),
         );
      }, GET);
   },

   test: static function (string $response): bool|string {
      $marker = 'H1-EVIDENCE:';
      $offset = strpos($response, $marker);
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || $offset === false
      ) {
         Vars::$labels = ['H1 native-harness response'];
         dump(json_encode($response));

         return 'H1 fixture failed: the native HTTP control route did not complete.';
      }

      $Evidence = json_decode(
         trim(substr($response, $offset + strlen($marker))),
         true,
      );
      if (is_array($Evidence) === false) {
         Vars::$labels = ['H1 malformed evidence', 'H1 native response'];
         dump(json_encode($response));

         return 'H1 fixture failed: the route returned malformed evidence.';
      }
      if (($Evidence['fixture_error'] ?? '') !== '') {
         Vars::$labels = ['H1 fixture error', 'H1 evidence'];
         dump($Evidence['fixture_error'], json_encode($Evidence));

         return 'H1 fixture failed before the stale-writer boundary: '
            . $Evidence['fixture_error'];
      }

      $control = $Evidence['control'] ?? [];
      if (
         ($control['loaded'] ?? false) !== true
         || ($control['identity'] ?? null) !== 'control'
         || ($control['wire_present'] ?? false) !== true
      ) {
         Vars::$labels = ['H1 unrelated-session control', 'H1 evidence'];
         dump(json_encode($Evidence));

         return 'H1 control failed: the real File-backed Session did not complete an unrelated round trip.';
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
         Vars::$labels = ['H1 race preconditions', 'H1 evidence'];
         dump(json_encode($Evidence));

         return 'H1 fixture failed: it did not prove a loaded stale request, old-ID revocation, and valid rotated control.';
      }

      $attack = $Evidence['attack'] ?? [];
      if (($attack['stale_save_error'] ?? '') !== '') {
         Vars::$labels = ['H1 stale-writer sink error', 'H1 evidence'];
         dump(json_encode($Evidence));

         return 'H1 fixture failed: the stale writer did not complete the save '
            . 'boundary cleanly: ' . $attack['stale_save_error'];
      }

      $resurrected = ($attack['stale_save_error'] ?? '') === ''
         && ($attack['old_present_after_stale_save'] ?? false) === true
         && ($attack['replayed_loaded'] ?? false) === true
         && ($attack['replayed_identity'] ?? null) === 'victim'
         && ($attack['replayed_role'] ?? null) === 'admin'
         && ($attack['replayed_late'] ?? null) === 'completed';

      if ($resurrected) {
         Vars::$labels = ['H1 stale-writer resurrection', 'H1 evidence'];
         dump(json_encode($Evidence));

         return 'CONFIRMED H1: a stale Session loaded before regenerate() recreated '
            . 'the invalidated File-backed ID and restored identity=victim, role=admin.';
      }

      if (
         ($attack['old_present_after_stale_save'] ?? false) !== false
         || ($attack['replayed_loaded'] ?? false) !== false
         || ($attack['fresh_loaded_after'] ?? false) !== true
         || ($attack['fresh_identity_after'] ?? null) !== 'victim'
         || ($attack['fresh_role_after'] ?? null) !== 'admin'
         || ($attack['fresh_late_after'] ?? null) !== null
      ) {
         Vars::$labels = ['H1 unexpected postcondition', 'H1 evidence'];
         dump(json_encode($Evidence));

         return 'H1 probe reached an unexpected partial state; revocation safety was not established.';
      }

      return true;
   },
);
