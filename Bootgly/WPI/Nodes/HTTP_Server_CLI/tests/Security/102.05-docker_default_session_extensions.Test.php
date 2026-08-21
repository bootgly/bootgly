<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('HTTPServerCLIDockerSessionProbe', false)) {
   class HTTPServerCLIDockerSessionProbe
   {
      public string $error = '';
      public bool $sessionResponse = false;
      public string $sessionStatus = '';
      public bool $healthResponse = false;
      public int $sessionPID = 0;
      public int $healthPID = 0;
      public bool $sharedMemoryExtension = false;
      public bool $semaphoreExtension = false;
      public bool $runtimeSelected = false;
      public bool $auditedArtifact = false;
      public string $imageID = '';
      public bool $skip = false;
   }
}


/**
 * Security PoC L1 — the runtime under audit must include the extensions
 * required by the default Cache-backed Shared Session handler.
 *
 * A real Session-mutating HTTP request is the source-to-sink check; an adjacent
 * route on the same worker is the positive server/harness control.
 */
$Probe = new HTTPServerCLIDockerSessionProbe;

return new Test(
   description: 'The audited official Docker runtime must support its default Shared Session handler',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      if (getenv('BOOTGLY_RUNTIME_AUDIT') !== '1') {
         $Probe->skip = true;

         return "GET /l1-docker-harness HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      }

      $attestation = @file_get_contents('/run/bootgly-image-attestation.json');
      $data = is_string($attestation)
         ? json_decode($attestation, true)
         : null;
      $frameworkSHA = getenv('BOOTGLY_FRAMEWORK_SHA');
      $Probe->imageID = is_array($data) && is_string($data['image_id'] ?? null)
         ? $data['image_id']
         : '';
      $Probe->runtimeSelected = is_file('/run/bootgly-image-attestation.json')
         && is_readable('/run/bootgly-image-attestation.json')
         && is_writable('/run/bootgly-image-attestation.json') === false
         && is_array($data)
         && preg_match('/^sha256:[a-f0-9]{64}$/D', $Probe->imageID) === 1
         && is_string($frameworkSHA)
         && preg_match('/^[a-f0-9]{40}$/D', $frameworkSHA) === 1
         && ($data['framework_sha'] ?? null) === $frameworkSHA
         && ($data['framework_dirty'] ?? null) === getenv('BOOTGLY_FRAMEWORK_DIRTY')
         && ($data['php_version'] ?? null) === getenv('PHP_VERSION')
         && ($data['php_version'] ?? null) === PHP_VERSION;
      $Probe->auditedArtifact = $Probe->runtimeSelected
         && $Probe->imageID
            === 'sha256:a32fa3c8a74cb3b393ecf77027de9aeceaed2418d7612eca6b1edcc3abaa6377'
         && $frameworkSHA === '99532ec7f86d44eda1505bd593559e6080340ae2'
         && PHP_VERSION === '8.4.24';
      if ($Probe->runtimeSelected === false) {
         $Probe->error = 'L1 runner did not mount matching Docker image-inspect attestation.';

         return "GET /l1-docker-harness HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      }

      $Probe->sharedMemoryExtension = extension_loaded('sysvshm');
      $Probe->semaphoreExtension = extension_loaded('sysvsem');

      $Read = static function ($Socket, float $timeout = 2.0): string {
         stream_set_blocking($Socket, false);
         $wire = '';
         $expected = null;
         $deadline = microtime(true) + $timeout;

         while (microtime(true) < $deadline) {
            $chunk = @fread($Socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
               $separator = strpos($wire, "\r\n\r\n");
               if ($separator !== false && $expected === null) {
                  $head = substr($wire, 0, $separator + 2);
                  if (preg_match('#\r\nContent-Length: ([0-9]+)\r\n#i', $head, $matches) === 1) {
                     $expected = $separator + 4 + (int) $matches[1];
                  }
               }
               if ($expected !== null && strlen($wire) >= $expected) {
                  return substr($wire, 0, $expected);
               }
            }

            if (@feof($Socket)) {
               break;
            }
            usleep(5_000);
         }

         return $wire;
      };

      $Send = static function (string $request) use ($hostPort, $Read): string {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 2,
         );
         if (! is_resource($Socket)) {
            return '';
         }
         stream_set_blocking($Socket, true);
         if (@fwrite($Socket, $request) !== strlen($request)) {
            fclose($Socket);
            return '';
         }

         $wire = $Read($Socket);
         fclose($Socket);

         return $wire;
      };

      $Decode = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($wire, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      try {
         $sessionWire = $Send(
            "GET /l1-docker-session HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n"
         );
         if (preg_match('#^HTTP/1\.1 ([0-9]{3})#', $sessionWire, $matches) === 1) {
            $Probe->sessionStatus = $matches[1];
         }
         $session = $Decode($sessionWire);
         $sessionPID = $session['pid'] ?? null;
         if (
            is_array($session)
            && ($session['phase'] ?? null) === 'session'
            && is_int($sessionPID)
            && $sessionPID > 0
         ) {
            $Probe->sessionResponse = true;
            $Probe->sessionPID = $sessionPID;
         }

         $healthWire = $Send(
            "GET /l1-docker-health HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n"
         );
         $health = $Decode($healthWire);
         $healthPID = $health['pid'] ?? null;
         if (
            is_array($health)
            && ($health['phase'] ?? null) === 'health'
            && is_int($healthPID)
            && $healthPID > 0
         ) {
            $Probe->healthResponse = true;
            $Probe->healthPID = $healthPID;
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /l1-docker-harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/l1-docker-session', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Session = $Request->Session;
         if ($Session === null) {
            throw new RuntimeException('L1 default Session was not constructed.');
         }
         $Session->set('l1', 'control');

         return $Response->JSON->send([
            'phase' => 'session',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/l1-docker-health', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'health',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/l1-docker-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'harness',
            'pid' => getmypid(),
         ]);
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);

      if ($Probe->skip) {
         return true;
      }

      if ($Probe->error !== '') {
         return 'L1 fixture error: ' . $Probe->error;
      }
      if (
         $Probe->healthResponse !== true
         || ! is_array($harness)
         || ($harness['phase'] ?? null) !== 'harness'
         || ($harness['pid'] ?? null) !== $Probe->healthPID
      ) {
         return 'L1 server controls failed independently of Session: '
            . json_encode([
               'probe' => $Probe,
               'harness' => $harness,
            ]);
      }

      if (
         $Probe->sharedMemoryExtension === false
         || $Probe->semaphoreExtension === false
      ) {
         if ($Probe->sessionResponse || $Probe->sessionStatus !== '500') {
            return 'L1 missing-extension runtime produced an unexpected Session result: '
               . json_encode($Probe);
         }
         if ($Probe->auditedArtifact === false) {
            return 'L1 selected runtime lacks the default Session extensions but does not '
               . 'match the exact artifact whose Docker provenance was externally verified: '
               . json_encode($Probe);
         }

         Vars::$labels = ['L1 Docker Session dependency evidence'];
         dump(json_encode([
            'probe' => $Probe,
            'harness' => $harness,
         ]));

         return 'CONFIRMED L1: the audited official Docker runtime lacks sysvshm/sysvsem required by the '
            . 'default Shared Session handler; the Session route failed while same-worker '
            . 'health and harness controls succeeded. Evidence: ' . json_encode($Probe);
      }

      if (
         $Probe->sessionResponse === false
         || $Probe->sessionStatus !== '200'
         || $Probe->sessionPID !== $Probe->healthPID
      ) {
         return 'L1 extensions are present, but the Session control failed for another reason: '
            . json_encode($Probe);
      }

      return true;
   },
);
