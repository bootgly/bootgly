<?php

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M2 — multipart text data needs an aggregate memory cap.
 *
 * Every field is exactly at the existing 1 MiB per-field boundary. Eleven
 * fields therefore exceed the normal 10 MiB body allowance while remaining
 * far below the 500 MiB multipart allowance. NUL values force urlencode() to
 * retain a deterministic three-byte `%00` representation for every raw byte.
 * The body is decoded through 64 KiB reads to model the transport ceiling.
 */

$probe = [
   'error' => '',
   'body_limit' => Request::$maxBodySize,
   'multipart_limit' => Request::$maxFileSize,
   'per_field_limit' => Request::$maxMultipartFieldSize,
   'field_count' => 0,
   'raw_field_bytes' => 0,
   'multipart_bytes' => 0,
   'prefix_bytes' => 0,
   'suffix_bytes' => 0,
   'transport_read_bytes' => 64 * 1024,
   'reads_completed' => 0,
   'pre_finish_state' => '',
   'pre_finish_consumed' => 0,
   'encoded_storage' => '',
   'post_encoded_bytes' => -1,
   'encoded_ratio' => 0.0,
   'decode_peak_growth' => -1,
   'finish_peak_growth' => -1,
   'final_state' => '',
   'final_consumed' => 0,
   'rejected' => false,
   'rejection' => '',
   'post_encoded_after_finish' => -1,
   'field_values_after_reject' => -1,
   'aggregate_size_after_reject' => -1,
   'decoded_field_count' => 0,
   'attack_fields_decoded' => 0,
   'decoded_field_bytes' => 0,
   'sentinel_decoded' => false,
   'body_downloaded' => -1,
   'body_waiting' => true,
];

return new Specification(
   description: 'Multipart text fields must have an aggregate memory cap before URL encoding',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $WPI = WPI;
      $OldRequest = $WPI->Request;
      $Decoder = null;

      try {
         $Request = new Request;
         $Request->Body->downloaded = 0;
         $Request->Body->waiting = true;
         $Request->Body->streaming = true;
         $WPI->Request = $Request;

         /** @var Connection $Connection */
         $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         $Package = new class($Connection) extends TCPPackages {
            public string $rejection = '';

            public function __construct (Connection $Connection)
            {
               parent::__construct($Connection);
            }

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };

         $boundary = 'Bootgly-M2-Aggregate';
         $wireBoundary = '--' . $boundary;
         $valueSize = Request::$maxMultipartFieldSize;
         $fieldCount = intdiv(Request::$maxBodySize, $valueSize) + 1;
         $value = str_repeat("\0", $valueSize);
         $prefix = '';

         for ($index = 0; $index < $fieldCount; $index++) {
            $prefix .= $wireBoundary . "\r\n"
               . "Content-Disposition: form-data; name=\"f{$index}\"\r\n"
               . "\r\n"
               . $value . "\r\n";
         }

         // Leave one small field open. This exposes postEncoded after every
         // attack field has been accepted but before finish() calls parse_str().
         $prefix .= $wireBoundary . "\r\n"
            . "Content-Disposition: form-data; name=\"sentinel\"\r\n"
            . "\r\n";
         $suffix = "ok\r\n" . $wireBoundary . "--\r\n";

         $prefixBytes = strlen($prefix);
         $suffixBytes = strlen($suffix);
         $rawFieldBytes = $fieldCount * $valueSize;
         $probe['field_count'] = $fieldCount;
         $probe['raw_field_bytes'] = $rawFieldBytes;
         $probe['multipart_bytes'] = $prefixBytes + $suffixBytes;
         $probe['prefix_bytes'] = $prefixBytes;
         $probe['suffix_bytes'] = $suffixBytes;
         $Request->Body->length = $probe['multipart_bytes'];

         $Decoder = new Decoder_Downloading;
         $Decoder->init($wireBoundary);
         $Package->Decoder = $Decoder;
         $encodedStorage = property_exists(Decoder_Downloading::class, 'postEncoded')
            ? 'postEncoded'
            : 'fieldsEncoded';
         $Encoded = new ReflectionProperty(Decoder_Downloading::class, $encodedStorage);
         $FieldValues = property_exists(Decoder_Downloading::class, 'fields')
            ? new ReflectionProperty(Decoder_Downloading::class, 'fields')
            : null;
         $AggregateSize = property_exists(Decoder_Downloading::class, 'fieldsSize')
            ? new ReflectionProperty(Decoder_Downloading::class, 'fieldsSize')
            : null;
         $probe['encoded_storage'] = $encodedStorage;

         gc_collect_cycles();
         memory_reset_peak_usage();
         $decodeBaseline = memory_get_usage(false);
         $offset = 0;
         $consumed = 0;
         $PreFinishState = States::Incomplete;

         while ($offset < $prefixBytes) {
            $segment = substr($prefix, $offset, $probe['transport_read_bytes']);
            $segmentBytes = strlen($segment);
            $PreFinishState = $Decoder->decode($Package, $segment, $segmentBytes);
            $consumed += $Package->consumed;
            $probe['reads_completed']++;
            $offset += $segmentBytes;

            if ($PreFinishState !== States::Incomplete) {
               break;
            }
         }

         $encoded = $Encoded->getValue($Decoder);
         $postEncodedBytes = is_string($encoded) ? strlen($encoded) : -1;
         $probe['pre_finish_state'] = $PreFinishState->name;
         $probe['pre_finish_consumed'] = $consumed;
         $probe['post_encoded_bytes'] = $postEncodedBytes;
         $probe['encoded_ratio'] = $rawFieldBytes > 0
            ? round($postEncodedBytes / $rawFieldBytes, 6)
            : 0.0;
         $probe['decode_peak_growth'] = memory_get_peak_usage(false) - $decodeBaseline;

         $FinalState = $PreFinishState;
         if ($PreFinishState === States::Incomplete) {
            unset($prefix, $segment, $value);
            gc_collect_cycles();
            memory_reset_peak_usage();
            $finishBaseline = memory_get_usage(false);
            $FinalState = $Decoder->decode($Package, $suffix, $suffixBytes);
            $probe['finish_peak_growth'] = memory_get_peak_usage(false) - $finishBaseline;
            $probe['final_consumed'] = $Package->consumed;
         }

         $encoded = $Encoded->getValue($Decoder);
         $probe['post_encoded_after_finish'] = is_string($encoded) ? strlen($encoded) : -1;
         if ($FieldValues instanceof ReflectionProperty) {
            $storedValues = $FieldValues->getValue($Decoder);
            $probe['field_values_after_reject'] = is_array($storedValues)
               ? count($storedValues)
               : -1;
         }
         if ($AggregateSize instanceof ReflectionProperty) {
            $aggregateSize = $AggregateSize->getValue($Decoder);
            $probe['aggregate_size_after_reject'] = is_int($aggregateSize)
               ? $aggregateSize
               : -1;
         }
         $probe['final_state'] = $FinalState->name;
         $probe['rejected'] = $Package->rejected;
         $probe['rejection'] = $Package->rejection;
         $probe['body_downloaded'] = $Request->Body->downloaded;
         $probe['body_waiting'] = $Request->Body->waiting;

         $Fields = new ReflectionProperty(Request::class, '_fields');
         $storedFields = $Fields->getValue($Request);
         if (! is_array($storedFields)) {
            $storedFields = [];
         }
         $probe['decoded_field_count'] = count($storedFields);
         $probe['sentinel_decoded'] = ($storedFields['sentinel'] ?? null) === 'ok';

         for ($index = 0; $index < $fieldCount; $index++) {
            $field = $storedFields["f{$index}"] ?? null;
            if (! is_string($field)) {
               continue;
            }

            $probe['attack_fields_decoded']++;
            $probe['decoded_field_bytes'] += strlen($field);
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if ($Decoder instanceof Decoder_Downloading) {
            $Decoder->disconnect();
         }
         $WPI->Request = $OldRequest;
      }

      return "GET /m2-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m2-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M2-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'M2-HARNESS-OK')) {
         return 'M2 harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         Vars::$labels = ['M2 aggregate text-field probe'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      // Fixed behavior: reject the aggregate before retaining its encoded form.
      if (
         $probe['pre_finish_state'] === States::Rejected->name
         && $probe['rejected'] === true
         && str_contains($probe['rejection'], '413 Request Entity Too Large')
         && $probe['post_encoded_bytes'] === 0
         && $probe['field_values_after_reject'] === 0
         && $probe['aggregate_size_after_reject'] === 0
         && $probe['body_waiting'] === false
      ) {
         return true;
      }

      if (
         $probe['raw_field_bytes'] > $probe['body_limit']
         && $probe['multipart_bytes'] < $probe['multipart_limit']
         && $probe['pre_finish_state'] === States::Incomplete->name
         && $probe['pre_finish_consumed'] === $probe['prefix_bytes']
         && $probe['post_encoded_bytes'] >= ($probe['raw_field_bytes'] * 3)
         && $probe['final_state'] === States::Complete->name
         && $probe['final_consumed'] === $probe['suffix_bytes']
         && $probe['rejected'] === false
         && $probe['rejection'] === ''
         && $probe['post_encoded_after_finish'] === 0
         && $probe['attack_fields_decoded'] === $probe['field_count']
         && $probe['decoded_field_bytes'] === $probe['raw_field_bytes']
         && $probe['decoded_field_count'] === ($probe['field_count'] + 1)
         && $probe['sentinel_decoded'] === true
         && $probe['body_downloaded'] === $probe['multipart_bytes']
         && $probe['body_waiting'] === false
      ) {
         Vars::$labels = ['M2 aggregate text-field amplification evidence'];
         dump(json_encode($probe));
         return 'CONFIRMED M2: multipart accepted text bytes above the normal body cap, '
            . 'retained an approximately 3x URL-encoded intermediate, and then materialized '
            . 'the complete decoded field map; evidence=' . json_encode($probe);
      }

      Vars::$labels = ['M2 unexpected aggregate text-field outcome'];
      dump(json_encode($probe));
      return 'The aggregate multipart text-field path was neither rejected safely nor reproduced exactly.';
   },
);
