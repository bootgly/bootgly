<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\VCS;


use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use function assert;
use function implode;
use function json_encode;
use Closure;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\VCS\Remotes\Refusals;


/**
 * A pure table over the three string functions of `Remotes`, with no git and
 * no filesystem:
 * - `screen()` admits https, ssh (`ssh://`, `git+ssh://`, scp-like),
 *   `file://` (whatever host it names — git ignores it) and local paths, and
 *   names the exact reason for everything else: a remote helper (a digit-led
 *   name too, as git reads one), a plaintext or unsupported scheme,
 *   credentials in the URL (any `@` in a `file://` authority too — git
 *   ignores it but keeps it as the clone's `origin` —, and an scp-like
 *   `user:password@host:path`), or a malformed value (option-shaped, padded,
 *   a control byte, an authority curl and git read apart — behind a third
 *   slash included —, a `scheme://` that does not open the value, a host ssh
 *   would take for an option, no resolver names, or named like a scheme — a
 *   URL short of a slash).
 * - `redact()` drops the userinfo of a `scheme://` URL (keeping an SSH login)
 *   and the `user:password@` of an scp-like address, and changes nothing after
 *   its authority — which, but in `file://`, comes after every slash curl
 *   skips. The display sites of `projects import`
 *   cannot reach it end to end: once credentials are refused, no admitted URL
 *   carries a secret. So this table is the only spec that would notice a
 *   broken `redact()`.
 * - `normalize()` matches nothing for a spoofed authority, a remote helper or
 *   a `file://` that names a host (git reads a local path there, not that
 *   host), reduces an scp-like value to the host git dials — its FIRST `:` —,
 *   while the canonical spellings of spec 1.2 still reduce to one value.
 *
 * Each leg reports its mismatched rows by input in its description.
 */

return new Test(
   description: 'screen() admits https, ssh and local repositories and names why it refuses the rest; redact() hides credentials; normalize() matches no spoof',
   test: function () {
      // ! A result as the failure message shows it — an enum case by name, anything else as JSON
      $Show = static function (mixed $value): string {
         // ?:
         if ($value instanceof Refusals) {
            return "Refusals::{$value->name}";
         }

         // :
         return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
      };
      // ! A no-break space (U+00A0) — `trim()` keeps it, a pasted URL may lead with it
      $NBSP = "\u{00A0}";
      // ! Every row whose result differs from the expected one — empty when none does
      /** @param array<string,null|Refusals|string> $table */
      $Compare = static function (Closure $Method, array $table) use ($Show): string {
         $mismatches = [];

         // @@
         foreach ($table as $input => $expected) {
            $result = $Method($input);

            if ($result !== $expected) {
               $mismatches[] = "{$Show($input)} gave {$Show($result)}, expected {$Show($expected)}";
            }
         }

         // ?:
         if ($mismatches === []) {
            return '';
         }
         $joined = implode('; ', $mismatches);

         // :
         return " — mismatched: {$joined}";
      };

      // # screen() — admitted
      $mismatches = $Compare(Remotes::screen(...), [
         'https://github.com/bootgly/bootgly.kit' => null,
         'HTTPS://GitHub.com/Bootgly/bootgly.kit.git' => null,
         'https://git.example.invalid:8443/o/r.git' => null,
         'https://[::1]:8443/o/r' => null,
         // ! `?`, `#` and `@` after the first `/` belong to the path, not to the authority
         'https://github.com/bootgly/bootgly.kit?ref=a#b' => null,
         'https://github.com/bootgly/bootgly.kit@v1' => null,
         'ssh://git@github.com/bootgly/bootgly.kit' => null,
         'ssh://git@github.com:22/bootgly/bootgly.kit/' => null,
         'ssh://github.com/bootgly/bootgly.kit' => null,
         'git+ssh://git@github.com/bootgly/bootgly.kit' => null,
         'git@github.com:bootgly/bootgly.kit.git' => null,
         'github.com:bootgly/bootgly.kit' => null,
         // ! An `@` in an scp-like path with no `host:` after it is the path's — no password
         'host:repo@1.0' => null,
         'file:///tmp/fixture' => null,
         // ! A `file://` host is ignored by git — no userinfo, nothing to refuse
         'file://evil.invalid/tmp/x' => null,
         // ! Past the third slash of a `file://`, an `@` is the path's — no authority holds it
         'file:///a@b/x' => null,
         '/tmp/fixture' => null,
         // ! A blank is refused in an scp-like host only — a path may hold one
         '/tmp/My Repo' => null,
         '/home/me/a:b' => null,
         // ! A colon after a `/` is a path, never an scp-like host — `@-` there included
         '/home/me/git@-mirror:x' => null,
         '../rel:x' => null,
         './rel' => null,
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "screen() admits https (host, port, IPv6 literal), ssh:// with or without a login, git+ssh://, scp-like (an `@` in its path included), file:// (empty or host authority, an `@` past the third slash) and local paths (a blank included){$mismatches}"
      );

      // # screen() — remote helpers
      $mismatches = $Compare(Remotes::screen(...), [
         'ext::sh -c touch% W' => Refusals::Helper,
         'fd::0' => Refusals::Helper,
         'https::http://github.com/bootgly/bootgly.kit' => Refusals::Helper,
         'testsvn::x' => Refusals::Helper,
         // ! NEW-2: a helper that also spells the canonical host after `#@`
         'ext::sh -c touch% W;#@github.com:bootgly/bootgly.kit' => Refusals::Helper,
         // ! git reads a helper name that starts with a digit too
         '0ext::sh -c x;#@github.com:bootgly/bootgly.kit' => Refusals::Helper,
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "screen() refuses every `<helper>::<address>` as Helper — ext::, fd::, https::, testsvn::, the NEW-2 string and a digit-led 0ext::{$mismatches}"
      );

      // # screen() — refused schemes
      $mismatches = $Compare(Remotes::screen(...), [
         'http://github.com/bootgly/bootgly.kit' => Refusals::Plaintext,
         'HTTP://github.com/bootgly/bootgly.kit' => Refusals::Plaintext,
         'git://github.com/bootgly/bootgly.kit' => Refusals::Plaintext,
         'ftp://host.invalid/o/r' => Refusals::Plaintext,
         'ftps://host.invalid/o/r' => Refusals::Unsupported,
         'rsync://host.invalid/o/r' => Refusals::Unsupported,
         'ssh+git://git@github.com/bootgly/bootgly.kit' => Refusals::Unsupported,
         // ! A scheme that starts with a digit is still a scheme — unsupported, not malformed
         '0foo://x' => Refusals::Unsupported,
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "screen() refuses http (any case), git and ftp as Plaintext, and ftps, rsync, ssh+git and a digit-led scheme as Unsupported{$mismatches}"
      );

      // # screen() — credentials
      $mismatches = $Compare(Remotes::screen(...), [
         'https://user:S3CRET@github.com/bootgly/bootgly.kit' => Refusals::Credentials,
         'https://ghp_TOKEN@github.com/bootgly/bootgly.kit' => Refusals::Credentials,
         'https://@github.com/bootgly/bootgly.kit' => Refusals::Credentials,
         'ssh://git:S3CRET@github.com/bootgly/bootgly.kit' => Refusals::Credentials,
         'git+ssh://git:S3CRET@github.com/bootgly/bootgly.kit' => Refusals::Credentials,
         // ! git ignores a `file://` authority but keeps it as the clone's `origin` — any `@` there, any case
         'file://u:pw@host.invalid/tmp/fixture' => Refusals::Credentials,
         'FILE://tok@/x' => Refusals::Credentials,
         // ! scp-like `user:password@host:path` — git dials host `user`, the password rides in the path
         'user:S3CRET@host:o/r' => Refusals::Credentials,
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "screen() refuses any userinfo on https (password, lone token, bare `@`), a password on ssh, any `@` in a file:// authority (password or lone token, any case) and an scp-like `user:password@host:path` as Credentials{$mismatches}"
      );

      // # screen() — malformed
      $mismatches = $Compare(Remotes::screen(...), [
         '' => Refusals::Malformed,
         '--upload-pack=touch W' => Refusals::Malformed,
         '-utouch W' => Refusals::Malformed,
         "/tmp/a\nb" => Refusals::Malformed,
         "https://github.com/bootgly/\e[1mbootgly.kit" => Refusals::Malformed,
         "/tmp/fixture\0x" => Refusals::Malformed,
         "https://github.com/bootgly/\x7Fbootgly.kit" => Refusals::Malformed,
         // ! R7: curl reads the host before `?` / `#`, git reads it after the last `@`
         'https://attacker.invalid?@github.com/bootgly/bootgly.kit' => Refusals::Malformed,
         'https://attacker.invalid#@github.com/bootgly/bootgly.kit' => Refusals::Malformed,
         'https://github.com#@attacker.invalid/bootgly/bootgly.kit' => Refusals::Malformed,
         'https://attacker.invalid\\@github.com/bootgly/bootgly.kit' => Refusals::Malformed,
         'https://attacker.invalid @github.com/bootgly/bootgly.kit' => Refusals::Malformed,
         // ! A host ssh would read as an option, in both syntaxes
         'ssh://-oProxyCommand=x/p' => Refusals::Malformed,
         'ssh://git@-oProxyCommand=x/p' => Refusals::Malformed,
         '-oProxyCommand=x:y' => Refusals::Malformed,
         'git@-oProxy:x' => Refusals::Malformed,
         // ! A slash past the second one: curl still finds the authority — and its userinfo — after it
         'https:///user:S3CRET@host/o/r' => Refusals::Malformed,
         'HTTPS:///tok@h/x' => Refusals::Malformed,
         // ! Padded, or a `scheme://` that does not open the value: git reads an
         //   scp-like host or a path there, and the refusal would show it as typed
         ' https://u:p@h/x' => Refusals::Malformed,
         "{$NBSP}https://u:p@h/x" => Refusals::Malformed,
         'https://u:p@h/x ' => Refusals::Malformed,
         '<https://user:S3CRET@github.com/bootgly/bootgly.kit>' => Refusals::Malformed,
         // ! An scp-like host no resolver names — a blank, invalid UTF-8
         'My Repo:x' => Refusals::Malformed,
         "git@\xFFhost:x" => Refusals::Malformed,
         // ! An scp-like host named like a scheme, any case — a URL short of a slash,
         //   which git would dial over ssh as host `https`, its userinfo in the path
         'https:/user:S3CRET@host/o/r' => Refusals::Malformed,
         'https:user:S3CRET@host/o/r' => Refusals::Malformed,
         'HTTPS:/x' => Refusals::Malformed,
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "screen() refuses empty, option-shaped, padded and control-byte values, an authority with `?`, `#`, `\\` or a blank or behind a third slash, a `scheme://` that does not open the value, a dash-led ssh host and an scp-like host with a blank or invalid UTF-8 or named like a scheme (any case) as Malformed{$mismatches}"
      );

      // # redact()
      $mismatches = $Compare(Remotes::redact(...), [
         'https://user:S3CRET@github.com/bootgly/bootgly.kit' => 'https://github.com/bootgly/bootgly.kit',
         'https://ghp_TOKEN@github.com/bootgly/bootgly.kit.git' => 'https://github.com/bootgly/bootgly.kit.git',
         'HTTPS://ghp_TOKEN@github.com/bootgly/bootgly.kit' => 'HTTPS://github.com/bootgly/bootgly.kit',
         'https://@github.com/bootgly/bootgly.kit' => 'https://github.com/bootgly/bootgly.kit',
         // ! The userinfo runs to the LAST `@` before the first `/`, whatever it carries
         'https://u@S3CRET:pw@github.com/bootgly/bootgly.kit' => 'https://github.com/bootgly/bootgly.kit',
         'https://S3CRET?@github.com/bootgly/bootgly.kit' => 'https://github.com/bootgly/bootgly.kit',
         // ! An SSH login stays; an SSH password takes the whole userinfo with it
         'ssh://git@github.com/bootgly/bootgly.kit' => 'ssh://git@github.com/bootgly/bootgly.kit',
         'SSH://git@github.com/bootgly/bootgly.kit' => 'SSH://git@github.com/bootgly/bootgly.kit',
         'git+ssh://git@github.com/bootgly/bootgly.kit' => 'git+ssh://git@github.com/bootgly/bootgly.kit',
         'ssh://git:S3CRET@github.com/bootgly/bootgly.kit' => 'ssh://github.com/bootgly/bootgly.kit',
         // ! A `file://` userinfo goes too, leaving the empty authority
         'file://u:S3CRET@/tmp/x' => 'file:///tmp/x',
         'file://tok@/x' => 'file:///x',
         // ! Slashes past the second one: curl reads the userinfo after them, so it goes —
         //   the slashes stay; in `file://` they open the path, and an `@` there stays
         'https:///user:S3CRET@host/o/r' => 'https:///host/o/r',
         'HTTPS:///tok@h/x' => 'HTTPS:///h/x',
         'file:///a@b/x' => 'file:///a@b/x',
         // ! An scp-like `user:password@host:path` loses its `user:password@`
         'user:S3CRET@host:o/r' => 'host:o/r',
         // ! Other scp-like addresses and paths come back unchanged — an `@` in an scp-like path included
         'git@github.com:bootgly/bootgly.kit.git' => 'git@github.com:bootgly/bootgly.kit.git',
         'host:repo@1.0' => 'host:repo@1.0',
         '/home/me/a@b:c' => '/home/me/a@b:c',
         'file:///tmp/a@b' => 'file:///tmp/a@b',
         // ! Nothing after the first `/` changes — an `@` in the path is no userinfo
         'https://github.com/bootgly/bootgly.kit@v1' => 'https://github.com/bootgly/bootgly.kit@v1',
         'https://ghp_TOKEN@github.com/o/user:pw@x/r' => 'https://github.com/o/user:pw@x/r',
         'ssh://git:S3CRET@github.com/a@b' => 'ssh://github.com/a@b',
         '' => '',
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "redact() strips https and file:// userinfo (password, token, bare `@`, behind a third slash too), an ssh password with its login and an scp-like `user:password@`, keeps an ssh login, and leaves other scp-like values (an `@` in the path included), paths, a file:// path and everything after the authority intact{$mismatches}"
      );

      // # normalize() — comparable to nothing
      $mismatches = $Compare(Remotes::normalize(...), [
         // ! R7: an authority curl and git read apart never spells the canonical repository
         'https://attacker.invalid?@github.com/bootgly/bootgly.kit' => '',
         'https://attacker.invalid#@github.com/bootgly/bootgly.kit' => '',
         'https://evil.invalid:9#@github.com/bootgly/bootgly.kit' => '',
         'https://attacker.invalid\\@github.com/bootgly/bootgly.kit' => '',
         'https://attacker.invalid @github.com/bootgly/bootgly.kit' => '',
         // ! Two `@`: readers disagree on where the userinfo ends — never a host with an `@` in it
         'https://attacker.invalid@x@github.com/bootgly/bootgly.kit' => '',
         // ! A helper names a program, not a repository — NEW-2 included
         'ext::sh -c touch% W;#@github.com:bootgly/bootgly.kit' => '',
         'ext::sh -c touch% W' => '',
         'fd::0' => '',
         'https::https://github.com/bootgly/bootgly.kit' => '',
         'https::http://github.com/bootgly/bootgly.kit' => '',
         'testsvn::x' => '',
         // ! git reads a helper name that starts with a digit too
         '0ext::sh -c x;#@github.com:bootgly/bootgly.kit' => '',
         // ! git ignores a `file://` host and reads a local path — never the repository the host spells
         'file://github.com/bootgly/bootgly.kit' => '',
         'file://localhost/tmp/fixture' => '',
         // ! git strips the brackets of an scp-like host and dials it — never the host after the `@`
         '[evil.invalid]:x@github.com:bootgly/bootgly.kit' => '',
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "normalize() is empty for an authority with `?`, `#`, `\\`, a blank or a second `@`, for every remote helper (a digit-led one too), for the NEW-2 string, for a file:// that names a host and for a bracketed scp-like host{$mismatches}"
      );

      // # normalize() — the host git dials
      $mismatches = $Compare(Remotes::normalize(...), [
         // ! git ends an scp-like host at its FIRST `:` — `x@github.com:…` is the
         //   path on `evil.invalid`, never the canonical repository
         'evil.invalid:x@github.com:bootgly/bootgly.kit' => 'evil.invalid/x@github.com:bootgly/bootgly.kit',
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "normalize() reduces an scp-like `evil.invalid:x@github.com:…` to the host git dials, not to github.com/bootgly/bootgly.kit{$mismatches}"
      );

      // # normalize() — the canonical spellings of spec 1.2
      $mismatches = $Compare(Remotes::normalize(...), [
         'https://github.com/bootgly/bootgly.kit' => 'github.com/bootgly/bootgly.kit',
         'HTTPS://GitHub.com/Bootgly/bootgly.kit.git' => 'github.com/bootgly/bootgly.kit',
         'git@github.com:Bootgly/bootgly.kit.git' => 'github.com/bootgly/bootgly.kit',
         'ssh://git@github.com/bootgly/bootgly.kit/' => 'github.com/bootgly/bootgly.kit',
         'ssh://git@github.com:22/bootgly/bootgly.kit/' => 'github.com/bootgly/bootgly.kit',
         'git+ssh://git@github.com/bootgly/bootgly.kit' => 'github.com/bootgly/bootgly.kit',
         'file:///tmp/fixture' => '/tmp/fixture',
         'FILE:///tmp/fixture' => '/tmp/fixture',
         'file:///tmp/fixture/' => '/tmp/fixture',
         '/tmp/fixture' => '/tmp/fixture',
         'github.com/bootgly/bootgly.kit' => '',
      ]);

      yield assert(
         assertion: $mismatches === '',
         description: "normalize() still reduces https, scp-like, ssh:// (port, trailing slash), git+ssh:// and file:// to one value, and a bare relative `host/path` to nothing{$mismatches}"
      );
   }
);
