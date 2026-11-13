<div align="center">

<img src="https://raw.githubusercontent.com/orgezeo/marshal-file-manager/refs/heads/main/images/icons/mfm.png" alt="Marshal File Manager" width="132">

# Marshal File Manager

**One PHP file. Forty tools. Your whole server — files, databases, CMS sites, mail, SSH and diagnostics — in a single hardened workspace.**

<p>
  <img alt="Version" src="https://img.shields.io/badge/version-current-2ea043?style=flat-square">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.0%2B%20·%208.4%20recommended-777bb3?style=flat-square&logo=php&logoColor=white">
  <img alt="Single file" src="https://img.shields.io/badge/deployment-single%20file-1f6feb?style=flat-square">
  <img alt="Build step" src="https://img.shields.io/badge/build%20step-none-brightgreen?style=flat-square">
  <img alt="Dependencies" src="https://img.shields.io/badge/dependencies-none-blueviolet?style=flat-square">
  <img alt="License" src="https://img.shields.io/badge/license-personal%20use-orange?style=flat-square">
</p>

<p>
  <a href="#-quick-start"><b>Quick start</b></a> ·
  <a href="#-the-toolbox"><b>Toolbox</b></a> ·
  <a href="#-permissions"><b>Permissions</b></a> ·
  <a href="#-security-model"><b>Security</b></a> ·
  <a href="#-troubleshooting"><b>Troubleshooting</b></a> ·
  <a href="https://t.me/s4base"><b>Telegram</b></a>
</p>

</div>

---

> ### ⚠️ Read this first
> Marshal File Manager is an **administrative** tool. It runs with the privileges of your PHP process, and several tools reach beyond the web root by design. Install it only on a server you own or are explicitly authorised to administer, put it behind HTTPS, and restrict it with a firewall, VPN, IP allow-list or HTTP authentication.

---

## 📑 Contents

<details open>
<summary><b>Expand / collapse</b></summary>

- [Why this exists](#-why-this-exists)
- [Quick start](#-quick-start)
  - [Requirements](#requirements)
  - [Install](#install)
  - [First sign-in](#first-sign-in)
  - [Optional web installer](#optional-web-installer)
- [The workspace](#-the-workspace)
- [The toolbox](#-the-toolbox)
  - [Installed tools](#installed-tools)
  - [Every other tool](#every-other-tool)
  - [Search Anything](#search-anything)
- [Files & operations](#-files-operations)
- [Permissions](#-permissions)
  - [Capability catalogue](#capability-catalogue)
  - [Presets](#presets)
- [Accounts & sessions](#-accounts-sessions)
- [Automatic updates](#-automatic-updates)
  - [The ten-second tick](#the-ten-second-tick)
  - [WP-Cron installation](#wp-cron-installation)
  - [The request contract](#the-request-contract)
- [Rooting: the privilege catalogue](#-rooting-the-privilege-catalogue)
- [Configuration reference](#-configuration-reference)
- [Runtime files](#-runtime-files)
- [Action reference](#-action-reference)
- [Security model](#-security-model)
- [Troubleshooting](#-troubleshooting)
- [Updating](#-updating)
- [Project structure](#-project-structure)
- [Contributing](#-contributing)
- [Limitations & responsible use](#-limitations-responsible-use)
- [License](#-license)

</details>

---

## 🎯 Why this exists

Control panels are heavy, and shared hosts often refuse to install them. Marshal File Manager is the opposite: **one `index.php`**, no framework, no build step, no package manager, no separate backend directory. Upload it, open it, sign in, and the server is yours to administer from a browser.

It is deliberately self-contained:

| | |
|---|---|
| 🧩 **Single file** | `index.php` holds authentication, backend, UI and JavaScript. Deployment is a file copy. |
| ⚡ **Zero build** | No npm, no bundler, no compiled assets. Server-side PHP + browser-native JS. |
| 🎨 **Two themes** | Dark and light, shared across devices and saved server-side. |
| 📱 **Responsive** | Desktop, tablet and phone layouts with their own breakpoints and touch targets. |
| 🛡️ **Hardened by default** | CSRF on every mutating request, hashed passwords, login lockout, idle expiry, per-user roots, protected internal paths. |
| ♻️ **Self-healing** | File Guardian keeps a copy of the manager itself and puts it back if the file disappears. |

---

## 🚀 Quick start

### Requirements

<table>
<tr><td><b>Required</b></td><td>

- PHP **8.0+** (8.4 recommended)
- A web server that executes PHP: Apache, Nginx, LiteSpeed, or any honest shared host
- A writable application directory for runtime metadata
- A browser with JavaScript enabled

</td></tr>
<tr><td><b>Recommended extensions</b></td><td>

`json` · `session` · `openssl` · `mbstring` · `fileinfo` · `curl` · `zip` · `phar` · `mysqli` · `pdo` + `pdo_pgsql` · `imap`

</td></tr>
<tr><td><b>Per-feature</b></td><td>

| Feature | Needs |
|---|---|
| CMS Installer | `mysqli`, outbound HTTPS, `tar` via `exec` (Joomla also `mbstring`) |
| WordPress core updates | cURL + PHP ZIP |
| WebMail | `imap` |
| Database tools | `mysqli` / `pdo_pgsql` |
| Guardian DB storage | MySQL-compatible or PostgreSQL driver |
| Assistant Agent | outbound HTTPS to its AI service |

</td></tr>
</table>

Capabilities are checked at runtime. **A missing extension disables only the tool that needs it** — never the whole manager.

### Install

```bash
# 1 · put the file where it should manage
mkdir -p /var/www/html/marshal-fm
cp index.php /var/www/html/marshal-fm/index.php

# 2 · give the PHP user ownership, and nothing more
chown -R www-data:www-data /var/www/html/marshal-fm
chmod 750 /var/www/html/marshal-fm
chmod 640 /var/www/html/marshal-fm/index.php
```

Local smoke test:

```bash
php -S 0.0.0.0:5000 -t /var/www/html/marshal-fm
# → http://127.0.0.1:5000/
```

In production, serve it over **HTTPS** behind your normal web server or hosting panel.

### First sign-in

On a fresh install with no `.users.php` the manager creates **`admin` / `admin`** — and **refuses to let you in** until you replace both:

| Field | Rule |
|---|---|
| Username | 3–64 chars, starts with a letter, then letters / numbers / `.` `_` `-` |
| Password | at least 12 characters |

Provisioning accounts by hand? Generate the hash, never the plain text:

```bash
php -r 'echo password_hash("replace-this-password", PASSWORD_BCRYPT), PHP_EOL;'
```

### Optional web installer

`setup_mfm.php` is a separate convenience file: upload it, open it, and it downloads and places `index.php` for you.

- Downloads from the project's raw GitHub branch over HTTPS; a custom source must still be `raw.githubusercontent.com` and end in `/index.php`.
- Never overwrites an existing file, and lets you pick the destination filename.
- Confirms the download looks like Marshal File Manager, runs `php -l` when the host allows it, reports missing extensions (without trying to install system packages), and deletes itself when permitted.
- Starts the manager with the fixed `admin` / `admin` credentials, which it then forces you to change.

> ### 🚨 While the installer is present
> `setup_mfm.php` is **publicly reachable and needs no login**. Run it behind an IP/VPN restriction, and if self-deletion fails, delete it yourself before leaving the directory online.

---

## 🖥️ The workspace

```
┌──────────────┬──────────────────────────────────────────────────────────────┐
│  Marshal FM  │  Search anything…                                  ⟳  ☾  ⏻  │  ← topbar
├──────────────┼──────────────────────────────────────────────────────────────┤
│ Root (/)     │  path / breadcrumbs                          list · grid    │
│ Home         │ ┌──────────────────────────┬───────────────────────────────┐ │
│ Up one level │ │  files & folders         │  preview / editor / info      │ │
│ ------------ │ │  drag, drop, select      │  metadata, permissions, tags  │ │
│ TOOLS        │ │                          │                               │ │
│ ★ installed  │ │                          │                               │ │
│   A → Z      │ └──────────────────────────┴───────────────────────────────┘ │
│ ------------ │  status bar: disk · load · memory · uptime · clock          │
│ FAVORITES    │                                                              │
│ FOLDERS HERE │                                                              │
└──────────────┴──────────────────────────────────────────────────────────────┘
```

**Topbar** — a search field that expands to the centre of the screen and dims everything else, plus theme, logout and (for administrators) user management.

**Sidebar** — navigation, then **Tools**, then favorites and the folders in the current directory. The Tools list has a fixed, predictable order:

1. **Installed tools first**, sorted by name, each with a flat yellow star.
2. **Every other tool**, sorted the same way.

The order is written into the markup itself, not applied by JavaScript, so it is correct before a single script runs. Each row keeps its own permission guard, so a read-only session simply does not see the tools it may not use.

**Status bar** — live disk usage, load average, memory, uptime and clock.

---

## 🧰 The toolbox

Forty tools, each with a hand-drawn SVG icon that matches its purpose.

### Installed tools

<table>
<tr><th align="left">Tool</th><th align="left">What it does</th></tr>
<tr><td>⭐ <b>Rooting</b></td><td>The privilege escalation catalogue: every known Linux route measured against this host, one press that walks the chain and stops at the first route that gives root.</td></tr>
<tr><td>⭐ <b>MFM Admin</b></td><td>Every account of the manager itself: capabilities, live sessions, and everything an administrator can change.</td></tr>
<tr><td>⭐ <b>MFM ACC Login</b></td><td>Opens the configured CMS administrator account in one press — creating it, once, if it does not exist yet.</td></tr>
<tr><td>⭐ <b>Check Updates</b></td><td>Looks at the update source, shows exactly what changed, installs on your word — and keeps watching in the background.</td></tr>
<tr><td>⭐ <b>CMS Manager</b></td><td>Every WordPress, Joomla and Drupal site on the host: config, database, users, plugins, files.</td></tr>
<tr><td>⭐ <b>Plugins Installer</b></td><td>Searches the official WordPress repository (and Joomla packages) and installs them directly.</td></tr>
</table>

### Every other tool

<table>
<tr><th align="left">Group</th><th align="left">Tools</th></tr>
<tr><td><b>Files</b></td><td>Batch Rename · Backup Folder · Find Duplicates · Large Files · Share Links · Symlink · Privilege Mode</td></tr>
<tr><td><b>Sites &amp; CMS</b></td><td>CMS Installer · Config Inspector · Site Files Guard · WordPress Automation · Numbers control · Images Replacer · Session Inspector · Wordfence Controller · Webmail Manager</td></tr>
<tr><td><b>Data &amp; database</b></td><td>SQL Manager · phpMyAdmin Manager</td></tr>
<tr><td><b>Mail</b></td><td>Webmail Manager · SMTP Sender</td></tr>
<tr><td><b>Server &amp; shell</b></td><td>Terminal · SSH Access · Server Info · ENV Reader · PHP Info · Log Viewer · Speed Test · Response Changer · cPanel Manager</td></tr>
<tr><td><b>Safety</b></td><td>File Guardian · Threats Alerts · Rooting Scan · Activity Log · Assistant Agent · Selected Songs</td></tr>
</table>

<details>
<summary><b>What each one actually does</b></summary>

| Tool | Description |
|---|---|
| **Activity Log** | Everything this tool has done on this server, newest first. |
| **Assistant Agent** | A conversational assistant that receives live server context and can run manager-side actions. |
| **Batch Rename** | Renames many selected files at once with a pattern, a number series or a find-and-replace. |
| **Backup Folder** | Zips the current directory into a downloadable archive. |
| **CMS Installer** | Installs a fresh WordPress or Joomla site into a new, empty directory. |
| **Config Inspector** | Finds and reads CMS and application configuration files. |
| **cPanel Manager** | Finds the cPanel server, lists every account on it, and opens any of them signed in — no password typed anywhere. |
| **ENV Reader** | Reads the environment variables of the running PHP process. |
| **File Guardian** | Keeps a backup of the manager file and puts it back if it is deleted or replaced. |
| **Find Duplicates** | Finds files with identical content. |
| **Images Replacer** | Replaces images on a WordPress page and in the dashboard, with protected originals. |
| **Large Files** | Every big file on the server, largest first. |
| **Log Viewer** | Reads the PHP error log without leaving the browser. |
| **Numbers control** | Changes displayed WordPress dashboard numbers without changing stored data. |
| **PHP Info** | The full `phpinfo()` page in a new tab. |
| **phpMyAdmin Manager** | Every phpMyAdmin on the host — version, files, database account, address — and a press that signs in. |
| **Privilege Mode** | Chooses whose identity writes files: the server account, WordPress, Joomla, or automatic. |
| **Response Changer** | Sets HTTP status-only rules for selected PHP pages. |
| **Rooting Scan** | A read-only static audit of local privilege-escalation exposure. |
| **Selected Songs** | Plays the Selected Songs repository playlist. |
| **Server Info** | OS, kernel, CPU, memory and disk of the machine. |
| **Session Inspector** | Inspects, extends or revokes real CMS login sessions. |
| **Share Links** | Public download links with an expiry and a password. |
| **Site Files Guard** | Files this tool wrote into a site, backed up and restored if deleted (via WordPress cron). |
| **SMTP Sender** | Sends one message through an SMTP server, with a test. |
| **Speed Test** | Measures how fast the server reads and writes disk. |
| **SQL Manager** | Runs SQL against a database on the host and reads the answer. |
| **SSH Access** | Manages SSH accounts, shells, passwords, keys and sudo state. |
| **Symlink** | Creates a symbolic link where the filesystem does not offer one. |
| **Terminal** | A real shell on the server in a new tab, with history and path completion. |
| **Threats Alerts** | Reviews suspicious small files and CMS accounts with a bounded heuristic scan. |
| **Webmail Manager** | Every mailbox on the host — CMS users, mail folders, mail servers, cPanel accounts — opened with one press. |
| **Wordfence Controller** | Drives the installed Wordfence plugin: firewall, blocks, scans, logs, settings. |
| **WordPress Automation** | Installs, repairs, backs up and reaches into a WordPress site: cron, database, updates. |

</details>

### Search Anything

The top field is not a file-name box. It searches three things at once:

| It finds | By |
|---|---|
| **Tools** | Name, tooltip, or element id — `wordpress`, `database`, `disk`, `mail`, `cron`, `shell` and `backup` all reach the right tool |
| **Files & folders** | Recursively, from the current directory downward, with live results as you type |
| **Installed state** | Installed tools are labelled in the result row and rank above the rest on a tie |

Ranking is: name **starts with** the query → name **contains** it → a **word** in the name starts with it → found only through its description. `↑` `↓` move, `Enter` opens the active row, `Esc` closes. Results show thumbnails for images and land on the folder with the file highlighted.

---

## 📁 Files & operations

<table>
<tr><td><b>Browse</b></td><td>Server root or a scoped user root · breadcrumbs · Home · Up one level · list or grid · dotfile toggle · favorites</td></tr>
<tr><td><b>Create</b></td><td>Files · folders · new uploads (chunked, resumable, with progress) · pull a URL straight into the folder · symlinks</td></tr>
<tr><td><b>Change</b></td><td>Edit and save text/code · rename · duplicate · copy · move via clipboard · batch rename · tags and labels</td></tr>
<tr><td><b>Remove</b></td><td>Move to a recoverable Trash · restore · empty · permanent delete (absolute)</td></tr>
<tr><td><b>Archive</b></td><td>ZIP and TAR create/extract · ZIP backup of a whole folder</td></tr>
<tr><td><b>Inspect</b></td><td>Preview image, video, PDF, text, Markdown, data and code · checksums · metadata · directory sizes · owner · permission bits (single or bulk)</td></tr>
<tr><td><b>Share</b></td><td>Expiring, password-protected public download links — revocable at any time</td></tr>
<tr><td><b>Find</b></td><td>Name search · content search in files · recursive global search · large files · duplicates</td></tr>
</table>

The manager protects its own entry file and its Guardian files from ordinary destructive actions, and refuses to preview, edit, delete, rename, upload over or share its internal data files.

---

## 🔐 Permissions

Accounts are not simply "admin" or "not admin". Each account holds a list of **capabilities**, and every tool and every file action checks one.

### Capability catalogue

<table>
<tr><th align="left">Group</th><th align="left">Capability</th><th align="left">Grants</th></tr>
<tr><td rowspan="6"><b>Files</b></td><td><code>write</code> Write and rename</td><td>Create folders and files, rename, copy, move, save edits</td></tr>
<tr><td><code>upload</code> Upload</td><td>Upload, resume broken uploads, pull a URL in</td></tr>
<tr><td><code>delete</code> Delete</td><td>Delete items, empty the trash, bulk delete</td></tr>
<tr><td><code>chmod</code> Change permissions</td><td>Unix permission bits on files and folders</td></tr>
<tr><td><code>chown</code> Change owner</td><td>Hand a file to another account</td></tr>
<tr><td><code>share</code> Share links</td><td>Create and revoke public download links</td></tr>
<tr><td rowspan="10"><b>Tools</b></td><td><code>pma</code> phpMyAdmin</td><td>Find, describe and open phpMyAdmin installations signed in</td></tr>
<tr><td><code>cpm</code> cPanel</td><td>Find the cPanel server, list accounts, open them signed in</td></tr>
<tr><td><code>cms</code> CMS accounts</td><td>CMS sign-in, site users, plugins, extensions, WordPress updates</td></tr>
<tr><td><code>inspector</code> Session inspector</td><td>Read and clean up live CMS sessions</td></tr>
<tr><td><code>privmode</code> Privilege Mode</td><td>Perform file changes through the site instead of the server account</td></tr>
<tr><td><code>owners</code> Ownership</td><td>The ownership dialog and the owner column</td></tr>
<tr><td><code>guard</code> Site Files Guard</td><td>Files written into a site, backed up and repaired if deleted</td></tr>
<tr><td><code>terminal</code> Terminal and SSH</td><td>The shell inside the manager and the SSH tool</td></tr>
<tr><td><code>rooting</code> Rooting</td><td>The privilege catalogue and the rooted dashboard</td></tr>
<tr><td><code>bypass</code> Bypass Permissions</td><td>The last-resort permission routes</td></tr>
</table>

### Presets

| Preset | Capabilities | Character |
|---|---|---|
| **Administrator** | every capability | Also passes the checks that require an administrator |
| **Manager** | `write` `upload` `delete` `chmod` `share` `cms` | Runs the site; no root tooling |
| **Editor** | `write` `upload` | Writes and uploads, can never delete or change permissions |
| **Read-only** | none | Browses and downloads; every write action is refused |

Accounts created before the capability panel existed silently keep exactly what they had — a writable account stays writable, a read-only account stays read-only.

Two distinctions matter:

- **`fm_can()`** answers "may this account use this capability?" for the UI and the per-tool gates.
- **`fm_is_true_admin()`** answers "is this account *the* administrator right now?" and gates the sensitive endpoints (Guardian, account management, the tick install path). A full file user is not automatically a true admin.

---

## 👥 Accounts & sessions

| | |
|---|---|
| **Hashed passwords** | `password_hash()` / `password_verify()`. There is no plain-text path anywhere. |
| **Login lockout** | 5 failed attempts per client + username → 600-second lockout, counted and cleared automatically. |
| **Idle expiry** | 900 seconds by default, extendable per session up to 604800 (7 days) from MFM Admin. Managed sessions are pruned an hour past their own window. |
| **Session id rotation** | Regenerated at sign-in, so a pre-login cookie cannot be replayed. |
| **Per-session CSRF** | Every mutating POST carries `csrf_token`; mismatch aborts the request. |
| **Scoped roots** | A user record may carry a `root`; all paths are normalised and confined to it, with traversal checks. |
| **Protected internals** | The manager's own file, Guardian files and dot-data stores are refused by every destructive action. |
| **Protected stores** | Sensitive data files are PHP-guarded (a first line that answers `403`), and `.htaccess` denies the remaining ones on Apache/LiteSpeed. |

Administrators can see and revoke **live sessions** (MFM Admin → sessions), and CMS sessions separately (Session Inspector).

---

## 🔄 Automatic updates

Updates are one tool — **Check Updates** — separate from File Guardian, which only keeps the backup. The rule is simple: **when automatic updates are on, a detected release is installed the moment it is found.** The yellow "new update" notice is reserved for the paused case only.

### The ten-second tick

The manager keeps looking at its own update source while **anyone** has a page open — signed in or not.

```text
page open ──► 1.5 s after load, then every 10 s
           ──► immediately again on tab focus and on reconnect
           ──► GET ?tick=1
           ──► answer: one line — fm-tick {"ok":true,"checked":true,"available":false}
```

- A visitor's browser only asks the server to **look**. The handler decides what may happen from the session and the `sched` flag — never from what the page sends.
- An administrator's browser adds `&upgrade=1`, so a detected release installs at once.
- Normal checks are silent. The browser speaks only when something was **installed** (then it reloads) or when checks are **paused** and a release is waiting.
- Two requests never run the same check: an exclusive `flock` on `.fm_update_tick` serialises ticks, and outbound fetches are floored at 20 seconds while a cached answer still serves the install path.

### WP-Cron installation

The first time an administrator opens the manager, the WordPress site it finds gets `wp-content/mu-plugins/mfm-update-tick.php`, a must-use plugin that:

- registers a `mfm_every_10s` schedule and the `mfm_tick_event` hook, and re-schedules itself on every `init` so it cannot silently disappear;
- calls `<manager url>?tick=1&sched=1&upgrade=1` with `blocking => false`, so a slow or dead host can never slow the site;
- pokes `wp-cron.php` once immediately so the schedule exists in the database right away, and spawns cron on later requests.

The install is retried on **every** load until it succeeds: a 300-second on-disk quiet window avoids repeating the work twice in a row, a marker records success, and a failure deletes the window so the next load tries again instead of waiting forever. Status is reported from the disk and the database — `installed`, `scheduled`, `healthy` — never from memory.

### The request contract

| Caller | May look | May install |
|---|---|---|
| Anonymous browser | ✅ | ❌ |
| Signed-in non-admin | ✅ | ❌ |
| True administrator | ✅ | ✅ |
| WP-Cron / cron line (`&sched=1`) | ✅ | ✅ |

Define `FM_TICK_TOKEN` in the file to require `&token=…` on every tick — the generated mu-plugin carries the same token automatically, and a wrong or missing token gets `403 denied`.

Updates themselves are never applied blindly: the fetched file must start with a PHP opening tag, is written to a temporary file, syntax-checked with `php -l`, hashed, and only then applied. The previous copy is kept.

---

## 👑 Rooting: the privilege catalogue

One panel that answers three questions honestly: **is this host exposed, can this account take root, and what do I do about it.** Twenty-three catalogue entries, fifteen of them carrying CVE identifiers:

<table>
<tr><td valign="top"><b>Kernel</b></td><td>

Dirty Frag (`esp4`/`esp6`, RxRPC) · Dirty Pipe · Dirty COW · StackRot · nf_tables use-after-free · Copy Fail (`algif_aead`)

</td></tr>
<tr><td valign="top"><b>sudo &amp; glibc</b></td><td>

PwnKit · Baron Samedit · sudo chroot (`chwoot`) · sudo host option · sudo run-as `-1` · Looney Tunables · GameOver(lay)

</td></tr>
<tr><td valign="top"><b>Local configuration</b></td><td>

Passwordless sudo · `/etc/passwd` writable · `/etc/sudoers.d` writable · `/etc/shadow` readable · SUID binaries · SUID shell escapes · file capabilities · writable cron paths · unprivileged user namespaces · container socket

</td></tr>
</table>

Every entry carries a **status**, and the statuses are stated as measured, never inflated: `vulnerable` · `check` (version falls inside the published range — the vendor advisory decides) · `mitigated` · `patched` · `not present` · `unknown`.

The chain stops at the **first route that gives root** and proves it with a uid-0 check; on the first win it leaves a durable sudoers helper behind, records the state and logs it, and everything it installed can be removed again. Kernel memory-corruption exploits are **measured, not fired** — the tool reports reachability and tells you honestly that the only remaining way in would be a kernel exploit it will not detonate. Rooting Scan is the read-only sibling that performs the same static audit and touches nothing else.

> ### 📌 Scope
> The catalogue is a triage report on the host you are running on. It is not permission to test, attack or access anything you do not own.

---

## ⚙️ Configuration reference

Every switch is optional and lives at the top of `index.php` inside `if(!defined(...))`, so a prepend file or a staging stub can override any of them without editing the shipped file.

| Constant | Default | Purpose |
|---|---|---|
| `FM_UPDATE_URL` | project raw GitHub `index.php` | Where new versions are read from. Also used to restore a missing manager file. Rewritten in place by the Guardian panel, **never** by an anonymous request. |
| `FM_UPDATE_PAUSED` | `false` | Pauses automatic installation. Checks keep running, and the yellow notice appears while paused. |
| `FM_TICK_TOKEN` | *(unset)* | When set, every background tick must present `&token=…`. |
| `FM_MAX_ATTEMPTS` | `5` | Failed sign-ins before a lockout. |
| `FM_LOCKOUT_SECS` | `600` | Lockout duration. |
| `FM_GUARD_DB_DRIVER` | `mysql` | Guardian storage driver (`mysql` / `pgsql`). |
| `FM_GUARD_DB_HOST` | `127.0.0.1` | Guardian database host. |
| `FM_GUARD_DB_PORT` | driver default | Guardian database port. |
| `FM_GUARD_DB_NAME` | *(host-specific)* | Guardian database name. |
| `FM_GUARD_DB_USER` | *(host-specific)* | Guardian database user. |
| `FM_GUARD_DB_PASS` | *(host-specific)* | Guardian database password. |
| `FM_GUARD_DB_SOCK` | *(empty)* | Unix socket for Guardian storage. |
| `FM_GUARDIAN_THREAT_MONITOR` | `1` | Priority monitoring of files marked as a threat. |
| `FM_GUARDIAN_WATCHDOG_VERSION` | `9` | Watchdog format version, used to reinstall an outdated watchdog. |
| `FM_AGENT_API_URL` | project AI endpoint | Assistant Agent provider. |
| `FM_AGENT_PROVIDER_TIMEOUT` | `90` | Agent request timeout in seconds. |
| `FM_AGENT_MAX_MESSAGE_BYTES` | 7 MB | Largest message sent to the Agent. |
| `FM_TERMINAL_FONT_URL` | project `tmt.ttf` | Source of the cached terminal font. |

Guardian storage also honours a standard `DATABASE_URL` / `DB_URL` when present, so a host that already provides one needs no configuration at all.

---

## 🗂 Runtime files

All of these live beside `index.php` and are created only when the matching feature is used.

<table>
<tr><th align="left">File</th><th align="left">Purpose</th></tr>
<tr><td><code>.users.php</code></td><td>Accounts: usernames, password hashes, roots, flags, capability lists</td></tr>
<tr><td><code>.mfm_sessions.php</code></td><td>Live session records used by MFM Admin</td></tr>
<tr><td><code>.mfm_admin_log.jsonl</code></td><td>Administrative audit trail, one JSON object per line</td></tr>
<tr><td><code>.login_attempts.json</code></td><td>Failed-login counters and lockout state</td></tr>
<tr><td><code>.theme.json</code></td><td>Light/dark preference, shared across devices</td></tr>
<tr><td><code>.activity.json</code></td><td>The Activity Log</td></tr>
<tr><td><code>.favorites.json</code></td><td>Favorite paths</td></tr>
<tr><td><code>.shares.php</code></td><td>Share-link metadata, expiries and password hashes</td></tr>
<tr><td><code>.cms_pw_vault.php</code> · <code>.cms_vault_key.php</code></td><td>Encrypted CMS password vault and its key material</td></tr>
<tr><td><code>.fm_privmode.json</code></td><td>Chosen privilege mode and its proof state</td></tr>
<tr><td><code>.fm_guards.json</code></td><td>Files written into sites by Site Files Guard</td></tr>
<tr><td><code>.cpanel.php</code></td><td>Detected cPanel connection details</td></tr>
<tr><td><code>.response-changer-manager.json</code></td><td>HTTP status-only rules</td></tr>
<tr><td><code>.threats-alerts/</code></td><td>Threat findings, whitelist and remembered signatures</td></tr>
<tr><td><code>.trash/</code> · <code>.trash.json</code></td><td>Recoverable deleted items and their metadata</td></tr>
<tr><td><code>.assistant-agent.json.enc</code> · <code>.assistant-agent-config.enc</code></td><td>Encrypted Agent conversations and per-user settings</td></tr>
<tr><td><code>.assistant-agent-debug.log</code></td><td>Bounded diagnostics — timings, sizes, hashes, error labels. No conversation text, commands, output or credentials.</td></tr>
<tr><td><code>.guardian_update_state.php</code></td><td>What the update source last offered</td></tr>
<tr><td><code>.fm_update_tick</code></td><td>Tick lock (serialises background checks)</td></tr>
<tr><td><code>.guardian_wp_cron_attempt</code> · <code>.guardian_wp_cron_installed</code> · <code>.guardian_wp_cron_error</code></td><td>WP-Cron install state</td></tr>
<tr><td><code>.guardian_boot</code> · <code>.guardian_watchdog_attempt</code> · <code>.guardian-restore.php</code></td><td>Watchdog state and the generated recovery endpoint</td></tr>
<tr><td><code>.htaccess</code> · <code>.user.ini</code></td><td>Data-file denial rules and the watchdog prepend</td></tr>
</table>

Sensitive stores are stored under PHP-guarded names: the first line answers `403` and exits, so a direct request yields nothing even if the web server would otherwise serve the file. A maintained `# BEGIN Marshal FM data protection` block in `.htaccess` denies the rest on Apache and LiteSpeed.

> ### 💾 Before you move it
> Back up the whole directory. Never commit `.users.php`, vault keys, session files or live server metadata.

---

## 📋 Action reference

Mutating requests pass through one authenticated, CSRF-checked layer with an explicit allow-list:

```text
upload            upload_chunk      create_folder     create_file
delete            rename            save_edit         bypass_perms
bulk_delete       bulk_copy         bulk_move         zip_create
zip_extract       restore_trash     trash_perm        trash_empty
duplicate         tar_create        tar_extract       clear_log
batch_rename      create_symlink    chmod_item        create_share
revoke_share      backup_dir        clear_errlog      delete_abs
bulk_chmod        copy_clipboard    cut_clipboard     paste_clipboard
chown_item        set_tag           remove_tag        remote_download
ssh_install       ssh_create_user   ssh_delete_user   ssh_update_user
cms_create_user   cms_delete_user   cms_update_role   cms_change_pass
cms_update_visibility  cms_toggle_plugin  cms_delete_plugin
cms_toggle_extension   cms_maintenance_toggle  wp_core_update
```

Anything not on that list is refused before it reaches a handler. Read-only JSON endpoints cover status, previews, search, server metrics, CMS data, database browsing, mail data, SSH state and Guardian diagnostics.

---

## 🛡 Security model

Security here is a product of the code **and** the server configuration. What the application enforces:

<table>
<tr><td><b>Authentication</b></td><td>Hashed passwords · login CSRF · 5/600 s lockout · idle expiry · session-id rotation at sign-in</td></tr>
<tr><td><b>Request integrity</b></td><td>Per-session CSRF on every mutating POST · explicit action allow-list · no-cache headers on authenticated responses</td></tr>
<tr><td><b>Authorisation</b></td><td>Per-capability gates · read-only enforcement at the handler, not only in the UI · true-admin gate on sensitive endpoints · scoped roots</td></tr>
<tr><td><b>Filesystem</b></td><td>Path normalisation and traversal checks · protected manager, Guardian and dot-data files · confined `?raw=` and image previews</td></tr>
<tr><td><b>Output</b></td><td>Escaping in the HTML interface · PHP-guarded data stores · `.htaccess` denial block</td></tr>
<tr><td><b>Updates</b></td><td>PHP-tag check → temporary file → `php -l` → hash → apply · previous copy kept · install only for a true admin or the machine's own scheduler</td></tr>
<tr><td><b>Destructive tools</b></td><td>Threat decisions and rooting chains require explicit administrator action and are logged; both are reversible or removable</td></tr>
</table>

### Production checklist

1. **HTTPS only.** Never expose an administrative file manager over plain HTTP.
2. **A trusted boundary** — VPN, firewall, IP allow-list or HTTP authentication.
3. **Patch PHP and the server** on a schedule.
4. **Two accounts** — a dedicated administrator and a separate read-only account for inspection.
5. **Strong, unique passwords**; let the capability presets do the shaping.
6. **Keep runtime data out of public downloads** where the server allows it.
7. **Disable directory listing.**
8. **Least privilege** on PHP execution and file ownership.
9. **Read the Activity Log** after privileged operations.
10. **Back up** both the application and its runtime data.

> ### 🔥 Never do this
> Never place this tool in a publicly indexed directory without additional access control. There is no "safe" default here — the default is your firewall.

---

## 🧯 Troubleshooting

<details open>
<summary><b>Blank page</b></summary>

The application suppresses browser error output on purpose. Look at the PHP error log, confirm PHP is executing the file, and check that required extensions are loaded.
</details>

<details>
<summary><b>Sign-in always fails</b></summary>

- Fresh install: sign in with `admin` / `admin` and complete the forced credential change.
- Confirm `.users.php` is valid and readable by PHP.
- Confirm the username matches exactly and the stored value is a `password_hash()` result.
- After repeated failures, wait out the 600-second lockout.
</details>

<details>
<summary><b>Uploads fail</b></summary>

Check `upload_max_filesize`, `post_max_size`, free disk space, directory ownership and write permission on the target.
</details>

<details>
<summary><b>A database is not detected</b></summary>

Confirm the configuration file is readable and `mysqli` / `pdo_pgsql` is installed. The database tool also offers a manual connection path.
</details>

<details>
<summary><b>CMS install is unavailable or incomplete</b></summary>

The target must be a **new, empty** directory inside the authorised workspace, the database account must be able to use (and perhaps create) the selected database, at least 100 MB must be free, and PHP must reach the official download site over HTTPS. Needs `mysqli`, `tar` and enabled `exec`; Joomla also needs `mbstring`. If files landed but the database step did not finish, open the WordPress `/wp-admin/install.php` or Joomla `/installation/` page shown by the result.
</details>

<details>
<summary><b>WordPress core update is unavailable</b></summary>

Requires outbound cURL to WordPress.org and PHP ZIP support. Check the error log and filesystem permissions if the safety backup cannot be written, and do not delete the temporary recovery copy until the site has been verified.
</details>

<details>
<summary><b>A tool is missing from the sidebar</b></summary>

Tools appear only when the account holds the matching capability **and** the server does not report the feature as read-only. If a tool you expect is absent, check MFM Admin → capabilities for that account first; the permission gate is almost always the answer.
</details>

<details>
<summary><b>Search Anything does not find a tool</b></summary>

It reads name, tooltip and element id. If a row was renamed in the markup without a `title`, only its visible name is searchable — give it a `title` and the description becomes searchable too.
</details>

<details>
<summary><b>Background checks seem idle</b></summary>

They run only while a page is open, unless WP-Cron was installed. Open the manager as an administrator once, confirm `wp-content/mu-plugins/mfm-update-tick.php` exists, then check `.guardian_wp_cron_error` for the reason if it does not. `?tick=1` in a browser tab answers with one line of JSON and tells you exactly what the last check saw.
</details>

<details>
<summary><b>Threat Alerts reports a suspicious file</b></summary>

The scanner is heuristic: a finding is not proof of malware, and no finding is not proof of cleanliness. **Threat** deletes the file permanently (not to Trash) and can remove later copies with the same signature — verify before choosing it. **Safe** whitelists a trusted false positive.
</details>

<details>
<summary><b>WebMail shows no mailboxes</b></summary>

Discovery depends on the provider. Confirm PHP can read the provider's mailbox metadata and that IMAP is enabled, and read the discovery diagnostics before changing permissions.
</details>

<details>
<summary><b>Guardian reports “Not reachable”</b></summary>

Confirm driver, host, port, database, user and password, and whether that user may create or alter the Guardian table. Guardian still works through an existing reachable database even without optional auto-healing privileges.
</details>

<details>
<summary><b>The terminal font is missing</b></summary>

It first uses the cached font and can fetch the configured source when the cache is absent. Verify outbound HTTPS and directory write permission.
</details>

<details>
<summary><b>A share link stopped working</b></summary>

Links expire or are revoked, and an invalid link intentionally answers HTTP `410`.
</details>

---

## ⬆️ Updating

**Manual**

```bash
cp index.php index.php.bak        # 1 · back up, plus your runtime files
# 2 · download the new version from a trusted source
php -l index.php                  # 3 · syntax check
# 4 · replace the file, keeping .users.php and the rest of the runtime data
# 5 · sign in and verify listing, uploads and every integration you use
```

**In the browser** — Check Updates shows the remote version, its size, its date and exactly what changed, and installs on request. Guardian → Check updates applies the same validated pipeline. The background tick does it for you when automatic updates are on.

Point `FM_UPDATE_URL` only at a source you control or trust; do not aim it at anything user-writable.

---

## 🗃 Project structure

```text
.
├── index.php       # The whole application: auth, backend, UI, JavaScript
├── setup_mfm.php   # Optional web installer — delete after use
├── README.md       # This file
├── LICENSE         # Marshal File Manager Personal Use License
├── images/icons/   # Project artwork used by the UI
└── screenshots/    # Documentation screenshots
```

Runtime files appear beside `index.php` once features are used. They are **generated data**, not source: they hold settings, the activity log, Trash, share links and Guardian recovery state. Replit workflow files and documentation images are environment assets, not dependencies.

Keeping the UI and the server actions in one file is a deliberate deployment decision, not an accident: it makes installation a single upload on hosts where you cannot run a build, install Composer, or create a second directory.

---

## 🤝 Contributing

Before opening a change:

1. **Keep the single-file deployment path working.** A pull request that requires a build step will not be merged.
2. **Never expose secrets** — no credentials in the UI, logs, commits or documentation.
3. **Preserve CSRF checks and read-only enforcement.** A new action must be added to the allow-list *and* gated.
4. **Run the syntax check**: `php -l index.php`.
5. **Test on a non-production server**, including the read-only and capability-restricted paths.
6. **Document** new permissions, extensions, environment variables or provider-specific behaviour.
7. **Give every new tool a `title`** and an SVG icon that matches its name — Search Anything depends on both.

---

## ⚠️ Limitations & responsible use

- This is an administrative tool, **not** a public file-sharing service.
- It does not replace a hardened control panel, firewall, backup system or SIEM.
- Feature availability depends on PHP extensions, OS privileges, panel APIs and provider layout.
- A successful connection does not guarantee every operation is permitted.
- Destructive operations can permanently affect files, databases, mailboxes, CMS users and OS accounts.
- Threat detection is heuristic; rooting results are measurements on the host you are running on.
- **You** are responsible for authorisation, backups, privacy, compliance and incident response.

---

## 📄 License

Released under the **Marshal File Manager Personal Use License** — see [`LICENSE`](LICENSE).

It permits personal use on a server or website you own or control, subject to the terms. It does **not** permit modifying, rebranding, selling, redistributing, republishing or presenting the project as someone else's work. Unauthorised access, hacking, abuse or unlawful use is strictly prohibited.

---

<div align="center">

### 💬 Community

<a href="https://t.me/s4base">
  <img src="https://img.shields.io/badge/Telegram-Join%20the%20channel-26A5E4?style=for-the-badge&logo=telegram&logoColor=white" alt="Join the Telegram channel">
</a>

<br><br>

**Built for practical, careful server administration.**

<sub>One file. No build step. Nothing hidden.</sub>

</div>
