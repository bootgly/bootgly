<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// Bootgly CI — announcement of a stable release on Bootgly's social channels.
//
// Builds one short English message and posts it on every network named in
// `NETWORKS`:
//   PATCH        from the release notes: the `> Focus:` line and the bold
//                leads of `## Changes`, listed as Fixes, with the release link.
//   MINOR/MAJOR  from the release post on the blog (`POST`, its slug), once the
//                website serves it: its summary and, like the post, Features,
//                Improvements and Fixes, with the post and release links.
// A MINOR/MAJOR release event posts nothing: the publication of its blog post
// dispatches its announcement, the only one. A network that fails, or whose
// secret is not set, does not stop the next one; the run fails at the end when
// any did. `DRY_RUN` (any non-empty value) prints each payload instead of
// posting it, and needs no secret.
//
// Environment:
//   TAG                  release tag, `vX.Y.Z` (a pre-release is refused)
//   POST                 slug of the release post on bootgly.com/blog (MINOR/MAJOR)
//   WAIT                 seconds to wait for the website to serve POST (default: 900)
//   NETWORKS             comma-separated: telegram, discord
//   DRY_RUN              non-empty: print, never post
//   GITHUB_REPOSITORY    owner/name (default: bootgly/bootgly)
//   GITHUB_EVENT_NAME    set by GitHub Actions (`release` on a published release)
//   GH_TOKEN             optional GitHub token (raises the API rate limit)
//   TELEGRAM_BOT_TOKEN   token of a bot allowed to post in TELEGRAM_CHAT
//   TELEGRAM_CHAT        channel or group username, or id (default: @bootgly)
//   DISCORD_WEBHOOK_URL  webhook of the Discord announcements channel


/**
 * Prints a GitHub Actions annotation (`error`, `warning` or `notice`).
 */
function report (string $level, string $message): void
{
   $message = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);

   fwrite(STDERR, "::{$level}::{$message}\n");
}

/**
 * Sends one HTTPS request; returns its status (0: no response), body and transport error.
 *
 * @param non-empty-string $method
 * @param array<int,string> $headers
 *
 * @return array{int,string,string}
 */
function request (
   string $method,
   #[SensitiveParameter] string $URL,
   #[SensitiveParameter] array $headers = [],
   null|string $body = null
): array
{
   // !
   $Handle = curl_init($URL);
   if ($Handle === false) {
      return [0, '', 'curl_init() failed'];
   }
   curl_setopt_array($Handle, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 30,
   ]);
   if ($body !== null) {
      curl_setopt($Handle, CURLOPT_POSTFIELDS, $body);
   }

   // @
   $response = curl_exec($Handle);
   $status = curl_getinfo($Handle, CURLINFO_RESPONSE_CODE);

   // :
   return [
      $status,
      is_string($response) ? $response : '',
      curl_error($Handle),
   ];
}

/**
 * Cleans Markdown for line-based reading: LF line breaks, no fenced code block
 * (a `## ` inside one is not a heading), links reduced to their text outside
 * code spans.
 *
 * A fence follows CommonMark, as the website renders the post: up to 3 spaces
 * of indent, a backtick fence's info string has no backtick (` ```x``` ` is a
 * code span), and it closes on a line of the same character at least as long.
 * A fence that never closes is kept rather than swallowing the sections after
 * it. A code span is kept as is; a link, whose text may hold code spans or a
 * lone backtick but no bracket, becomes its text (atomic groups: linear on any
 * input).
 */
function clean (string $markdown): string
{
   $markdown = str_replace("\r\n", "\n", $markdown);
   $markdown = preg_replace(
      '/^ {0,3}(?:(`{3,})[^`\n]*|(~{3,})[^\n]*)\n.*?^ {0,3}(?:\1`*|\2~*)[ \t]*$/ms',
      '',
      $markdown
   ) ?? $markdown;

   // :
   return preg_replace_callback(
      '/(`[^`\n]+`)|!?\[((?>`[^`\n]+`|[^\[\]`\n]+|`)*+)\]\((?>[^()\s]+|\([^()\s]*\))*+(?:\s+"[^"\n]*")?\)/',
      static fn (array $match): string => $match[1] !== '' ? $match[1] : $match[2],
      $markdown
   ) ?? $markdown;
}

/**
 * Collects the bold leads of the top-level bullets of a Markdown section.
 *
 * A bold label (`**Removed:**`) or an upgrade note (`**Upgrading from …**`) is
 * not a change; a `**` inside a code span never ends a lead.
 *
 * @return list<string>
 */
function collect (string $section): array
{
   $leads = [];

   preg_match_all('/^- \*\*((?:`[^`]*`|[^`*]|\*(?!\*))+)\*\*/m', $section, $matches);
   // @@
   foreach ($matches[1] as $lead) {
      $lead = rtrim($lead, ' .');
      if (str_ends_with($lead, ':') || str_starts_with($lead, 'Upgrading')) {
         continue;
      }
      $leads[] = $lead;
   }

   // :
   return $leads;
}

/**
 * Splits Markdown into its `## ` sections, keyed by heading.
 *
 * @return array<string,string>
 */
function divide (string $markdown): array
{
   $sections = [];

   preg_match_all('/^## (.+?)[ \t]*\n(.*?)(?=^## |\z)/ms', $markdown, $matches, PREG_SET_ORDER);
   // @@
   foreach ($matches as $match) {
      $sections[$match[1]] ??= $match[2];
   }

   // :
   return $sections;
}

/**
 * Extracts the summary and the fixes from the notes of a PATCH release.
 *
 * @return array{string, list<array{string, list<string>}>}
 */
function parse (string $notes): array
{
   // ! GitHub stores release bodies with CRLF line breaks
   $notes = clean($notes);
   $focus = '';

   // @
   if (preg_match('/^> Focus: \*\*(.+?)\*\*[ \t]*$/m', $notes, $matches) === 1) {
      $focus = trim($matches[1]);
   }
   $fixes = collect(divide($notes)['Changes'] ?? '');

   // :
   return [$focus, $fixes !== [] ? [['🐛 Fixes', cap($fixes, 8)]] : []];
}

/**
 * Outlines a release post as the website serves it in Markdown: its summary,
 * the titles of its Features, each Improvements subsection with its leads, and
 * the leads of its Fixes.
 *
 * @return array{string, list<array{string, list<string>}>}
 */
function outline (string $post): array
{
   // ! The served Markdown opens with the title, then the description quoted
   $post = clean($post);
   $summary = '';
   if (preg_match('/^> (.+?)[ \t]*$/m', $post, $matches) === 1) {
      $summary = $matches[1];
   }
   $parts = divide($post);
   $sections = [];

   // @ Features: one line per feature
   preg_match_all('/^### (.+?)[ \t]*$/m', $parts['Features'] ?? '', $matches);
   if ($matches[1] !== []) {
      $sections[] = ['✨ Features', cap($matches[1], 8)];
   }

   // @ Improvements: one line per area (`Performance: …`)
   $improvements = [];
   preg_match_all('/^### (.+?)[ \t]*\n(.*?)(?=^### |\z)/ms', $parts['Improvements'] ?? '', $matches, PREG_SET_ORDER);
   // @@
   foreach ($matches as $match) {
      $leads = implode(', ', collect($match[2]));
      $improvements[] = $leads !== '' ? "{$match[1]}: {$leads}" : $match[1];
   }
   if ($improvements !== []) {
      $sections[] = ['⚡ Improvements', $improvements];
   }

   // @ Fixes: the first ones, then a pointer to the rest
   $fixes = collect($parts['Fixes'] ?? '');
   if ($fixes !== []) {
      $sections[] = ['🐛 Fixes', cap($fixes, 3)];
   }

   // :
   return [$summary, $sections];
}

/**
 * Caps a list at `$limit` lines: past it, `$limit - 1` items and a pointer to the rest (never "1 more").
 *
 * @param list<string> $items
 *
 * @return list<string>
 */
function cap (array $items, int $limit): array
{
   // ?:
   if (count($items) <= $limit) {
      return $items;
   }

   $hidden = count($items) - ($limit - 1);
   $items = array_slice($items, 0, $limit - 1);
   $items[] = "…and {$hidden} more";

   // :
   return $items;
}

/**
 * Escapes Markdown-flavored text for Telegram's HTML parse mode (`code` and **bold** kept).
 *
 * Code spans are split out first: Telegram allows no entity inside `code`, so
 * a `**` in a span (a glob such as `configs/**`) is never read as bold.
 */
function escape (string $text): string
{
   // !
   $pieces = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
   $pieces = $pieces === false ? [$text] : $pieces;
   $escaped = '';

   // @@ Odd pieces are the code spans
   foreach ($pieces as $index => $piece) {
      if ($index % 2 === 1) {
         $code = htmlspecialchars(substr($piece, 1, -1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
         $escaped .= "<code>{$code}</code>";
         continue;
      }

      $piece = htmlspecialchars($piece, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $escaped .= preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $piece) ?? $piece;
   }

   // :
   return $escaped;
}


// ! Environment
$tag = (string) getenv('TAG');
$post = (string) getenv('POST');
$wait = (int) (getenv('WAIT') ?: 900);
$repository = (string) getenv('GITHUB_REPOSITORY');
$repository = $repository !== '' ? $repository : 'bootgly/bootgly';
$event = (string) getenv('GITHUB_EVENT_NAME');
$networks = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) getenv('NETWORKS'))))));
$dry = (string) getenv('DRY_RUN') !== '';
$supported = ['telegram', 'discord'];

// ? Only a stable release is announced
if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/D', $tag, $version) !== 1) {
   report('error', "TAG must name a stable release (vX.Y.Z), got '{$tag}'.");
   exit(1);
}
if (preg_match('#^[\w.-]+/[\w.-]+$#D', $repository) !== 1) {
   report('error', "GITHUB_REPOSITORY is not owner/name: '{$repository}'.");
   exit(1);
}
$unknown = array_diff($networks, $supported);
if ($networks === [] || $unknown !== []) {
   $listed = implode(', ', $supported);
   report('error', "NETWORKS must list some of: {$listed}.");
   exit(1);
}
if ($post !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $post) !== 1) {
   report('error', "POST must be a blog post slug, got '{$post}'.");
   exit(1);
}

$kind = match (true) {
   (int) $version[3] > 0 => 'patch',
   (int) $version[2] > 0 => 'minor',
   default => 'major',
};
// ? A PATCH release has no blog post; a MINOR/MAJOR one is announced from it
if ($kind === 'patch' && $post !== '') {
   report('error', "{$tag} is a patch release: it is announced from its notes, without POST.");
   exit(1);
}
if ($kind !== 'patch' && $post === '') {
   // ?: Its post's publication announces it
   if ($event === 'release') {
      report('notice', "{$tag} is a {$kind} release: its blog post announces it. If the post was published before this release, its Announce run failed: re-run its failed jobs (past 30 days, dispatch this workflow with the tag and the post).");
      exit(0);
   }

   report('error', "{$tag} is a {$kind} release: name its blog post in POST.");
   exit(1);
}

// ---

// @ Release
$headers = [
   'Accept: application/vnd.github+json',
   'X-GitHub-Api-Version: 2022-11-28',
   'User-Agent: bootgly-announce',
];
$token = (string) getenv('GH_TOKEN');
if ($token !== '') {
   $headers[] = "Authorization: Bearer {$token}";
}
[$status, $response, $error] = request('GET', "https://api.github.com/repos/{$repository}/releases/tags/{$tag}", $headers);
$release = json_decode($response, true);
// ! A post published before its release: this run announces it once re-run
$later = $post !== '' ? ' Publish it as a stable release, then re-run the failed jobs of this run.' : '';
if ($status !== 200 || is_array($release) === false) {
   $cause = trim("HTTP {$status} {$error}");
   report('error', "Release {$tag} of {$repository} not found ({$cause}).{$later}");
   exit(1);
}
// ? The tag says stable; the release must say it too
if (($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false) {
   report('error', "Release {$tag} is a draft or a pre-release: only stable releases are announced.{$later}");
   exit(1);
}
$URL = $release['html_url'] ?? null;
$URL = is_string($URL) ? $URL : "https://github.com/{$repository}/releases/tag/{$tag}";

// @ Content
if ($post === '') {
   $notes = $release['body'] ?? null;
   [$summary, $sections] = parse(is_string($notes) ? $notes : '');
   $link = $URL;
}
else {
   // ! A push to the website deploys in minutes: wait until it serves the post
   $link = "https://bootgly.com/blog/{$post}/";
   $deadline = time() + $wait;
   // @@
   while (true) {
      [$status, $markdown, $error] = request('GET', "{$link}index.md", ['User-Agent: bootgly-announce']);
      if ($status === 200 && str_starts_with($markdown, '# ')) {
         break;
      }
      if (time() >= $deadline) {
         $cause = trim("HTTP {$status} {$error}");
         report('error', "The website did not serve {$link} within {$wait} s ({$cause}). Once it serves the post (a failed deploy fixed by a later push is not dispatched again), re-run this failed job.");
         exit(1);
      }
      sleep(30);
   }
   [$summary, $sections] = outline($markdown);
   if ($sections === []) {
      report('error', "{$link} has no Features, Improvements or Fixes to announce.");
      exit(1);
   }
}
$upgrade = "bootgly kit upgrade {$tag}";

// ---

// @ Messages
// # Telegram (HTML parse mode, 4096 characters): the preview shows the post,
//   or the release when there is no post
$lines = ["🚀 <b>Bootgly {$tag} is out</b> — a {$kind} release"];
if ($summary !== '') {
   $lines[] = '';
   $lines[] = escape($summary);
}
// @@
foreach ($sections as [$title, $items]) {
   $lines[] = '';
   $lines[] = "<b>{$title}</b>";
   foreach ($items as $item) {
      $escaped = escape($item);
      $lines[] = "• {$escaped}";
   }
}
$lines[] = '';
$lines[] = "Upgrade: <code>{$upgrade}</code>";
if ($post !== '') {
   $lines[] = "📝 Blog: {$link}";
}
$lines[] = "📦 Release notes: {$URL}";
$telegram = implode("\n", $lines);

// # Discord (message content, 2000 characters): the first link unfurls into
//   its card, like a link posted by hand; `<…>` keeps the release link a link
$lines = ["🚀 **Bootgly {$tag} is out** — a {$kind} release"];
if ($summary !== '') {
   $lines[] = '';
   $lines[] = $summary;
}
// @@
foreach ($sections as [$title, $items]) {
   $lines[] = '';
   $lines[] = "**{$title}**";
   foreach ($items as $item) {
      $lines[] = "• {$item}";
   }
}
$lines[] = '';
$lines[] = "Upgrade: `{$upgrade}`";
$lines[] = $post !== '' ? "📝 {$link}" : "📦 {$URL}";
if ($post !== '') {
   $lines[] = "📦 Release notes: <{$URL}>";
}
$discord = implode("\n", $lines);

// ? Limits: refuse rather than post a cut message
if (mb_strlen($telegram) > 4096 || mb_strlen($discord) > 2000) {
   report('error', "The announcement of {$tag} is longer than Telegram (4096) or Discord (2000) accepts.");
   exit(1);
}

// ---

// @@ Networks
$results = [];
$failed = false;
foreach ($networks as $network) {
   // ! Payload and endpoint
   switch ($network) {
      case 'telegram':
         $secret = (string) getenv('TELEGRAM_BOT_TOKEN');
         $chat = (string) getenv('TELEGRAM_CHAT');
         $chat = $chat !== '' ? $chat : '@bootgly';
         $payload = [
            'chat_id' => $chat,
            'text' => $telegram,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['url' => $link],
         ];
         $valid = preg_match('/^\d+:[\w-]+$/D', $secret) === 1
            && preg_match('/^(@\w{5,32}|-?\d+)$/D', $chat) === 1;
         $endpoint = "https://api.telegram.org/bot{$secret}/sendMessage";
         break;
      default: // discord
         $secret = (string) getenv('DISCORD_WEBHOOK_URL');
         $payload = [
            'content' => $discord,
            'allowed_mentions' => ['parse' => []],
         ];
         $valid = preg_match('#^https://(?:discord|discordapp)\.com/api/(?:v\d+/)?webhooks/\d+/[\w-]+$#D', $secret) === 1;
         $endpoint = "{$secret}?wait=true";
   }
   $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

   // ? Dry run: print, never post
   if ($dry) {
      echo "::group::{$network} (dry run)\n";
      echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
      echo "::endgroup::\n";
      $results[$network] = 'dry run';
      continue;
   }
   // ? Secret missing or malformed (never printed)
   if ($valid === false) {
      report('error', "{$network}: its secret is missing or malformed — not posted.");
      $results[$network] = 'not configured';
      $failed = true;
      continue;
   }

   // @ Post
   [$status, $response, $error] = request('POST', $endpoint, ['Content-Type: application/json'], $body);
   $answer = json_decode($response, true);
   $posted = $status === 200 && is_array($answer) && match ($network) {
      'telegram' => ($answer['ok'] ?? false) === true,
      default => isset($answer['id']),
   };
   if ($posted) {
      echo "{$network}: posted.\n";
      $results[$network] = 'posted';
      continue;
   }

   $reason = is_array($answer) ? ($answer['description'] ?? $answer['message'] ?? '') : '';
   $reason = is_string($reason) ? $reason : '';
   report('error', "{$network}: not posted (HTTP {$status}) {$reason} {$error}");
   $results[$network] = "failed (HTTP {$status})";
   $failed = true;
}

// ---

// @ Summary
$file = (string) getenv('GITHUB_STEP_SUMMARY');
if ($file !== '') {
   $rows = "### Announcement of {$tag}\n\n| Network | Result |\n|---|---|\n";
   // @@
   foreach ($results as $network => $result) {
      $rows .= "| {$network} | {$result} |\n";
   }
   file_put_contents($file, $rows, FILE_APPEND);
}

// :
exit($failed ? 1 : 0);
