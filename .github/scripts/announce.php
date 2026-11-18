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
// Reads the release `TAG` from the GitHub API, builds one short English message
// from its notes — the `> Focus:` line and what `## Changes` lists — and posts
// it on every network named in `NETWORKS`. A network that fails, or whose
// secret is not set, does not stop the next one; the run fails at the end when
// any did. `DRY_RUN` (any non-empty value) prints each payload instead of
// posting it, and needs no secret.
//
// Environment:
//   TAG                  release tag, `vX.Y.Z` (a pre-release is refused)
//   NETWORKS             comma-separated: telegram, discord
//   DRY_RUN              non-empty: print, never post
//   GITHUB_REPOSITORY    owner/name (default: bootgly/bootgly)
//   GH_TOKEN             optional GitHub token (raises the API rate limit)
//   TELEGRAM_BOT_TOKEN   token of a bot that is an administrator of TELEGRAM_CHAT
//   TELEGRAM_CHAT        channel username or id (default: @bootgly)
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
 * Extracts the focus line and the list of changes from release notes.
 *
 * A MINOR or MAJOR release groups its changes under `###` headings (the
 * catch-all `Also` is left out); a PATCH release lists them as bullets led by
 * a bold sentence — a bold label (`**Removed:**`) or an upgrade note
 * (`**Upgrading from …**`) is not a change.
 *
 * @return array{focus: string, changes: list<string>}
 */
function parse (string $notes): array
{
   // ! GitHub stores release bodies with CRLF line breaks
   $notes = str_replace("\r\n", "\n", $notes);
   $focus = '';
   $changes = [];

   // @
   if (preg_match('/^> Focus: \*\*(.+?)\*\*[ \t]*$/m', $notes, $matches) === 1) {
      $focus = trim($matches[1]);
   }

   if (preg_match('/^## Changes[ \t]*\n(.*?)(?=^## |\z)/ms', $notes, $matches) === 1) {
      $section = $matches[1];

      preg_match_all('/^### (.+?)[ \t]*$/m', $section, $headings);
      // @@
      foreach ($headings[1] as $heading) {
         if (strcasecmp($heading, 'Also') !== 0) {
            $changes[] = $heading;
         }
      }

      // ? No heading: a PATCH release
      if ($changes === []) {
         preg_match_all('/^- \*\*((?:`[^`]*`|[^`*]|\*(?!\*))+)\*\*/m', $section, $leads);
         // @@
         foreach ($leads[1] as $lead) {
            $lead = rtrim($lead, ' .');
            if (str_ends_with($lead, ':') || str_starts_with($lead, 'Upgrading')) {
               continue;
            }
            $changes[] = $lead;
         }
      }
   }

   // :
   return ['focus' => $focus, 'changes' => $changes];
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
$repository = (string) getenv('GITHUB_REPOSITORY');
$repository = $repository !== '' ? $repository : 'bootgly/bootgly';
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
if ($status !== 200 || is_array($release) === false) {
   report('error', "Release {$tag} of {$repository} not found (HTTP {$status}) {$error}");
   exit(1);
}
// ? The tag says stable; the release must say it too
if (($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false) {
   report('error', "Release {$tag} is a draft or a pre-release: only stable releases are announced.");
   exit(1);
}

$URL = $release['html_url'] ?? null;
$URL = is_string($URL) ? $URL : "https://github.com/{$repository}/releases/tag/{$tag}";
$published = $release['published_at'] ?? null;
$published = is_string($published) ? $published : '';
$notes = $release['body'] ?? null;
['focus' => $focus, 'changes' => $changes] = parse(is_string($notes) ? $notes : '');
$kind = match (true) {
   (int) $version[3] > 0 => 'patch',
   (int) $version[2] > 0 => 'minor',
   default => 'major',
};
$upgrade = "bootgly kit upgrade {$tag}";

// ! At most 8 changes; past that, 7 and a pointer to the rest (never "1 more")
$shown = array_slice($changes, 0, count($changes) > 8 ? 7 : 8);
$hidden = count($changes) - count($shown);

// ---

// @ Messages
// # Telegram (HTML parse mode, 4096 characters)
$lines = ["🚀 <b>Bootgly {$tag} is out</b> — a {$kind} release"];
if ($focus !== '') {
   $lines[] = '';
   $lines[] = escape($focus);
}
if ($shown !== []) {
   $lines[] = '';
   $lines[] = "<b>What's new</b>";
   // @@
   foreach ($shown as $change) {
      $escaped = escape($change);
      $lines[] = "• {$escaped}";
   }
   if ($hidden > 0) {
      $lines[] = "• …and {$hidden} more in the notes";
   }
}
$lines[] = '';
$lines[] = "Upgrade: <code>{$upgrade}</code>";
$lines[] = "Release notes: {$URL}";
$telegram = implode("\n", $lines);

// # Discord (embed: title 256, description 4096 characters)
$lines = [];
if ($focus !== '') {
   $lines[] = $focus;
   $lines[] = '';
}
if ($shown !== []) {
   $lines[] = "**What's new**";
   // @@
   foreach ($shown as $change) {
      $lines[] = "• {$change}";
   }
   if ($hidden > 0) {
      $lines[] = "• …and {$hidden} more in the notes";
   }
   $lines[] = '';
}
$lines[] = "Upgrade: `{$upgrade}`";
$discord = implode("\n", $lines);

// ? Limits: refuse rather than post a cut message
if (mb_strlen($telegram) > 4096 || mb_strlen($discord) > 4096) {
   report('error', "The announcement of {$tag} is longer than 4096 characters.");
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
            'link_preview_options' => ['url' => $URL],
         ];
         $valid = preg_match('/^\d+:[\w-]+$/D', $secret) === 1
            && preg_match('/^(@\w{5,32}|-?\d+)$/D', $chat) === 1;
         $endpoint = "https://api.telegram.org/bot{$secret}/sendMessage";
         break;
      default: // discord
         $secret = (string) getenv('DISCORD_WEBHOOK_URL');
         $payload = [
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
               'title' => "Bootgly {$tag} is out",
               'url' => $URL,
               'description' => $discord,
               'footer' => ['text' => "Bootgly PHP Framework · {$kind} release"],
            ] + ($published !== '' ? ['timestamp' => $published] : [])],
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
$summary = (string) getenv('GITHUB_STEP_SUMMARY');
if ($summary !== '') {
   $rows = "### Announcement of {$tag}\n\n| Network | Result |\n|---|---|\n";
   // @@
   foreach ($results as $network => $result) {
      $rows .= "| {$network} | {$result} |\n";
   }
   file_put_contents($summary, $rows, FILE_APPEND);
}

// :
exit($failed ? 1 : 0);
