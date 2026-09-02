<?php

namespace Bootgly\ACI\Process;


use function assert;
use function file_put_contents;
use function is_file;
use function is_link;
use function is_string;
use function mkdir;
use function preg_match;
use function rmdir;
use function rtrim;
use function str_contains;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Service` — the unit one project's service renders to, the name it derives
 * from a project path, the stamp that keeps one project's unit out of
 * another's hands, and what systemctl answers about a unit that does not
 * exist. Enabling needs root and a systemd machine: that is the Docker
 * end-to-end, not this suite — installing is exercised in a scratch directory.
 */

return new Test(
   description: 'Service renders a stamped systemd unit, names units injectively, escapes what systemd expands and honours the stamp',
   test: function () {
      // # Names — one unit name per project path, a sibling unit by suffix
      yield assert(
         assertion: Service::identify('Demo/HTTP_Server_CLI') === 'bootgly-Demo-HTTP_Server_CLI'
            && Service::identify('App') === 'bootgly-App'
            && Service::identify('App/API', 'schedule') === 'bootgly-App-API.schedule'
            && Service::identify('App-API') === 'bootgly-App:2DAPI'
            && Service::identify('Weird Name/#1') === 'bootgly-Weird:20Name-:231',
         description: 'identify() joins path segments with dashes, keeps case and hex-encodes what a unit name cannot carry — a dash included'
      );
      // # …and never lets two paths meet — not across `-`, `.`, case, adjacency, nor the suffix
      yield assert(
         assertion: Service::identify('App/API') !== Service::identify('App-API')
            && Service::identify('a-/b') !== Service::identify('a/-b')
            && Service::identify('a-/-') !== Service::identify('a/--')
            && Service::identify('App/Schedule') !== Service::identify('App', 'schedule')
            && Service::identify('App.schedule') !== Service::identify('App', 'schedule')
            && Service::identify('App.') !== Service::identify('App')
            && Service::identify('Weird Name/#1') !== Service::identify('Weird_Name/#1')
            && Service::identify('APP/API') !== Service::identify('App/API'),
         description: 'identify() is injective: a dash doubles, a slash becomes one dash, a dot is encoded, case survives'
      );

      // # The unit — every field the caller passed, stamped, nothing decided here
      $Service = new Service(
         name: 'bootgly-App',
         project: 'App',
         kit: '/srv/my kit',
         description: 'Bootgly project App',
         command: ['/usr/bin/php', '/srv/my kit/bootgly', 'project', 'App', 'start', '-f'],
         user: 'deploy',
         after: ['postgresql.service'],
         reload: '/bin/kill -USR2 $MAINPID'
      );
      $unit = $Service->render();

      yield assert(
         assertion: $Service->unit === 'bootgly-App.service'
            && str_contains($unit, "[Unit]\nDescription=Bootgly project App\n")
            && str_contains($unit, "X-Bootgly-Project=App\nX-Bootgly-Kit=/srv/my kit\n")
            && str_contains($unit, "After=network-online.target postgresql.service\n")
            && str_contains($unit, "Wants=network-online.target\n")
            && str_contains($unit, "StartLimitIntervalSec=300\nStartLimitBurst=10\n")
            && str_contains($unit, "[Service]\nType=simple\nUser=deploy\n")
            && str_contains($unit, "WorkingDirectory=/srv/my kit\n")
            && str_contains($unit, "ExecStart=/usr/bin/php \"/srv/my kit/bootgly\" project App start -f\n")
            && str_contains($unit, "ExecReload=/bin/kill -USR2 \$MAINPID\n")
            && str_contains($unit, "Restart=on-failure\nRestartSec=5\nKillMode=mixed\nTimeoutStopSec=30\n")
            && str_contains($unit, "[Install]\nWantedBy=multi-user.target\n"),
         description: 'render() writes the unit, quoting only the ExecStart words that need it and never a path setting'
      );

      // # A worker unit has no reload line — SIGUSR2 would kill a process that does not handle it
      $Worker = new Service('bootgly-App.schedule', 'App', '/srv/kit', 'x', ['/usr/bin/php', 'schedule', 'run'], 'deploy');

      yield assert(
         assertion: str_contains($Worker->render(), 'ExecReload=') === false,
         description: 'a service without a reload command renders no ExecReload= line'
      );

      // # What systemd expands is neutralized — `%` specifiers everywhere, `$` variables in ExecStart
      $Expanding = new Service(
         name: 'x',
         project: '100%uptime/$HOME',
         kit: '/srv/100%uptime',
         description: 'Bootgly project 100%uptime',
         command: ['/usr/bin/php', '/srv/100%uptime/bootgly', 'project', '$HOME', '/opt/${TEAM}/x', 'start', '-f'],
         user: 'deploy'
      );
      $rendered = $Expanding->render();

      yield assert(
         assertion: str_contains($rendered, "Description=Bootgly project 100%%uptime\n")
            && str_contains($rendered, "WorkingDirectory=/srv/100%%uptime\n")
            && str_contains($rendered, 'ExecStart=/usr/bin/php /srv/100%%uptime/bootgly project $$HOME "/opt/$${TEAM}/x" start -f')
            && preg_match('/(?<!%)%(?!%)/', $rendered) === 0
            && preg_match('/ExecStart=.*(?<!\$)\$(?!\$)/', $rendered) === 0,
         description: 'a % in a path, a name or a description is doubled, and a $ in an ExecStart word is doubled, so nothing expands'
      );

      // # The executable must be absolute — systemd strips its prefix characters after unquoting
      $refused = null;
      try {
         new Service('x', 'x', '/', 'x', ['-php', '-f'], 'root')->render();
      }
      catch (InvalidArgumentException $Refused) {
         $refused = $Refused->getMessage();
      }

      yield assert(
         assertion: $refused !== null && str_contains($refused, 'absolute path'),
         description: 'a command whose first word is not an absolute path is refused, never quoted around'
      );

      // # A line break or a trailing backslash in a value can never reach the next directive
      $Broken = new Service('x', "App\nExecStart=/bin/sh", "/srv/kit\\ \n", "Bootgly\nExecStart=/bin/sh", ['/bin/true'], "deploy\nUser=root");
      $broken = $Broken->render();

      yield assert(
         assertion: str_contains($broken, "Description=Bootgly ExecStart=/bin/sh\n")
            && str_contains($broken, "X-Bootgly-Project=App ExecStart=/bin/sh\n")
            && str_contains($broken, "WorkingDirectory=/srv/kit\n")
            && str_contains($broken, "User=deploy User=root\n"),
         description: 'line breaks collapse to spaces and a trailing backslash is dropped, whitespace after it included'
      );

      // # The stamp — installing and removing only what this project wrote
      $scratch = sys_get_temp_dir() . '/bootgly-service-' . uniqid() . '/';
      mkdir($scratch, 0700, true);
      $directory = Service::$directory;
      Service::$directory = $scratch;
      try {
         $Mine = new Service('bootgly-Mine', 'Mine', '/srv/kit', 'x', ['/bin/true'], 'deploy');
         $Theirs = new Service('bootgly-Mine', 'Other/Project', '/srv/kit', 'x', ['/bin/true'], 'deploy');
         $Elsewhere = new Service('bootgly-Mine', 'Mine', '/srv/another-kit', 'x', ['/bin/true'], 'deploy');

         $absent = $Mine->installed === false && $Mine->owned === true && $Mine->owner === ['', ''];
         $written = $Mine->install() === true && $Mine->installed === true && is_file($scratch . 'bootgly-Mine.service');
         $stamped = $Mine->owner === ['Mine', '/srv/kit'] && $Mine->owned === true;
         $foreign = $Theirs->owned === false && $Theirs->install() === false && $Theirs->uninstall() === false
            && $Elsewhere->owned === false && $Elsewhere->uninstall() === false
            && is_file($scratch . 'bootgly-Mine.service');
         $removed = $Mine->uninstall() === true && $Mine->installed === false && $Mine->uninstall() === true;

         // ! A unit written before the stamp existed is nobody's — left alone too
         file_put_contents($scratch . 'bootgly-Mine.service', "[Unit]\nDescription=legacy\n");
         $legacy = $Mine->installed === true && $Mine->owner === ['', ''] && $Mine->owned === false
            && $Mine->install() === false && $Mine->uninstall() === false;
         unlink($scratch . 'bootgly-Mine.service');

         // ! A masked unit is a link to /dev/null — seen as installed, never written through
         symlink('/dev/null', $scratch . 'bootgly-Mine.service');
         $masked = $Mine->installed === true && $Mine->owner === ['', ''] && $Mine->owned === false
            && $Mine->install() === false && $Mine->uninstall() === false
            && is_link($scratch . 'bootgly-Mine.service');
         unlink($scratch . 'bootgly-Mine.service');

         // ! A dangling link would create whatever it names — never followed either
         symlink($scratch . 'planted.conf', $scratch . 'bootgly-Mine.service');
         $dangling = $Mine->install() === false && is_file($scratch . 'planted.conf') === false;
         unlink($scratch . 'bootgly-Mine.service');

         // ! A link to a file carrying THIS project's own stamp is still a link:
         //   nobody's — the stamp must never be read through it, nor the link removed
         file_put_contents($scratch . 'mine.conf', $Mine->render());
         symlink($scratch . 'mine.conf', $scratch . 'bootgly-Mine.service');
         $linked = $Mine->installed === true && $Mine->owner === ['', ''] && $Mine->owned === false
            && $Mine->install() === false && $Mine->uninstall() === false
            && is_link($scratch . 'bootgly-Mine.service') && is_file($scratch . 'mine.conf');
         unlink($scratch . 'bootgly-Mine.service');
         unlink($scratch . 'mine.conf');

         // ! An empty project can own nothing — not even an unstamped file
         file_put_contents($scratch . 'bootgly-Mine.service', "[Unit]\nDescription=legacy\n");
         $Nobody = new Service('bootgly-Mine', '', '', 'x', ['/bin/true'], 'deploy');
         $nobody = $Nobody->owned === false && $Nobody->install() === false && $Nobody->uninstall() === false;
         unlink($scratch . 'bootgly-Mine.service');

         // ! The units directory needs no trailing slash
         Service::$directory = rtrim($scratch, '/');
         $normalized = $Mine->file === $scratch . 'bootgly-Mine.service';
         Service::$directory = $scratch;

         // ! A stamp with stray whitespace still matches: writer and reader trim alike
         $Spaced = new Service('bootgly-Mine', 'Mine ', "/srv/kit\n", 'x', ['/bin/true'], 'deploy');
         $spaced = $Spaced->install() === true && $Spaced->owned === true && $Spaced->uninstall() === true;

         yield assert(
            assertion: $absent && $written && $stamped && $foreign && $removed && $legacy && $masked && $dangling && $linked && $nobody && $normalized && $spaced,
            description: 'install()/uninstall() honour the stamp: absent is free, mine is mine; another project or kit, an unstamped unit, a masked unit, a dangling link, a link to my own stamp and an empty project are left alone'
         );
      }
      finally {
         Service::$directory = $directory;
         @unlink($scratch . 'bootgly-Mine.service');
         @unlink($scratch . 'planted.conf');
         @unlink($scratch . 'mine.conf');
         @rmdir($scratch);
      }

      // # Inspecting a unit that does not exist never throws — it answers strings
      $state = new Service('bootgly-no-such-unit-' . uniqid(), 'x', '/', 'x', ['/bin/true'], 'root')->inspect();

      yield assert(
         assertion: is_string($state['enabled']) && is_string($state['active'])
            && $state['enabled'] !== '' && $state['active'] !== '',
         description: 'inspect() answers two words for an unknown unit'
      );
   }
);
