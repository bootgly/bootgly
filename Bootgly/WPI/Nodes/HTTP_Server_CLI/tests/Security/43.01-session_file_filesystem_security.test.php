<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M9 — File-backed sessions must enforce an owner-only filesystem
 * boundary and must not trust attacker-positioned signing material.
 *
 * The functional control performs a real File handler round-trip in an
 * automatically created directory. The adversarial fixtures then use a
 * permissive pre-existing directory under umask 0000, pre-position a known
 * `.secret`, and simulate a secret-publication failure. Secure behavior may
 * repair safe ownership/modes or reject the unsafe fixture, but must never
 * create a broadly readable session, accept an attacker-known signing key, or
 * continue with an ephemeral key that another worker cannot reproduce.
 */
$Evidence = [
   'fixture_error' => '',
   'control' => [],
   'attack' => [],
   'publication' => [],
];

return new Specification(
   description: 'File sessions must enforce private files and trusted secret creation',
   Separator: new Separator(line: true),

   request: static function () use (&$Evidence): string {
      $root = sys_get_temp_dir() . '/bootgly-m9-' . bin2hex(random_bytes(12));
      $previousUmask = umask(0000);
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
      $Mode = static function (string $path): null|string {
         $state = @lstat($path);

         return is_array($state)
            ? sprintf('%04o', ((int) $state['mode']) & 0777)
            : null;
      };

      $Reflection = new ReflectionClass(File::class);
      $PathProperty = $Reflection->getProperty('path');
      $SecretProperty = $Reflection->getProperty('secret');
      $SecretPathProperty = $Reflection->getProperty('secretPath');
      $previousPath = (string) $PathProperty->getValue();
      $previousSecret = (string) $SecretProperty->getValue();
      $previousSecretPath = (string) $SecretPathProperty->getValue();

      try {
         if (@mkdir($root, 0700, true) === false && is_dir($root) === false) {
            throw new RuntimeException('Could not create the isolated M9 fixture root.');
         }

         // # Positive control: the genuine handler can persist and authenticate
         // a normal session while rejecting a traversal-shaped ID.
         $controlPath = $root . '/control';
         $controlID = bin2hex(random_bytes(16));
         $controlPayload = serialize(['control' => 'roundtrip']);
         $PathProperty->setValue(null, '');
         $SecretProperty->setValue(null, '');
         $SecretPathProperty->setValue(null, '');
         $Control = new File(['save_path' => $controlPath]);
         $controlWrite = $Control->write($controlID, $controlPayload);
         $controlRead = $Control->read($controlID);
         $controlInvalid = $Control->write('../m9-invalid', $controlPayload);
         $controlSession = $controlPath . '/session_' . $controlID;
         $Evidence['control'] = [
            'write' => $controlWrite,
            'roundtrip' => $controlRead === $controlPayload,
            'invalid_id_rejected' => $controlInvalid === false,
            'directory_mode' => $Mode($controlPath),
            'secret_mode' => $Mode($controlPath . '/.secret'),
            'session_mode' => $Mode($controlSession),
         ];

         // # Attacker fixture: File::secret() currently follows and accepts a
         // pre-positioned secret without validating its type, owner, link count
         // or mode. A regular-file fallback keeps this probe portable if the
         // host filesystem does not permit symlinks.
         $attackPath = $root . '/attack';
         $secretTarget = $root . '/attacker-known-secret';
         $secretPath = $attackPath . '/.secret';
         $knownSecret = str_repeat('m9-known-secret-', 4);
         @mkdir($attackPath, 0777, true);
         @chmod($attackPath, 0777);
         file_put_contents($secretTarget, $knownSecret);
         @chmod($secretTarget, 0644);
         $secretKind = @symlink($secretTarget, $secretPath) ? 'symlink' : 'regular';
         if ($secretKind === 'regular') {
            file_put_contents($secretPath, $knownSecret);
            @chmod($secretPath, 0644);
         }

         $PathProperty->setValue(null, '');
         $SecretProperty->setValue(null, '');
         $SecretPathProperty->setValue(null, '');
         try {
            $Attack = new File(['save_path' => $attackPath]);
            $attackID = bin2hex(random_bytes(16));
            $attackPayload = serialize(['control' => 'known-secret-write']);
            $attackWrite = $Attack->write($attackID, $attackPayload);
            $attackSession = $attackPath . '/session_' . $attackID;
            $attackWire = @file_get_contents($attackSession);

            $forgedID = bin2hex(random_bytes(16));
            $forgedPayload = serialize(['identity' => 'admin']);
            $forgedSession = $attackPath . '/session_' . $forgedID;
            $forgedWire = hash_hmac('sha256', $forgedPayload, $knownSecret) . $forgedPayload;
            $forgedWrite = @file_put_contents($forgedSession, $forgedWire);
            $forgedRead = $Attack->read($forgedID);
            $PreviousHandler = Handler::$instance;
            $ForgedSession = null;
            try {
               Handler::$instance = $Attack;
               $ForgedSession = new Session($forgedID);
               $normalSessionLoaded = $ForgedSession->loaded;
               $normalSessionIdentity = $ForgedSession->get('identity');
            }
            finally {
               unset($ForgedSession);
               Handler::$instance = $PreviousHandler;
            }

            $Evidence['attack'] = [
               'rejected' => false,
               'secret_kind' => $secretKind,
               'directory_mode' => $Mode($attackPath),
               'secret_target_mode' => $Mode($secretTarget),
               'session_mode' => $Mode($attackSession),
               'handler_write' => $attackWrite,
               'handler_roundtrip' => $Attack->read($attackID) === $attackPayload,
               'known_secret_signed' => is_string($attackWire)
                  && substr($attackWire, 0, 64) === hash_hmac('sha256', $attackPayload, $knownSecret),
               'forged_file_written' => $forgedWrite === strlen($forgedWire),
               'forged_payload_loaded' => $forgedRead === $forgedPayload,
               'normal_session_loaded' => $normalSessionLoaded,
               'normal_session_identity' => $normalSessionIdentity,
            ];
         }
         catch (InvalidArgumentException|RuntimeException $Exception) {
            $message = $Exception->getMessage();
            $Evidence['attack'] = [
               'rejected' => true,
               'expected' => in_array($message, [
                  'File session save directory has unsafe metadata.',
                  'File session secret path must not be a symbolic link.',
                  'File session secret has unsafe metadata.',
               ], true),
               'error' => $Exception::class . ': ' . $message,
            ];
         }

         // # Deterministic key-consistency control. A directory occupying the
         // secret pathname makes publication fail. Continuing with an
         // unpersisted random secret causes the next worker view to reject the
         // first worker's otherwise valid session.
         $publicationPath = $root . '/publication';
         $publicationSecret = $publicationPath . '/.secret';
         @mkdir($publicationSecret, 0700, true);
         $PathProperty->setValue(null, '');
         $SecretProperty->setValue(null, '');
         $SecretPathProperty->setValue(null, '');
         try {
            $First = new File(['save_path' => $publicationPath]);
            $publicationID = bin2hex(random_bytes(16));
            $publicationPayload = serialize(['worker' => 'first']);
            $firstWrite = $First->write($publicationID, $publicationPayload);

            $SecretProperty->setValue(null, '');
            $SecretPathProperty->setValue(null, '');
            $Second = new File(['save_path' => $publicationPath]);
            $secondRead = $Second->read($publicationID);
            $Evidence['publication'] = [
               'rejected' => false,
               'secret_path_is_directory' => is_dir($publicationSecret),
               'first_write' => $firstWrite,
               'second_worker_read' => $secondRead,
               'inconsistent' => $firstWrite === true && $secondRead !== $publicationPayload,
            ];
         }
         catch (InvalidArgumentException|RuntimeException $Exception) {
            $message = $Exception->getMessage();
            $Evidence['publication'] = [
               'rejected' => true,
               'expected' => $message === 'File session secret has unsafe metadata.',
               'error' => $Exception::class . ': ' . $message,
            ];
         }
      }
      catch (Throwable $Throwable) {
         $Evidence['fixture_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $PathProperty->setValue(null, $previousPath);
         $SecretProperty->setValue(null, $previousSecret);
         $SecretPathProperty->setValue(null, $previousSecretPath);
         umask($previousUmask);
         $Cleanup($root);
      }

      return "GET /m9/file-session-storage HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m9/file-session-storage', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 200, body: 'M9-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$Evidence): bool|string {
      if (str_contains($response, 'M9-HARNESS-OK') === false) {
         Vars::$labels = ['M9 native-harness evidence'];
         dump(json_encode($Evidence), json_encode($response));

         return 'M9 fixture failed: the native HTTP control route did not complete.';
      }
      if ($Evidence['fixture_error'] !== '') {
         Vars::$labels = ['M9 fixture error', 'M9 evidence'];
         dump($Evidence['fixture_error'], json_encode($Evidence));

         return 'M9 fixture failed before reaching the filesystem trust boundary: '
            . $Evidence['fixture_error'];
      }

      $control = $Evidence['control'];
      if (
         ($control['write'] ?? false) !== true
         || ($control['roundtrip'] ?? false) !== true
         || ($control['invalid_id_rejected'] ?? false) !== true
      ) {
         Vars::$labels = ['M9 positive-control evidence'];
         dump(json_encode($Evidence));

         return 'M9 control failed: the genuine File handler did not complete a valid round-trip.';
      }

      $violations = [];
      if (($control['directory_mode'] ?? null) !== '0700') {
         $violations[] = 'automatically created directory mode=' . ($control['directory_mode'] ?? 'missing');
      }
      if (($control['secret_mode'] ?? null) !== '0600') {
         $violations[] = 'generated secret mode=' . ($control['secret_mode'] ?? 'missing');
      }
      if (($control['session_mode'] ?? null) !== '0600') {
         $violations[] = 'session mode under umask 0000=' . ($control['session_mode'] ?? 'missing');
      }

      $attack = $Evidence['attack'];
      if (
         ($attack['rejected'] ?? false) === true
         && ($attack['expected'] ?? false) !== true
      ) {
         Vars::$labels = ['M9 unexpected attack-fixture rejection'];
         dump(json_encode($Evidence));

         return 'M9 fixture failed: the unsafe directory/secret was rejected for an unexpected reason.';
      }
      if (($attack['rejected'] ?? false) === false) {
         if (($attack['directory_mode'] ?? null) !== '0700') {
            $violations[] = 'pre-existing save directory accepted at mode='
               . ($attack['directory_mode'] ?? 'missing');
         }
         if (($attack['session_mode'] ?? null) !== '0600') {
            $violations[] = 'attack-fixture session mode=' . ($attack['session_mode'] ?? 'missing');
         }
         if (($attack['known_secret_signed'] ?? false) === true) {
            $violations[] = 'handler signed with attacker-known ' . ($attack['secret_kind'] ?? 'unknown') . ' secret';
         }
         if (
            ($attack['forged_payload_loaded'] ?? false) === true
            && ($attack['normal_session_loaded'] ?? false) === true
            && ($attack['normal_session_identity'] ?? null) === 'admin'
         ) {
            $violations[] = 'attacker-HMACed identity=admin loaded by the normal Session path';
         }
      }

      $publication = $Evidence['publication'];
      if (
         ($publication['rejected'] ?? false) === true
         && ($publication['expected'] ?? false) !== true
      ) {
         Vars::$labels = ['M9 unexpected publication-fixture rejection'];
         dump(json_encode($Evidence));

         return 'M9 fixture failed: secret publication was rejected for an unexpected reason.';
      }
      if (
         ($publication['rejected'] ?? false) === false
         && ($publication['inconsistent'] ?? false) === true
      ) {
         $violations[] = 'failed secret publication produced inconsistent worker keys';
      }

      if ($violations !== []) {
         Vars::$labels = ['M9 filesystem-boundary evidence'];
         dump(json_encode($Evidence));

         return 'CONFIRMED M9: File session storage accepted unsafe filesystem state: '
            . implode('; ', $violations) . '.';
      }

      return true;
   },
);
