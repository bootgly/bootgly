<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Benchmark\HTTP_Server_CLI\Encoders\WorkerEvidence;


require_once BOOTGLY_ROOT_BASE
   . '/projects/Benchmark/HTTP_Server_CLI/Encoders/WorkerEvidence.php';


return new Specification(
   description: 'It should prove and disarm nonce-bound worker evidence',
   test: new Assertions(Case: function (): Generator
   {
      $root = sys_get_temp_dir()
         . '/bootgly-worker-evidence-' . getmypid() . '-' . bin2hex(random_bytes(8));
      $token = bin2hex(random_bytes(32));
      $nonce = bin2hex(random_bytes(32));
      $preRegisterNonce = bin2hex(random_bytes(32));
      $secondNonce = bin2hex(random_bytes(32));
      $sealNonce = bin2hex(random_bytes(32));
      $previousDirectory = getenv('BENCHMARK_SERVER_DIR');
      $requestInitialized = isset(HTTP_Server_CLI::$Request);
      $PreviousRequest = $requestInitialized ? HTTP_Server_CLI::$Request : null;
      $responseInitialized = isset(HTTP_Server_CLI::$Response);
      $PreviousResponse = $responseInitialized ? HTTP_Server_CLI::$Response : null;
      $routerInitialized = isset(HTTP_Server_CLI::$Router);
      $PreviousRouter = $routerInitialized ? HTTP_Server_CLI::$Router : null;
      $PreviousEncoder = HTTP_Server_CLI::$Encoder;
      $handlerInitialized = isset(SAPI::$Handler);
      $PreviousHandler = $handlerInitialized ? SAPI::$Handler : null;

      $OriginalEncoder = new class implements Encoder {
         public static int $calls = 0;

         public static function encode (Packages $Packages, null|int &$length): string
         {
            self::$calls++;
            $Header = HTTP_Server_CLI::$Response->Header;
            $Header->build();
            $matches = [];
            $matched = preg_match(
               '/(?:\A|\r\n)X-Bootgly-Benchmark-Worker: ([^\r\n]*)/D',
               $Header->raw,
               $matches,
            );
            $acknowledgement = $matched === 1 ? $matches[1] : '';
            $wire = 'wire:' . ($acknowledgement === '' ? 'none' : $acknowledgement);
            $length = strlen($wire);

            return $wire;
         }
      };
      $handled = 0;
      $Handler = static function (
         Request $Request,
         Response $Response,
         Router $Router,
      ) use (&$handled): Generator {
         $handled++;
         $Response->Body->raw = 'handled';

         yield from [];
      };
      $AlternateHandler = static function (
         Request $Request,
         Response $Response,
         Router $Router,
      ): Generator {
         yield from [];
      };
      $Evidence = new WorkerEvidence;
      $Packages = new class extends Packages {};
      $CleanRequest = new Request;
      $CleanResponse = new Response;
      $CleanRouter = new Router;
      $createdRoot = false;
      $leasePath = '';
      $leaseReleased = false;
      $ResetEncoder = $PreviousEncoder instanceof Encoder
         ? $PreviousEncoder
         : $OriginalEncoder;
      $ResetHandler = $handlerInitialized && $PreviousHandler instanceof Closure
         ? $PreviousHandler
         : $AlternateHandler;

      try {
         if (!mkdir($root, 0o700)) {
            throw new RuntimeException('Could not create the worker-evidence test directory.');
         }
         $createdRoot = true;
         if (!putenv('BENCHMARK_SERVER_DIR=' . $root)) {
            throw new RuntimeException('Could not configure the worker-evidence test directory.');
         }

         HTTP_Server_CLI::$Request = new Request;
         HTTP_Server_CLI::$Response = new Response;
         HTTP_Server_CLI::$Router = new Router;
         HTTP_Server_CLI::$Encoder = $OriginalEncoder;
         SAPI::$Handler = $Handler;

         // @ boot() captures the exact production encoder and handler once,
         //   but only the serving-worker lifecycle event may register proof.
         $Evidence->boot($token, $OriginalEncoder, $Handler);
         $Evidence->boot(str_repeat('0', 64), $OriginalEncoder, $AlternateHandler);

         $PreRegisterRequest = new Request;
         $PreRegisterRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => $preRegisterNonce,
         ]);
         $PreRegisterResponse = new Response;
         $preRegisterResult = $Evidence->mark(
            $PreRegisterRequest,
            $PreRegisterResponse,
         );

         HTTP_Server_CLI::$Request = $PreRegisterRequest;
         HTTP_Server_CLI::$Response = new Response;
         $preRegisterLength = null;
         $preRegisterWire = WorkerEvidence::encode($Packages, $preRegisterLength);
         $EvidenceClass = new ReflectionClass(WorkerEvidence::class);
         $preRegisterLeases = glob($root . '/workers/worker-*.lease');

         yield new Assertion(
            description: 'Request paths fail closed before the serving-worker lifecycle registration',
            fallback: 'A request registered or emitted worker evidence before ProcessEvents::Boot!'
         )
            ->expect(
               $preRegisterResult === false
                  && $PreRegisterResponse->Header->get(
                     'X-Bootgly-Benchmark-Worker'
                  ) === ''
                  && $preRegisterWire === 'wire:none'
                  && $preRegisterLength === strlen($preRegisterWire)
                  && $OriginalEncoder::$calls === 1
                  && $preRegisterLeases === []
                  && !is_dir($root . '/workers')
                  && $EvidenceClass->getProperty('PID')->getValue() === null
                  && $EvidenceClass->getProperty('workerIdentity')->getValue() === null
                  && $EvidenceClass->getProperty('lease')->getValue() === null,
               Op::Identical,
               true,
            )
            ->assert();

         // @ Worker lifecycle registration must create and retain the lease
         //   before the first routed request. A duplicate event is idempotent.
         WorkerEvidence::register();
         WorkerEvidence::register();
         $lifecycleLeases = glob($root . '/workers/worker-*.lease');

         $MissingNonceRequest = new Request;
         $MissingNonceRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
         ]);
         $MissingNonceResponse = new Response;
         $missingNonceResult = $Evidence->mark($MissingNonceRequest, $MissingNonceResponse);

         $WrongNonceRequest = new Request;
         $WrongNonceRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => substr($nonce, 1),
         ]);
         $WrongNonceResponse = new Response;
         $wrongNonceResult = $Evidence->mark($WrongNonceRequest, $WrongNonceResponse);

         $WrongTokenRequest = new Request;
         $WrongTokenRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => str_repeat('0', 64),
            'x-bootgly-benchmark-nonce' => $nonce,
         ]);
         $WrongTokenResponse = new Response;
         $wrongTokenResult = $Evidence->mark($WrongTokenRequest, $WrongTokenResponse);

         yield new Assertion(
            description: 'Missing, malformed, or unauthorized nonces receive no acknowledgement',
            fallback: 'Worker evidence accepted an incomplete or unauthorized proof request!'
         )
            ->expect(
               [
                  $missingNonceResult,
                  $MissingNonceResponse->Header->get('X-Bootgly-Benchmark-Worker'),
                  $wrongNonceResult,
                  $WrongNonceResponse->Header->get('X-Bootgly-Benchmark-Worker'),
                  $wrongTokenResult,
                  $WrongTokenResponse->Header->get('X-Bootgly-Benchmark-Worker'),
               ],
               Op::Identical,
               [false, '', false, '', false, ''],
            )
            ->assert();

         $ValidRequest = new Request;
         $ValidRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => $nonce,
         ]);
         $ValidResponse = new Response;
         $validResult = $Evidence->mark($ValidRequest, $ValidResponse);
         $acknowledgement = $ValidResponse->Header->get('X-Bootgly-Benchmark-Worker');
         $prefix = $token . ':' . $nonce . ':';
         $identity = str_starts_with($acknowledgement, $prefix)
            ? substr($acknowledgement, strlen($prefix))
            : '';

         yield new Assertion(
            description: 'A valid request receives an exact token-and-nonce-bound worker identity',
            fallback: 'Worker acknowledgement was absent, malformed, or sealed unexpectedly!'
         )
            ->expect(
               $validResult === false
                  && $identity !== ''
                  && $acknowledgement === $prefix . $identity
                  && preg_match('/\A[1-9][0-9]*-[0-9a-f]{32}\z/D', $identity) === 1,
               Op::Identical,
               true,
            )
            ->assert();

         $leases = glob($root . '/workers/worker-*.lease');
         $leasePath = is_array($leases) && count($leases) === 1 ? $leases[0] : '';
         $leaseContents = $leasePath === '' ? false : file_get_contents($leasePath);
         $lease = is_string($leaseContents)
            ? json_decode($leaseContents, true, flags: JSON_THROW_ON_ERROR)
            : null;
         $leaseMode = $leasePath === '' ? false : fileperms($leasePath);
         $expectedFingerprint = 'sha256:' . hash('sha256', "worker\0{$identity}");

         yield new Assertion(
            description: 'Worker boot creates one protected, token-free lifetime lease before requests',
            fallback: 'Worker lease metadata, permissions, or secret redaction is invalid!'
         )
            ->expect(
               is_array($lifecycleLeases)
                  && count($lifecycleLeases) === 1
                  && $lifecycleLeases[0] === $leasePath
                  && is_array($lease)
                  && ($lease['schema'] ?? null) === 'bootgly.worker-lease'
                  && ($lease['version'] ?? null) === 1
                  && ($lease['fingerprint'] ?? null) === $expectedFingerprint
                  && ($lease['pid'] ?? null) === getmypid()
                  && is_int($leaseMode)
                  && ($leaseMode & 0o777) === 0o600
                  && !str_contains((string) $leaseContents, $token)
                  && !str_contains((string) $leaseContents, $nonce)
                  && !str_contains((string) $leaseContents, $identity),
               Op::Identical,
               true,
            )
            ->assert();

         $MissingEncodeRequest = new Request;
         $MissingEncodeRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
         ]);
         HTTP_Server_CLI::$Request = $MissingEncodeRequest;
         HTTP_Server_CLI::$Response = new Response;
         $missingLength = null;
         $missingWire = WorkerEvidence::encode($Packages, $missingLength);

         $ValidEncodeRequest = new Request;
         $ValidEncodeRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => $secondNonce,
         ]);
         HTTP_Server_CLI::$Request = $ValidEncodeRequest;
         HTTP_Server_CLI::$Response = new Response;
         $validLength = null;
         $validWire = WorkerEvidence::encode($Packages, $validLength);
         $validEncodePrefix = 'wire:' . $token . ':' . $secondNonce . ':';
         /** @var array<string,string|true> $presets */
         $presets = HTTP_Server_CLI::$Response->Header->preset;
         $responseWasCleaned = !array_key_exists(
            'X-Bootgly-Benchmark-Worker',
            $presets,
         );

         yield new Assertion(
            description: 'Deferred encoding delegates invalid input and binds valid input without state leakage',
            fallback: 'Deferred worker evidence changed ordinary output or leaked its temporary header!'
         )
            ->expect(
               $missingWire === 'wire:none'
                  && $missingLength === strlen($missingWire)
                  && str_starts_with($validWire, $validEncodePrefix)
                  && substr($validWire, strlen($validEncodePrefix)) === $identity
                  && $validLength === strlen($validWire)
                  && $responseWasCleaned
                  && $OriginalEncoder::$calls === 3,
               Op::Identical,
               true,
            )
            ->assert();

         $SealEncodeRequest = new Request;
         $SealEncodeRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => $sealNonce,
            'x-bootgly-benchmark-seal' => $token,
         ]);
         HTTP_Server_CLI::$Request = $SealEncodeRequest;
         HTTP_Server_CLI::$Response = new Response;
         HTTP_Server_CLI::$Encoder = $Evidence;
         SAPI::$Handler = $AlternateHandler;
         $sealEncodeLength = null;
         $sealEncodeWire = WorkerEvidence::encode($Packages, $sealEncodeLength);
         /** @var array<string,string|true> $sealEncodePresets */
         $sealEncodePresets = HTTP_Server_CLI::$Response->Header->preset;

         yield new Assertion(
            description: 'A deferred sealing response retains its proof and then restores both runtime seams',
            fallback: 'Deferred worker evidence did not seal and restore atomically!'
         )
            ->expect(
               $sealEncodeWire === 'wire:' . $token . ':' . $sealNonce . ':' . $identity
                  && $sealEncodeLength === strlen($sealEncodeWire)
                  && !array_key_exists(
                     'X-Bootgly-Benchmark-Worker',
                     $sealEncodePresets,
                  )
                  && HTTP_Server_CLI::$Encoder === $OriginalEncoder
                  && SAPI::$Handler === $Handler
                  && $OriginalEncoder::$calls === 4,
               Op::Identical,
               true,
            )
            ->assert();

         // @ Exercise restore() directly after replacing both runtime seams.
         HTTP_Server_CLI::$Encoder = $Evidence;
         SAPI::$Handler = $AlternateHandler;
         WorkerEvidence::restore();

         yield new Assertion(
            description: 'Explicit restoration reinstates the exact captured runtime seams',
            fallback: 'Worker evidence did not restore the original encoder and handler!'
         )
            ->expect(
               HTTP_Server_CLI::$Encoder === $OriginalEncoder
                  && SAPI::$Handler === $Handler,
               Op::Identical,
               true,
            )
            ->assert();

         $WrappedHandler = $Evidence->wrap($token, $Handler);
         HTTP_Server_CLI::$Encoder = $OriginalEncoder;
         SAPI::$Handler = $WrappedHandler;
         $SealRequest = new Request;
         $SealRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => $secondNonce,
            'x-bootgly-benchmark-seal' => $token,
         ]);
         $SealResponse = new Response;
         $Generator = $WrappedHandler($SealRequest, $SealResponse, HTTP_Server_CLI::$Router);
         foreach ($Generator as $unused) {
         }

         $sealAcknowledgement = $SealResponse->Header->get(
            'X-Bootgly-Benchmark-Worker'
         );
         $PostSealRequest = new Request;
         $PostSealRequest->Header->adopt([
            'x-bootgly-benchmark-warmup' => $token,
            'x-bootgly-benchmark-nonce' => $nonce,
         ]);
         $PostSealResponse = new Response;
         $PostSealGenerator = (SAPI::$Handler)(
            $PostSealRequest,
            $PostSealResponse,
            HTTP_Server_CLI::$Router,
         );
         foreach ($PostSealGenerator as $unused) {
         }
         HTTP_Server_CLI::$Request = $PostSealRequest;
         HTTP_Server_CLI::$Response = $PostSealResponse;
         $PostSealEncoder = HTTP_Server_CLI::$Encoder;
         $postSealLength = null;
         $postSealWire = $PostSealEncoder::encode($Packages, $postSealLength);

         yield new Assertion(
            description: 'A seal is acknowledged, restores both seams, and removes post-seal instrumentation',
            fallback: 'The sealing request did not fully disarm worker evidence!'
         )
            ->expect(
               $sealAcknowledgement === $token . ':' . $secondNonce . ':' . $identity
                  && HTTP_Server_CLI::$Encoder === $OriginalEncoder
                  && SAPI::$Handler === $Handler
                  && $PostSealResponse->Header->get('X-Bootgly-Benchmark-Worker') === ''
                  && $postSealWire === 'wire:none'
                  && $postSealLength === strlen($postSealWire)
                  && $OriginalEncoder::$calls === 5
                  && $handled === 2,
               Op::Identical,
               true,
            )
            ->assert();
      }
      finally {
         $EvidenceClass = new ReflectionClass(WorkerEvidence::class);
         $BootedProperty = $EvidenceClass->getProperty('booted');
         $TokenProperty = $EvidenceClass->getProperty('token');
         $PIDProperty = $EvidenceClass->getProperty('PID');
         $IdentityProperty = $EvidenceClass->getProperty('workerIdentity');
         $LeaseProperty = $EvidenceClass->getProperty('lease');
         $EncoderProperty = $EvidenceClass->getProperty('Encoder');
         $HandlerProperty = $EvidenceClass->getProperty('Handler');
         $Lease = $LeaseProperty->getValue();

         if (is_resource($Lease)) {
            @flock($Lease, LOCK_UN);
            fclose($Lease);
         }

         $BootedProperty->setValue(null, false);
         $TokenProperty->setValue(null, '');
         $PIDProperty->setValue(null, null);
         $IdentityProperty->setValue(null, null);
         $LeaseProperty->setValue(null, null);
         $EncoderProperty->setValue(null, $ResetEncoder);
         $HandlerProperty->setValue(null, $ResetHandler);

         if ($leasePath === '' || !is_file($leasePath)) {
            $leaseReleased = true;
         }
         else {
            $LeaseProbe = @fopen($leasePath, 'r+b');
            if (is_resource($LeaseProbe)) {
               $leaseReleased = flock($LeaseProbe, LOCK_EX | LOCK_NB);
               if ($leaseReleased) {
                  flock($LeaseProbe, LOCK_UN);
               }
               fclose($LeaseProbe);
            }
         }

         HTTP_Server_CLI::$Request = $requestInitialized
            && $PreviousRequest instanceof Request
               ? $PreviousRequest
               : $CleanRequest;
         HTTP_Server_CLI::$Response = $responseInitialized
            && $PreviousResponse instanceof Response
               ? $PreviousResponse
               : $CleanResponse;
         HTTP_Server_CLI::$Router = $routerInitialized
            && $PreviousRouter instanceof Router
               ? $PreviousRouter
               : $CleanRouter;
         HTTP_Server_CLI::$Encoder = $PreviousEncoder;
         SAPI::$Handler = $ResetHandler;

         if ($previousDirectory === false) {
            putenv('BENCHMARK_SERVER_DIR');
         }
         else {
            putenv('BENCHMARK_SERVER_DIR=' . $previousDirectory);
         }

         if ($createdRoot) {
            $leases = glob($root . '/workers/*');
            if (is_array($leases)) {
               foreach ($leases as $candidate) {
                  if (is_file($candidate) || is_link($candidate)) {
                     unlink($candidate);
                  }
               }
            }
            if (is_dir($root . '/workers')) {
               rmdir($root . '/workers');
            }
            if (is_dir($root)) {
               rmdir($root);
            }
         }
      }

      $EvidenceClass = new ReflectionClass(WorkerEvidence::class);
      $RestoredRequest = HTTP_Server_CLI::$Request;
      $RestoredResponse = HTTP_Server_CLI::$Response;
      $RestoredRouter = HTTP_Server_CLI::$Router;

      yield new Assertion(
         description: 'Cleanup releases the lease and restores inert process-global state',
         fallback: 'Worker evidence cleanup leaked a lock, secret, runtime seam, or temporary artifact!'
      )
         ->expect(
            $leaseReleased
               && $EvidenceClass->getProperty('booted')->getValue() === false
               && $EvidenceClass->getProperty('token')->getValue() === ''
               && $EvidenceClass->getProperty('PID')->getValue() === null
               && $EvidenceClass->getProperty('workerIdentity')->getValue() === null
               && $EvidenceClass->getProperty('lease')->getValue() === null
               && $EvidenceClass->getProperty('Encoder')->getValue() === $ResetEncoder
               && $EvidenceClass->getProperty('Handler')->getValue() === $ResetHandler
               && $RestoredRequest === ($PreviousRequest ?? $CleanRequest)
               && $RestoredResponse === ($PreviousResponse ?? $CleanResponse)
               && $RestoredRouter === ($PreviousRouter ?? $CleanRouter)
               && HTTP_Server_CLI::$Encoder === $PreviousEncoder
               && SAPI::$Handler === $ResetHandler
               && getenv('BENCHMARK_SERVER_DIR') === $previousDirectory
               && !is_dir($root),
            Op::Identical,
            true,
         )
         ->assert();
   })
);
