# Marshal File Manager

<p align="center">
  <img src="https://github.com/orgezeo/marshal-file-manager/blob/main/images/icons/mfm.png?raw=true" alt="Marshal File Manager logo" width="120">
</p>

<p align="center">
  <strong>A secure, self-hosted server control workspace for files, databases, CMS sites, mailboxes, SSH, and server diagnostics.</strong>
</p>

<p align="center">
  <a href="#features">Features</a> ·
  <a href="#requirements">Requirements</a> ·
  <a href="#installation">Installation</a> ·
  <a href="#usage">Usage</a> ·
  <a href="#security-model">Security</a>
</p>

> Marshal File Manager is a single-file PHP administration tool designed for hosting environments where you need practical server access without installing a large framework or control panel.

![Marshal File Manager interface](screenshots/terminal-manager-check.jpg)

## Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Optional Web Installer](#optional-web-installer)
- [First Login](#first-login)
- [Usage](#usage)
  - [File Manager](#file-manager)
  - [Terminal](#terminal)
  - [Assistant Agent](#assistant-agent)
  - [Server Information](#server-information)
  - [Database Manager](#database-manager)
  - [cPanel Management](#cpanel-management)
  - [WebMail](#webmail)
  - [SMTP Sender](#smtp-sender)
  - [CMS Management](#cms-management)
    - [CMS Installer](#cms-installer)
    - [WordPress tools](#wordpress-tools)
  - [Threat Alerts](#threat-alerts)
  - [SSH Access](#ssh-access)
  - [File Guardian](#file-guardian)
- [Configuration and Runtime Files](#configuration-and-runtime-files)
- [Supported Actions](#supported-actions)
- [Security Model](#security-model)
- [Permissions and Hosting Notes](#permissions-and-hosting-notes)
- [Troubleshooting](#troubleshooting)
- [Updating](#updating)
- [Project Structure](#project-structure)
- [Limitations and Responsible Use](#limitations-and-responsible-use)
- [Contributing](#contributing)
- [License](#license)
- [Community](#community)

## Overview

Marshal File Manager (`index.php`) provides a responsive browser-based administration interface for a PHP server. It combines a file manager with operational tools that are normally spread across a hosting panel, database client, CMS dashboard, mail client, and SSH administration screen.

The application is intentionally self-contained:

- The primary application is one PHP entry file.
- It uses server-side PHP and browser-native JavaScript.
- It does not require a frontend build step.
- It can run from a normal document root or a subdirectory.
- It supports both dark and light themes.
- It is responsive for desktop, tablet, and mobile screens.
- An optional `setup_mfm.php` web installer can download and install the manager; it is separate from the single-file application and should be removed or protected after use.

The application should be installed only on a server that you own or are explicitly authorized to administer.

## Features

### File management

- Browse from the server root or the configured user root.
- Navigate through breadcrumb links, Home, and Up one level.
- List or grid view.
- Search file names and search file contents.
- Upload files with progress feedback.
- Create files and folders.
- Edit and save text and code files.
- Rename, duplicate, copy, move, and delete items.
- Move deleted items to a recoverable Trash.
- Restore items or permanently empty the Trash.
- Create and extract ZIP and TAR archives.
- Calculate directory sizes.
- Create symbolic links.
- Change permissions for individual items or batches.
- Batch rename files.
- Add colored tags and labels.
- Create expiring share links for files.
- Download a remote file into the current directory.
- Preview images, video, PDF, text, Markdown, data, and code files.
- Inspect checksums and file metadata.
- Find large files and duplicate files.
- Create a ZIP backup of the current directory.

### Administration and diagnostics

- Multi-user login support.
- Administrator and read-only accounts.
- Per-user root directories.
- Activity log with clear-log support.
- Error-log viewer.
- Live CPU, memory, disk, uptime, PHP, OS, and web-server information.
- Live status bar for disk usage, load, memory, uptime, and time.
- Environment information view with sensitive values handled server-side.
- PHP information page.
- Network speed test.
- Theme preference persistence.
- Assistant Agent for guided server inspection and requested administrative actions.
- Slow, bounded threat scanning for suspicious files, plus CMS-user review and whitelisting.

### Hosting, CMS, and mail tools

- Automatic or manual cPanel connection.
- cPanel account listing, package listing, account creation, password changes, suspension, and termination.
- WordPress and Joomla configuration discovery.
- CMS user listing and management.
- CMS roles, passwords, visibility, plugins, themes, extensions, and maintenance mode.
- One-click CMS administrator login bridges where supported.
- WordPress core-version checks and updates from WordPress.org.
- WordPress Site Health checks and a reversible, explicitly selected status override.
- WordPress dashboard number presentation controls that do not change stored site counts.
- WordPress image replacement with protected originals and individual or batch restore.
- WordPress cron inspection, execution, deletion, and one-time email scheduling.
- CMS Installer for new WordPress or Joomla sites in empty directories.
- Optional visible WordPress file-recovery helper.
- Dedicated single-recipient SMTP Sender for administrative messages.
- Mailbox discovery across common hosting, Dovecot, Exim/Postfix, Plesk, cPanel, and account-local layouts.
- IMAP mailbox browsing, folders, messages, attachments, flags, deletion, and SMTP sending.
- SSH installation status and SSH user management.
- SSH shell selection, passwords, sudo status, public keys, and user deletion.

## Requirements

### Required

- PHP 8.0 or newer. PHP 8.4 is recommended.
- A web server capable of executing PHP.
- A writable application directory for runtime metadata.
- A browser with JavaScript enabled.

### Recommended PHP extensions

The exact extensions available depend on the host and the tools you use:

- `json`
- `session`
- `openssl`
- `mbstring`
- `fileinfo`
- `curl` or URL-aware `file_get_contents`
- `zip` for ZIP creation and extraction
- ` Phar` / `phar` support for TAR operations where applicable
- `mysqli` for MySQL-compatible database operations
- `pdo` and `pdo_pgsql` for PostgreSQL-backed Guardian storage
- `imap` for WebMail

Some tools have additional requirements:

- The CMS Installer needs `mysqli`, outbound HTTPS access, and `tar` available through PHP `exec`; Joomla also requires `mbstring`.
- WordPress core updates need outbound HTTPS through cURL and the PHP ZIP extension.
- Assistant Agent needs outbound HTTPS access to its AI service.

The application checks capability availability at runtime. A missing optional extension or server command disables or limits only the dependent feature; it does not make the file manager unusable. The web installer reports missing optional PHP extensions and continues without trying to install system packages.

## Installation

### 1. Download the application

Copy `index.php` into the directory that should be managed. For example:

```bash
mkdir -p /var/www/html/marshal-fm
cp index.php /var/www/html/marshal-fm/index.php
```

Keep the logo URL in the file unchanged if you want the login screen and header to use the project logo from GitHub.

### 2. Set ownership and permissions

The PHP process must be able to read the application and write its runtime files:

```bash
chown -R www-data:www-data /var/www/html/marshal-fm
chmod 750 /var/www/html/marshal-fm
chmod 640 /var/www/html/marshal-fm/index.php
```

Use the correct web-server user for your distribution. Do not make the directory world-writable.

### 3. Serve the directory

For a quick local test:

```bash
php -S 0.0.0.0:5000 -t /var/www/html/marshal-fm
```

Then open:

```text
http://127.0.0.1:5000/
```

For production, place it behind HTTPS and your normal Apache, Nginx, LiteSpeed, or hosting-panel PHP configuration. Do not expose an administrative file manager over plain HTTP.

### 4. Open the application

If installed at the domain root:

```text
https://example.com/
```

If installed in a subdirectory:

```text
https://example.com/marshal-fm/
```

The application is served through `index.php`; no framework routing or build command is required.

### Optional Web Installer

`setup_mfm.php` is an optional browser-based alternative to copying `index.php` yourself. Upload it to the directory where the manager should live, protect that directory, and open `https://your-domain/path/setup_mfm.php`.

The installer:

- Downloads the default manager source from the project's raw GitHub branch over HTTPS. A different source must still use `raw.githubusercontent.com` and a branch URL ending in `/index.php`.
- Lets you choose the destination PHP filename in the same directory. It refuses to overwrite an existing file.
- Checks that the downloaded file looks like the expected Marshal File Manager source and runs a PHP syntax check when the host allows it.
- Reports unavailable PHP extensions without trying to install packages or run privileged system commands.
- Starts the installed manager with the fixed initial `admin` / `admin` credentials; the manager requires you to replace them on the first sign-in.
- Deletes itself after a successful installation when the host permits it.

**Protect this installer while it is present:** it is publicly reachable and does not require an existing File Manager login. Run it only in the intended directory, preferably behind an IP/VPN or HTTP-authentication restriction. If installation fails, or the installer reports that it could not delete itself, remove `setup_mfm.php` manually before leaving the directory online.

## First Login

On a fresh installation without `.users.json`, the manager creates the initial `admin` / `admin` account. The first sign-in is blocked until you choose a new username and password:

- Username: 3–64 characters, beginning with a letter and then using letters, numbers, `.`, `_`, or `-`.
- Password: at least 12 characters.

Do not leave the default credentials in place. Once setup is complete, the login form uses the users stored in `.users.json`. A user record contains a username, a password hash, and optional access flags such as:

- `admin`
- `readonly`
- `root`

Passwords must be stored as PHP password hashes, never as plain text. A minimal record looks like this:

```json
[
  {
    "user": "admin",
    "hash": "$2y$10$REPLACE_WITH_A_REAL_PASSWORD_HASH",
    "admin": true,
    "readonly": false,
    "root": "/var/www/html"
  }
]
```

Generate a password hash with PHP:

```bash
php -r 'echo password_hash("replace-this-password", PASSWORD_DEFAULT), PHP_EOL;'
```

Replace the example hash, protect the file, and log in through the browser if you provision accounts manually. Do not commit real credentials or production runtime files to GitHub.

Failed logins are tracked per client and username, and repeated failures trigger a temporary lockout. Authenticated sessions expire after a period of inactivity.

## Usage

### File Manager

After signing in, the main workspace shows the current directory. Use the top search field for file-name searches, the sidebar for navigation, and the row actions for common operations.

#### Common workflow

1. Open a directory from the list or grid.
2. Select one or more items.
3. Use the toolbar for upload, create, copy, move, archive, permissions, or deletion.
4. Click a supported file to preview it.
5. Use the context menu or item actions for rename, download, edit, tags, and permissions.
6. Recover accidental deletions from Trash before permanently deleting them.

The manager protects its own entry file and Guardian files from ordinary destructive actions.

### Terminal

Open **Terminal** from the Tools section. The terminal keeps the current working directory, provides command history and path completion, and displays command output and execution time.

Terminal commands run with the operating-system privileges of the PHP process. Treat this as equivalent to server shell access:

- Use only trusted commands.
- Avoid commands copied from untrusted sources.
- Review destructive commands before execution.
- Prefer the file manager operations when a command is not necessary.
- Restrict the application with a trusted network boundary.

### Assistant Agent

Open **Assistant Agent** from Tools to ask questions about the current server or request work in the authorized workspace. It receives live context such as the PHP version, server type, current directory, and detected CMS, and it can propose or run manager-side shell and file actions. A new conversation begins with a read-only environment check; later actions run one at a time and their actual results are returned to the Agent.

Assistant actions are not a separate security boundary. The manager executes an action returned by the Agent; there is no additional per-action approval prompt. Actions run with the server-side permissions available to the manager and PHP process. Use the Agent only behind trusted access controls, give destructive instructions deliberately, and verify its reported results.

Conversation history is stored locally in encrypted runtime files, but prompts and the results returned from requested actions are also sent to the Assistant's AI service so it can answer. Do not submit passwords, API keys, tokens, private keys, or other secrets, and do not ask the Agent to print them.

### Server Information

**Server Info** displays live values such as hostname, server and client IPs, uptime, CPU cores and load, RAM usage, PHP version, OS, SAPI, memory limits, upload limits, disk usage, timezone, and enabled extensions.

**Environment** shows the server environment view. Never share screenshots of this view publicly if it contains infrastructure details.

### Database Manager

Open **Database Manager** to scan common configuration files and connect to a detected MySQL-compatible or PostgreSQL database.

Available operations include:

- Inspect databases and tables.
- Browse table columns and paginated rows.
- Run SQL queries.
- Export tables as CSV.
- Export tables as SQL.

Database queries run with the credentials and privileges of the selected connection. Use a read-only database account whenever possible. Back up important data before running `UPDATE`, `DELETE`, `ALTER`, `DROP`, or other mutating queries.

### cPanel Management

Open **cPanel** from the Tools section. The tool can attempt automatic detection or accept a manual connection.

Depending on the host and API permissions, it can:

- Detect the current cPanel user.
- List hosted accounts.
- List hosting plans.
- Create accounts.
- Change account passwords.
- Suspend or unsuspend accounts.
- Terminate accounts.

cPanel operations are provider-dependent. If the host does not expose the required API or the connected account lacks permission, the corresponding action will be unavailable or return a diagnostic message.

### WebMail

Open **WebMail** to discover mailboxes and connect to IMAP/SMTP services.

The discovery process is designed for varied hosting layouts. It checks available control-panel data, Dovecot passdb/configuration sources, Exim/Postfix sources, Plesk data, cPanel APIs, and account-local paths where accessible.

Supported mailbox actions include:

- List mailboxes.
- Browse folders.
- List and open messages.
- Download attachments.
- Mark messages.
- Delete messages.
- Send mail through SMTP.

Mailbox discovery and mailbox access are separate capabilities. A mailbox may be valid while automatic discovery is unavailable; in that case use the available manual connection or hosting-panel configuration.

### SMTP Sender

**SMTP Sender** is a separate administrator tool for sending one message directly through an SMTP server. Enter the server host and port, choose no encryption, TLS, or SSL, and provide authentication when the server requires it. The tool can test the connection before sending and supports plain-text or HTML content, an optional Reply-To address, priority, and one attachment up to 5 MB.

Each send has exactly one recipient. Sending is limited to five messages per ten minutes in the current session; there is no recipient list, BCC, bulk campaign, or credential rotation. SMTP credentials are submitted for the request and are not saved by the tool. Use it only for authorized administrative messages.

### CMS Management

The CMS tools are intended for sites you own or administer. Configuration discovery supports common WordPress and Joomla layouts.

#### CMS Installer

Administrators can open **CMS Installer** to install a new WordPress or Joomla site inside the authorized File Manager workspace. The installer checks PHP and storage requirements, downloads the latest compatible official package over HTTPS, inspects the archive, and requires a new empty target directory. It refuses to overwrite an existing CMS or other non-empty target.

Provide the site title, database host and port, database name and account, table prefix, and CMS administrator username, password, and email. The database account must be able to use the selected database and may need permission to create it. The installer verifies the MySQL connection before copying files; it does not save the supplied database credentials in a separate File Manager settings file.

WordPress setup is completed automatically when the server supports it. If the files and configuration are ready but automatic database setup does not finish, open `/wp-admin/install.php` in the new site. Joomla installation is attempted through its bundled CLI installer; if that cannot complete, open `/installation/` to finish the official web installer and create the tables and administrator account.

The installer needs `mysqli`, outbound HTTPS access and `tar` through PHP `exec`; Joomla additionally needs `mbstring`. It requires at least 100 MB of reported free disk space. If a failure occurs after the installer has created the target, it attempts to remove that newly created target and its temporary package files.

#### WordPress tools

The WordPress tools include:

- Inspect WordPress users.
- Create and delete users.
- Change roles and passwords.
- Toggle hidden/visible user state.
- List, activate, deactivate, and delete plugins.
- Switch and delete themes.
- Inspect and manage maintenance mode.
- Check the installed core version and available official versions, then update WordPress core from WordPress.org. The update preserves `wp-config.php` and `wp-content`, and keeps a recovery copy of the previous core during the update. The updater requires cURL and PHP ZIP support.
- Review live Site Health checks. An optional Site Health control can explicitly set the displayed status to Good, Should be improved, or Critical problems. This is a deliberate presentation override, not a repair or a live health result; choose **Auto** to remove the override and return to WordPress's real tests.
- Set selected dashboard count displays (posts, pages, comments, users, media, drafts, pending, or scheduled items), an optional email-count selector, or up to ten custom CSS-selector counts. This changes numbers shown in the WordPress administrator interface only; it does not change posts, comments, users, or database counts. Reset the control to remove the helper and show the real numbers again.
- Find images on a selected WordPress page or scan the all-site inventory, including media-library and dashboard assets. Replace an individual image, paste an image URL or server path that was not listed, or apply one replacement image to the current result set. Replacement uploads are limited to 25 MB and must be readable supported image files. The chosen bytes are written to the original image's server path and filename/extension; a protected copy of the original is made before the first replacement. Restore images individually or restore all originals.
- Inspect and run scheduled WP-Cron events.
- Delete selected cron events.
- Schedule a one-time email through WP-Cron.
- Install or remove an optional visible file-recovery helper.

Image discovery is page-scoped by default. **Selected page only** reads that page's image references; **All site images** can also include the media library and dashboard assets. Batch replacement operates on the images currently listed, with an upper limit of 5,000 targets. Use a compatible replacement format because the file is copied byte-for-byte rather than converted.

#### Joomla

- Inspect and manage users supported by the detected configuration.
- Change passwords and roles where the connected site permits it.
- Manage supported extensions.
- Inspect and toggle maintenance mode.
- Use the administrator-login bridge where the site layout and permissions allow it.

CMS list views are read-only. Changes are performed only through their explicit action buttons.

### Threat Alerts

**Threats Alerts** provides a slow, bounded scan of the authorized workspace and a separate review of detected CMS users. It inspects readable text or executable/script files up to 3 MB, assigns risk levels from file type and suspicious content indicators, and can scan a single path on request. Images and archives are not read as text. This is heuristic triage—not a full antivirus scanner or a guarantee that a site is clean.

For a file finding, review its path and reason, then mark it **Safe** to add its path/content signature to the whitelist or **Threat** to delete it. A Threat decision is destructive: the file is permanently removed (not moved to Trash), its content signature is remembered, and matching copies can be removed by priority monitoring. Only mark a file Threat after verifying it.

The **CMS Users** tab lets you choose a detected WordPress or Joomla configuration and review account identity, role, and automatic indicators. Marking an account **Threat** deletes it and remembers its identity for removal if a matching account is created during a later active check. Marking it **Safe** whitelists the account. These actions change the CMS directly, so verify the selected installation and account first.

### SSH Access

Open **SSH Access** to inspect whether OpenSSH is installed and determine the connection details exposed by the server.

The User Management tab can support:

- Creating SSH users.
- Removing SSH users.
- Changing passwords.
- Changing login shells.
- Adding public keys.
- Viewing key counts.
- Viewing locked/active status.
- Granting or revoking sudo privileges where supported.

SSH user changes affect operating-system accounts. Confirm the username, shell, key, and privilege level before applying changes.

### File Guardian

File Guardian is an authenticated self-healing backup for the installed manager file. It stores an exact copy of the current file in a database controlled by the administrator and can restore that file if it is deleted or becomes unavailable.

Guardian can:

- Save the current file to durable storage.
- Display backup and connection status.
- Sync the current file manually.
- Check a configured update URL.
- Validate downloaded PHP before applying it.
- Restore the last known-good copy.
- Install a hosted watchdog where the server permits it.
- Use the configured PHP router recovery path on PHP's built-in server.

Guardian is intentionally limited to restoring the exact installed manager file. It is not a general remote code execution system and should not be treated as one.

The default update source is the project's raw GitHub file:

```text
https://raw.githubusercontent.com/orgezeo/marshal-file-manager/refs/heads/main/index.php
```

Change the update URL only from the authenticated Guardian interface, and review the source before applying updates.

## Configuration and Runtime Files

The application keeps small runtime files beside `index.php`:

| File | Purpose |
| --- | --- |
| `.users.json` | Local users, password hashes, roots, and access flags. |
| `.theme.json` | Persisted light/dark theme preference. |
| `.login_attempts.json` | Failed-login counters and temporary lockout state. |
| `.shares.json` | Generated share-link metadata and expiration values. |
| `.fm_activity.json` | Activity log data when enabled by the runtime. |
| `.fm_favorites.json` | Favorite paths. |
| `.fm_trash/` | Recoverable deleted items and metadata. |
| `.cms_pw_vault.json` | Encrypted CMS password vault data. |
| `.cms_vault_key` | Key material used by the CMS password vault; protect it carefully. |
| `.assistant-agent.json.enc` and `.assistant-agent-config.enc` | Encrypted Assistant Agent conversations and per-user conversation settings. |
| `.assistant-agent-debug-*.log` | Bounded diagnostic timings, state transitions, sizes, hashes, and error labels; conversation text, commands, command output, and credentials are excluded. |
| `.mail_sandbox/` | Local mailbox sandbox data when that mode is available. |
| `.guardian_watchdog_attempt` | Guardian/watchdog state marker. |
| `.guardian-restore.php` | Generated hosted recovery endpoint when Guardian installs one. |
| `.fg_*/` | Generated Guardian metadata and protected recovery files. |
| `attached_assets/fonts/tmt.ttf` | Cached terminal font downloaded from the configured source. |

Some files are created only after a feature is used. Back up runtime data before moving the installation, but do not commit passwords, vault keys, session files, or live server metadata.

### Guardian database storage

Guardian prefers the existing database environment when available, including `DATABASE_URL` or `DB_URL`, and supports PostgreSQL and MySQL-compatible storage paths. It can also use explicit `FM_GUARD_DB_*` settings when configured by the administrator.

The Guardian table is small and stores the protected file's content, hash, path, update source, mode, and timestamps. It does not replace the application's primary database.

## Supported Actions

The authenticated request layer includes explicit actions for:

```text
upload                 create_folder            create_file
delete                 rename                   save_edit
bypass_perms           go_to_path               add_favorite
remove_favorite        bulk_delete              bulk_copy
bulk_move              zip_create               zip_extract
restore_trash          trash_perm               trash_empty
duplicate              tar_create               tar_extract
clear_log              batch_rename             create_symlink
chmod_item             create_share             revoke_share
backup_dir             clear_errlog              delete_abs
bulk_chmod             set_tag                  remove_tag
remote_download        ssh_install              ssh_create_user
ssh_delete_user        ssh_update_user          CMS actions
webmail_send           webmail_delete            webmail_mark
```

Additional read-only JSON endpoints provide status, previews, search, server metrics, CMS data, database browsing, WebMail data, SSH status, and Guardian diagnostics.

## Security Model

Marshal File Manager is an administrative application. Its security depends on both the code and the server configuration.

Implemented protections include:

- Session-based authentication.
- Password verification using PHP password hashes.
- Login CSRF token.
- Per-session CSRF token for authenticated POST actions.
- Failed-login tracking and temporary lockout.
- Idle session expiration.
- Read-only account enforcement for mutating actions.
- Root-directory restrictions for scoped users.
- Path normalization and traversal checks.
- Protection for the manager's own file and Guardian files.
- Output escaping in the HTML interface.
- Temporary-file validation and PHP linting before Guardian updates.
- Expiring share-link validation.
- No-cache headers for authenticated and dynamic responses.
- Threat-alert file and CMS-user actions require explicit administrator decisions, but a decision marked **Threat** is intentionally destructive and can affect matching files or future matching accounts.

### Required production protections

Add the following at the web-server or hosting-panel level:

1. Use HTTPS.
2. Restrict access by VPN, firewall, IP allowlist, HTTP authentication, or an equivalent trusted boundary.
3. Keep PHP and all server packages patched.
4. Use a dedicated administrator account and a separate read-only account for inspection.
5. Use strong, unique passwords.
6. Keep `.users.json`, vault files, Guardian files, logs, and runtime metadata outside public downloads where your server configuration allows it.
7. Disable directory listing.
8. Restrict PHP execution and file permissions to the least privilege required.
9. Review activity logs after privileged operations.
10. Back up both the application and its runtime data.

Do not place this tool in a publicly indexed directory without additional access controls.

## Permissions and Hosting Notes

Some features require more privilege than ordinary shared hosting provides:

- Terminal commands require the PHP process to be allowed to execute the requested command.
- SSH installation and user administration require operating-system privileges.
- cPanel administration requires valid API access and provider permission.
- Database operations require reachable drivers and valid credentials.
- WebMail requires IMAP/SMTP support and mailbox credentials or provider discovery.
- The CMS Installer requires a writable empty target directory, MySQL access through `mysqli`, outbound HTTPS, `tar`/`exec`, and the CMS-specific PHP extensions noted above.
- The optional `setup_mfm.php` installer is publicly reachable while present. Restrict it before use and remove it manually if self-deletion fails.
- Assistant Agent use requires outbound HTTPS to its AI service. Its replies can be delayed or unavailable if that service or the network is unavailable.
- Guardian watchdog installation requires a writable and correctly configured server location.
- File ownership and mode changes may fail when PHP does not own the target file.

A failed optional capability should be treated as a hosting limitation, not as permission to broaden server privileges without review.

## Troubleshooting

### The page is blank

Check the PHP error log, confirm that PHP is executing the file, and verify that the required extensions are installed. The application intentionally suppresses browser error output, so diagnostics are normally found in the server log.

### Login always fails

- On a fresh install, sign in with the initial `admin` / `admin` credentials and complete the required username/password change.
- Confirm `.users.json` is valid JSON.
- Confirm the username matches exactly.
- Confirm the stored value is a PHP `password_hash()` result.
- Check that PHP can read the users file.
- Wait for a temporary lockout to expire after repeated failed attempts.

### Uploads fail

Check `upload_max_filesize`, `post_max_size`, available disk space, directory ownership, and the target directory's write permission.

### A database is not detected

Confirm the relevant configuration file is readable and that `mysqli`, `pdo`, or `pdo_pgsql` is installed as needed. You can also connect using the database manager's available manual connection path.

### CMS installation is unavailable or incomplete

Check that the target is a new empty directory inside the authorized workspace, the database account can connect and use the selected database, at least 100 MB of free space is available, and PHP can reach the official download site over HTTPS. The installer needs `mysqli`, `tar` and enabled `exec`; Joomla also needs `mbstring`. If files were installed but automatic CMS setup did not finish, use the WordPress `/wp-admin/install.php` or Joomla `/installation/` page shown by the result.

### WordPress core update is unavailable

The updater requires outbound cURL access to WordPress.org and PHP ZIP support. Check the PHP error log and filesystem permissions if the safety backup or core files cannot be written. Do not remove the temporary recovery copy until the site has been checked after an update.

### Threat Alerts reports a suspicious file

The scanner uses file-type and content heuristics, so a finding is not proof of malware and no finding is not proof that a site is clean. Review the file before choosing **Threat**: that action permanently deletes it and can remove later copies with the same content signature. Use **Safe** for a trusted false positive.

### WebMail shows no mailboxes

Mailbox discovery depends on the hosting provider. Check that the PHP process can read the provider's mailbox metadata and that IMAP is enabled. Discovery diagnostics should be reviewed before changing filesystem permissions.

### Guardian reports “Not reachable”

Confirm the database driver, host, port, database, username, and password. On hosted servers, also check whether the database user can create or alter the Guardian storage table. Guardian can still work through an existing reachable database even when optional auto-healing privileges are unavailable.

### The terminal font is missing

The application first uses `attached_assets/fonts/tmt.ttf` and can fetch the configured GitHub source when the cached font is absent. Verify outbound HTTPS access and directory write permissions.

### A share link no longer works

Share links may expire or be revoked. An invalid or expired link intentionally returns an HTTP `410` response.

## Updating

### Manual update

1. Back up `index.php` and runtime files.
2. Download the new version from a trusted source.
3. Run a syntax check:

   ```bash
   php -l index.php
   ```

4. Preserve your local `.users.json` and runtime data.
5. Replace the application file.
6. Open the application and verify login, file listing, uploads, and any integrations you use.

### Guardian update

An authenticated administrator can use **Guardian → Check updates**. The fetched file is checked for a PHP opening tag, written to a temporary file, syntax-checked, hashed, and only then applied.

Do not point the update URL at an untrusted or user-controlled source.

## Project Structure

Marshal File Manager is intentionally distributed as a single PHP application file:

```text
.
├── index.php   # Complete application: authentication, backend, UI, and JavaScript
├── setup_mfm.php # Optional web installer; remove after use if it remains
└── Readme.md   # Project documentation
```

`index.php` is the complete core application. `setup_mfm.php` is an optional, separate bootstrap installer and is not required after `index.php` has been installed. The application does not require a framework, package manager, frontend build process, or separate backend directory.

Some optional runtime files may appear beside `index.php` after the application is used. They store local settings, activity data, Trash items, share links, or Guardian recovery data. They are generated by the running application and are not additional source-code components of the file manager. Replit workflow files and documentation screenshots are also environment/documentation assets, not application dependencies.

The source intentionally keeps the main UI and server actions together in `index.php`, making manual deployment as simple as uploading that one file to a PHP-enabled server. The optional web installer is a convenience for obtaining and placing that application file; it does not change the core deployment model.

## Limitations and Responsible Use

- This is an administrative tool, not a public file-sharing service.
- It is not a replacement for a hardened hosting control panel, firewall, backup system, or SIEM.
- Feature availability depends on PHP extensions, operating-system privileges, hosting-panel APIs, and provider layout.
- A successful connection does not guarantee that every operation is permitted.
- Destructive operations can permanently affect files, databases, mailboxes, CMS users, and server accounts.
- The administrator is responsible for authorization, backups, privacy, compliance, and incident response.

## Contributing

Before submitting a change:

1. Keep the single-file deployment path working.
2. Avoid exposing credentials or secrets in the UI, logs, commits, or documentation.
3. Preserve CSRF checks and read-only enforcement.
4. Run:

   ```bash
   php -l index.php
   ```

5. Test the affected feature on a non-production server.
6. Document new permissions, extensions, environment variables, or provider-specific behavior.

## License

This project is provided under the **Marshal File Manager Personal Use License** in the [`LICENSE`](LICENSE) file.

The license permits personal use on a server or website owned or controlled by the user, subject to the license terms. It does not permit modifying, rebranding, selling, redistributing, republishing, or presenting the project as someone else's work. Any unauthorized access, hacking, abuse, or unlawful use is strictly prohibited.

## Community

Stay up to date through the project community channel:

<p>
  <a href="https://t.me/s4base">
    <img src="https://img.shields.io/badge/Telegram-Join%20the%20channel-26A5E4?logo=telegram&logoColor=white" alt="Join the Telegram channel">
  </a>
</p>

---

<p align="center">
  Built for practical, careful server administration.
</p>
