<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ProtocolException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M15 — ACME metadata must not send requests to an unapproved
 * authority or prohibited address class.
 *
 * Both scenarios use the public register() flow and real self-signed TLS
 * listeners. The control completes directory, nonce and account requests on
 * one authority. The attack keeps directory and nonce on that configured
 * authority, but advertises newAccount on a distinct loopback authority. A
 * vulnerable client sends that second listener an account-key-signed JWS.
 *
 * verify:false deliberately models Bootgly's documented private/test-CA mode.
 * The PoC therefore proves alternate-authority loopback HTTPS SSRF under that
 * precondition; it does not claim arbitrary internal TLS reachability while
 * normal peer and hostname verification remain enabled.
 */
$evidence = [];
$probeError = '';

return new Specification(
   description: 'ACME-advertised endpoints must not escape the approved authority/address policy',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (
      &$evidence,
      &$probeError,
   ): string {
      $certificate = __DIR__
         . '/../../../HTTP_Client_CLI/tests/E2E_SSL/localhost.cert.pem';
      $key = __DIR__
         . '/../../../HTTP_Client_CLI/tests/E2E_SSL/localhost.key.pem';

      $HTTP = static function (
         int $code,
         string $status,
         string $body,
         array $headers = [],
      ): string {
         $fields = [
            "HTTP/1.1 {$code} {$status}",
            'Content-Length: ' . strlen($body),
            'Connection: close',
         ];
         foreach ($headers as $name => $value) {
            $fields[] = "{$name}: {$value}";
         }

         return implode("\r\n", $fields) . "\r\n\r\n{$body}";
      };

      /** @return array{line:string,host:string,content_type:string,body:string} */
      $Receive = static function ($Peer): array {
         stream_set_timeout($Peer, 3);
         $input = '';
         while (! str_contains($input, "\r\n\r\n") && ! feof($Peer)) {
            $chunk = @fread($Peer, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $input .= $chunk;
         }

         $headEnd = strpos($input, "\r\n\r\n");
         $head = $headEnd === false ? $input : substr($input, 0, $headEnd);
         $body = $headEnd === false ? '' : substr($input, $headEnd + 4);
         preg_match('/^Content-Length:\s*(\d+)\s*$/mi', $head, $lengthMatches);
         $length = (int) ($lengthMatches[1] ?? 0);
         while (strlen($body) < $length && ! feof($Peer)) {
            $chunk = @fread($Peer, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
               break;
            }
            $body .= $chunk;
         }

         $lineEnd = strpos($head, "\r\n");
         preg_match('/^Host:\s*([^\r\n]+)\r?$/mi', $head, $hostMatches);
         preg_match('/^Content-Type:\s*([^\r\n]+)\r?$/mi', $head, $typeMatches);

         return [
            'line' => $lineEnd === false ? $head : substr($head, 0, $lineEnd),
            'host' => trim((string) ($hostMatches[1] ?? '')),
            'content_type' => trim((string) ($typeMatches[1] ?? '')),
            'body' => $body,
         ];
      };

      /**
       * @param resource $Listener
       * @param array<int,string> $scripts
       */
      $Serve = static function (
         $Listener,
         array $scripts,
         int $minimum,
         string $capture,
      ) use ($Receive): never {
         $requests = [];
         $error = '';

         foreach ($scripts as $script) {
            $Peer = @stream_socket_accept($Listener, 3.0);
            if ($Peer === false) {
               if (count($requests) < $minimum) {
                  $error = 'accepted only ' . count($requests)
                     . " of {$minimum} required request(s)";
               }
               break;
            }

            $requests[] = $Receive($Peer);
            $offset = 0;
            while ($offset < strlen($script)) {
               $written = @fwrite($Peer, substr($script, $offset));
               if ($written === false || $written === 0) {
                  $error = 'could not write the complete scripted TLS response';
                  break 2;
               }
               $offset += $written;
            }
            @fflush($Peer);
            @fclose($Peer);
         }

         if (count($requests) < $minimum && $error === '') {
            $error = 'captured fewer requests than the fixture requires';
         }

         file_put_contents($capture, json_encode([
            'requests' => $requests,
            'error' => $error,
         ]));
         @fclose($Listener);
         exit($error === '' ? 0 : 2);
      };

      $Unpack = static function (string $segment): string {
         $base64 = strtr($segment, '-_', '+/');
         $remainder = strlen($base64) % 4;
         if ($remainder !== 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
         }

         return (string) base64_decode($base64, true);
      };

      // ! Keep the retained PoC executable against both the original
      //   vulnerable constructor and the remediated policy API.
      $Constructor = new ReflectionMethod(ACME_Client::class, '__construct');
      $policyParameters = [];
      foreach ($Constructor->getParameters() as $Parameter) {
         $policyParameters[$Parameter->getName()] = true;
      }
      $policySupported = isset(
         $policyParameters['authorities'],
         $policyParameters['allowPrivate'],
      );

      /**
       * Run one real ACME registration, optionally advertising newAccount at
       * a separately bound TLS authority.
       *
       * @return array<string,mixed>
       */
      $Run = static function (bool $alternate, bool $allowed = false) use (
         $certificate,
         $key,
         $HTTP,
         $Serve,
         $Unpack,
         $policySupported,
      ): array {
         $label = $alternate
            ? ($allowed ? 'allowed' : 'attack')
            : 'control';
         // ! The alternate authority must bind exactly where its advertised
         //   hostname resolves. 'localhost' maps to ::1 first on dual-stack
         //   hosts (GitHub runners ship "::1 localhost"), so an IPv4-only
         //   bind would strand the client's newAccount POST and time out.
         $alternateHost = $allowed ? 'localhost' : '127.0.0.1';
         $result = [
            'label' => $label,
            'authority_url' => '',
            'alternate_url' => '',
            'registered_url' => null,
            'client_error' => null,
            'authority' => ['requests' => [], 'error' => 'not started'],
            'alternate' => ['requests' => [], 'error' => 'not started'],
            'authority_child_clean' => false,
            'alternate_child_clean' => ! $alternate,
            'jws' => [],
            'policy_supported' => $policySupported,
         ];

         $Context = stream_context_create([
            'ssl' => [
               'local_cert' => $certificate,
               'local_pk' => $key,
               'verify_peer' => false,
            ],
         ]);
         $AuthorityListener = @stream_socket_server(
            'tls://127.0.0.1:0',
            $authorityCode,
            $authorityMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $Context,
         );
         if ($AuthorityListener === false) {
            $result['fixture_error'] = "authority bind failed: {$authorityCode} {$authorityMessage}";

            return $result;
         }

         $AlternateListener = null;
         if ($alternate) {
            $AlternateListener = @stream_socket_server(
               "tls://{$alternateHost}:0",
               $alternateCode,
               $alternateMessage,
               STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
               $Context,
            );
            if ($AlternateListener === false) {
               @fclose($AuthorityListener);
               $result['fixture_error'] = "alternate bind failed: {$alternateCode} {$alternateMessage}";

               return $result;
            }
         }

         $authorityAddress = stream_socket_get_name($AuthorityListener, false);
         $authoritySeparator = is_string($authorityAddress)
            ? strrpos($authorityAddress, ':')
            : false;
         $authorityPort = $authoritySeparator === false
            ? 0
            : (int) substr($authorityAddress, $authoritySeparator + 1);
         $alternateAddress = is_resource($AlternateListener)
            ? stream_socket_get_name($AlternateListener, false)
            : '';
         $alternateSeparator = is_string($alternateAddress)
            ? strrpos($alternateAddress, ':')
            : false;
         $alternatePort = $alternateSeparator === false
            ? 0
            : (int) substr($alternateAddress, $alternateSeparator + 1);

         if ($authorityPort < 1 || ($alternate && $alternatePort < 1)) {
            @fclose($AuthorityListener);
            if (is_resource($AlternateListener)) {
               @fclose($AlternateListener);
            }
            $result['fixture_error'] = 'fixture did not obtain ephemeral TLS ports';

            return $result;
         }

         $authorityURL = "https://127.0.0.1:{$authorityPort}";
         $alternateURL = $alternate
            ? "https://{$alternateHost}:{$alternatePort}"
            : $authorityURL;
         $result['authority_url'] = $authorityURL;
         $result['alternate_url'] = $alternateURL;

         // ! Generate the account key before fixture children begin their
         //   bounded accept windows; slow RSA generation cannot starve the
         //   explicitly allowed delegated-authority control.
         $accountPath = sys_get_temp_dir()
            . "/bootgly-m15-{$label}-" . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
         try {
            $Account = new Account($accountPath);
            $Account->Key->derive();
         }
         catch (Throwable $Throwable) {
            @fclose($AuthorityListener);
            if (is_resource($AlternateListener)) {
               @fclose($AlternateListener);
            }
            foreach (glob("{$accountPath}*") ?: [] as $file) {
               @unlink($file);
            }
            @rmdir($accountPath);
            $result['fixture_error'] = 'account key setup failed: '
               . $Throwable::class . ': ' . $Throwable->getMessage();

            return $result;
         }

         $directory = json_encode([
            'newAccount' => $alternate
               ? "{$alternateURL}/private/new-account"
               : "{$authorityURL}/new-account",
            'newNonce' => "{$authorityURL}/new-nonce",
            'newOrder' => "{$authorityURL}/new-order",
         ]);
         $directoryResponse = $HTTP(200, 'OK', (string) $directory, [
            'Content-Type' => 'application/json',
         ]);
         $authorityScripts = [
            $directoryResponse,
            $HTTP(204, 'No Content', '', [
               'Replay-Nonce' => "m15-{$label}-nonce",
            ]),
         ];
         if (! $alternate) {
            $authorityScripts[] = $HTTP(201, 'Created', '{}', [
               'Content-Type' => 'application/json',
               'Location' => "{$authorityURL}/acct/1",
            ]);
         }
         $alternateScripts = $alternate
            ? [$HTTP(201, 'Created', '{}', [
               'Content-Type' => 'application/json',
               'Location' => "{$alternateURL}/acct/1",
            ])]
            : [];

         $authorityCapture = tempnam(sys_get_temp_dir(), "bootgly-m15-{$label}-a-");
         $alternateCapture = $alternate
            ? tempnam(sys_get_temp_dir(), "bootgly-m15-{$label}-b-")
            : null;
         if (
            ! is_string($authorityCapture)
            || ($alternate && ! is_string($alternateCapture))
         ) {
            @fclose($AuthorityListener);
            if (is_resource($AlternateListener)) {
               @fclose($AlternateListener);
            }
            if (is_string($authorityCapture)) {
               @unlink($authorityCapture);
            }
            if (is_string($alternateCapture)) {
               @unlink($alternateCapture);
            }
            foreach (glob("{$accountPath}*") ?: [] as $file) {
               @unlink($file);
            }
            @rmdir($accountPath);
            $result['fixture_error'] = 'fixture capture allocation failed';

            return $result;
         }

         $authorityMinimum = $alternate ? 1 : count($authorityScripts);
         $authorityPID = pcntl_fork();
         if ($authorityPID === 0) {
            if (is_resource($AlternateListener)) {
               @fclose($AlternateListener);
            }
            $Serve(
               $AuthorityListener,
               $authorityScripts,
               $authorityMinimum,
               $authorityCapture,
            );
         }

         $alternatePID = 0;
         if ($authorityPID > 0 && $alternate) {
            $alternatePID = pcntl_fork();
            if ($alternatePID === 0) {
               @fclose($AuthorityListener);
               $Serve(
                  $AlternateListener,
                  $alternateScripts,
                  0,
                  (string) $alternateCapture,
               );
            }
         }

         @fclose($AuthorityListener);
         if (is_resource($AlternateListener)) {
            @fclose($AlternateListener);
         }

         if ($authorityPID < 1 || ($alternate && $alternatePID < 1)) {
            $result['fixture_error'] = 'fixture could not fork its TLS peer(s)';
         }
         else {
            try {
               if ($policySupported) {
                  $Class = new ReflectionClass(ACME_Client::class);
                  $Client = $Class->newInstanceArgs([
                     $Account,
                     "{$authorityURL}/directory",
                     false,
                     30,
                     2.0,
                     null,
                     $allowed ? [$alternateURL] : [],
                     true,
                  ]);
               }
               else {
                  $Client = new ACME_Client(
                     $Account,
                     "{$authorityURL}/directory",
                     verify: false,
                  );
               }
               $result['registered_url'] = $Client->register(
                  'm15@example.test',
                  true,
               );
            }
            catch (Throwable $Throwable) {
               $result['client_error'] = [
                  'class' => $Throwable::class,
                  'message' => $Throwable->getMessage(),
               ];
            }
         }

         $authorityStatus = 0;
         if ($authorityPID > 0) {
            pcntl_waitpid($authorityPID, $authorityStatus);
            $result['authority_child_clean'] = pcntl_wifexited($authorityStatus)
               && pcntl_wexitstatus($authorityStatus) === 0;
         }
         $alternateStatus = 0;
         if ($alternatePID > 0) {
            pcntl_waitpid($alternatePID, $alternateStatus);
            $result['alternate_child_clean'] = pcntl_wifexited($alternateStatus)
               && pcntl_wexitstatus($alternateStatus) === 0;
         }

         $authorityJSON = file_get_contents($authorityCapture);
         $authorityData = is_string($authorityJSON)
            ? json_decode($authorityJSON, true)
            : null;
         $result['authority'] = is_array($authorityData)
            ? $authorityData
            : ['requests' => [], 'error' => 'invalid authority capture'];
         @unlink($authorityCapture);

         if (is_string($alternateCapture)) {
            $alternateJSON = file_get_contents($alternateCapture);
            $alternateData = is_string($alternateJSON)
               ? json_decode($alternateJSON, true)
               : null;
            $result['alternate'] = is_array($alternateData)
               ? $alternateData
               : ['requests' => [], 'error' => 'invalid alternate capture'];
            @unlink($alternateCapture);
         }

         $alternateRequests = $result['alternate']['requests'] ?? [];
         $signedBody = is_array($alternateRequests)
            && is_string($alternateRequests[0]['body'] ?? null)
            ? $alternateRequests[0]['body']
            : '';
         $decodedJWS = json_decode($signedBody, true);
         if ($alternate && is_array($decodedJWS) && $Account instanceof Account) {
            $protected = is_string($decodedJWS['protected'] ?? null)
               ? $decodedJWS['protected']
               : '';
            $payload = is_string($decodedJWS['payload'] ?? null)
               ? $decodedJWS['payload']
               : '';
            $signature = is_string($decodedJWS['signature'] ?? null)
               ? $decodedJWS['signature']
               : '';
            $header = json_decode($Unpack($protected), true);
            $decodedPayload = json_decode($Unpack($payload), true);
            $verified = $signature !== ''
               ? openssl_verify(
                  "{$protected}.{$payload}",
                  $Unpack($signature),
                  $Account->Key->derive(),
                  OPENSSL_ALGO_SHA256,
               )
               : false;
            $result['jws'] = [
               'structure' => array_keys($decodedJWS) === [
                  'protected',
                  'payload',
                  'signature',
               ],
               'algorithm' => is_array($header) ? ($header['alg'] ?? null) : null,
               'nonce' => is_array($header) ? ($header['nonce'] ?? null) : null,
               'url' => is_array($header) ? ($header['url'] ?? null) : null,
               'jwk_matches' => is_array($header)
                  && ($header['jwk'] ?? null) === $Account->JWK,
               'kid_absent' => is_array($header) && ! isset($header['kid']),
               'payload' => $decodedPayload,
               'signature_valid' => $verified === 1,
            ];
         }

         foreach (glob("{$accountPath}*") ?: [] as $file) {
            @unlink($file);
         }
         @rmdir($accountPath);

         return $result;
      };

      try {
         if (! function_exists('pcntl_fork') || ! function_exists('openssl_verify')) {
            throw new RuntimeException('M15 requires pcntl and OpenSSL support.');
         }

         $evidence['control'] = $Run(false);
         $evidence['attack'] = $Run(true);
         if ($policySupported) {
            $evidence['allowed'] = $Run(true, true);
         }
      }
      catch (Throwable $Throwable) {
         $probeError = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /m15/harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m15/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'M15-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$evidence, &$probeError): bool|string {
      if (! str_contains($response, 'M15-HARNESS-OK')) {
         return 'M15 HTTP harness did not complete after the ACME transport probe.';
      }
      if ($probeError !== '') {
         return 'M15 fixture failed: ' . $probeError;
      }

      $control = $evidence['control'] ?? [];
      $controlRequests = $control['authority']['requests'] ?? [];
      $controlURL = $control['authority_url'] ?? '';
      $controlHost = parse_url((string) $controlURL, PHP_URL_HOST);
      $controlPort = parse_url((string) $controlURL, PHP_URL_PORT);
      $controlAuthority = is_string($controlHost) && is_int($controlPort)
         ? "{$controlHost}:{$controlPort}"
         : '';
      $controlLines = is_array($controlRequests)
         ? array_column($controlRequests, 'line')
         : [];
      $controlHosts = is_array($controlRequests)
         ? array_column($controlRequests, 'host')
         : [];
      $controlValid = ($control['fixture_error'] ?? null) === null
         && ($control['client_error'] ?? null) === null
         && ($control['registered_url'] ?? null) === "{$controlURL}/acct/1"
         && ($control['authority_child_clean'] ?? false) === true
         && ($control['authority']['error'] ?? null) === ''
         && $controlLines === [
            'GET /directory HTTP/1.1',
            'HEAD /new-nonce HTTP/1.1',
            'POST /new-account HTTP/1.1',
         ]
         && $controlHosts === array_fill(0, 3, $controlAuthority);
      if ($controlValid === false) {
         Vars::$labels = ['M15 same-authority control evidence'];
         dump(json_encode($evidence));

         return 'M15 fixture did not complete the same-authority ACME registration control. Evidence: '
            . json_encode($evidence);
      }

      $allowed = $evidence['allowed'] ?? [];
      $allowedURL = (string) ($allowed['alternate_url'] ?? '');
      $allowedHost = parse_url($allowedURL, PHP_URL_HOST);
      $allowedPort = parse_url($allowedURL, PHP_URL_PORT);
      $allowedAuthority = is_string($allowedHost) && is_int($allowedPort)
         ? "{$allowedHost}:{$allowedPort}"
         : '';
      $allowedAuthorityRequests = $allowed['authority']['requests'] ?? [];
      $allowedAlternateRequests = $allowed['alternate']['requests'] ?? [];
      $allowedAuthorityLines = is_array($allowedAuthorityRequests)
         ? array_column($allowedAuthorityRequests, 'line')
         : [];
      $allowedAlternateLines = is_array($allowedAlternateRequests)
         ? array_column($allowedAlternateRequests, 'line')
         : [];
      $allowedJWS = $allowed['jws'] ?? [];
      $allowedPayload = is_array($allowedJWS['payload'] ?? null)
         ? $allowedJWS['payload']
         : [];
      $allowedValid = ($control['policy_supported'] ?? false) === false
         || ($allowed['fixture_error'] ?? null) === null
         && ($allowed['client_error'] ?? null) === null
         && ($allowed['registered_url'] ?? null) === "{$allowedURL}/acct/1"
         && ($allowed['authority_child_clean'] ?? false) === true
         && ($allowed['alternate_child_clean'] ?? false) === true
         && ($allowed['authority']['error'] ?? null) === ''
         && ($allowed['alternate']['error'] ?? null) === ''
         && $allowedAuthorityLines === [
            'GET /directory HTTP/1.1',
            'HEAD /new-nonce HTTP/1.1',
         ]
         && $allowedAlternateLines === ['POST /private/new-account HTTP/1.1']
         && ($allowedAlternateRequests[0]['host'] ?? null) === $allowedAuthority
         && ($allowedAlternateRequests[0]['content_type'] ?? null) === 'application/jose+json'
         && ($allowedJWS['structure'] ?? false) === true
         && ($allowedJWS['algorithm'] ?? null) === 'RS256'
         && ($allowedJWS['nonce'] ?? null) === 'm15-allowed-nonce'
         && ($allowedJWS['url'] ?? null) === "{$allowedURL}/private/new-account"
         && ($allowedJWS['jwk_matches'] ?? false) === true
         && ($allowedJWS['kid_absent'] ?? false) === true
         && ($allowedJWS['signature_valid'] ?? false) === true
         && ($allowedPayload['termsOfServiceAgreed'] ?? false) === true
         && ($allowedPayload['contact'] ?? null) === ['mailto:m15@example.test'];
      if ($allowedValid === false) {
         Vars::$labels = ['M15 explicitly allowed delegated-authority control'];
         dump(json_encode($evidence));

         return 'M15 fixture did not preserve an explicitly allowed delegated authority, original Host, and signed JWS URL.';
      }

      $attack = $evidence['attack'] ?? [];
      $authorityURL = (string) ($attack['authority_url'] ?? '');
      $alternateURL = (string) ($attack['alternate_url'] ?? '');
      $authorityRequests = $attack['authority']['requests'] ?? [];
      $alternateRequests = $attack['alternate']['requests'] ?? [];
      $authorityLines = is_array($authorityRequests)
         ? array_column($authorityRequests, 'line')
         : [];
      $alternateLines = is_array($alternateRequests)
         ? array_column($alternateRequests, 'line')
         : [];
      $attackControls = ($attack['fixture_error'] ?? null) === null
         && $authorityURL !== ''
         && $alternateURL !== ''
         && $authorityURL !== $alternateURL
         && ($attack['authority_child_clean'] ?? false) === true
         && ($attack['alternate_child_clean'] ?? false) === true
         && ($attack['authority']['error'] ?? null) === ''
         && ($attack['alternate']['error'] ?? null) === ''
         && ($authorityLines[0] ?? null) === 'GET /directory HTTP/1.1';
      if ($attackControls === false) {
         Vars::$labels = ['M15 alternate-authority fixture controls'];
         dump(json_encode($evidence));

         return 'M15 fixture did not prove distinct live TLS authorities and a configured-directory request.';
      }

      $JWS = $attack['jws'] ?? [];
      $payload = is_array($JWS['payload'] ?? null) ? $JWS['payload'] : [];
      $vulnerable = ($attack['client_error'] ?? null) === null
         && ($attack['registered_url'] ?? null) === "{$alternateURL}/acct/1"
         && $authorityLines === [
            'GET /directory HTTP/1.1',
            'HEAD /new-nonce HTTP/1.1',
         ]
         && $alternateLines === ['POST /private/new-account HTTP/1.1']
         && ($alternateRequests[0]['content_type'] ?? null) === 'application/jose+json'
         && ($JWS['structure'] ?? false) === true
         && ($JWS['algorithm'] ?? null) === 'RS256'
         && ($JWS['nonce'] ?? null) === 'm15-attack-nonce'
         && ($JWS['url'] ?? null) === "{$alternateURL}/private/new-account"
         && ($JWS['jwk_matches'] ?? false) === true
         && ($JWS['kid_absent'] ?? false) === true
         && ($JWS['signature_valid'] ?? false) === true
         && ($payload['termsOfServiceAgreed'] ?? false) === true
         && ($payload['contact'] ?? null) === ['mailto:m15@example.test'];
      if ($vulnerable) {
         Vars::$labels = ['M15 signed alternate-authority HTTPS SSRF evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M15: a CA-advertised alternate loopback authority received the account-key-signed newAccount POST.';
      }

      $secure = $alternateLines === []
         && ($attack['registered_url'] ?? null) === null
         && ($attack['client_error']['class'] ?? null) === ProtocolException::class;
      if ($secure === false) {
         Vars::$labels = ['M15 incomplete security evidence'];
         dump(json_encode($evidence));

         return 'M15 probe produced neither the complete signed authority escape nor a policy rejection before contacting the alternate authority.';
      }

      return true;
   },
);
