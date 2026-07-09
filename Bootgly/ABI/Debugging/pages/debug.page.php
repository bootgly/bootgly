<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// Raw debug page resource — consumed exclusively by \Bootgly\ABI\Debugging\Page::render().
// Every value inside $page is already HTML-escaped; this file only echoes.

/** @var array{title:string,chain:array<int,array<string,mixed>>,context:array<string,array<string,string>>} $page */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $page['title'] ?></title>
<style>
:root {
   --bg: #16181d; --bg-panel: #1e2128; --bg-active: #262a33;
   --fg: #d8dbe2; --fg-dim: #8b90a0; --accent: #e5484d; --accent-soft: #3b2226;
   --mark: #2d2226; --line: #30343f; --chip: #f2555a;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
   background: var(--bg); color: var(--fg); font: 14px/1.5 ui-sans-serif, system-ui, sans-serif;
   min-height: 100vh; display: flex; flex-direction: column;
}
code, pre, .mono { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; }
header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--line); background: var(--bg-panel); }
header .badges { display: flex; gap: .5rem; align-items: center; margin-bottom: .75rem; flex-wrap: wrap; }
header .class { background: var(--accent-soft); color: var(--chip); border: 1px solid var(--chip);
   padding: .15rem .6rem; border-radius: 4px; font-weight: 600; }
header .code { color: var(--fg-dim); }
header h1 { font-size: 1.2rem; font-weight: 500; word-break: break-word; }
main { display: flex; flex: 1; min-height: 0; }
nav {
   width: 34%; max-width: 30rem; min-width: 16rem; border-right: 1px solid var(--line);
   overflow-y: auto; background: var(--bg-panel);
}
nav .chained { padding: .6rem 1rem .2rem; color: var(--fg-dim); font-size: .75rem;
   text-transform: uppercase; letter-spacing: .06em; }
nav button {
   display: block; width: 100%; text-align: left; background: none; border: 0;
   border-bottom: 1px solid var(--line); color: var(--fg); padding: .6rem 1rem; cursor: pointer;
}
nav button:hover { background: var(--bg-active); }
nav button.active { background: var(--bg-active); box-shadow: inset 3px 0 0 var(--accent); }
nav button .sig { display: block; font-size: .85rem; word-break: break-word; }
nav button .loc { display: block; color: var(--fg-dim); font-size: .75rem; margin-top: .15rem; word-break: break-word; }
nav .overflow { padding: .6rem 1rem; color: var(--fg-dim); font-size: .8rem; }
section.panel { flex: 1; overflow-y: auto; padding: 1.25rem 1.5rem; display: none; }
section.panel.active { display: block; }
section.panel h2 { font-size: .9rem; font-weight: 500; color: var(--fg-dim); margin-bottom: .75rem; word-break: break-word; }
section.panel h2 b { color: var(--fg); font-weight: 600; }
.excerpt { background: var(--bg-panel); border: 1px solid var(--line); border-radius: 6px;
   overflow-x: auto; font-size: .85rem; line-height: 1.6; }
.excerpt table { border-collapse: collapse; width: 100%; }
.excerpt td { padding: 0 .75rem; white-space: pre; }
.excerpt td.n { text-align: right; color: var(--fg-dim); user-select: none; width: 1%;
   border-right: 1px solid var(--line); }
.excerpt tr.marked { background: var(--mark); }
.excerpt tr.marked td.n { color: var(--chip); font-weight: 700; }
.args { margin-top: 1rem; }
.args h3, .ctx h3 { font-size: .75rem; color: var(--fg-dim); text-transform: uppercase;
   letter-spacing: .06em; margin-bottom: .4rem; }
.args ol { margin-left: 1.5rem; font-size: .85rem; }
.args li { word-break: break-word; }
.empty { color: var(--fg-dim); font-size: .85rem; }
#context { border-top: 1px solid var(--line); background: var(--bg-panel); padding: 1rem 2rem; }
#context details { margin: .25rem 0; }
#context summary { cursor: pointer; color: var(--fg-dim); font-size: .8rem;
   text-transform: uppercase; letter-spacing: .06em; }
#context table { border-collapse: collapse; margin: .5rem 0 .75rem; font-size: .85rem; width: 100%; }
#context td { padding: .2rem .75rem .2rem 0; vertical-align: top; word-break: break-word; }
#context td.k { color: var(--fg-dim); white-space: nowrap; }
footer { padding: .5rem 2rem; color: var(--fg-dim); font-size: .75rem; border-top: 1px solid var(--line); }
@media (max-width: 48rem) { main { flex-direction: column; } nav { width: 100%; max-width: none; max-height: 14rem; } }
</style>
</head>
<body>
<?php $first = $page['chain'][0] ?? null; ?>
<header>
   <div class="badges">
      <span class="class mono"><?= $first['class'] ?? $page['title'] ?></span>
      <?php if (($first['code'] ?? '') !== '') : ?>
      <span class="code mono"><?= $first['code'] ?></span>
      <?php endif; ?>
   </div>
   <h1><?= $first['message'] ?? '' ?></h1>
</header>
<main>
   <nav id="frames">
   <?php foreach ($page['chain'] as $s => $section) : ?>
      <?php if ($s > 0) : ?>
      <div class="chained mono">Caused by <?= $section['class'] ?></div>
      <?php endif; ?>
      <?php foreach ($section['frames'] as $f => $frame) : ?>
      <button type="button" data-panel="p-<?= $s ?>-<?= $f ?>">
         <span class="sig mono"><?= $frame['signature'] ?></span>
         <span class="loc mono"><?= $frame['file'] ?>:<?= $frame['line'] ?></span>
      </button>
      <?php endforeach; ?>
      <?php if ($section['overflow'] > 0) : ?>
      <div class="overflow">+<?= $section['overflow'] ?> more frames…</div>
      <?php endif; ?>
   <?php endforeach; ?>
   </nav>
   <?php foreach ($page['chain'] as $s => $section) : ?>
   <?php foreach ($section['frames'] as $f => $frame) : ?>
   <section class="panel" id="p-<?= $s ?>-<?= $f ?>">
      <h2 class="mono"><b><?= $frame['signature'] ?></b> — <?= $frame['file'] ?>:<?= $frame['line'] ?></h2>
      <?php if ($frame['excerpt'] !== []) : ?>
      <div class="excerpt mono"><table>
         <?php foreach ($frame['excerpt'] as $row) : ?>
         <tr<?= $row['marked'] ? ' class="marked"' : '' ?>><td class="n"><?= $row['number'] ?></td><td><?= $row['content'] === '' ? ' ' : $row['content'] ?></td></tr>
         <?php endforeach; ?>
      </table></div>
      <?php else : ?>
      <p class="empty">Source not available.</p>
      <?php endif; ?>
      <div class="args">
         <h3>Arguments</h3>
         <?php if ($frame['args'] !== []) : ?>
         <ol class="mono">
            <?php foreach ($frame['args'] as $arg) : ?>
            <li><?= $arg ?></li>
            <?php endforeach; ?>
         </ol>
         <?php else : ?>
         <p class="empty">No arguments captured.</p>
         <?php endif; ?>
      </div>
   </section>
   <?php endforeach; ?>
   <?php endforeach; ?>
</main>
<?php if ($page['context'] !== []) : ?>
<div id="context" class="ctx">
   <h3>Context</h3>
   <?php foreach ($page['context'] as $name => $rows) : ?>
   <details<?= $name === array_key_first($page['context']) ? ' open' : '' ?>>
      <summary><?= $name ?></summary>
      <table>
         <?php foreach ($rows as $key => $value) : ?>
         <tr><td class="k mono"><?= $key ?></td><td class="mono"><?= $value ?></td></tr>
         <?php endforeach; ?>
      </table>
   </details>
   <?php endforeach; ?>
</div>
<?php endif; ?>
<footer>Bootgly — development debug page. Set <code>BOOTGLY_ENVIRONMENT=production</code> to disable.</footer>
<script>
(function () {
   var nav = document.getElementById('frames');
   var buttons = nav.querySelectorAll('button[data-panel]');
   function activate (button) {
      buttons.forEach(function (other) { other.classList.remove('active'); });
      document.querySelectorAll('section.panel.active').forEach(function (panel) {
         panel.classList.remove('active');
      });
      button.classList.add('active');
      var panel = document.getElementById(button.getAttribute('data-panel'));
      if (panel) { panel.classList.add('active'); }
   }
   buttons.forEach(function (button) {
      button.addEventListener('click', function () { activate(button); });
   });
   if (buttons.length > 0) { activate(buttons[0]); }
})();
</script>
</body>
</html>
