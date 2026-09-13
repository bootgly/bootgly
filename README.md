<a name="readme-top"></a>

<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/bootgly-logo.180x180.jpg" alt="bootgly-logo" width="120px" height="120px"/>
</p>
<h1 align="center">Bootgly</h1>
<p align="center">
  <b>The native, zero-dependency PHP framework.</b><br/>
  <i>One async core for Web 🌐 and CLI 📟 — built for performance and clarity.</i>
</p>
<p align="center">
  <a href="https://packagist.org/packages/bootgly/bootgly">
    <img alt="Github Actions - Bootgly Workflow" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/bootgly.yml?label=test"/>
    <img alt="Bootgly License" src="https://img.shields.io/github/license/bootgly/bootgly"/>
  </a>
</p>

Bootgly is a base framework for building **APIs and apps** on both the **Web (WPI)** and **Console (CLI)** platforms — powered by a native, event-loop HTTP server written in pure PHP. It is the first PHP framework built on the [I2P (Interface-to-Platform) architecture][I2P_ARQUITECTURE].

## Why Bootgly?

- ⚡ **Native async HTTP server in pure PHP** — long-running and event-loop driven, with Fibers for non-blocking I/O. No Nginx, no PHP-FPM in front. A pure-PHP alternative to Swoole, Workerman and FrankenPHP — no C extension required. <ins>**AutoTLS with ACME v2</ins>.**
- 📦 **Zero third-party dependencies in the core** — every essential feature (HTTP server, router, config, testing, sessions, DBAL + ORM) is built in. A smaller `vendor/` and a smaller supply-chain surface.
- 🎯 **One canonical way to do everything** — one HTTP server, one config schema, one test framework. Predictable, consistent code: fewer decisions, less to maintain, no bikeshedding.
- 🧱 **Strict, enforceable architecture** — six layers (ABI → ACI → ADI → API → CLI → WPI) with one-way dependencies and no cross-layer skipping. One core, two platforms.

## ⚡ Quickstart

Install Bootgly and create your first project with one command — the installer opens the project wizard:

```bash
curl -fsSL https://bootgly.com/install | bash
```

> **⚡ Over 1,000,000 req/s — in pure PHP.** On the TechEmpower `/plaintext` route, the HTTP Server CLI peaks at **1,076,709 req/s** — ahead of **Swoole** (964,908) and roughly **150× a Laravel + PHP-FPM** stack — with **no C extension** and no third-party runtime in its core. It leads Swoole on `/plaintext`, `/json`, `/query` (+126%) and `/updates` (+60%), and beats every other PHP framework benchmarked on every route.
>
> _Measured on 24 logical CPUs, PHP 8.4.22, 514 connections, 10 s per route, symmetric DB pool._ → **Full comparison & reproducible runs:** [Bootgly vs Swoole, Hyperf, ReactPHP, AMPHP & Laravel](https://docs.bootgly.com/manual/WPI/HTTP/HTTP_Server_CLI/vs/)
>
> Check [Benchmark Detailed Results](https://github.com/bootgly/bootgly_benchmarks/tree/main/HTTP_Server_CLI/)

> [!NOTE]
> **Stable — the `1.x` line.** Bootgly follows [Semantic Versioning][SEMANTIC_VERSIONING]: minor releases add capabilities, patch releases repair, and nothing documented breaks before `2.0.0`. Fixes land on the latest `1.x` minor — read the [Versioning](#-versioning) and [Support policy](#-support-policy) sections below, or the full [Versioning guide][VERSIONING_GUIDE]. The [documentation][PROJECT_DOCS] covers every layer.

## Table of Contents

- [🟢 Boot Requirements](#-boot-requirements)
  - [🤝 Compatibility](#-compatibility)
  - [⚙️ Dependencies](#️-dependencies)
- [📑 Versioning](#-versioning)
  - [🛟 Support policy](#-support-policy)
  - [🔐 Security policy](#-security-policy)
- [🌱 Community](#-community)
  - [💻 Contributing](#-contributing)
  - [🛂 Code of Conduct](#-code-of-conduct)
  - [🔗 Social Networks](#-social-networks)
  - [💖 Sponsorship](#-sponsorship)
- [🚀 Getting started](#-getting-started)
- [📃 License](#-license)

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 🟢 Boot Requirements

### 🤝 Compatibility

| Operating system | Servers (WPI) | CLI tooling |
| --- | --- | --- |
| ✅ Linux | ✅ | ✅ |
| ✅ WSL2 | ✅ | ✅ |
| ❔ macOS | ❌ | ✅ |
| ❔ Windows | ❌ | ✅ |

Linux is first-class: the servers rely on `pcntl`/`posix`, so on macOS and Windows only the
CLI tooling runs natively — use Docker for everything else.

### ⚙️ Dependencies

- PHP 8.4+ ⚠️
- Opcache + JIT enabled (+50% performance) 👍

> 🐳 **Docker:** `docker run -it bootgly/bootgly.kit` gives you the whole kit — framework, Console and Web — ready to create and run projects. `latest` and the `1` / `1.x` aliases follow the stable line; pin an exact version (`bootgly/bootgly.kit:<version>`) for a build that never moves. This repository publishes `bootgly/bootgly`, the framework image you build your own on — it is always pulled by an explicit tag (`<version>`, `1.x`, `1`; never `latest`); see the [`Dockerfile`](Dockerfile) and the [Docker guide][DOCKER_GUIDE].

#### PHP Packages

- `php-cli` ⚠️
- `php-openssl` ⚠️
- `php-readline` ⚠️
- `php-mbstring` 👍

--

⚠️ = Required

👍 = Recommended

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 📑 Versioning

Bootgly follows [Semantic Versioning 2.0.0][SEMANTIC_VERSIONING] and commits follow
[Conventional Commits 1.0.0][CONVENTIONAL_COMMITS]. From `1.0.0` on:

- **Minor** releases (`1.x.0`) add capabilities and keep everything documented working as
  before. **Patch** releases (`1.x.y`) repair existing behavior.
- **Removing or renaming a public API, changing its established semantics, or adding a required
  method to an interface applications implement is a `2.0.0` change.** A deprecation in a minor
  never authorizes a removal in the next minor — the deprecated way keeps working until `2.0.0`.
- Internal migrations preserve public entry points and wire/storage compatibility. Runtime
  behavior that is not documented, or is marked `@internal`, is not covered.
- Each minor tracks the two newest PHP minors (`8.4+` today).

The full rules, the deprecation policy and what counts as public API live in the
[Versioning guide][VERSIONING_GUIDE].

### 🛟 Support policy

| Version | Bug fixes | Security fixes |
| --- | --- | --- |
| Latest `1.x` minor | ✅ | ✅ |
| Older `1.x` minors | ❌ | ❌ — upgrade to the latest minor |
| `-beta` / `-rc` pre-releases | until the release they precede ships | until the release they precede ships |
| `0.x` | ❌ | ❌ |

`1.0` is **not** a long-term-support line: fixes land on the latest `1.x` minor only, and
upgrading between `1.x` minors is meant to be a version bump, not a migration. An LTS line
may be declared for a later minor; it is not promised.

### 🔐 Security policy

Report vulnerabilities privately — never in a public issue — through
[GitHub private vulnerability reporting][SECURITY_ADVISORY] or **cybersec@bootgly.com**.
The full policy, scope and audit history are in [SECURITY.md][SECURITY_POLICY].

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 🌱 Community

Join us and help the community.

**Love Bootgly? Give [our repo][GITHUB_REPOSITORY] a star ⭐!**

### 💻 Contributing

Read the [contributing guidelines][CONTRIBUTING] first: they cover the layer rules, the naming
and commenting conventions, the native test runner and PHPStan gates a change must pass, and the
[Conventional Commits][CONVENTIONAL_COMMITS] format every commit uses. Bug reports and feature
requests have [issue templates][ISSUES]; security issues go through the [security policy](#-security-policy).

#### 🛂 Code of Conduct

Help us keep Bootgly open and inclusive. Please read and follow our [Code of Conduct][CODE_OF_CONDUCT].

### 🔗 Social networks

- Bootgly on **LinkedIn**: [[Company Page][LINKEDIN]]
- Bootgly on **Telegram**: [[Telegram Group][TELEGRAM]]
- Bootgly on **Reddit**: [[Reddit Community][REDDIT]]
- Bootgly on **Discord**: [[Discord Channel][DISCORD]]
- Bootgly on **X** (formerly Twitter): [[X (Twitter)][X_TWITTER]]
- Bootgly on **Youtube**: [[YouTube Channel][YOUTUBE]]

### 💖 Sponsorship

A lot of time and energy is devoted to Bootgly projects. To accelerate your growth, if you like this project or depend on it for your stack to work, consider [sponsoring it][GITHUB_SPONSOR].

Your sponsorship will keep this project always **up to date** with **new features** and **improvements** / **bug fixes**.

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 🚀 Getting started

### 📦 Install (one command)

The canonical way to start: the installer clones the [bootgly.kit](https://github.com/bootgly/bootgly.kit/) starter template, initializes the Bootgly platform and opens the **project wizard**:

```bash
curl -fsSL https://bootgly.com/install | bash
```

Create more projects anytime — from scratch or importing a platform project (like the Demos):

```bash
php bootgly projects create
```

Or import any Project from Platforms or git repository carrying the Bootgly project signature (a `*.Project.php` file at its root):

```bash
php bootgly projects import
```

### 📟 Bootgly CLI

<details>
   <summary><kbd>Import `Demo/CLI` project and run Bootgly CLI demo</kbd></summary><br>

   1) Run the Bootgly CLI demo in terminal:

   ```bash
   php bootgly demo
   ```
</details>

<details>
   <summary><kbd>Setup Bootgly CLI globally</kbd></summary><br>

   1) Run the Bootgly CLI setup command as your ordinary user. It delegates
   only the fixed system installation operation through sudo when required:

   ```bash
   php bootgly setup
   ```
</details>

<details>
   <summary><kbd>Perform Bootgly tests</kbd></summary><br>

   1) Check the global bootstrap tests file `tests/autoboot.php`;
   2) Run the Bootgly CLI test command in terminal:

   ```bash
   bootgly test
   ```

   ---

   You can also run specific suites or test files by index:

   ```bash
   bootgly test 16
   ```
   ```
   bootgly test 16 1
   ```
</details>

### 🌐 Bootgly WPI

<details>
   <summary><kbd>Import `Demo/HTTP_Server_CLI` project and run the demo of HTTP Server</kbd></summary>

   1) Import a Web project with the wizard (`php bootgly projects import`);
   2) Run it in the terminal:

   ```bash
   bootgly project Demo/HTTP_Server_CLI start
   ```
</details>

<b>[Routing HTTP Requests on the Server-side][ROUTING]</b>

Check the documentation for more details and examples: [Bootgly Docs][PROJECT_DOCS].

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 📃 License

The Bootgly is open-sourced software licensed under the [MIT license][MIT_LICENSE].


<!-- Links -->
[I2P_ARQUITECTURE]: https://docs.bootgly.com/manual/Bootgly/basic/architecture/overview/
[ROUTING]: https://docs.bootgly.com/manual/WPI/HTTP/HTTP_Server_CLI/Router/overview/

[PROJECT_DOCS]: https://docs.bootgly.com/
[DOCKER_GUIDE]: https://docs.bootgly.com/guide/docker/
[GITHUB_REPOSITORY]: https://github.com/bootgly/bootgly/
[GITHUB_SPONSOR]: https://github.com/sponsors/bootgly/
[X_TWITTER]: https://x.com/bootglyphp/
[YOUTUBE]: https://www.youtube.com/@Bootgly/

[TELEGRAM]: https://t.me/bootgly/
[REDDIT]: https://www.reddit.com/r/bootgly/
[DISCORD]: https://discord.com/invite/SKRHsYmtyJ/
[LINKEDIN]: https://www.linkedin.com/company/bootgly/

[CODE_OF_CONDUCT]: https://github.com/bootgly/bootgly/blob/main/.github/CODE_OF_CONDUCT.md
[CONTRIBUTING]: https://github.com/bootgly/bootgly/blob/main/.github/CONTRIBUTING.md
[ISSUES]: https://github.com/bootgly/bootgly/issues/new/choose
[SECURITY_POLICY]: https://github.com/bootgly/bootgly/blob/main/.github/SECURITY.md
[SECURITY_ADVISORY]: https://github.com/bootgly/bootgly/security/advisories/new
[VERSIONING_GUIDE]: https://docs.bootgly.com/guide/versioning/
[SEMANTIC_VERSIONING]: https://semver.org/
[CONVENTIONAL_COMMITS]: https://www.conventionalcommits.org/en/v1.0.0/

[MIT_LICENSE]: https://opensource.org/license/mit/

[BACK_TO_TOP]: https://img.shields.io/badge/-BACK_TO_TOP-151515?style=flat-square
