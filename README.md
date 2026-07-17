<a name="readme-top"></a>

<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/bootgly-logo.128x128.jpg" alt="bootgly-logo" width="120px" height="120px"/>
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

## 💡 Why Bootgly?

- ⚡ **Native async HTTP server in pure PHP** — long-running and event-loop driven, with Fibers for non-blocking I/O. No Nginx, no PHP-FPM in front. A pure-PHP alternative to Swoole, Workerman and FrankenPHP — no C extension required.
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
> Check [Benchmark Detailed Results](https://github.com/bootgly/bootgly_benchmarks/tree/main/HTTP_Server_CLI)

> [!NOTE]
> **Beta — stabilizing toward 1.0.** Bootgly is under active development and the public API is still being finalized ahead of the 1.0 release. Pin a version and expect some changes before then; not yet recommended for production use. [Documentation is a work in progress.][PROJECT_DOCS]

## Table of Contents

- [🤔 About](#-about)
  - [💬 Commit Convention](#-commit-convention)
  - [📑 Versioning System](#-versioning-system)
- [🟢 Boot Requirements](#-boot-requirements)
  - [🤝 Compatibility](#-compatibility)
  - [⚙️ Dependencies](#️-dependencies)
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

Operation System |
--- |
✅ Linux (Debian based) |
❌ Windows |
❔ Unix |

--

✅ = Compatible

❌ = Incompatible

❔ = Untested

Above is the native compatibility, of course it is possible to run on Windows and Unix using Docker containers.

> 🐳 **Docker:** build a `slim` or `full` Bootgly image to run servers, test, benchmark and ship your own projects — see the [`Dockerfile`](Dockerfile) and the [Docker guide][DOCKER_GUIDE].

### ⚙️ Dependencies

- PHP 8.4+ ⚠️
- Opcache + JIT enabled (+50% performance) 👍

#### PHP Packages

- `php-cli` ⚠️
- `php-mbstring` 👍
- `php-readline` ⚠️
- `php-openssl` ⚠️

--

⚠️ = Required

👍 = Recommended

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 🌱 Community

Join us and help the community.

**Love Bootgly? Give [our repo][GITHUB_REPOSITORY] a star ⭐!**

### 💻 Contributing

Wait for the "contributing guidelines" to start your contribution.

#### 🛂 Code of Conduct

Help us keep Bootgly open and inclusive. Please read and follow our [Code of Conduct][CODE_OF_CONDUCT].

### 🔗 Social networks

- Bootgly on **LinkedIn**: [[Company Page][LINKEDIN]]
- Bootgly on **Telegram**: [[Telegram Group][TELEGRAM]]
- Bootgly on **Reddit**: [[Reddit Community][REDDIT]]
- Bootgly on **Discord**: [[Discord Channel][DISCORD]]

### 💖 Sponsorship

A lot of time and energy is devoted to Bootgly projects. To accelerate your growth, if you like this project or depend on it for your stack to work, consider [sponsoring it][GITHUB_SPONSOR].

Your sponsorship will keep this project always **up to date** with **new features** and **improvements** / **bug fixes**.

---

<div align="right">

[![Back to top][BACK_TO_TOP]](#readme-top)

</div>

## 🚀 Getting started

### 📦 Install (one command)

The canonical way to start: the installer clones the [bootgly.kit](https://github.com/bootgly/bootgly.kit) starter template, initializes the Bootgly platform and opens the **project wizard**:

```bash
curl -fsSL https://bootgly.com/install | bash
```

Create more projects anytime — from scratch or importing a platform project (like the Demos):

```bash
php bootgly project create
```

Or import any git repository carrying the Bootgly project signature (a `*.project.php` file at its root):

```bash
php bootgly project import https://github.com/foo/project1 Project1
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

   1) Run the Bootgly CLI setup command in terminal (with sudo):

   ```bash
   sudo php bootgly setup
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

   1) Import a Web project with the wizard (`php bootgly project import`);
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
[I2P_ARQUITECTURE]: https://docs.bootgly.com/manual/Bootgly/basic/architecture/overview
[ROUTING]: https://docs.bootgly.com/manual/WPI/HTTP/HTTP_Server_CLI/Router/overview

[CLI_INTERFACE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/
[CLI_TERMINAL_COMPONENTS]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components

[CLI_TERMINAL_ALERT]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Alert
[CLI_TERMINAL_FIELDSET]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Fieldset
[CLI_TERMINAL_MENU]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Menu
[CLI_TERMINAL_PROGRESS]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Progress
[CLI_TERMINAL_TABLE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Table
[CLI_HEADER]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/components/Header.php
[CONSOLE_PLATFORM]: https://github.com/bootgly/bootgly-console

[WPI_INTERFACE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/WPI/
[HTTP_SERVER_ROUTER_CLASS]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Nodes/HTTP_Server_CLI/Router.php
[WEB_TCP_CLIENT_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Interfaces/TCP_Client_CLI.php
[WEB_TCP_SERVER_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Interfaces/TCP_Server_CLI.php
[WEB_UDP_CLIENT_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Interfaces/UDP_Client_CLI.php
[WEB_UDP_SERVER_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Interfaces/UDP_Server_CLI.php
[WEB_HTTP_SERVER_CLI]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Nodes/HTTP_Server_CLI.php
[WEB_HTTP_CLIENT_CLI]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Nodes/HTTP_Client_CLI.php
[WEB_PLATFORM]: https://github.com/bootgly/bootgly-web

[PROJECT_DOCS]: https://docs.bootgly.com/
[DOCKER_GUIDE]: https://docs.bootgly.com/guide/docker
[GITHUB_REPOSITORY]: https://github.com/bootgly/bootgly/
[GITHUB_SPONSOR]: https://github.com/sponsors/bootgly/
[VS_HTTP]: https://docs.bootgly.com/manual/WPI/HTTP/HTTP_Server_CLI/vs/
[BENCHMARKS]: https://github.com/bootgly/bootgly_benchmarks/tree/main/HTTP_Server_CLI

[TELEGRAM]: https://t.me/bootgly/
[REDDIT]: https://www.reddit.com/r/bootgly/
[DISCORD]: https://discord.com/invite/SKRHsYmtyJ/
[LINKEDIN]: https://www.linkedin.com/company/bootgly/


[CODE_OF_CONDUCT]: https://github.com/bootgly/bootgly/blob/main/.github/CODE_OF_CONDUCT.md
[SEMANTIC_VERSIONING]: https://semver.org/
[CONVENTIONAL_COMMITS]: https://www.conventionalcommits.org/en/v1.0.0/


[MIT_LICENSE]: https://opensource.org/license/mit/

[BACK_TO_TOP]: https://img.shields.io/badge/-BACK_TO_TOP-151515?style=flat-square
