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
- [First Login](#first-login)
- [Usage](#usage)
  - [File Manager](#file-manager)
  - [Terminal](#terminal)
  - [Server Information](#server-information)
  - [Database Manager](#database-manager)
  - [cPanel Management](#cpanel-management)
  - [WebMail](#webmail)
  - [CMS Management](#cms-management)
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

### Hosting, CMS, and mail tools

- Automatic or manual cPanel connection.
- cPanel account listing, package listing, account creation, password changes, suspension, and termination.
- WordPress and Joomla configuration discovery.
- CMS user listing and management.
- CMS roles, passwords, visibility, plugins, themes, extensions, and maintenance mode.
- One-click CMS administrator login bridges where supported.
- WordPress cron inspection, execution, deletion, and email scheduling.
- Optional visible WordPress file-recovery helper.
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

The application checks capability availability at runtime. A missing optional extension disables only the dependent feature; it does not make the file manager unusable.

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

## First Login

The login form uses the users stored in `.users.json`. A user record contains a username, a password hash, and optional access flags such as:

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

Replace the example hash, protect the file, and log in through the browser. Do not commit real credentials or production runtime files to GitHub.

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

### CMS Management

The CMS tools are intended for sites you own or administer. Configuration discovery supports common WordPress and Joomla layouts.

#### WordPress

- Inspect WordPress users.
- Create and delete users.
- Change roles and passwords.
- Toggle hidden/visible user state.
- List, activate, deactivate, and delete plugins.
- Switch and delete themes.
- Inspect and manage maintenance mode.
- Inspect and run scheduled WP-Cron events.
- Delete selected cron events.
- Schedule an email through WP-Cron.
- Install or remove an optional visible file-recovery helper.

#### Joomla

- Inspect and manage users supported by the detected configuration.
- Change passwords and roles where the connected site permits it.
- Manage supported extensions.
- Inspect and toggle maintenance mode.
- Use the administrator-login bridge where the site layout and permissions allow it.

CMS list views are read-only. Changes are performed only through their explicit action buttons.

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
- Guardian watchdog installation requires a writable and correctly configured server location.
- File ownership and mode changes may fail when PHP does not own the target file.

A failed optional capability should be treated as a hosting limitation, not as permission to broaden server privileges without review.

## Troubleshooting

### The page is blank

Check the PHP error log, confirm that PHP is executing the file, and verify that the required extensions are installed. The application intentionally suppresses browser error output, so diagnostics are normally found in the server log.

### Login always fails

- Confirm `.users.json` is valid JSON.
- Confirm the username matches exactly.
- Confirm the stored value is a PHP `password_hash()` result.
- Check that PHP can read the users file.
- Wait for a temporary lockout to expire after repeated failed attempts.

### Uploads fail

Check `upload_max_filesize`, `post_max_size`, available disk space, directory ownership, and the target directory's write permission.

### A database is not detected

Confirm the relevant configuration file is readable and that `mysqli`, `pdo`, or `pdo_pgsql` is installed as needed. You can also connect using the database manager's available manual connection path.

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
└── Readme.md   # Project documentation
```

That is the complete core project. The application does not require a framework, package manager, frontend build process, or separate backend directory.

Some optional runtime files may appear beside `index.php` after the application is used. They store local settings, activity data, Trash items, share links, or Guardian recovery data. They are generated by the running application and are not additional source-code components of the file manager. Replit workflow files and documentation screenshots are also environment/documentation assets, not application dependencies.

The source intentionally keeps the main UI and server actions together in `index.php`, making deployment as simple as uploading that one file to a PHP-enabled server.

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

No license file is currently included in this project. Add a `LICENSE` file before publishing the repository if you want others to legally reuse, modify, or redistribute the code.

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
