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


use function explode;
use function in_array;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strrpos;
use function strspn;
use function strtolower;
use function substr;
use function trim;

use Bootgly\ACI\VCS\Remotes\Refusals;


/**
 * The remotes of one working tree.
 */
final class Remotes
{
   /** The transports a remote may use — anything else is refused. */
   public const array TRANSPORTS = ['https', 'ssh', 'git+ssh', 'file'];
   /** The refused transports that are plaintext — the rest are merely unsupported. */
   private const array PLAINTEXT = ['http', 'git', 'ftp'];
   /** The transports whose userinfo is a login, not a secret. */
   private const array SHELLS = ['ssh', 'git+ssh'];

   // * Config
   public private(set) Git $Git;


   public function __construct (Git $Git)
   {
      // * Config
      $this->Git = $Git;
   }

   /**
    * List the remotes with their fetch URLs.
    *
    * @return array<string,string> Remote name => fetch URL.
    */
   public function list (): array
   {
      $names = $this->Git->query(['remote']);
      // ?
      if ($names === null || $names === '') {
         return [];
      }

      // @@
      $remotes = [];
      foreach (explode("\n", $names) as $name) {
         $name = trim($name);
         if ($name === '') {
            continue;
         }

         // ! `--`: a remote named like an option is a name, never a switch
         $URL = $this->Git->query(['remote', 'get-url', '--', $name]);
         if ($URL !== null) {
            $remotes[$name] = $URL;
         }
      }

      // :
      return $remotes;
   }

   /**
    * Find the remote pointing at a repository, whatever the spelling of its URL.
    *
    * `https://github.com/bootgly/bootgly.kit`, `git@github.com:bootgly/bootgly.kit.git`
    * and `ssh://git@github.com/bootgly/bootgly.kit/` all name the same repository.
    *
    * @param string $URL The repository URL (or local path) to look for.
    *
    * @return null|string The remote name, or null when no remote points there.
    */
   public function find (string $URL): null|string
   {
      $wanted = self::normalize($URL);
      // ?
      if ($wanted === '') {
         return null;
      }

      // @@
      foreach ($this->list() as $name => $candidate) {
         if (self::normalize($candidate) === $wanted) {
            return $name;
         }
      }

      // :
      return null;
   }

   /**
    * Add a remote.
    *
    * @param string $name The remote name.
    * @param string $URL Its URL.
    *
    * @return bool True when git accepted it.
    */
   public function add (string $name, string $URL): bool
   {
      return $this->Git->execute(['remote', 'add', '--', $name, $URL]) === 0;
   }

   /**
    * Screen a repository URL against the transports a fetch may use.
    *
    * Admitted: `https://host/…` (no userinfo — a lone name may be a token),
    * `ssh://[user@]host/…` and `git+ssh://…` (a login, never a password),
    * scp-like `[user@]host:path`, `file://…` (no userinfo) and a local path.
    * Refused, with the reason:
    * - `Malformed` — an empty, padded or option-shaped value, a control byte,
    *   a `://` that does not open the value, a slash past the second one
    *   (curl still finds an authority after it), an authority carrying `?`,
    *   `#`, `\` or a blank (curl and git would dial different hosts), a host
    *   starting with a dash (ssh would read an option), and an scp-like host
    *   holding a blank or named like a scheme (`https:/…`, a URL short of a slash);
    * - `Helper` — `<helper>::<address>`, the name led by a letter or a digit
    *   as git reads it (`ext::` runs a command);
    * - `Plaintext` — `http://`, `git://` and `ftp://`; `Unsupported` — any
    *   other scheme;
    * - `Credentials` — userinfo on `https://` or `file://`, a password on
    *   `ssh://`, and an scp-like `user:password@host:path`.
    * The authority runs to the first `/`, as git reads it.
    *
    * The same transports are enforced again inside git on every transfer
    * (`Git::PROTOCOLS`), where an `insteadOf` rewrite is visible and this
    * string is not.
    *
    * @param string $URL The repository URL, as typed.
    *
    * @return null|Refusals Null when admitted; the reason otherwise.
    */
   public static function screen (string $URL): null|Refusals
   {
      // ? Empty, option-shaped, padded or carrying a control byte
      if ($URL === '' || $URL[0] === '-' || trim($URL) !== $URL || preg_match('/[\x00-\x1F\x7F]/', $URL) === 1) {
         return Refusals::Malformed;
      }
      // ? `<helper>::<address>` — git hands the address to `git-remote-<helper>`
      //   (a helper name may start with a digit, as git reads it)
      if (preg_match('/^[A-Za-z0-9][A-Za-z0-9+.-]*::/', $URL) === 1) {
         return Refusals::Helper;
      }

      // # `scheme://authority/path` — the authority runs to the first `/`, as git reads it
      if (preg_match('#^([A-Za-z0-9][A-Za-z0-9+.-]*)://(/*)([^/]*)#', $URL, $matches) === 1) {
         $scheme = strtolower($matches[1]);
         $slashes = $matches[2];
         $authority = $matches[3];

         // ?: A local path in disguise — git ignores its authority, but a
         //   credential written there is still kept as the clone's `origin`
         if ($scheme === 'file') {
            return $slashes === '' && str_contains($authority, '@') === true ? Refusals::Credentials : null;
         }
         // ?:
         if (in_array($scheme, self::TRANSPORTS, true) === false) {
            return in_array($scheme, self::PLAINTEXT, true) === true ? Refusals::Plaintext : Refusals::Unsupported;
         }
         // ?: A slash past the second one — curl still finds an authority after it
         if ($slashes !== '') {
            return Refusals::Malformed;
         }

         $at = strrpos($authority, '@');
         $host = $at === false ? $authority : substr($authority, $at + 1);
         // ?: Where curl and git would dial different hosts, or ssh would read an option
         if (preg_match('/[?#\\\\\s]/', $authority) === 1 || str_starts_with($host, '-') === true) {
            return Refusals::Malformed;
         }
         // ?: A secret in the URL
         if ($at !== false && ($scheme === 'https' || str_contains(substr($authority, 0, $at), ':') === true)) {
            return Refusals::Credentials;
         }

         // :
         return null;
      }

      // ? A `scheme://` that does not open the value — a pasted URL with a
      //   prefix git reads as an scp-like host or a path, shown as typed
      if (str_contains($URL, '://') === true) {
         return Refusals::Malformed;
      }

      // # scp-like `[user@]host:path` — a colon before any slash; anything else is a local path
      if (preg_match('#^([^/]*?):#', $URL, $matches) === 1) {
         $at = strrpos($matches[1], '@');
         $host = $at === false ? $matches[1] : substr($matches[1], $at + 1);

         // ?: A host ssh would read as an option, one no resolver names (a blank,
         //   invalid UTF-8), or a scheme short of its slashes (`https:/…`)
         if (str_starts_with($host, '-') === true || preg_match('/[\s\p{Z}]/u', $matches[1]) !== 0
            || in_array(strtolower($host), [...self::TRANSPORTS, ...self::PLAINTEXT, 'ftps', 'ssh+git'], true) === true) {
            return Refusals::Malformed;
         }
         // ?: `user:password@host:path` — git dials host `user`, the password rides in the path
         if (preg_match('#^[^/:@]*:[^/@]*@[^/]*:#', $URL) === 1) {
            return Refusals::Credentials;
         }
      }

      // :
      return null;
   }

   /**
    * Redact a repository URL for display.
    *
    * The userinfo of a `scheme://` URL is dropped (a lone name may be a token),
    * except an SSH login with no password; it runs to the last `@` before the
    * next `/` after the scheme's slashes (any extra ones skipped, except in
    * `file://`), so nothing git or curl could read as a credential is shown.
    * An scp-like `user:password@host:path` loses its `user:password@`; other
    * scp-like addresses and paths come back unchanged. Display only — it says
    * nothing about which host is dialed: `screen()` the URL first.
    *
    * @param string $URL The repository URL.
    *
    * @return string The URL without its credentials.
    */
   public static function redact (string $URL): string
   {
      // ?: An scp-like `user:password@host:path`
      if (str_contains($URL, '://') === false && preg_match('#^[^/:@]*:[^/@]*@([^/]*:.*)$#s', $URL, $matches) === 1) {
         return $matches[1];
      }
      // ?: No `scheme://`
      if (preg_match('#^([A-Za-z0-9][A-Za-z0-9+.-]*)://#', $URL, $matches) !== 1) {
         return $URL;
      }

      $scheme = strtolower($matches[1]);
      $rest = substr($URL, strlen($matches[0]));
      // ! Slashes past the second one still lead curl to an authority — never in `file://`
      $slashes = $scheme === 'file' ? '' : substr($rest, 0, strspn($rest, '/'));
      $rest = substr($rest, strlen($slashes));
      $authority = substr($rest, 0, strcspn($rest, '/'));
      $at = strrpos($authority, '@');
      // ?: No userinfo
      if ($at === false) {
         return $URL;
      }

      // ! An SSH login stays; any other userinfo goes
      $userinfo = substr($authority, 0, $at);
      $login = in_array($scheme, self::SHELLS, true) === true && str_contains($userinfo, ':') === false
         ? "{$userinfo}@"
         : '';
      $tail = substr($rest, $at + 1);

      // :
      return "{$matches[1]}://{$slashes}{$login}{$tail}";
   }

   /**
    * Reduce a repository URL to a comparable `host/path` (or a local path).
    *
    * Scheme, user, port, a trailing slash and a trailing `.git` are dropped;
    * the result is lowercased — hosting services do not distinguish case in
    * an owner or repository name.
    *
    * @param string $URL
    *
    * @return string The normalized form; empty when the URL is empty.
    */
   public static function normalize (string $URL): string
   {
      $URL = trim($URL);

      // ? A remote helper names a program, not a repository — comparable to nothing
      if (preg_match('#^[a-z0-9][a-z0-9+.-]*::#i', $URL) === 1) {
         return '';
      }
      // ! `file://` is a local path in disguise — git ignores its authority, so
      //   one that names a host (`file://github.com/…`) is comparable to nothing
      if (preg_match('#^file://#i', $URL) === 1) {
         $URL = substr($URL, 7);

         // ?
         if (str_starts_with($URL, '/') === false) {
            return '';
         }
      }
      // ! `user@host:path` (scp-like) and `scheme://[user@]host[:port]/path` both reduce to `host/path` —
      //   an authority carrying `?`, `#`, `\` or a blank matches neither (curl and git read different hosts in it)
      elseif (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/?\#\\\\\s]+@)?([^/:?\#\\\\\s@]+)(?::\d+)?/(.*)$#i', $URL, $matches) === 1) {
         $URL = "{$matches[1]}/{$matches[2]}";
      }
      // ! scp-like: git ends the host at the FIRST `:` — a login holds no `:` (nor brackets),
      //   so `evil:x@github.com:o/r` reduces to the host git dials, `evil`
      elseif (preg_match('#^(?:[^@/:\[\]]+@)?([^/:@\[\]]+):(?!//)(.*)$#', $URL, $matches) === 1) {
         $URL = "{$matches[1]}/{$matches[2]}";
      }
      // ? Anything else is a local path — and only an absolute one is comparable:
      //   a bare `github.com/owner/repo` is a directory, not the repository it spells
      elseif (str_starts_with($URL, '/') === false) {
         return '';
      }

      $URL = rtrim($URL, '/');
      if (str_ends_with($URL, '.git') === true) {
         $URL = substr($URL, 0, -4);
      }
      $URL = preg_replace('#/{2,}#', '/', $URL) ?? $URL;

      // :
      return strtolower(rtrim($URL, '/'));
   }
}
