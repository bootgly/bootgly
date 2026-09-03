<?php
/*
 * Swap-purity probe for `kit upgrade` — run in a FRESH php process:
 *
 *   php -r 'require $argv[1];' -- <this file>   with KIT_PROBE_BASE=<temp dir> and
 *   KIT_PROBE_ROOT=<framework root> (and KIT_PROBE_MODE=json for the JSON path) in the
 *   environment
 *
 * A prepended autoloader records every class the framework loads together
 * with the kit's HEAD at that moment. Once the kit's HEAD is the target
 * release, its files are the release's — any class loaded from then on came
 * from a framework this process never validated. The probe drives the real
 * command through the version-footer middleware, as `bootgly kit upgrade`
 * would, in human mode (no `--json`), and writes `<base>/report.json` at
 * shutdown: the result, the classes loaded after the swap, the output.
 */

$base = (string) getenv('KIT_PROBE_BASE');
$root = rtrim((string) getenv('KIT_PROBE_ROOT'), '/');
$kit = null;
$target = null;
$sentinel = null;
$loads = [];

// ! "Swapped" the moment either mark shows: the release's HEAD in `.git/HEAD`,
//   or a file only the release carries already on disk — git writes the tree
//   BEFORE it moves HEAD, so the file closes most of that window
$swapped = static function () use (&$kit, &$target, &$sentinel): bool {
   if ($kit === null) {
      return false;
   }

   return trim((string) @file_get_contents("{$kit}/.git/HEAD")) === $target
      || ($sentinel !== null && file_exists($sentinel));
};
spl_autoload_register(function (string $class) use (&$loads, $swapped): void {
   $loads[] = [$class, $swapped()];
}, true, true);

$report = ['result' => null, 'moved' => false, 'after' => [], 'loads' => 0, 'output' => ''];

require "{$root}/autoboot.php";

// ! Written at shutdown — registered AFTER the framework's own shutdown function,
//   so a class it or a destructor loads is counted too; the test reads this file
register_shutdown_function(static function () use (&$report, &$loads, $base): void {
   $after = [];
   foreach ($loads as [$class, $swapped]) {
      if ($swapped === true) {
         $after[] = $class;
      }
   }
   $report['after'] = $after;
   $report['loads'] = count($loads);
   file_put_contents("{$base}/report.json", json_encode($report));
});

$fixture = (require __DIR__ . '/kit_fixture.php')($base);
$kit = $fixture['clone']('kit', 'refs/tags/v1.0.0-beta.2');
$target = $fixture['commits']['v1.0.0'];
$sentinel = "{$kit}/docs/notes.md";

$Command = new class ($kit, $fixture['canon']) extends Bootgly\commands\KitCommand {
   public function __construct (string $kit, string $repository)
   {
      parent::__construct();
      $this->kit = $kit;
      $this->repository = $repository;
   }

   protected function scan (): array
   {
      return [];
   }
};

$Host = new Bootgly\CLI\Terminal\Output('php://memory');
$Terminal = Bootgly\CLI->Terminal;
$Terminal->Output = $Host;

// @ The command, wrapped as the CLI wraps every built-in command — in human
//   mode (the plan lines render before the swap) or in `--json` mode (nothing
//   renders before it: the footer is skipped, the document is the first write)
$options = getenv('KIT_PROBE_MODE') === 'json' ? ['yes' => true, 'json' => true] : ['yes' => true];
$Middleware = new Bootgly\commands\VersionFooterMiddleware;
$result = $Middleware->process($Command, ['upgrade', 'v1.0.0'], $options, static function ($Command, $arguments, $options): bool {
   return $Command->run($arguments, $options);
});

rewind($Host->stream);
$report['result'] = $result;
$report['moved'] = trim((string) file_get_contents("{$kit}/.git/HEAD")) === $target;
$report['output'] = (string) stream_get_contents($Host->stream);
