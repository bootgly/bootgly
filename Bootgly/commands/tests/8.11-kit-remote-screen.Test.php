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


use function array_diff;
use function array_key_exists;
use function assert;
use function chmod;
use function count;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function mkdir;
use function preg_match;
use function putenv;
use function rewind;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\CLI\Terminal\Output;


/**
 * `kit` screens its releases remote with `Remotes::screen()` and fetches it
 * under the transport allow-list (`Git::transfer()`).
 *
 * The fixture is the 8.x kit lineage. The canonical repository
 * (`KitCommand::REPOSITORY`) is mapped by `insteadOf`, through
 * `GIT_CONFIG_COUNT`, to the local canonical fixture; `http.proxy` points at a
 * closed local port and `core.sshCommand` only writes a witness. No leg reaches
 * the network, even with the gate removed. The configuration goes through the
 * environment, so it reaches every git child: `Git::execute()` passes the
 * caller's environment on. The spec runs under a private `HOME`, and stops
 * first when git ignores that configuration channel (older git drops
 * `GIT_CONFIG_COUNT`, and with it the mirror and the proxy).
 *
 * - Credentials in the remote (user:password, or a token as the username) are
 *   refused before any fetch, and the secret appears in neither the JSON
 *   document nor the rendered output.
 * - A credential the user's own `insteadOf` adds (`git remote get-url` expands
 *   it) is judged on the URL the kit stores: a plain stored URL goes on to the
 *   fetch — the canonical `origin` under a token rewrite of github.com, and
 *   the `bootgly` the kit adds, rewritten to `file://TOKEN@<fixture>`, is
 *   fetched — while the same token in the STORED URL is refused, also when a
 *   plain canonical URL is stored after it (git dials the first stored URL,
 *   `config --get` reads the last): every stored URL is judged.
 * - A helper URL whose tail spells the canonical repository
 *   (`ext::…;#@github.com:bootgly/bootgly.kit`, or the digit-led `0ext::…`
 *   git also reads as a helper), an https authority that curl reads as another
 *   host (`attacker.invalid?@github.com`), or an scp-like address whose host
 *   ends at the first `:` (`evil.invalid:x@github.com:…`, `[evil.invalid]:x@…`)
 *   is not taken for the canonical remote. `bootgly` is added beside it, the
 *   helper or the ssh dial never happens, and the kit fetches the canonical
 *   repository.
 * - The canonical repository rewritten by `insteadOf` to an `ext::` command is
 *   refused by the screen before any fetch ("is a remote helper"), and one
 *   rewritten to `attacker.invalid?@github.com` ("has a malformed URL"): `git
 *   remote get-url` expands `insteadOf`, so the screen sees the rewrite.
 * - The canonical repository rewritten by `insteadOf` to a string shaped like a
 *   local path, which `remote.bootgly.vcs=ext` hands to git-remote-ext, passes
 *   the screen: `get-url` shows the path, never the helper. A `vcs` alone gives
 *   `get-url` no URL, so `bootgly` is not taken for a remote pointing elsewhere
 *   and is added. Only the transfer's `GIT_ALLOW_PROTOCOL` stops the helper:
 *   "Could not fetch", and the command never runs.
 *
 * A caller that allows a helper exports it BESIDE the transports
 * (`GIT_ALLOW_PROTOCOL=ext:file:https:ssh`): the policy the transfer's
 * allow-list must beat, which it narrows to `file:https:ssh`, so the
 * canonical fixture is still fetched. Each helper and scp-like configuration
 * first gets a positive control: git itself, under it, runs the helper's
 * command or dials the attacker's host (the token rewrite gets one too: get-url
 * shows the token while the stored URL is plain).
 * Kit shows a remote by its name and never by its URL, so it has no
 * `Remotes::redact()` site. The secret checks guard against a refusal that
 * starts printing the URL; `redact()` has its own coverage in the VCS unit
 * table.
 */

return new Test(
   description: '`kit` screens its releases remote: credentials refused unshown (a token from the user\'s insteadOf admitted), a helper or spoofed host never adopted, a hidden helper stopped inside git',
   test: function () {
      // ! Every variable this spec exports, with what the process had — restored in `finally`
      $saved = [];
      $Export = static function (array $variables) use (&$saved): void {
         foreach ($variables as $name => $value) {
            if (array_key_exists($name, $saved) === false) {
               $saved[$name] = getenv($name);
            }
            putenv($value === null ? $name : "{$name}={$value}");
         }
      };
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      $base = Temporaries::reserve('kit-remote-screen');

      try {
         // ! A private HOME, and a digit-led remote helper on PATH: `git-remote-0ext`
         //   leaves its witness in git's directory and fails
         $home = "{$base}/home";
         $helpers = "{$base}/helpers";
         mkdir($home, 0700);
         mkdir($helpers, 0700);
         file_put_contents("{$helpers}/git-remote-0ext", "#!/bin/sh\ntouch WITNESS\nexit 1\n");
         chmod("{$helpers}/git-remote-0ext", 0700);
         $PATH = (string) getenv('PATH');

         // ! Hermetic git: no user or system configuration, no inherited policy,
         //   config channel, ssh command or proxy exemption
         $Export([
            'HOME' => $home,
            'XDG_CONFIG_HOME' => null,
            'PATH' => "{$helpers}:{$PATH}",
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_COUNT' => null,
            'GIT_CONFIG_PARAMETERS' => null,
            'GIT_ALLOW_PROTOCOL' => null,
            'GIT_SSH_COMMAND' => null,
            'GIT_SSH' => null,
            'NO_PROXY' => null,
            'no_proxy' => null,
         ]);

         $tests = __DIR__;
         $fixture = (require "{$tests}/fixtures/kit_fixture.php")($base);
         $canon = $fixture['canon'];
         $Run = $fixture['run'];
         $repository = KitCommand::REPOSITORY;

         // ! A leg's git configuration and transport policy, through the environment
         $Configure = static function (array $entries, null|string $allow) use ($Export): void {
            $variables = ['GIT_CONFIG_COUNT' => (string) count($entries), 'GIT_ALLOW_PROTOCOL' => $allow];
            foreach ($entries as $index => [$key, $value]) {
               $variables["GIT_CONFIG_KEY_{$index}"] = $key;
               $variables["GIT_CONFIG_VALUE_{$index}"] = $value;
            }
            $Export($variables);
         };
         $Clear = static function () use ($Export): void {
            $Export(['GIT_CONFIG_COUNT' => null, 'GIT_ALLOW_PROTOCOL' => null]);
         };
         // ! The command bound to a fixture kit and the repository it trusts
         $Bind = static function (string $kit, string $repository): KitCommand {
            return new class ($kit, $repository) extends KitCommand {
               public function __construct (string $kit, string $repository)
               {
                  parent::__construct();
                  $this->kit = $kit;
                  $this->repository = $repository;
               }

               // ! The registry and the pid files belong to the process's own kit, not the fixture's
               protected function scan (): array
               {
                  return [];
               }
            };
         };
         // ! `kit list` under one leg's configuration: the result, the document and the raw text
         $Probe = static function (KitCommand $Command, array $options, array $entries, null|string $allow) use ($Configure, $Clear): array {
            $Host = new Output('php://memory');
            $Terminal = CLI->Terminal;
            $Restore = $Terminal->Output;
            $Configure($entries, $allow);
            $Terminal->Output = $Host;
            try {
               $result = $Command->run(['list'], $options);
            }
            finally {
               $Terminal->Output = $Restore;
               $Clear();
            }
            rewind($Host->stream);
            $text = (string) stream_get_contents($Host->stream);
            $document = json_decode($text, true);

            // :
            return [$result, is_array($document) ? $document : [], $text];
         };
         // ! What a hostile command or dial left in git's directory — null when nothing ran
         $Witness = static function (string $directory): null|string {
            $witness = "{$directory}/WITNESS";

            // :
            return is_file($witness) === true ? (string) file_get_contents($witness) : null;
         };
         // ! Git itself under the same configuration, in a bare control checkout:
         //   the witness the hostile command (or ssh dial) left, if it ran
         $Control = static function (string $name, string $remote, string $command, array $entries, null|string $allow) use ($base, $Run, $Configure, $Clear, $Witness): null|string {
            $control = "{$base}/{$name}";
            mkdir($control, 0700);
            $Run($control, 'init --quiet');
            if ($remote !== '') {
               $quoted = escapeshellarg($remote);
               $Run($control, "remote add origin {$quoted}");
            }

            $Configure($entries, $allow);
            try {
               $Run($control, $command);
            }
            finally {
               $Clear();
            }

            // :
            return $Witness($control);
         };
         // ! A template kit (no remote, no tag) with an optional origin
         $Kit = static function (string $name, null|string $origin) use ($fixture, $Run): string {
            $kit = $fixture['template']($name, 'v1.0.0-beta.1');
            if ($origin !== null) {
               $quoted = escapeshellarg($origin);
               $Run($kit, "remote add origin {$quoted}");
            }

            // :
            return $kit;
         };

         // ! Every leg: no proxy reachable and an ssh that only writes a witness (a
         //   request that slips through fails locally), and the canonical
         //   repository served by the local fixture
         $proxy = ['http.proxy', 'http://127.0.0.1:9'];
         $ssh = ['core.sshCommand', 'echo >>WITNESS'];
         $hermetic = [$proxy, $ssh];
         $mirror = ["url.{$canon}.insteadOf", $repository];
         $ext = ['protocol.ext.allow', 'always'];
         // ! What a caller that wants ext exports: the helper beside the transports
         $operator = 'ext:file:https:ssh';

         // ? The configuration channel reaches git — without it no leg is hermetic
         $Configure([$proxy], null);
         try {
            $channel = $Run($canon, 'config --get http.proxy');
         }
         finally {
            $Clear();
         }

         yield assert(
            assertion: $channel === 'http://127.0.0.1:9',
            description: 'probe precondition: git honours GIT_CONFIG_COUNT (older git drops it, and with it the mirror and the proxy) — no leg runs without it'
         );
         yield assert(
            assertion: preg_match('/[\s%]/', $canon) === 0
               && $Run($canon, 'tag --no-column -l v1.0.0') === 'v1.0.0',
            description: 'probe precondition: the canonical fixture is tagged and its path is one ext word (no blank, no %)'
         );

         // # Credentials in the releases remote: refused before any fetch, never shown
         foreach (['https://user:S3CRET@github.com/bootgly/bootgly.kit', 'https://S3CRETTOKEN@github.com/bootgly/bootgly.kit'] as $index => $URL) {
            $kit = $Kit("credentials-{$index}", $URL);
            [$result, $document, $JSON] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $mirror], null);
            [, , $rendered] = $Probe($Bind($kit, $repository), [], [...$hermetic, $mirror], null);

            yield assert(
               assertion: $result === false && ($document['status'] ?? null) === 'refused'
                  && ($document['remote'] ?? null) === 'origin' && ($document['added'] ?? null) === false
                  && str_contains($document['reason'] ?? '', 'carries credentials')
                  && array_key_exists('fetched', $document) === false
                  && str_contains($rendered, 'carries credentials')
                  && str_contains($document['detail'] ?? '', "git remote set-url origin {$repository}"),
               description: "a releases remote with credentials ({$index}: user:password, then a token as the username) is refused before any fetch, with the set-url fix"
            );
            yield assert(
               assertion: str_contains($JSON, 'S3CRET') === false && str_contains($rendered, 'S3CRET') === false,
               description: "the refusal ({$index}) names the remote, never its URL: the secret is in neither the JSON nor the rendered output"
            );
         }

         // # A credential the user's own insteadOf adds: judged on the URL the kit stores
         $token = ['url.https://TOKEN123@github.com/.insteadOf', 'https://github.com/'];
         $kit = $Kit('token-rewrite', $repository);
         $Configure([...$hermetic, $token], null);
         try {
            $expanded = $Run($kit, 'remote get-url origin');
         }
         finally {
            $Clear();
         }

         yield assert(
            assertion: $expanded === 'https://TOKEN123@github.com/bootgly/bootgly.kit'
               && $Run($kit, 'config --get remote.origin.url') === $repository,
            description: 'probe precondition: get-url expands the user\'s insteadOf to a token URL while the stored origin is the plain canonical one'
         );

         [$result, $document, $JSON] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $token], null);
         [, , $rendered] = $Probe($Bind($kit, $repository), [], [...$hermetic, $token], null);

         yield assert(
            assertion: ($document['remote'] ?? null) === 'origin' && ($document['added'] ?? null) === false
               && str_contains($document['reason'] ?? '', 'carries credentials') === false
               && ($document['fetched'] ?? null) === false
               && str_contains($rendered, 'carries credentials') === false
               && str_contains($JSON, 'TOKEN123') === false && str_contains($rendered, 'TOKEN123') === false,
            description: 'a token the user\'s own insteadOf adds to the canonical origin is not refused: the stored URL is plain, so the kit goes on to the fetch (through the closed proxy), and the token is never shown'
         );

         // ! The token rewrite to the local fixture (git ignores a file:// authority):
         //   the kit adds `bootgly` — stored plain — and fetches through the token URL
         $local = ["url.file://TOKEN123@{$canon}.insteadOf", $repository];
         $kit = $Kit('token-local', null);
         [$result, $document, $JSON] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $local], null);
         $shown = $Kit('token-local-rendered', null);
         [, , $rendered] = $Probe($Bind($shown, $repository), [], [...$hermetic, $local], null);

         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'listed'
               && ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && $Run($kit, 'config --get remote.bootgly.url') === $repository
               && ($document['fetched'] ?? null) === true && ($document['verified'] ?? null) === true
               && str_contains($JSON, 'TOKEN123') === false && str_contains($rendered, 'TOKEN123') === false,
            description: 'a canonical repository the user\'s insteadOf rewrites to file://TOKEN123@<fixture> is fetched and verified (the stored URL is plain), and the token is never shown'
         );

         // ! The same token in the STORED url — the rewrite does not apply to it
         $kit = $Kit('token-stored', 'https://TOKEN123@github.com/bootgly/bootgly.kit');
         [$result, $document, $JSON] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $token], null);
         [, , $rendered] = $Probe($Bind($kit, $repository), [], [...$hermetic, $token], null);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && ($document['remote'] ?? null) === 'origin'
               && str_contains($document['reason'] ?? '', 'carries credentials')
               && array_key_exists('fetched', $document) === false
               && str_contains($JSON, 'TOKEN123') === false && str_contains($rendered, 'TOKEN123') === false,
            description: 'under the same insteadOf, an origin that STORES the token is still refused before any fetch, and the token is never shown'
         );

         // ! The stored token FIRST, the plain canonical URL as a second value:
         //   git dials the first, `config --get` reads the last
         $kit = $Kit('token-first', 'https://TOKEN123@github.com/bootgly/bootgly.kit');
         $quoted = escapeshellarg($repository);
         $Run($kit, "config --add remote.origin.url {$quoted}");
         $Configure([...$hermetic, $token], null);
         try {
            $first = $Run($kit, 'remote get-url origin');
         }
         finally {
            $Clear();
         }

         yield assert(
            assertion: $first === 'https://TOKEN123@github.com/bootgly/bootgly.kit'
               && $Run($kit, 'config --get-all remote.origin.url') === $first
               && $Run($kit, 'config --get remote.origin.url') === $repository,
            description: 'probe precondition: an origin storing the token URL first and the plain canonical one second — get-url (the URL git dials) shows the token, `config --get` the plain last value'
         );

         [$result, $document, $JSON] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $token], null);
         [, , $rendered] = $Probe($Bind($kit, $repository), [], [...$hermetic, $token], null);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && ($document['remote'] ?? null) === 'origin' && ($document['added'] ?? null) === false
               && str_contains($document['reason'] ?? '', 'carries credentials')
               && array_key_exists('fetched', $document) === false
               && str_contains($rendered, 'carries credentials')
               && str_contains($JSON, 'TOKEN123') === false && str_contains($rendered, 'TOKEN123') === false,
            description: 'an origin storing the token URL first and the plain canonical URL after it is refused before any fetch: every stored URL is judged, not the last one `config --get` reads, and the token is never shown'
         );

         // ! The plain canonical URL FIRST and the token URL second, under the
         //   token rewrite: git dials the first (expanded to a token URL), and
         //   the stored token behind it must still refuse the lift
         $kit = $Kit('token-second', $repository);
         $tokened = escapeshellarg('https://TOKEN123@github.com/bootgly/bootgly.kit');
         $Run($kit, "config --add remote.origin.url {$tokened}");
         [$result, $document, $JSON] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $token], null);

         yield assert(
            assertion: $Run($kit, 'config --get-all remote.origin.url') === $repository
               && $Run($kit, 'config --get remote.origin.url') === 'https://TOKEN123@github.com/bootgly/bootgly.kit'
               && $result === false && ($document['status'] ?? null) === 'refused'
               && str_contains($document['reason'] ?? '', 'carries credentials')
               && array_key_exists('fetched', $document) === false
               && str_contains($JSON, 'TOKEN123') === false,
            description: 'an origin storing the plain canonical URL first and a token URL second is refused too: the lift needs EVERY stored URL clean, not only the first one git dials'
         );

         // # A helper URL whose tail spells the canonical repository
         $helper = 'ext::sh -c touch% WITNESS;#@github.com:bootgly/bootgly.kit';

         yield assert(
            assertion: $Control('control-helper', $helper, 'ls-remote origin', [...$hermetic, $ext], $operator) !== null,
            description: "probe precondition: git itself, under GIT_ALLOW_PROTOCOL={$operator}, runs the helper origin's command"
         );

         $kit = $Kit('helper', $helper);
         [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $mirror, $ext], $operator);

         yield assert(
            assertion: ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && $Run($kit, 'remote get-url bootgly') === $repository && $Run($kit, 'remote get-url origin') === $helper,
            description: 'an ext:: origin ending in @github.com:bootgly/bootgly.kit is not adopted: `bootgly` is added beside it'
         );
         yield assert(
            assertion: $Witness($kit) === null,
            description: 'the helper origin\'s command never runs while the caller allows ext'
         );
         yield assert(
            assertion: $result === true && ($document['status'] ?? null) === 'listed'
               && ($document['fetched'] ?? null) === true && ($document['verified'] ?? null) === true,
            description: "the canonical remote added in its place is fetched and verified, while the caller exports GIT_ALLOW_PROTOCOL={$operator}"
         );

         // # A digit-led helper (git reads `0ext::` as one) whose tail spells the canonical repository
         $digit = '0ext::sh -c touch% WITNESS;#@github.com:bootgly/bootgly.kit';
         $zero = ['protocol.0ext.allow', 'always'];

         yield assert(
            assertion: $Control('control-digit', $digit, 'ls-remote origin', [...$hermetic, $zero], '0ext:file:https:ssh') !== null,
            description: 'probe precondition: git itself, with 0ext allowed, runs the git-remote-0ext helper the origin names'
         );

         $kit = $Kit('digit', $digit);
         [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $mirror, $zero], '0ext:file:https:ssh');

         yield assert(
            assertion: ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && $Run($kit, 'remote get-url origin') === $digit
               && $result === true && ($document['fetched'] ?? null) === true
               && $Witness($kit) === null,
            description: 'a 0ext:: origin ending in @github.com:bootgly/bootgly.kit is not adopted: `bootgly` is added and fetched, and the helper never runs while the caller allows 0ext'
         );

         // # An https authority curl reads as another host
         $spoof = 'https://attacker.invalid?@github.com/bootgly/bootgly.kit';
         $kit = $Kit('spoof', $spoof);
         [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $mirror], null);

         yield assert(
            assertion: ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && $Run($kit, 'remote get-url bootgly') === $repository && $Run($kit, 'remote get-url origin') === $spoof
               && $result === true && ($document['fetched'] ?? null) === true,
            description: 'an origin at attacker.invalid?@github.com/bootgly/bootgly.kit is not adopted: `bootgly` is added and fetched instead'
         );

         // # An scp-like address: git ends the host at the FIRST `:` (brackets stripped)
         foreach (['evil.invalid:x@github.com:bootgly/bootgly.kit', '[evil.invalid]:x@github.com:bootgly/bootgly.kit'] as $index => $address) {
            $dialed = $Control("control-scp-{$index}", $address, 'ls-remote origin', $hermetic, null);

            yield assert(
               assertion: $dialed !== null && str_contains($dialed, " evil.invalid git-upload-pack 'x@github.com:bootgly/bootgly.kit'"),
               description: "probe precondition: git itself dials evil.invalid for the origin {$address}, never github.com"
            );

            $kit = $Kit("scp-{$index}", $address);
            [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $mirror], null);

            yield assert(
               assertion: ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
                  && $Run($kit, 'remote get-url origin') === $address
                  && $result === true && ($document['fetched'] ?? null) === true
                  && $Witness($kit) === null,
               description: "an scp-like origin {$address} is not adopted: `bootgly` is added and fetched, and ssh never dials evil.invalid"
            );
         }

         // # The canonical repository rewritten by insteadOf to an ext:: command
         $rewrite = ["url.ext::sh -c touch% WITNESS;git% %s% {$canon}.insteadOf", $repository];

         yield assert(
            assertion: $Control('control-rewrite', '', "ls-remote {$repository}", [...$hermetic, $rewrite, $ext], $operator) !== null,
            description: "probe precondition: git itself, under GIT_ALLOW_PROTOCOL={$operator}, runs the command the canonical repository is rewritten to"
         );

         $kit = $Kit('rewrite', null);
         [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $rewrite, $ext], $operator);

         yield assert(
            assertion: $result === false && ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && str_contains($document['reason'] ?? '', 'is a remote helper, not a repository.')
               && array_key_exists('fetched', $document) === false,
            description: 'a canonical repository rewritten to ext:: is refused as a remote helper before any fetch: get-url expands insteadOf, so the screen sees the helper'
         );
         yield assert(
            assertion: $Witness($kit) === null,
            description: 'the command the canonical repository is rewritten to never runs while the caller allows ext'
         );

         // # The canonical repository rewritten by insteadOf to an authority curl reads as another host
         $malformed = ['url.https://attacker.invalid?@github.com/bootgly/bootgly.kit.insteadOf', $repository];
         $kit = $Kit('malformed', null);
         [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], [...$hermetic, $malformed], null);

         yield assert(
            assertion: $result === false && ($document['status'] ?? null) === 'refused'
               && ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && str_contains($document['reason'] ?? '', 'has a malformed URL.')
               && array_key_exists('fetched', $document) === false,
            description: 'a canonical repository rewritten to attacker.invalid?@github.com is refused as a malformed URL before any fetch: get-url expands insteadOf, so the screen sees the authority'
         );

         // # A helper the screen cannot see: the canonical repository rewritten by insteadOf
         //   to a "local path" that remote.<name>.vcs hands to git-remote-ext
         $command = "/bin/sh -c touch% WITNESS;git% %s% {$canon}";
         $path = ["url.{$command}.insteadOf", $repository];

         yield assert(
            assertion: $Control('control-vcs', $repository, 'ls-remote origin', [...$hermetic, $path, ['remote.origin.vcs', 'ext'], $ext], $operator) !== null,
            description: "probe precondition: git itself, under GIT_ALLOW_PROTOCOL={$operator}, runs the canonical repository rewritten to a path remote.origin.vcs hands to ext"
         );

         // ! Two kits: a second run on the first would refuse — the `bootgly` it
         //   added reads, through get-url, as the rewritten path (points elsewhere)
         $hidden = [...$hermetic, $path, ['remote.bootgly.vcs', 'ext'], $ext];
         $kit = $Kit('vcs', null);
         [$result, $document] = $Probe($Bind($kit, $repository), ['json' => true], $hidden, $operator);
         $shown = $Kit('vcs-rendered', null);
         [, , $rendered] = $Probe($Bind($shown, $repository), [], $hidden, $operator);

         yield assert(
            assertion: ($document['remote'] ?? null) === 'bootgly' && ($document['added'] ?? null) === true
               && $Run($kit, 'remote get-url bootgly') === $repository
               && ($document['fetched'] ?? null) === false && $result === false
               && str_contains($rendered, 'Could not fetch the releases from'),
            description: 'the canonical repository rewritten to a path remote.bootgly.vcs hands to ext passes the screen and is added, but is not fetched — "Could not fetch"'
         );
         yield assert(
            assertion: $Witness($kit) === null && $Witness($shown) === null,
            description: "the transfer allow-list narrows the exported GIT_ALLOW_PROTOCOL={$operator} (and protocol.ext.allow=always) to file:https:ssh: the command never runs in either kit"
         );
      }
      finally {
         // ! Nothing this spec exported outlives it
         foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
         }
         $Erase($base);
      }
   }
);
