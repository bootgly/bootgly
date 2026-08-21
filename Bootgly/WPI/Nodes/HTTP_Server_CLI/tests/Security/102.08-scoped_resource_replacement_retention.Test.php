<?php

use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M4 — replacing a same-name scoped resource must release the old one.
 *
 * Resources::set() overwrites resources[$name], but attach() independently adds
 * every persistent scoped instance to the private Scoped map. No replacement
 * path removes the displaced instance. Because the server reuses one Response
 * for the worker lifetime, remotely invoking an application route which mounts
 * a fresh same-name scoped resource retains every old object. Response::reset()
 * then calls clean() on the entire historical set before every later request,
 * producing both linear memory retention and quadratic aggregate cleanup work.
 *
 * This is conditional application surface, not a claim that request bytes can
 * invent resource objects: the deployment must expose a route using the public
 * mount API per request (a natural pattern for request-configured resources).
 * Once that route exists, each unauthenticated GET deterministically advances
 * the framework-owned leak.
 *
 * Controls prove that normal persistent built-in lookup is stable, every attack
 * request really mounted a new object, the public name resolves to the newest
 * object, and scoped clean() still runs. A secure replacement retains one marker,
 * cleans that live marker at each request boundary, and releases each displaced
 * final alias once — bounded linear work, never the historical quadratic scan.
 */
$requestsCount = 32;
$Probe = new class {
   public bool $stable = false;
   public int $mounts = 0;
   public int $cleans = 0;
   public int $latest = 0;
   public string $class = '';
};

$Requests = [
   static fn (): string => "GET /m4-scoped/setup HTTP/1.1\r\n"
      . "Host: localhost\r\n\r\n",
];
for ($index = 0; $index < $requestsCount; $index++) {
   $Requests[] = static fn (): string => "GET /m4-scoped/replace HTTP/1.1\r\n"
      . "Host: localhost\r\n\r\n";
}
$Requests[] = static fn (): string => "GET /m4-scoped/evidence HTTP/1.1\r\n"
   . "Host: localhost\r\nConnection: close\r\n\r\n";

return new Test(
   description: 'Same-name scoped resource replacement must not retain historical instances',
   Separator: new Separator(line: true),
   requests: $Requests,

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe): Generator {
      yield $Router->route('/m4-scoped/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $ViewA = $Response->View;
         $ViewB = $Response->View;
         $Probe->stable = $ViewA === $ViewB;

         return $Response(body: 'M4-SCOPED-SETUP');
      }, GET);

      yield $Router->route('/m4-scoped/replace', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Resource = new class($Probe) extends Resource {
            public function __construct (private object $Probe)
            {
               parent::__construct(persistent: true, scoped: true);
            }

            public function clean (): void
            {
               $this->Probe->cleans++;
            }
         };

         $Mounted = $Response->mount($Resource, 'M4Scoped');
         $Probe->mounts++;
         $Probe->latest = spl_object_id($Mounted);
         $Probe->class = $Mounted::class;

         return $Response(body: 'M4-SCOPED-REPLACED');
      }, GET);

      yield $Router->route('/m4-scoped/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Registry = $Response->Resources;
         $ScopedProperty = new ReflectionProperty($Registry, 'Scoped');
         /** @var array<int,Resource> $Scoped */
         $Scoped = $ScopedProperty->getValue($Registry);

         $markers = 0;
         foreach ($Scoped as $Resource) {
            if ($Resource::class === $Probe->class) {
               $markers++;
            }
         }

         $Current = $Registry->resources['M4Scoped'] ?? null;
         $evidence = [
            'stable_builtin' => $Probe->stable,
            'mounts' => $Probe->mounts,
            'cleans' => $Probe->cleans,
            'scoped_markers' => $markers,
            'name_points_to_latest' => $Current instanceof Resource
               && spl_object_id($Current) === $Probe->latest,
         ];

         // Validation-only cleanup: preserve the measured evidence, then remove
         // only this case's anonymous marker class from both ownership maps so
         // later Security cases do not inherit the vulnerability we reproduced.
         $CleanScoped = $Scoped;
         foreach ($CleanScoped as $ID => $Resource) {
            if ($Resource::class === $Probe->class) {
               unset($CleanScoped[$ID]);
            }
         }
         $ScopedProperty->setValue($Registry, $CleanScoped);

         $ResourcesProperty = new ReflectionProperty($Registry, 'resources');
         /** @var array<string,Resource> $Resources */
         $Resources = $ResourcesProperty->getValue($Registry);
         $Marker = $Resources['M4Scoped'] ?? null;
         if ($Marker instanceof Resource && $Marker::class === $Probe->class) {
            unset($Resources['M4Scoped']);
         }
         $ResourcesProperty->setValue($Registry, $Resources);

         $evidence['cleanup_markers'] = count($CleanScoped) - count(array_filter(
            $CleanScoped,
            static fn (Resource $Resource): bool => $Resource::class !== $Probe->class,
         ));
         $evidence['cleanup_name_removed'] = isset($Resources['M4Scoped']) === false;

         return $Response(body: 'M4-SCOPED:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($requestsCount): bool|string {
      if (count($responses) !== $requestsCount + 2) {
         return 'M4 scoped-resource fixture expected ' . ($requestsCount + 2)
            . ' responses, got ' . count($responses) . '.';
      }
      if (str_contains($responses[0] ?? '', 'M4-SCOPED-SETUP') === false) {
         return 'M4 scoped-resource built-in control route did not run.';
      }
      for ($index = 1; $index <= $requestsCount; $index++) {
         if (str_contains($responses[$index] ?? '', 'M4-SCOPED-REPLACED') === false) {
            return "M4 scoped-resource replacement request {$index} did not run.";
         }
      }

      $wire = $responses[$requestsCount + 1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'M4-SCOPED:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      if (is_array($evidence) === false) {
         return 'M4 scoped-resource evidence was not valid JSON: ' . json_encode($body);
      }

      if (
         ($evidence['stable_builtin'] ?? null) !== true
         || ($evidence['mounts'] ?? null) !== $requestsCount
         || ($evidence['name_points_to_latest'] ?? null) !== true
         || ($evidence['cleans'] ?? 0) < $requestsCount
         || ($evidence['scoped_markers'] ?? 0) < 1
         || ($evidence['cleanup_markers'] ?? null) !== 0
         || ($evidence['cleanup_name_removed'] ?? null) !== true
      ) {
         return 'M4 scoped-resource controls did not prove stable lookup, every '
            . 'replacement, newest-name ownership and scoped cleanup: '
            . json_encode($evidence);
      }

      $markers = (int) $evidence['scoped_markers'];
      $cleans = (int) $evidence['cleans'];
      $vulnerableCleans = intdiv($requestsCount * ($requestsCount + 1), 2);
      $boundedCleans = (2 * $requestsCount) - 1;
      if ($markers === $requestsCount && $cleans === $vulnerableCleans) {
         return 'CONFIRMED M4: ' . $requestsCount . ' remotely triggered same-name '
            . "mounts left {$markers} scoped objects retained while only one remained "
            . "addressable, and Response::reset invoked {$cleans} cumulative cleanups "
            . '(one continuously live object needs at least ' . $requestsCount . '). Repetition grows '
            . 'worker memory linearly and cleanup work quadratically. Evidence: '
            . json_encode($evidence);
      }
      if ($markers > 1 || $cleans > $boundedCleans) {
         return 'M4 unsafe behavior retained displaced same-name scoped resources outside '
            . 'the exact confirmed linear/quadratic shape: ' . json_encode($evidence);
      }
      if ($markers !== 1 || $cleans !== $boundedCleans) {
         return 'M4 replacement did not perform exactly one bounded cleanup for each '
            . 'request boundary and displaced final alias: ' . json_encode($evidence);
      }

      return true;
   },
);
