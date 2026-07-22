<?php

use const Bootgly\CLI;
use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\Stream;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\CLI\Command;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M13 — the operator `connections` diagnostic must not render
 * remote request buffers or concatenate decoded protocol objects.
 *
 * A real side connection sends two byte-identical requests. Both decode into
 * the connection-owned Request (the first a decoder-cache miss, the second a
 * template hit), so Connection::$decoded holds a live Request on each. Both
 * requests carry an Authorization secret, Bootgly markup, OSC-52 and DCS
 * bytes. The receive buffer is no longer retained per connection, so the
 * remote source is proven through the decoded Request headers the diagnostic
 * has access to. The route calls the registered command through the real
 * SIGIOT dispatch branch, while its production Line formatter writes
 * exclusively to php://temp. A separate throwing exact-scope command verifies
 * the fixed signal boundary.
 *
 * Expected secure behavior: only allowlisted scalar metadata is rendered,
 * no remote buffer/control byte reaches the diagnostic stream, and the
 * decoded Request is summarized without a Throwable.
 */
$secret = 'M13_AUTH_SECRET_7D93';
$OSC = chr(27) . ']52;c;' . base64_encode('M13 harmless clipboard probe') . chr(7);
$DCS = chr(27) . 'P$qm13' . chr(27) . '\\';
$markup = '@#Red: M13-REMOTE-STYLE@;';
$sideResponses = [];
$probeError = '';

return new Specification(
   description: 'connections diagnostic must not expose remote state, terminal controls, or crash on decoded objects',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (
      $secret,
      $OSC,
      $DCS,
      $markup,
      &$sideResponses,
      &$probeError,
   ): string {
      $bytes = "GET /m13/connections HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Authorization: Bearer {$secret}\r\n"
         . "X-M13-Terminal: {$OSC}{$DCS}\r\n"
         . "X-M13-Markup: {$markup}\r\n"
         . "\r\n";

      $Read = static function ($socket): string {
         $response = '';
         $expected = null;
         $deadline = microtime(true) + 3.0;

         while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }

               continue;
            }

            $response .= $chunk;
            $separator = strpos($response, "\r\n\r\n");
            if ($separator !== false && $expected === null) {
               $head = substr($response, 0, $separator + 2);
               if (preg_match('#\r\nContent-Length: ([0-9]+)\r\n#i', $head, $matches) === 1) {
                  $expected = $separator + 4 + (int) $matches[1];
               }
            }

            if ($expected !== null && strlen($response) >= $expected) {
               return substr($response, 0, $expected);
            }
         }

         return $response;
      };

      $socket = @stream_socket_client(
         "tcp://{$hostPort}",
         $errorNumber,
         $errorMessage,
         timeout: 5,
      );
      if (! is_resource($socket)) {
         $probeError = "Could not open M13 side connection: {$errorNumber} {$errorMessage}";

         return "GET /m13/harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      }

      stream_set_blocking($socket, true);
      stream_set_timeout($socket, 3);

      for ($index = 0; $index < 2; $index++) {
         if (@fwrite($socket, $bytes) !== strlen($bytes)) {
            $probeError = 'Could not write a complete M13 side request.';
            break;
         }

         $response = $Read($socket);
         if ($response === '') {
            $probeError = 'The M13 side connection returned no response.';
            break;
         }
         $sideResponses[] = $response;

         if (@feof($socket)) {
            $probeError = 'The M13 side connection closed before the cache-hit request.';
            break;
         }
      }

      @fclose($socket);

      return "GET /m13/harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) use (
      $secret,
      $OSC,
      $DCS,
      $markup,
   ) {
      yield $Router->route('/m13/connections', static function (
         Request $Request,
         Response $Response,
      ) use ($secret, $OSC, $DCS, $markup): Response {
         // ! Locate the live side connection by its transport peer port. The
         //   receive buffer is no longer retained per connection (the very
         //   hardening this diagnostic must honor), so the secret cannot be
         //   matched from a raw input buffer — the current request's peer
         //   port is the stable transport identity of the side connection.
         $Connection = null;
         foreach (Connections::$Connections as $Candidate) {
            if ($Candidate->port === $Request->port) {
               $Connection = $Candidate;
               break;
            }
         }

         if ($Connection === null) {
            return $Response->JSON->send([
               'phase' => 'attack',
               'fixture_error' => 'live request connection not found',
            ]);
         }

         // ! The remote source is proven present through the decoded Request
         //   headers the diagnostic can reach — a stronger control than the
         //   retired input buffer: it shows the diagnostic HAD the secret
         //   available and still rendered only allowlisted scalar metadata.
         $decoded = $Connection->decoded;
         $decodedRequest = $decoded instanceof Request;
         $sourceHeaders = $decodedRequest ? $decoded->headers : [];
         $authorization = (string) ($sourceHeaders['authorization'] ?? '');
         $terminal = (string) ($sourceHeaders['x-m13-terminal'] ?? '');
         $sourceMarkup = (string) ($sourceHeaders['x-m13-markup'] ?? '');
         $Server = WPI->Server;
         $sink = fopen('php://temp', 'w+b');
         if ($sink === false) {
            return $Response->JSON->send([
               'phase' => 'attack',
               'fixture_error' => 'capture stream unavailable',
            ]);
         }

         $SavedHandlers = $Server->Logger->Handlers;
         $SavedConnections = Connections::$Connections;
         $SavedTap = Logger::$Tap;
         $savedDisplay = Display::$segments;
         $Handlers = new Handlers;
         $Handlers->push(new Stream($sink));
         $Server->Logger->Handlers = $Handlers;
         Logger::$Tap = null;
         // ! Isolate the real target connection so unrelated live harness
         //   connections cannot terminate the diagnostic before this source.
         Connections::$Connections = [$Connection->id => $Connection];
         Display::show(Display::MESSAGE);

         $commandReturned = false;
         $errorClass = '';
         $objectConversionError = false;
         $signalFaultExecuted = false;
         $signalFaultContained = false;
         try {
            try {
               $Server->handle(SIGIOT);
               $commandReturned = true;
            }
            catch (Throwable $Throwable) {
               $errorClass = $Throwable::class;
               $objectConversionError = $Throwable instanceof Error
                  && str_contains($Throwable->getMessage(), 'could not be converted to string');
            }

            // ! Exercise the outer SIGIOT containment independently of the
            //   now-total production command. The cloned same-class scope also
            //   proves that exact command selection reaches this test double.
            $SignalServer = clone $Server;
            $ThrowingCommand = new class extends Command
            {
               public string $name = 'connections';
               public string $description = 'M13 signal containment probe';
               public bool $executed = false;


               public function run (array $arguments = [], array $options = []): bool
               {
                  $this->executed = true;

                  throw new Error('M13 fixed signal containment probe');
               }
            };
            CLI->Commands->register($ThrowingCommand, $SignalServer);

            try {
               $SignalServer->handle(SIGIOT);
               $signalFaultContained = true;
            }
            catch (Throwable) {
               // @ Pre-fix confirmation remains observable without terminating
               //   the suite worker; the secure branch requires containment.
            }
            $signalFaultExecuted = $ThrowingCommand->executed;
         }
         finally {
            Connections::$Connections = $SavedConnections;
            $Server->Logger->Handlers = $SavedHandlers;
            Logger::$Tap = $SavedTap;
            Display::show($savedDisplay);
         }

         rewind($sink);
         $capture = (string) stream_get_contents($sink);
         fclose($sink);

         $markupMarker = str_contains($capture, 'M13-REMOTE-STYLE');

         return $Response->JSON->send([
            'phase' => 'attack',
            'connection_registered' => isset($SavedConnections[$Connection->id])
               && $SavedConnections[$Connection->id] === $Connection,
            'source_secret' => str_contains($authorization, $secret),
            'source_osc' => str_contains($terminal, $OSC),
            'source_dcs' => str_contains($terminal, $DCS),
            'source_markup' => str_contains($sourceMarkup, $markup),
            'decoded_request' => $decodedRequest,
            'diagnostic_rendered' => str_contains($capture, 'Worker #')
               && str_contains($capture, 'Connection ID #'),
            'diagnostic_complete' => str_contains(
               $capture,
               'Connections diagnostic complete.',
            ),
            'secret_leaked' => str_contains($capture, $secret),
            'osc_leaked' => str_contains($capture, $OSC),
            'dcs_leaked' => str_contains($capture, $DCS),
            'markup_marker_leaked' => $markupMarker,
            'markup_processed' => $markupMarker && ! str_contains($capture, $markup),
            'command_returned' => $commandReturned,
            'error_class' => $errorClass,
            'object_conversion_error' => $objectConversionError,
            'signal_fault_executed' => $signalFaultExecuted,
            'signal_fault_contained' => $signalFaultContained,
            'capture_bytes' => strlen($capture),
         ]);
      }, GET);

      yield $Router->route('/m13/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'M13-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$sideResponses, &$probeError): bool|string {
      if (! str_contains($response, 'M13-HARNESS-OK')) {
         return 'M13 harness request did not complete after the diagnostic probe.';
      }
      if ($probeError !== '') {
         return 'M13 fixture failed: ' . $probeError;
      }
      if (count($sideResponses) !== 2) {
         return 'M13 fixture did not receive both byte-identical side responses.';
      }

      $Decode = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($wire, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      $miss = $Decode($sideResponses[0]);
      $hit = $Decode($sideResponses[1]);
      $evidence = [
         'cache_miss' => $miss,
         'cache_hit' => $hit,
      ];

      if (! is_array($miss) || ! is_array($hit)) {
         Vars::$labels = ['M13 safe fixture evidence'];
         dump(json_encode($evidence));

         return 'M13 side responses did not contain the expected JSON evidence.';
      }

      $sourceControls = ($miss['connection_registered'] ?? false) === true
         && ($hit['connection_registered'] ?? false) === true
         && ($miss['source_secret'] ?? false) === true
         && ($hit['source_secret'] ?? false) === true
         && ($miss['source_osc'] ?? false) === true
         && ($hit['source_osc'] ?? false) === true
         && ($miss['source_dcs'] ?? false) === true
         && ($hit['source_dcs'] ?? false) === true
         && ($miss['source_markup'] ?? false) === true
         && ($hit['source_markup'] ?? false) === true
         && ($miss['diagnostic_rendered'] ?? false) === true
         && ($hit['diagnostic_rendered'] ?? false) === true
         && ($miss['decoded_request'] ?? false) === true
         && ($hit['decoded_request'] ?? false) === true;
      if ($sourceControls === false) {
         Vars::$labels = ['M13 source/cache/diagnostic controls'];
         dump(json_encode($evidence));

         return 'M13 fixture did not prove the remote source, diagnostic sink, and connection-owned decoded Request.';
      }

      // ! Regression signature — the connection now owns its decoded Request
      //   on both requests, so a vulnerable diagnostic would either LEAK the
      //   remote secret / terminal bytes / markup or CRASH converting the
      //   decoded object to string. Either on either request fails closed.
      $leakedAny = ($miss['secret_leaked'] ?? false) === true
         || ($hit['secret_leaked'] ?? false) === true
         || ($miss['osc_leaked'] ?? false) === true
         || ($hit['osc_leaked'] ?? false) === true
         || ($miss['dcs_leaked'] ?? false) === true
         || ($hit['dcs_leaked'] ?? false) === true
         || ($miss['markup_marker_leaked'] ?? false) === true
         || ($hit['markup_marker_leaked'] ?? false) === true;
      $crashedOnDecoded = ($miss['object_conversion_error'] ?? false) === true
         || ($hit['object_conversion_error'] ?? false) === true;

      if ($leakedAny || $crashedOnDecoded) {
         Vars::$labels = ['M13 vulnerability evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M13: the registered connections diagnostic exposed remote request state (Authorization secret / OSC / DCS / Bootgly markup) or threw Error on the connection-owned decoded Request.';
      }

      $secure = ($miss['command_returned'] ?? false) === true
         && ($hit['command_returned'] ?? false) === true
         && ($miss['diagnostic_complete'] ?? false) === true
         && ($hit['diagnostic_complete'] ?? false) === true
         && ($miss['signal_fault_executed'] ?? false) === true
         && ($hit['signal_fault_executed'] ?? false) === true
         && ($miss['signal_fault_contained'] ?? false) === true
         && ($hit['signal_fault_contained'] ?? false) === true
         && ($miss['error_class'] ?? '') === ''
         && ($hit['error_class'] ?? '') === ''
         && ($miss['secret_leaked'] ?? true) === false
         && ($hit['secret_leaked'] ?? true) === false
         && ($miss['osc_leaked'] ?? true) === false
         && ($hit['osc_leaked'] ?? true) === false
         && ($miss['dcs_leaked'] ?? true) === false
         && ($hit['dcs_leaked'] ?? true) === false
         && ($miss['markup_marker_leaked'] ?? true) === false
         && ($hit['markup_marker_leaked'] ?? true) === false;

      if ($secure === false) {
         Vars::$labels = ['M13 incomplete security evidence'];
         dump(json_encode($evidence));

         return 'M13 probe produced neither the complete vulnerable path nor the required safe diagnostic behavior.';
      }

      return true;
   },
);
