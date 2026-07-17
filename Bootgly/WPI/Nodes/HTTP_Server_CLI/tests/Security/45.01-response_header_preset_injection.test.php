<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M11 — Header::preset() must reject CR/LF bytes in field
 * names and values before they enter its worker-persistent preset map.
 *
 * A valid query-derived preset is the positive control. Name and value
 * injection are exercised independently, each followed by a request that
 * performs no mutation and therefore proves cross-request persistence. Both
 * malicious presets are explicitly removed before the case ends so a failing
 * pre-fix run cannot contaminate later Security cases.
 */
return new Specification(
   description: 'Response header presets must reject persistent CRLF injection',
   Separator: new Separator(line: true),

   requests: [
      static function (): string {
         return "GET /m11/preset?mode=control-seed&name=X-M11-Control&value=benign HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
      static function (): string {
         return 'GET /m11/preset?mode=name-seed'
            . '&name=X-M11-Seed%3A%20benign%0D%0ASet-Cookie'
            . '&value=m11-name%3Dattacker%3B%20Path%3D%2F'
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return "GET /m11/preset?mode=name-follow HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return 'GET /m11/preset?mode=name-clean'
            . '&name=X-M11-Seed%3A%20benign%0D%0ASet-Cookie'
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return 'GET /m11/preset?mode=value-seed&name=X-M11-Value'
            . '&value=benign%0D%0ACache-Control%3A%20public'
            . '%0D%0AX-M11-Injected%3A%20yes'
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return "GET /m11/preset?mode=value-follow HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function (): string {
         return "GET /m11/preset?mode=value-clean&name=X-M11-Value HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m11/preset', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Header = $Response->Header;
         $mode = $Request->query('mode');
         $mutated = null;

         switch ($mode) {
            case 'control-seed':
            case 'name-seed':
            case 'value-seed':
               $name = $Request->query('name');
               $value = $Request->query('value');
               $before = $Header->preset;
               $Header->preset($name, $value);
               $mutated = $Header->preset !== $before;
               break;

            case 'name-clean':
               $Header->preset($Request->query('name'), null);
               break;

            case 'value-clean':
               $Header->preset($Request->query('name'), null);
               $Header->preset('X-M11-Control', null);
               break;
         }

         $mutation = $mutated === null ? '' : ':mutated=' . ($mutated ? 'yes' : 'no');

         return $Response(body: "M11-{$mode}{$mutation}");
      }, GET);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 7) {
         return 'M11 probe did not receive all seven preset responses.';
      }

      $heads = [];
      $bodies = [];
      foreach ($responses as $index => $response) {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return 'M11 probe did not receive a complete HTTP response head.';
         }

         if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
            Vars::$labels = ['M11 non-200 response'];
            dump(json_encode(['request' => $index + 1, 'wire' => $response]));

            return 'M11 fixture failed: one preset request did not receive HTTP 200.';
         }

         $heads[] = substr($response, 0, $separator);
         $bodies[] = substr($response, $separator + 4);
      }

      $Line = static function (string $head, string $line): bool {
         return str_contains("\r\n{$head}\r\n", "\r\n{$line}\r\n");
      };
      $Prefix = static function (string $head, string $prefix): bool {
         foreach (explode("\r\n", $head) as $line) {
            if (str_starts_with($line, $prefix)) {
               return true;
            }
         }

         return false;
      };

      if (
         $bodies[0] !== 'M11-control-seed:mutated=yes'
         || $Line($heads[0], 'X-M11-Control: benign') === false
      ) {
         Vars::$labels = ['M11 valid-preset control evidence'];
         dump(json_encode(['body' => $bodies[0], 'head' => $heads[0]]));

         return 'M11 control failed: a valid query-derived preset was not stored and serialized.';
      }

      foreach ([1, 2, 3, 4, 5] as $index) {
         if ($Line($heads[$index], 'X-M11-Control: benign') === false) {
            Vars::$labels = ['M11 persistent-worker control evidence'];
            dump(json_encode(['request' => $index + 1, 'head' => $heads[$index]]));

            return 'M11 control failed: the benign preset did not persist across worker responses.';
         }
      }

      $nameCurrent = $Line($heads[1], 'X-M11-Seed: benign')
         && $Line($heads[1], 'Set-Cookie: m11-name=attacker; Path=/');
      $nameFollow = $Line($heads[2], 'X-M11-Seed: benign')
         && $Line($heads[2], 'Set-Cookie: m11-name=attacker; Path=/');
      $nameResidueCurrent = $Prefix($heads[1], 'X-M11-Seed')
         || $Line($heads[1], 'Set-Cookie: m11-name=attacker; Path=/');
      $nameResidueFollow = $Prefix($heads[2], 'X-M11-Seed')
         || $Line($heads[2], 'Set-Cookie: m11-name=attacker; Path=/');
      $nameClean = $Prefix($heads[3], 'X-M11-Seed')
         || $Line($heads[3], 'Set-Cookie: m11-name=attacker; Path=/');

      $valueCurrent = $Line($heads[4], 'X-M11-Value: benign')
         && $Line($heads[4], 'Cache-Control: public')
         && $Line($heads[4], 'X-M11-Injected: yes');
      $valueFollow = $Line($heads[5], 'X-M11-Value: benign')
         && $Line($heads[5], 'Cache-Control: public')
         && $Line($heads[5], 'X-M11-Injected: yes');
      $valueResidueCurrent = $Prefix($heads[4], 'X-M11-Value:')
         || $Line($heads[4], 'Cache-Control: public')
         || $Line($heads[4], 'X-M11-Injected: yes');
      $valueResidueFollow = $Prefix($heads[5], 'X-M11-Value:')
         || $Line($heads[5], 'Cache-Control: public')
         || $Line($heads[5], 'X-M11-Injected: yes');
      $valueClean = $Prefix($heads[6], 'X-M11-Value:')
         || $Line($heads[6], 'Cache-Control: public')
         || $Line($heads[6], 'X-M11-Injected: yes');
      $controlClean = $Line($heads[6], 'X-M11-Control: benign');

      $evidence = [
         'name_seed_body' => $bodies[1],
         'name_current' => $nameCurrent,
         'name_follow' => $nameFollow,
         'name_residue_current' => $nameResidueCurrent,
         'name_residue_follow' => $nameResidueFollow,
         'name_clean' => $nameClean,
         'value_seed_body' => $bodies[4],
         'value_current' => $valueCurrent,
         'value_follow' => $valueFollow,
         'value_residue_current' => $valueResidueCurrent,
         'value_residue_follow' => $valueResidueFollow,
         'value_clean' => $valueClean,
         'control_clean' => $controlClean,
         'name_seed_head' => $heads[1],
         'name_follow_head' => $heads[2],
         'value_seed_head' => $heads[4],
         'value_follow_head' => $heads[5],
      ];

      if ($nameClean || $valueClean || $controlClean) {
         Vars::$labels = ['M11 cleanup evidence'];
         dump(json_encode($evidence));

         return 'M11 fixture failed: explicit preset cleanup did not restore a clean worker.';
      }

      if (
         $bodies[1] === 'M11-name-seed:mutated=yes'
         && $bodies[4] === 'M11-value-seed:mutated=yes'
         && $nameCurrent
         && $nameFollow
         && $valueCurrent
         && $valueFollow
      ) {
         Vars::$labels = ['M11 persistent response-header injection evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M11: query-derived CRLF in preset names and values injected response fields and persisted into the next worker response.';
      }

      $secure = $bodies[1] === 'M11-name-seed:mutated=no'
         && $bodies[4] === 'M11-value-seed:mutated=no'
         && $nameResidueCurrent === false
         && $nameResidueFollow === false
         && $valueResidueCurrent === false
         && $valueResidueFollow === false;

      if ($secure === false) {
         Vars::$labels = ['M11 incomplete preset-validation evidence'];
         dump(json_encode($evidence));

         return 'M11 probe produced neither complete persistent injection nor atomic rejection of both invalid presets.';
      }

      return true;
   },
);
