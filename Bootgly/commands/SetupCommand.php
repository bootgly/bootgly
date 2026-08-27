<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_WORKING_DIR;
use const PHP_BINARY;
use function array_unshift;
use function escapeshellarg;
use function fclose;
use function file_exists;
use function file_get_contents;
use function fwrite;
use function is_dir;
use function is_executable;
use function is_file;
use function is_link;
use function is_resource;
use function is_writable;
use function posix_geteuid;
use function proc_close;
use function proc_open;
use function rewind;
use function stream_get_contents;
use function strlen;
use function strtr;
use function substr;
use function tmpfile;
use function trim;
use RuntimeException;

use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;


class SetupCommand extends Command
{
   // * Config
   public int $group = 0;

   // * Data
   // @ Command
   public string $name = 'setup';
   public string $description = 'Setup Bootgly CLI globally (on /usr/local/bin)';

   /** @var array<string,array<string>> */
   public array $options = [
      // Global options
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      // Local options
      'Uninstall Bootgly CLI from /usr/local/bin' => ['--uninstall'],
      'Grant PHP the ability to bind privileged ports without root' => ['--capabilities'],
   ];


   public function run (array $arguments = [], array $options = []): bool
   {
      // @ Set the name of the script to be installed
      $scriptName = 'bootgly';
      // @ Set the destination directory for global installation
      $installDir = '/usr/local/bin';
      $installPath = "$installDir/$scriptName";

      // ? PHP, Bootgly bootstraps and project code must never cross the root
      //   boundary. An ordinary invocation delegates only a fixed system
      //   executable with fixed destination/operation arguments.
      if (posix_geteuid() === 0) {
         $Output = CLI->Terminal->Output;
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Setup must run as an ordinary user.';
         $Alert->render();

         $PHPBinary = escapeshellarg(PHP_BINARY);
         $launcher = escapeshellarg(BOOTGLY_WORKING_DIR . $scriptName);
         $Output->render("@#yellow:Usage:@; $PHPBinary $launcher setup@.;");

         return false;
      }

      // @ Handle --uninstall
      if (isSet($options['uninstall'])) {
         return $this->uninstall($installPath);
      }

      // @ Handle --capabilities
      if (isSet($options['capabilities'])) {
         return $this->capabilities();
      }

      // @ Install
      return $this->install($scriptName, $installDir, $installPath);
   }

   /**
    * Compose the global CLI wrapper script.
    *
    * A wrapper instead of a symlink so the PHP binary is stable even when the
    * caller's PATH changes. The convenience wrapper deliberately refuses EUID
    * 0: privileged services must invoke an entirely root-controlled deployment
    * explicitly instead of promoting a workspace selected by the wrapper.
    *
    * An unprivileged invocation runs the nearest trusted Bootgly launcher above
    * the working directory, so the global command operates on that workspace.
    * A root invocation is refused before any PHP binary or launcher is selected,
    * so a sudo caller's checkout is never promoted to EUID 0.
    *
    * JIT is forced at the launcher because it CANNOT be turned on later:
    * `opcache.jit` is not writable at runtime (ini_set() returns false), so a
    * missing php.ini setting is unrecoverable once PHP has started. Bootgly
    * is a long-lived CLI runtime — its hottest functions run fully
    * interpreted without the tracing JIT, which reads as "the framework is
    * slow" rather than "the runtime was misconfigured". BOOTGLY_JIT=0 opts
    * out; the native code-coverage driver needs it.
    *
    * @param string $php Absolute PHP binary path.
    * @param string $script Absolute fallback launcher path.
    *
    * @return string The wrapper script content.
    */
   private function compose (string $php, string $script): string
   {
      // ! Keep the wrapper as a native Bash resource so editors and ShellCheck
      //   can parse it without PHP heredoc escaping rules.
      $directory = __DIR__;
      $template = file_get_contents("$directory/templates/bootgly.wrapper.bash");
      if ($template === false) {
         throw new RuntimeException('Bootgly CLI wrapper template could not be read.');
      }

      // : Lexical paths remain intact for runtime canonicalization and trust
      //   checks. They are data: strtr() substitutes both tokens in one pass, so a
      //   token-shaped substring inside either escaped path is never rescanned.
      return strtr($template, [
         '__BOOTGLY_PHP_BINARY__' => escapeshellarg($php),
         '__BOOTGLY_FALLBACK_LAUNCHER__' => escapeshellarg($script),
      ]);
   }

   /**
    * Locate a fixed system executable without consulting the caller's PATH.
    *
    * @param list<string> $candidates Absolute executable candidates.
    *
    * @return string The first executable path, or an empty string.
    */
   private function locate (array $candidates): string
   {
      foreach ($candidates as $candidate) {
         if (is_executable($candidate)) {
            return $candidate;
         }
      }

      return '';
   }

   /**
    * Execute one fixed operation, optionally through the system sudo binary.
    *
    * @param list<string> $arguments Executable path followed by its arguments.
    * @param bool $elevated Whether the operation requires root privileges.
    *
    * @param string $input Bytes exposed to the child as standard input.
    *
    * @return array{int,string} Exit status and combined output.
    */
   private function execute (array $arguments, bool $elevated, string $input = ''): array
   {
      if ($elevated && posix_geteuid() !== 0) {
         $sudoPath = $this->locate(['/usr/bin/sudo', '/bin/sudo']);
         if ($sudoPath === '') {
            return [127, 'sudo is required for this system operation.'];
         }
         array_unshift($arguments, '--');
         array_unshift($arguments, $sudoPath);
      }

      // ! tmpfile() gives the child an already-open private descriptor: no
      //   privileged process reopens a caller-controlled pathname.
      $inputStream = tmpfile();
      if ($inputStream === false) {
         return [74, 'Could not create the private input stream.'];
      }
      $outputStream = tmpfile();
      if ($outputStream === false) {
         fclose($inputStream);

         return [74, 'Could not create the private output stream.'];
      }

      $length = strlen($input);
      $offset = 0;
      while ($offset < $length) {
         $written = fwrite($inputStream, substr($input, $offset));
         if ($written === false || $written === 0) {
            fclose($inputStream);
            fclose($outputStream);

            return [74, 'Could not write the complete operation input.'];
         }
         $offset += $written;
      }
      rewind($inputStream);

      $descriptors = [
         0 => $inputStream,
         1 => $outputStream,
         2 => $outputStream,
      ];
      $pipes = [];
      $process = proc_open($arguments, $descriptors, $pipes);
      fclose($inputStream);
      if (is_resource($process) === false) {
         fclose($outputStream);

         return [71, 'Could not start the fixed system operation.'];
      }

      $exitCode = proc_close($process);
      rewind($outputStream);
      $output = trim((string) stream_get_contents($outputStream));
      fclose($outputStream);

      return [$exitCode, $output];
   }

   private function install (string $scriptName, string $installDir, string $installPath): bool
   {
      $Output = CLI->Terminal->Output;
      $Alert = new Alert($Output);

      $Output->render('@#green:Installing Bootgly CLI globally...@;@.;');

      // @ Resolve paths
      $PHPBinary = PHP_BINARY;
      $scriptPath = BOOTGLY_WORKING_DIR . $scriptName;

      // @ Validate PHP binary
      if ($PHPBinary === '' || is_executable($PHPBinary) === false) { // @phpstan-ignore-line
         $Alert->Type::Failure->set();
         $Alert->message = 'Could not detect a valid PHP binary.';
         $Alert->render();
         exit(1);
      }

      // @ Validate bootgly script
      if (is_file($scriptPath) === false) {
         $Alert->Type::Failure->set();
         $Alert->message = 'Bootgly script not found at @#cyan:' . $scriptPath . '@;';
         $Alert->render();
         exit(1);
      }

      // @ Check if the destination directory exists
      if (is_dir($installDir) === false) {
         $Alert->Type::Failure->set();
         $Alert->message = 'The installation directory @#cyan:' . $installDir . '@; does not exist.';
         $Alert->render();
         exit(1);
      }

      // ? A destination directory is never a valid wrapper and `install`
      //   must not reinterpret it as a target directory.
      if (is_dir($installPath)) {
         $Alert->Type::Failure->set();
         $Alert->message = 'The installation path @#cyan:' . $installPath . '@; is a directory.';
         $Alert->render();
         exit(1);
      }

      $installBinary = $this->locate(['/usr/bin/install', '/bin/install']);
      if ($installBinary === '') {
         $Alert->Type::Failure->set();
         $Alert->message = 'The system install command was not found.';
         $Alert->render();
         exit(1);
      }

      // ! Compose before replacing an existing installation: a missing or
      //   unreadable template must leave the working wrapper untouched.
      $wrapper = $this->compose($PHPBinary, $scriptPath);

      // ! Stream the already-composed bytes over an open fd 0. The elevated process
      //   opens no caller-controlled source pathname and receives fixed argv.
      [$exitCode, $output] = $this->execute(
         [$installBinary, '-m', '0755', '/dev/stdin', $installPath],
         elevated: is_writable($installDir) === false,
         input: $wrapper
      );

      if ($exitCode !== 0) {
         $Alert->Type::Failure->set();
         $Alert->message = 'Failed to install the wrapper at @#cyan:' . $installPath . '@;';
         $Alert->render();
         $Output->render("$output@.;");
         exit(1);
      }

      $Alert->Type::Success->set();
      $Alert->message = 'Bootgly CLI installed successfully!';
      $Alert->render();

      $Output->render('  @#yellow:Wrapper:@; @#cyan:' . $installPath . '@;@.;');
      $Output->render('  @#yellow:PHP:    @; @#cyan:' . $PHPBinary . '@;@.;');
      $Output->render('  @#yellow:Script: @; @#cyan:' . $scriptPath . '@;@..;');
      $Output->render('You can now use @#green:' . $scriptName . '@; from any directory.@.;');
      $Output->render('@#yellow:Privilege boundary:@; PHP stayed unprivileged; only the fixed system install operation used sudo when required.@.;');
      $Output->render('@#yellow:Security:@; this global wrapper refuses EUID 0. Use Linux capabilities, a reverse proxy, or invoke a root-controlled deployment explicitly.@.;');

      return true;
   }

   private function uninstall (string $installPath): bool
   {
      $Output = CLI->Terminal->Output;
      $Alert = new Alert($Output);

      if (file_exists($installPath) === false && is_link($installPath) === false) {
         $Output->render('@#yellow:Nothing to uninstall:@; @#cyan:' . $installPath . '@; does not exist.@.;');
         return true;
      }

      $removeBinary = $this->locate(['/usr/bin/rm', '/bin/rm']);
      if ($removeBinary === '') {
         $Alert->Type::Failure->set();
         $Alert->message = 'The system remove command was not found.';
         $Alert->render();
         exit(1);
      }

      [$exitCode, $output] = $this->execute(
         [$removeBinary, '-f', $installPath],
         elevated: is_writable('/usr/local/bin') === false
      );
      if ($exitCode !== 0) {
         $Alert->Type::Failure->set();
         $Alert->message = 'Failed to remove @#cyan:' . $installPath . '@;';
         $Alert->render();
         $Output->render("$output@.;");
         exit(1);
      }

      $Alert->Type::Success->set();
      $Alert->message = 'Bootgly CLI uninstalled from @#cyan:' . $installPath . '@;';
      $Alert->render();

      return true;
   }

   private function capabilities (): bool
   {
      $Output = CLI->Terminal->Output;
      $Alert = new Alert($Output);

      $PHPBinary = PHP_BINARY;

      $Output->render('@#green:Granting privileged port binding capability...@;@.;');

      // @ Check fixed system locations without trusting the caller's PATH
      $setcapPath = $this->locate([
         '/usr/sbin/setcap',
         '/sbin/setcap',
         '/usr/bin/setcap',
      ]);
      if ($setcapPath === '') {
         $Alert->Type::Failure->set();
         $Alert->message = '@#cyan:setcap@; command not found. Install libcap2-bin:';
         $Alert->render();
         $Output->render('  @#yellow:$@; sudo apt install libcap2-bin@.;');
         exit(1);
      }

      // @ Apply CAP_NET_BIND_SERVICE through the same narrow sudo boundary
      [$exitCode, $output] = $this->execute(
         [$setcapPath, 'cap_net_bind_service=+ep', $PHPBinary],
         elevated: true
      );
      if ($exitCode !== 0) {
         $Alert->Type::Failure->set();
         $Alert->message = 'Failed to set capabilities on @#cyan:' . $PHPBinary . '@;';
         $Alert->render();
         $Output->render("$output@.;");
         exit(1);
      }

      $Alert->Type::Success->set();
      $Alert->message = 'Capability @#cyan:CAP_NET_BIND_SERVICE@; granted!';
      $Alert->render();

      $Output->render('  @#yellow:Binary:@; @#cyan:' . $PHPBinary . '@;@..;');
      $Output->render('PHP can now bind to privileged ports (< 1024) @#green:without sudo@;.@.;');
      $Output->render('@#yellow:Warning:@; this applies to ALL PHP scripts, not just Bootgly.@.;');

      return true;
   }
}
