<?php

namespace Bootgly\commands;


use const BOOTGLY_STORAGE_DIR;
use const PHP_BINARY;
use function assert;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_file;
use function is_link;
use function mkdir;
use function posix_geteuid;
use function posix_getpwuid;
use function putenv;
use function rewind;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use ReflectionProperty;

use const Bootgly\CLI;
use Bootgly\ACI\Process\Inits;
use Bootgly\ACI\Process\Service;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Output;


/**
 * `project <Name> startup|unstartup|status` without root, on whatever booted
 * this machine: under systemd the units are staged with the commands that
 * install them; under anything else the platform is named and refused. Root
 * installs and `--now` are the Docker end-to-end, not this suite.
 */

return new Test(
   description: '`project startup`: stages the units and the install commands without root; names an unsupported init; refuses a bad account',
   test: function () {
      $Command = new ProjectCommand;
      $probe = static function (array $arguments, array $options = []) use ($Command): array {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Command->run($arguments, $options);
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);
         return [$result, (string) stream_get_contents($Host->stream)];
      };

      $project = 'Demo/HTTP_Server_CLI';
      $unit = 'bootgly-Demo-HTTP_Server_CLI.service';
      $staging = BOOTGLY_STORAGE_DIR . 'systemd';
      $staged = "{$staging}/{$unit}";
      $systemd = Inits::detect() === Inits::Systemd;
      // ! A box where the feature was used for real has the unit installed:
      //   the "nothing installed" probes would answer about that unit instead
      $installed = is_file(Service::$directory . $unit);
      // ! The invoking shell's sudo trail must not leak into the staged account
      $sudo = getenv('SUDO_USER');
      putenv('SUDO_USER');

      try {
         // # A bad account is refused before anything is written
         [$result, $output] = $probe(['startup', $project], ['user' => 'bootgly-no-such-account']);

         yield assert(
            assertion: $result === false && str_contains($output, 'Unknown user') && is_file($staged) === false,
            description: '--user naming an unknown account is refused and nothing is staged'
         );

         [$result, $output] = $probe(['startup', $project], ['user' => true]);

         yield assert(
            assertion: $result === false && str_contains($output, 'Invalid --user value'),
            description: 'a bare --user is refused, not ignored'
         );

         // # The platform decides the rest
         [$result, $output] = $probe(['startup', $project]);

         if ($systemd === true) {
            $account = posix_getpwuid(posix_geteuid());
            $user = $account === false ? '' : $account['name'];
            $rendered = is_file($staged) ? (string) file_get_contents($staged) : '';

            yield assert(
               assertion: $result === false && is_file($staged)
                  && str_contains($rendered, "User={$user}\n")
                  && str_contains($rendered, 'ExecStart=' . PHP_BINARY . ' ')
                  && str_contains($rendered, " project {$project} start -f\n")
                  && str_contains($rendered, 'After=network-online.target postgresql.service')
                  && str_contains($output, 'needs root')
                  && str_contains($output, 'sudo install -m 0644 -o root -g root ')
                  && str_contains($output, "sudo systemctl enable {$unit}")
                  && str_contains($output, '--now') === false,
               description: 'without root the unit is staged under storage/systemd with the install commands, enable without --now — got: ' . $output
            );

            // # …and `--now` reaches the printed command only when asked for
            [, $nowOutput] = $probe(['startup', $project], ['now' => true]);
            [$valueResult, $valueOutput] = $probe(['startup', $project], ['now' => 'yes']);

            yield assert(
               assertion: str_contains($nowOutput, "sudo systemctl enable --now {$unit}")
                  && $valueResult === false && str_contains($valueOutput, 'Invalid --now value'),
               description: '--now is printed only when asked, and a valued --now is refused'
            );

            // # A caller's SUDO_USER means nothing below root — the staged account stays the caller's
            putenv('SUDO_USER=root');
            @unlink($staged);
            [$sudoResult, $sudoOutput] = $probe(['startup', $project]);
            $sudoRendered = is_file($staged) ? (string) file_get_contents($staged) : '';
            putenv('SUDO_USER');

            yield assert(
               assertion: $sudoResult === false && str_contains($sudoRendered, "User={$user}\n")
                  && str_contains($sudoRendered, 'User=root') === false,
               description: 'SUDO_USER exported by a non-root caller never reaches User='
            );

            // # The staging directory is refused when it is a link — root installs out of it
            @unlink($staged);
            @rmdir($staging);
            $elsewhere = sys_get_temp_dir() . '/bootgly-staging-' . uniqid();
            mkdir($elsewhere, 0700);
            symlink($elsewhere, $staging);
            [$linkResult, $linkOutput] = $probe(['startup', $project]);
            $planted = is_file("{$elsewhere}/{$unit}");
            @unlink($staging);
            @rmdir($elsewhere);

            yield assert(
               assertion: $linkResult === false && str_contains($linkOutput, 'Refusing to stage') && $planted === false,
               description: 'a symlinked storage/systemd is refused and nothing is written through it'
            );

            // # …and when others can write it
            mkdir($staging, 0777);
            chmod($staging, 0777);
            [$modeResult, $modeOutput] = $probe(['startup', $project]);
            $written = is_file($staged);
            @unlink($staged);
            @rmdir($staging);

            yield assert(
               assertion: $modeResult === false && str_contains($modeOutput, 'Refusing to stage') && $written === false,
               description: 'a group- or world-writable storage/systemd is refused'
            );

            // # Units at this project's path that are not its own: named, never touched
            $scratch = sys_get_temp_dir() . '/bootgly-units-' . uniqid() . '/';
            mkdir($scratch, 0700);
            $directory = Service::$directory;
            Service::$directory = $scratch;
            try {
               $Foreign = new Service('bootgly-Demo-HTTP_Server_CLI', 'Other/Project', '/srv/other-kit', 'x', ['/bin/true'], 'deploy');
               $Foreign->install();
               [$foreignStatus, $foreignStatusOutput] = $probe(['status', $project]);
               [$foreignRemove, $foreignRemoveOutput] = $probe(['unstartup', $project]);
               $kept = is_file($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');
               unlink($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');

               symlink('/dev/null', $scratch . 'bootgly-Demo-HTTP_Server_CLI.service');
               [$maskedStatus, $maskedStatusOutput] = $probe(['status', $project]);
               [$maskedStart, $maskedStartOutput] = $probe(['startup', $project]);
               $link = is_link($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');
               unlink($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');

               // ! …and an unstamped one, which startup never wrote
               file_put_contents($scratch . 'bootgly-Demo-HTTP_Server_CLI.service', "[Unit]\nDescription=legacy\n");
               [$legacyRemove, $legacyRemoveOutput] = $probe(['unstartup', $project]);
               $legacyKept = is_file($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');
               unlink($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');

               yield assert(
                  assertion: $foreignStatus === true && str_contains($foreignStatusOutput, 'Other/Project')
                     && str_contains($foreignStatusOutput, 'no service is installed') === false
                     && $foreignRemove === false && str_contains($foreignRemoveOutput, 'left alone') && $kept === true
                     && $maskedStatus === true && str_contains($maskedStatusOutput, 'masked')
                     && $maskedStart === false && str_contains($maskedStartOutput, 'not this project') && $link === true
                     && $legacyRemove === false && str_contains($legacyRemoveOutput, 'no Bootgly stamp') && $legacyKept === true,
                  description: 'status and unstartup name a foreign unit and leave it (not a success); a masked unit is named and startup refuses it; an unstamped unit is named, left alone and not a success — got: ' . $foreignRemoveOutput . $maskedStartOutput . $legacyRemoveOutput
               );

               // # A registered project whose directory is gone is still managed by name
               $Registry = new ReflectionProperty(Projects::class, 'registry');
               $memo = $Registry->getValue();
               $Registry->setValue(null, [...Projects::read(), 'Ghost/Project' => ['interfaces' => ['WPI']]]);
               try {
                  [$ghostResult, $ghostOutput] = $probe(['status', 'Ghost/Project']);
               }
               finally {
                  $Registry->setValue(null, $memo);
               }

               yield assert(
                  assertion: $ghostResult === true && str_contains($ghostOutput, 'directory is gone')
                     && str_contains($ghostOutput, 'no service is installed'),
                  description: 'a registered project whose directory is gone is managed by name, with its own Note'
               );
            }
            finally {
               Service::$directory = $directory;
               @unlink($scratch . 'bootgly-Demo-HTTP_Server_CLI.service');
               @unlink($staged);
               @rmdir($scratch);
            }

            // # status and unstartup answer honestly when nothing is installed
            [$statusResult, $statusOutput] = $probe(['status', $project]);
            [$removeResult, $removeOutput] = $probe(['unstartup', $project]);

            yield assert(
               assertion: $installed === true
                  || ($statusResult === true && str_contains($statusOutput, 'no service is installed')
                     && $removeResult === true && str_contains($removeOutput, 'no service is installed')),
               description: $installed === true
                  ? 'skipped: the demo unit is installed on this machine'
                  : 'status and unstartup report that no service is installed'
            );

            // # A unit outlives its project: a path-safe name that is no longer
            //   registered is still managed by name; an unsafe one is refused
            [$orphanResult, $orphanOutput] = $probe(['status', 'NoSuchProject']);
            [$unsafeResult, $unsafeOutput] = $probe(['unstartup', '../etc']);

            yield assert(
               assertion: $orphanResult === true && str_contains($orphanOutput, 'is not registered')
                  && str_contains($orphanOutput, 'no service is installed')
                  && $unsafeResult === false && str_contains($unsafeOutput, 'Invalid project path'),
               description: 'status manages an unregistered name by path; an unsafe path is refused'
            );
         }
         else {
            yield assert(
               assertion: $result === false && str_contains($output, 'only systemd is supported')
                  && str_contains($output, "project {$project} start -f") && is_file($staged) === false,
               description: 'an unsupported platform is named, with the command a service must run — got: ' . $output
            );

            yield assert(
               assertion: $probe(['status', $project])[0] === false && $probe(['unstartup', $project])[0] === false,
               description: 'status and unstartup refuse on the same platform gate'
            );
         }
      }
      finally {
         if ($sudo !== false) {
            putenv("SUDO_USER={$sudo}");
         }
         @unlink($staged);
         @unlink("{$staging}/bootgly-Demo-HTTP_Server_CLI.schedule.service");
         if (is_link($staging)) {
            @unlink($staging);
         }
         else {
            @rmdir($staging);
         }
      }
   },
   // ! Root takes the install branch for real — never from a test suite
   skip: posix_geteuid() === 0
);
