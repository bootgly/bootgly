<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


// ! Opt-in live E2E — BOOTGLY_PGSQL_E2E=1 + DB_* environment
$optin = getenv('BOOTGLY_PGSQL_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 5432;
$reachable = false;

if ($optin) {
   $Probe = @fsockopen($host, $port, $errno, $error, 0.5);
   $reachable = is_resource($Probe);

   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'PostgreSQL(live): startup session parameters reach the session (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port) {
      $Open = static function (array $parameters) use ($host, $port): SQL {
         return new SQL([
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres',
            'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres',
            'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
            'timeout' => 5.0,
            'secure' => [
               'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
            ],
            'pool' => ['min' => 0, 'max' => 1],
            'parameters' => $parameters,
         ]);
      };
      $Show = static function (SQL $Database, string $name): mixed {
         $Operation = $Database->await($Database->query("SHOW {$name}"));

         return $Operation->Result?->rows[0][$name] ?? null;
      };

      // # Configured parameters — set before the first query runs
      $Database = $Open([
         'search_path' => 'bootgly_parameters, public',
         'statement_timeout' => 4321,
      ]);
      $path = $Show($Database, 'search_path');
      $timeout = $Show($Database, 'statement_timeout');
      $application = $Show($Database, 'application_name');
      $encoding = $Show($Database, 'client_encoding');

      yield assert(
         assertion: $path === 'bootgly_parameters, public',
         description: "SHOW search_path returns the configured value — {$path}"
      );
      yield assert(
         assertion: $timeout === '4321ms' && $application === 'Bootgly' && $encoding === 'UTF8',
         description: "An integer value is sent in decimal, application_name keeps its default and client_encoding stays UTF8 — {$timeout}, {$application}, {$encoding}"
      );

      // # Pinned settings — only names a stock PgBouncer tracks, sent in the
      //   same startup message, so a pooler accepts it and dates, encoding and
      //   literal quoting are what the driver reads and writes
      $pinned = [
         $Show($Database, 'DateStyle'),
         $Show($Database, 'standard_conforming_strings'),
      ];
      $settings = json_encode($pinned);

      yield assert(
         assertion: str_starts_with((string) $pinned[0], 'ISO,') && $pinned[1] === 'on',
         description: "The server accepts every pinned setting at startup — {$settings}"
      );

      $Operation = $Database->await($Database->query(
         "SELECT 1 AS one, '2026-11-05 10:00'::timestamp AS t, '1 day 2 hours'::interval AS i, '\\x0102'::bytea AS b, 0.1::float8 + 0.2::float8 AS f"
      ));
      $row = $Operation->Result?->rows[0] ?? [];
      $date = ($row['t'] ?? null) instanceof DateTimeImmutable ? $row['t']->format('Y-m-d H:i') : null;
      $decoded = json_encode([$row['one'] ?? null, $date, $row['i'] ?? null, bin2hex((string) ($row['b'] ?? '')), $row['f'] ?? null]);

      yield assert(
         assertion: $Operation->error === null
            && ($row['one'] ?? null) === 1
            && $date === '2026-11-05 10:00'
            && ($row['i'] ?? null) === '1 day 02:00:00'
            && ($row['b'] ?? null) === "\x01\x02"
            && ($row['f'] ?? null) === 0.1 + 0.2,
         description: "A plain query still runs and decodes on server defaults: ISO timestamp, postgres interval, hex bytea, exact float — {$decoded}"
      );

      // # bytea_output = escape — no longer pinned, decoded all the same
      $all = '';
      for ($byte = 0; $byte < 256; $byte++) {
         $all .= chr($byte);
      }
      $hex = bin2hex($all);
      $Database->await($Database->query("SET bytea_output = 'escape'"));
      $output = $Show($Database, 'bytea_output');
      $Operation = $Database->await($Database->query(
         "SELECT decode('{$hex}', 'hex') AS b, decode('5c783431', 'hex') AS x, ''::bytea AS e"
      ));
      $row = $Operation->Result?->rows[0] ?? [];
      $Database->await($Database->query('RESET bytea_output'));

      yield assert(
         assertion: $output === 'escape'
            && $Operation->error === null
            && ($row['b'] ?? null) === $all
            && ($row['x'] ?? null) === '\\x41'
            && ($row['e'] ?? null) === '',
         description: "With bytea_output escape, every byte 0..255, a literal `\\x41` and an empty bytea decode to their bytes — {$output}"
      );

      // # IntervalStyle — admitted: it changes the interval text returned
      $Styled = $Open(['IntervalStyle' => 'iso_8601', 'bytea_output' => 'escape']);
      $Operation = $Styled->await($Styled->query("SELECT '1 day 2 hours'::interval AS i, '\\x00ff'::bytea AS b"));
      $row = $Operation->Result?->rows[0] ?? [];
      $styled = json_encode([$row['i'] ?? null, bin2hex((string) ($row['b'] ?? ''))]);

      yield assert(
         assertion: $Operation->error === null && ($row['i'] ?? null) === 'P1DT2H' && ($row['b'] ?? null) === "\x00\xFF",
         description: "Startup IntervalStyle iso_8601 returns ISO interval text and startup bytea_output escape still decodes — {$styled}"
      );

      // # Session default — RESET returns to the startup value, not the server's
      $Database->await($Database->query('SET search_path TO pg_catalog'));
      $Database->await($Database->query('RESET search_path'));
      $reset = $Show($Database, 'search_path');

      yield assert(
         assertion: $reset === 'bootgly_parameters, public',
         description: "RESET search_path restores the startup value — {$reset}"
      );

      // # A configured application_name replaces the default
      $Named = $Open(['application_name' => 'bootgly_parameters_spec']);
      $named = $Show($Named, 'application_name');

      yield assert(
         assertion: $named === 'bootgly_parameters_spec',
         description: "A configured application_name replaces Bootgly — {$named}"
      );
   }
);
