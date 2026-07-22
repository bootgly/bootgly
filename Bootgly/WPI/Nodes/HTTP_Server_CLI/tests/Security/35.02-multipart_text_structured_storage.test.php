<?php

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression M2 — retain raw field values once while preserving the
 * legacy parse_str() shape semantics for duplicates and bracketed names.
 */

$probe = [
   'error' => '',
   'raw_value_bytes' => 0,
   'stored_value_bytes' => -1,
   'stored_value_count' => -1,
   'encoded_metadata_bytes' => -1,
   'legacy_encoded_value_bytes' => 0,
   'aggregate_size' => -1,
   'pre_finish_state' => '',
   'pre_finish_consumed' => 0,
   'prefix_bytes' => 0,
   'final_state' => '',
   'final_consumed' => 0,
   'suffix_bytes' => 0,
   'rejected' => false,
   'shape_preserved' => false,
   'fields_after_finish' => -1,
   'metadata_after_finish' => -1,
   'aggregate_after_finish' => -1,
   'body_downloaded' => -1,
   'body_waiting' => true,
];

return new Specification(
   description: 'Multipart text storage must avoid value encoding and preserve field shapes',
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

         $Package = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };

         $bulk = str_repeat("\0", 256 * 1024);
         $pairs = [
            ['plain', 'first'],
            ['plain', 'last'],
            ['list[]', 'alpha'],
            ['list[]', 'beta'],
            ['nested[left]', 'L'],
            ['nested[right]', 'R'],
            ['indexed[2]', 'two'],
            ['indexed[]', 'three'],
            ['replace', 'scalar'],
            ['replace[key]', 'mapped'],
            ['bulk', $bulk],
         ];
         $expected = [
            'plain' => 'last',
            'list' => ['alpha', 'beta'],
            'nested' => ['left' => 'L', 'right' => 'R'],
            'indexed' => [2 => 'two', 3 => 'three'],
            'replace' => ['key' => 'mapped'],
            'bulk' => $bulk,
            'sentinel' => 'ok',
         ];

         $boundary = 'Bootgly-M2-Structured';
         $wireBoundary = '--' . $boundary;
         $prefix = '';

         foreach ($pairs as [$name, $value]) {
            $probe['raw_value_bytes'] += strlen($value);
            $probe['legacy_encoded_value_bytes'] += strlen(urlencode($value));
            $prefix .= $wireBoundary . "\r\n"
               . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
               . "\r\n"
               . $value . "\r\n";
         }

         $prefix .= $wireBoundary . "\r\n"
            . "Content-Disposition: form-data; name=\"sentinel\"\r\n"
            . "\r\n";
         $suffix = "ok\r\n" . $wireBoundary . "--\r\n";
         $prefixBytes = strlen($prefix);
         $suffixBytes = strlen($suffix);
         $probe['prefix_bytes'] = $prefixBytes;
         $probe['suffix_bytes'] = $suffixBytes;
         $Request->Body->length = $prefixBytes + $suffixBytes;

         $Decoder = new Decoder_Downloading;
         $Decoder->Request = $Request;
         $Decoder->init($wireBoundary);
         $Package->Decoder = $Decoder;
         $FieldsStorage = new ReflectionProperty(Decoder_Downloading::class, 'fields');
         $EncodedStorage = new ReflectionProperty(Decoder_Downloading::class, 'fieldsEncoded');
         $AggregateSize = new ReflectionProperty(Decoder_Downloading::class, 'fieldsSize');

         $offset = 0;
         $consumed = 0;
         $PreFinishState = States::Incomplete;
         while ($offset < $prefixBytes) {
            $segment = substr($prefix, $offset, 64 * 1024);
            $segmentBytes = strlen($segment);
            $PreFinishState = $Decoder->decode($Package, $segment, $segmentBytes);
            $consumed += $Package->consumed;
            $offset += $segmentBytes;

            if ($PreFinishState !== States::Incomplete) {
               break;
            }
         }

         $storedValues = $FieldsStorage->getValue($Decoder);
         $storedBytes = 0;
         if (is_array($storedValues)) {
            foreach ($storedValues as $storedValue) {
               if (is_string($storedValue)) {
                  $storedBytes += strlen($storedValue);
               }
            }
         }
         $encodedMetadata = $EncodedStorage->getValue($Decoder);
         $aggregateSize = $AggregateSize->getValue($Decoder);
         $probe['stored_value_bytes'] = $storedBytes;
         $probe['stored_value_count'] = is_array($storedValues) ? count($storedValues) : -1;
         $probe['encoded_metadata_bytes'] = is_string($encodedMetadata)
            ? strlen($encodedMetadata)
            : -1;
         $probe['aggregate_size'] = is_int($aggregateSize) ? $aggregateSize : -1;
         $probe['pre_finish_state'] = $PreFinishState->name;
         $probe['pre_finish_consumed'] = $consumed;

         $FinalState = $Decoder->decode($Package, $suffix, $suffixBytes);
         $probe['final_state'] = $FinalState->name;
         $probe['final_consumed'] = $Package->consumed;
         $probe['rejected'] = $Package->rejected;
         $probe['body_downloaded'] = $Request->Body->downloaded;
         $probe['body_waiting'] = $Request->Body->waiting;

         $RequestFields = new ReflectionProperty(Request::class, '_fields');
         $storedRequestFields = $RequestFields->getValue($Request);
         $probe['shape_preserved'] = $storedRequestFields === $expected;

         $storedValues = $FieldsStorage->getValue($Decoder);
         $encodedMetadata = $EncodedStorage->getValue($Decoder);
         $aggregateSize = $AggregateSize->getValue($Decoder);
         $probe['fields_after_finish'] = is_array($storedValues) ? count($storedValues) : -1;
         $probe['metadata_after_finish'] = is_string($encodedMetadata)
            ? strlen($encodedMetadata)
            : -1;
         $probe['aggregate_after_finish'] = is_int($aggregateSize) ? $aggregateSize : -1;
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

      return "GET /m2-structured-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m2-structured-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M2-STRUCTURED-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'M2-STRUCTURED-HARNESS-OK')) {
         return 'M2 structured-storage harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         Vars::$labels = ['M2 structured-storage probe'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if (
         $probe['pre_finish_state'] !== States::Incomplete->name
         || $probe['pre_finish_consumed'] !== $probe['prefix_bytes']
         || $probe['stored_value_bytes'] !== $probe['raw_value_bytes']
         || $probe['stored_value_count'] !== 11
         || $probe['aggregate_size'] !== $probe['raw_value_bytes']
         || $probe['encoded_metadata_bytes'] >= $probe['legacy_encoded_value_bytes']
      ) {
         Vars::$labels = ['M2 bounded structured-storage evidence'];
         dump(json_encode($probe));
         return 'Multipart field values were duplicated, encoded, or accounted incorrectly before finish.';
      }

      if (
         $probe['final_state'] !== States::Complete->name
         || $probe['final_consumed'] !== $probe['suffix_bytes']
         || $probe['rejected'] !== false
         || $probe['shape_preserved'] !== true
         || $probe['fields_after_finish'] !== 0
         || $probe['metadata_after_finish'] !== 0
         || $probe['aggregate_after_finish'] !== 0
         || $probe['body_downloaded'] !== ($probe['prefix_bytes'] + $probe['suffix_bytes'])
         || $probe['body_waiting'] !== false
      ) {
         Vars::$labels = ['M2 field-shape compatibility evidence'];
         dump(json_encode($probe));
         return 'Structured multipart storage changed field shapes or retained decoder state after finish.';
      }

      return true;
   },
);
