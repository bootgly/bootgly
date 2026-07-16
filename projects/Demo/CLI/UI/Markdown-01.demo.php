<?php
namespace Bootgly\CLI;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Markdown;

$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Markdown component @;
 * @#yellow: @@: Demo 54 - Example #1 - markdown rendered in the terminal @;
 * {$location}
 */\n\n
OUTPUT);

// @ A markdown document exercising every supported feature
$Markdown = new Markdown($Output);
$Markdown->source = <<<'MARKDOWN'
# Bootgly Markdown

Render **markdown** right in the *terminal* — headings, lists, quotes,
`inline code`, [links](https://bootgly.com) and more, with ~~no~~ zero
third-party dependencies.

## Features

- **Wrapped paragraphs** — width-aware, multibyte-safe
- *Nested lists*
  - with tight children
  - and `code` inside items
- [x] Task lists
- [ ] Roadmap: syntax highlighting

## Usage

```php
use Bootgly\CLI\UI\Components\Markdown;

$Markdown = new Markdown($Output);
$Markdown->source = '# Hello';
$Markdown->render();
```

> **Tip** — quotes nest:
> > and the gutters stack, with styles intact.

## Support matrix

| Feature | Status | Notes |
|:--------|:------:|------:|
| Headings | done | h1-h6 |
| Tables | done | aligned |
| HTML | never | by design |

---

1. Ordered lists
2. Keep their numbers
3. And align markers

That is all — pipe this demo to a file and the output degrades to plain
structured text (zero escape codes).
MARKDOWN;

$Markdown->render();
