<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

const MFM_SETUP_DEFAULT_URL = 'https://raw.githubusercontent.com/orgezeo/marshal-file-manager/refs/heads/main/index.php';
const MFM_SETUP_MAX_BYTES = 12 * 1024 * 1024;
const MFM_SETUP_REQUIRED_MARKERS = ['class FileManager', 'FM_UPDATE_URL', 'function fm_load_users'];

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (empty($_SESSION['mfm_setup_csrf'])) {
    $_SESSION['mfm_setup_csrf'] = bin2hex(random_bytes(32));
}

function mfm_setup_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mfm_setup_progress_path(string $id): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.mfm-setup-progress-' . $id . '.json';
}

function mfm_setup_write_progress(string $id, array $data): void
{
    $data['updated_at'] = time();
    $path = mfm_setup_progress_path($id);
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($path, 0600);
}

function mfm_setup_http_url_is_allowed(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string)($parts['host'] ?? '')) !== 'raw.githubusercontent.com'
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)
    ) {
        return false;
    }
    $path = (string)($parts['path'] ?? '');
    return (bool)preg_match('~^/[\w.-]+/[\w.-]+/refs/heads/[\w./-]+/index\.php$~', $path);
}

function mfm_setup_valid_source(string $source): bool
{
    if (strlen($source) < 100 || strlen($source) > MFM_SETUP_MAX_BYTES) {
        return false;
    }
    if (strncmp(ltrim($source), '<?php', 5) !== 0) {
        return false;
    }
    foreach (MFM_SETUP_REQUIRED_MARKERS as $marker) {
        if (strpos($source, $marker) === false) {
            return false;
        }
    }
    return true;
}

function mfm_setup_save_download(string $url, string $tmpPath, string $jobId, array &$progress, ?string &$downloadError): bool
{
    $out = @fopen($tmpPath, 'xb');
    if (!$out) {
        $downloadError = 'Could not create a temporary file in this directory.';
        return false;
    }
    $bytes = 0;
    $total = 0;
    $lastUpdate = 0.0;
    $writeChunk = static function (string $chunk) use ($out, $jobId, &$progress, &$bytes, &$total, &$lastUpdate): int {
        $length = strlen($chunk);
        if ($length === 0 || $bytes + $length > MFM_SETUP_MAX_BYTES) {
            return 0;
        }
        $written = @fwrite($out, $chunk);
        if ($written === false || $written !== $length) {
            return 0;
        }
        $bytes += $written;
        $now = microtime(true);
        if ($now - $lastUpdate >= 0.35 || ($bytes % 262144) < $written) {
            $lastUpdate = $now;
            $progress['phase'] = 'download';
            $progress['message'] = $total > 0
                ? 'Downloading File Manager source…'
                : 'Downloading File Manager source… ' . number_format($bytes / 1024, 0) . ' KB received';
            $progress['downloaded'] = $bytes;
            $progress['total'] = $total;
            $progress['percent'] = $total > 0 ? min(72, 12 + (int)floor(($bytes / $total) * 60)) : null;
            mfm_setup_write_progress($jobId, $progress);
        }
        return $written;
    };

    $downloadOk = false;
    $failure = '';
    if (function_exists('curl_init')) {
        $curl = @curl_init($url);
        if ($curl) {
            @curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Marshal-FM-Setup/1.0',
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$total): int {
                    if (stripos($header, 'Content-Length:') === 0) {
                        $parsed = (int)trim(substr($header, strlen('Content-Length:')));
                        if ($parsed > 0 && $parsed <= MFM_SETUP_MAX_BYTES) {
                            $total = $parsed;
                        }
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($writeChunk): int {
                    return $writeChunk($chunk);
                },
            ]);
            $result = @curl_exec($curl);
            $status = (int)@curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $failure = (string)@curl_error($curl);
            $downloadOk = $result !== false && $status >= 200 && $status < 300;
            if (!$downloadOk && $status > 0) {
                $failure = 'GitHub returned HTTP ' . $status . '.';
            }
            @curl_close($curl);
        } else {
            $failure = 'The cURL download client could not be initialized.';
        }
    } elseif (filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 180,
                'follow_location' => 1,
                'max_redirects' => 4,
                'ignore_errors' => true,
                'header' => "User-Agent: Marshal-FM-Setup/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $input = @fopen($url, 'rb', false, $context);
        if ($input) {
            $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
            $status = 0;
            foreach ($headers as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                    $status = (int)$matches[1];
                } elseif (stripos($header, 'Content-Length:') === 0) {
                    $parsed = (int)trim(substr($header, strlen('Content-Length:')));
                    if ($parsed > 0 && $parsed <= MFM_SETUP_MAX_BYTES) {
                        $total = $parsed;
                    }
                }
            }
            if ($status >= 200 && $status < 300) {
                while (!feof($input)) {
                    $chunk = @fread($input, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($input)) || $writeChunk($chunk) === 0) {
                        $failure = 'The download was interrupted or exceeded the size limit.';
                        break;
                    }
                }
                $downloadOk = feof($input) && $failure === '';
            } else {
                $failure = $status > 0 ? 'GitHub returned HTTP ' . $status . '.' : 'Could not verify the HTTPS download response.';
            }
            @fclose($input);
        } else {
            $failure = 'The server could not open the GitHub HTTPS connection.';
        }
    } else {
        $failure = 'Neither cURL nor HTTPS stream downloads are available in this PHP environment.';
    }

    @fflush($out);
    @fclose($out);
    if (!$downloadOk) {
        $downloadError = $failure !== '' ? $failure : 'The download did not complete.';
        return false;
    }
    $progress['downloaded'] = $bytes;
    $progress['total'] = $total ?: $bytes;
    $progress['percent'] = 74;
    mfm_setup_write_progress($jobId, $progress);
    return true;
}

function mfm_setup_cleanup_old_progress(): void
{
    $items = @glob(__DIR__ . DIRECTORY_SEPARATOR . '.mfm-setup-progress-*.json');
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $path) {
        if (is_file($path) && (time() - (int)@filemtime($path)) > 86400) {
            @unlink($path);
        }
    }
}

if (isset($_GET['status'])) {
    $id = (string)$_GET['status'];
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        mfm_setup_json(['error' => 'Invalid installation status token.'], 400);
    }
    $path = mfm_setup_progress_path($id);
    $status = is_file($path) ? @json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($status)) {
        mfm_setup_json(['error' => 'Installation status is not available.'], 404);
    }
    mfm_setup_json($status);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'install') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals((string)$_SESSION['mfm_setup_csrf'], $csrf)) {
        mfm_setup_json(['error' => 'Security check failed. Reload this page and try again.'], 403);
    }

    $filename = trim((string)($_POST['filename'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $jobId = strtolower((string)($_POST['job_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        mfm_setup_json(['error' => 'The installation request is invalid. Reload this page and try again.'], 400);
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,95}\.php$/i', $filename)
        || strtolower($filename) === 'setup_mfm.php'
        || str_contains($filename, '..')
    ) {
        mfm_setup_json(['error' => 'Enter a PHP filename using letters, numbers, dots, dashes, or underscores. Do not use setup_mfm.php.'], 400);
    }
    if (!mfm_setup_http_url_is_allowed($url)) {
        mfm_setup_json(['error' => 'Use a secure raw.githubusercontent.com branch URL ending in /index.php.'], 400);
    }

    $destination = __DIR__ . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($destination) || is_link($destination)) {
        mfm_setup_json(['error' => 'A file with that name already exists. Choose another filename to avoid overwriting it.'], 409);
    }
    if (!is_writable(__DIR__)) {
        mfm_setup_json(['error' => 'This directory is not writable by the current PHP user.'], 500);
    }

    $progress = [
        'ok' => true,
        'phase' => 'checking',
        'message' => 'Checking the current PHP environment…',
        'percent' => 5,
        'downloaded' => 0,
        'total' => 0,
        'missing_extensions' => [],
    ];
    mfm_setup_write_progress($jobId, $progress);
    $requiredExtensions = ['curl', 'openssl', 'mbstring', 'mysqli', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'zip', 'gd', 'imap', 'fileinfo', 'iconv', 'exif', 'ftp'];
    foreach ($requiredExtensions as $extension) {
        if (!extension_loaded($extension)) {
            $progress['missing_extensions'][] = $extension;
        }
    }
    $progress['percent'] = 9;
    if ($progress['missing_extensions']) {
        $progress['message'] = 'Checking optional PHP extensions. Missing modules will not stop installation.';
        $progress['package_note'] = 'This web installer has no safe, host-independent way to add PHP extensions to the active server without administrator or hosting-panel access. The File Manager download will continue; optional features may remain unavailable.';
    } else {
        $progress['message'] = 'PHP extension check complete. No required modules are missing.';
        $progress['package_note'] = 'No additional Composer or third-party packages are required to install the standalone File Manager.';
    }
    mfm_setup_write_progress($jobId, $progress);

    /* Release PHP's session lock so the browser can poll live progress while
       the HTTPS download is running. */
    @session_write_close();

    $tmpPath = __DIR__ . DIRECTORY_SEPARATOR . '.' . $filename . '.download-' . $jobId;
    $downloadError = null;
    $progress['phase'] = 'download';
    $progress['message'] = 'Connecting to GitHub…';
    $progress['percent'] = 12;
    mfm_setup_write_progress($jobId, $progress);
    @set_time_limit(240);
    if (!mfm_setup_save_download($url, $tmpPath, $jobId, $progress, $downloadError)) {
        @unlink($tmpPath);
        $progress['ok'] = false;
        $progress['phase'] = 'error';
        $progress['percent'] = 0;
        $progress['message'] = (string)$downloadError;
        mfm_setup_write_progress($jobId, $progress);
        mfm_setup_json(['error' => (string)$downloadError], 502);
    }

    $progress['phase'] = 'verify';
    $progress['message'] = 'Verifying the downloaded File Manager source…';
    $progress['percent'] = 77;
    mfm_setup_write_progress($jobId, $progress);
    $source = @file_get_contents($tmpPath);
    if (!is_string($source) || !mfm_setup_valid_source($source)) {
        @unlink($tmpPath);
        $message = 'The downloaded file did not match the expected Marshal File Manager PHP source. Nothing was installed.';
        $progress['ok'] = false;
        $progress['phase'] = 'error';
        $progress['percent'] = 0;
        $progress['message'] = $message;
        mfm_setup_write_progress($jobId, $progress);
        mfm_setup_json(['error' => $message], 422);
    }

    $progress['message'] = 'Checking PHP syntax…';
    $progress['percent'] = 82;
    mfm_setup_write_progress($jobId, $progress);
    $lintOk = false;
    $lintMessage = 'PHP syntax check was unavailable; the source markers and file format were verified.';
    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        $lintOutput = [];
        $lintCode = 1;
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmpPath) . ' 2>&1';
        @exec($command, $lintOutput, $lintCode);
        $lintOk = $lintCode === 0;
        $lintMessage = $lintOk ? 'PHP syntax check passed.' : 'The downloaded PHP file failed its syntax check: ' . implode(' ', array_slice($lintOutput, 0, 3));
    }
    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true) && !$lintOk) {
        @unlink($tmpPath);
        $progress['ok'] = false;
        $progress['phase'] = 'error';
        $progress['percent'] = 0;
        $progress['message'] = $lintMessage;
        mfm_setup_write_progress($jobId, $progress);
        mfm_setup_json(['error' => $lintMessage], 422);
    }

    $progress['phase'] = 'install';
    $progress['message'] = 'Writing the File Manager into this directory…';
    $progress['percent'] = 88;
    mfm_setup_write_progress($jobId, $progress);
    @chmod($tmpPath, 0644);
    $installed = @rename($tmpPath, $destination);
    if (!$installed && !file_exists($destination)) {
        /* Some shared hosts reject rename operations; try a direct create
           without ever replacing an existing file. */
        $target = @fopen($destination, 'xb');
        if ($target) {
            $input = @fopen($tmpPath, 'rb');
            $copied = $input ? @stream_copy_to_stream($input, $target) : false;
            if ($input) {
                @fclose($input);
            }
            @fclose($target);
            $installed = $copied !== false && $copied === strlen($source);
            if (!$installed) {
                @unlink($destination);
            }
        }
        @unlink($tmpPath);
    }
    if (!$installed) {
        @unlink($tmpPath);
        $message = 'The PHP user could not create the destination file. Check directory permissions and try again.';
        $progress['ok'] = false;
        $progress['phase'] = 'error';
        $progress['percent'] = 0;
        $progress['message'] = $message;
        mfm_setup_write_progress($jobId, $progress);
        mfm_setup_json(['error' => $message], 500);
    }
    @chmod($destination, 0644);

    $progress['phase'] = 'cleanup';
    $progress['message'] = 'Removing the temporary installer…';
    $progress['percent'] = 96;
    mfm_setup_write_progress($jobId, $progress);
    $installerRemoved = @unlink(__FILE__) && !is_file(__FILE__);

    $relativeTarget = rawurlencode($filename);
    $directoryUrl = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($directoryUrl === '/' || $directoryUrl === '.' || $directoryUrl === '') {
        $directoryUrl = '';
    } else {
        $directoryUrl = rtrim($directoryUrl, '/');
    }
    $redirect = ($directoryUrl === '' ? '/' : $directoryUrl . '/') . $relativeTarget;
    $progress['phase'] = 'complete';
    $progress['message'] = $installerRemoved
        ? 'Installation complete. Opening the File Manager…'
        : 'Installation complete. The installer could not delete itself; remove setup_mfm.php manually.';
    $progress['percent'] = 100;
    $progress['installer_removed'] = $installerRemoved;
    $progress['redirect'] = $redirect;
    $progress['lint_message'] = $lintMessage;
    mfm_setup_write_progress($jobId, $progress);
    mfm_setup_json([
        'ok' => true,
        'redirect' => $redirect,
        'installer_removed' => $installerRemoved,
        'message' => $progress['message'],
    ]);
}

mfm_setup_cleanup_old_progress();
$csrfToken = htmlspecialchars((string)$_SESSION['mfm_setup_csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Install Marshal File Manager</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;background:#101010;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background-image:radial-gradient(ellipse 80% 60% at 30% 0%,rgba(125,129,132,.045),transparent),radial-gradient(ellipse 60% 50% at 80% 100%,rgba(80,81,77,.028),transparent);color:#C9C6C2}
    .card{width:100%;max-width:520px;background:#161616;border:1px solid rgba(125,129,132,.2);border-radius:20px;padding:16px 30px 26px;backdrop-filter:blur(20px);box-shadow:0 24px 60px rgba(0,0,0,.82),inset 0 1px 0 rgba(243,238,235,.03);animation:up .5s cubic-bezier(.34,1.56,.64,1) both}
    @keyframes up{from{opacity:0;transform:translateY(32px) scale(.96)}to{opacity:1;transform:none}}
    .logo{width:92px;height:92px;margin:0 auto 8px;display:flex;align-items:center;justify-content:center}
    .logo img{width:84px;height:84px;object-fit:contain}
    h1{text-align:center;font-size:21px;font-weight:700;color:#C9C6C2;margin-bottom:4px;letter-spacing:-.4px}
    .sub{text-align:center;font-size:13px;font-weight:300;color:rgba(216,212,208,.68);margin-bottom:22px;line-height:1.55}
    .field{margin-bottom:13px}
    label{display:block;font-size:11px;font-weight:700;color:#707477;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
    input{display:block;width:100%;padding:11px 12px;background:#101010;border:1px solid rgba(125,129,132,.24);border-radius:10px;color:#C9C6C2;font-size:13px;outline:none;font-family:inherit;transition:border-color .2s,box-shadow .2s}
    input:focus{border-color:#707477;box-shadow:0 0 0 4px rgba(133,137,140,.1)}
    input::placeholder{color:#50514D}
    input[readonly]{color:#9c9995;background:#131313}
    .hint{margin-top:6px;color:#707477;font-size:10.5px;line-height:1.5}
    .btn{width:100%;margin-top:8px;padding:12px;background:linear-gradient(135deg,#C9C6C2,#A9A5A1);border:none;border-radius:10px;color:#101010;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;box-shadow:0 4px 24px rgba(201,198,194,.1);transition:transform .18s cubic-bezier(.34,1.56,.64,1),box-shadow .18s}
    .btn:hover{transform:translateY(-2px);background:#F3EEEB;box-shadow:0 10px 30px rgba(216,212,208,.2)}
    .btn:active{transform:scale(.97)}
    .btn:disabled{cursor:wait;opacity:.6;transform:none}
    .notice{margin:13px 0;padding:10px 12px;border:1px solid rgba(245,158,11,.24);border-radius:9px;background:rgba(245,158,11,.07);color:#d9bd85;font-size:10.5px;line-height:1.55}
    .feedback{display:none;margin:12px 0;padding:11px 13px;border:1px solid rgba(239,68,68,.22);border-radius:9px;background:rgba(239,68,68,.07);color:#fca5a5;font-size:12px;line-height:1.55;overflow-wrap:anywhere}
    .feedback.is-visible{display:block}
    .progress{display:none;margin-top:17px;padding:13px;border:1px solid rgba(125,129,132,.18);border-radius:11px;background:#121212}
    .progress.is-visible{display:block}
    .progress-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;color:#aaa7a3;font-size:11px}
    .progress-text{line-height:1.5}
    .progress-pct{flex:0 0 auto;color:#C9C6C2;font-weight:600}
    .track{height:6px;overflow:hidden;border-radius:6px;background:#252525}
    .bar{height:100%;width:3%;border-radius:6px;background:linear-gradient(90deg,#8b8985,#C9C6C2);transition:width .3s ease}
    .bar.is-indeterminate{width:32%;animation:sweep 1.15s ease-in-out infinite alternate}
    @keyframes sweep{from{transform:translateX(-10%)}to{transform:translateX(220%)}}
    .substatus{margin-top:8px;color:#707477;font-size:10px;line-height:1.5}
    @media(max-width:520px){.card{padding:14px 20px 22px}.logo{height:78px}.logo img{width:74px;height:74px}}
  </style>
</head>
<body>
  <main class="card">
    <a class="logo" href="https://t.me/s4base" target="_blank" rel="noopener noreferrer" aria-label="Open MFM Telegram channel">
      <img src="https://raw.githubusercontent.com/orgezeo/marshal-file-manager/refs/heads/main/images/icons/mfm.png" alt="Marshal File Manager">
    </a>
    <h1>Install Marshal File Manager</h1>
    <p class="sub">Download and install the latest File Manager from the configured GitHub source.</p>

    <form id="setupForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=$csrfToken?>">
      <input type="hidden" name="job_id" id="jobId">
      <div class="field">
        <label for="filename">File name</label>
        <input id="filename" name="filename" type="text" value="mfm_adminer.php" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,95}\.php" required>
        <div class="hint">The File Manager will be installed in this same directory. Existing files are never overwritten.</div>
      </div>
      <div class="field">
        <label for="setupUsername">Default username</label>
        <input id="setupUsername" type="text" value="admin" readonly aria-readonly="true">
      </div>
      <div class="field">
        <label for="setupPassword">Default password</label>
        <input id="setupPassword" type="text" value="admin" readonly aria-readonly="true">
        <div class="hint">These defaults are fixed by the File Manager. It will require you to change them after the first sign-in.</div>
      </div>
      <div class="field">
        <label for="sourceUrl">GitHub raw URL</label>
        <input id="sourceUrl" name="url" type="url" value="<?=htmlspecialchars(MFM_SETUP_DEFAULT_URL, ENT_QUOTES, 'UTF-8')?>" required>
        <div class="hint">For safety, use an HTTPS raw.githubusercontent.com branch URL ending in /index.php.</div>
      </div>
      <div class="notice">This page is publicly reachable while it exists. Run it only in the intended directory; it deletes itself after a successful install. If self-deletion fails, remove setup_mfm.php manually. The installer checks PHP extensions and continues if optional ones are missing; PHP extensions are managed by the hosting provider, so it will not run privileged system package commands.</div>
      <div class="feedback" id="feedback" role="alert"></div>
      <button class="btn" id="installButton" type="submit">Start installation</button>
      <section class="progress" id="progress" aria-live="polite">
        <div class="progress-head"><span class="progress-text" id="progressText">Preparing installation…</span><span class="progress-pct" id="progressPct">0%</span></div>
        <div class="track"><div class="bar" id="progressBar"></div></div>
        <div class="substatus" id="progressDetails"></div>
      </section>
    </form>
  </main>
  <script>
  (() => {
    const form = document.getElementById('setupForm');
    const button = document.getElementById('installButton');
    const feedback = document.getElementById('feedback');
    const progress = document.getElementById('progress');
    const progressText = document.getElementById('progressText');
    const progressPct = document.getElementById('progressPct');
    const progressBar = document.getElementById('progressBar');
    const progressDetails = document.getElementById('progressDetails');
    let pollTimer = null;
    let finishing = false;

    const showError = message => {
      feedback.textContent = message || 'The installation could not be completed.';
      feedback.classList.add('is-visible');
      button.disabled = false;
      button.textContent = 'Start installation';
      if (pollTimer) clearInterval(pollTimer);
    };
    const renderStatus = data => {
      progress.classList.add('is-visible');
      progressText.textContent = data.message || 'Working…';
      const percent = Number.isFinite(Number(data.percent)) ? Math.max(0, Math.min(100, Number(data.percent))) : null;
      if (percent === null) {
        progressPct.textContent = data.downloaded ? Math.round(Number(data.downloaded) / 1024) + ' KB' : '';
        progressBar.classList.add('is-indeterminate');
      } else {
        progressPct.textContent = Math.floor(percent) + '%';
        progressBar.classList.remove('is-indeterminate');
        progressBar.style.width = percent + '%';
      }
      const details = [];
      if (data.downloaded) {
        const received = (Number(data.downloaded) / 1024 / 1024).toFixed(2) + ' MB';
        const total = Number(data.total) > 0 ? ' of ' + (Number(data.total) / 1024 / 1024).toFixed(2) + ' MB' : '';
        details.push('Downloaded ' + received + total);
      }
      if (Array.isArray(data.missing_extensions) && data.missing_extensions.length) {
        details.push('Missing PHP modules: ' + data.missing_extensions.join(', ') + '. Continuing without host-level changes.');
      } else if (data.package_note) {
        details.push(data.package_note);
      }
      if (data.lint_message) details.push(data.lint_message);
      progressDetails.textContent = details.join(' ');
    };

    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (finishing || !form.reportValidity()) return;
      finishing = true;
      feedback.classList.remove('is-visible');
      button.disabled = true;
      button.textContent = 'Installing…';
      progress.classList.add('is-visible');
      progressText.textContent = 'Preparing installation…';
      progressPct.textContent = '0%';
      progressBar.style.width = '3%';
      const jobId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
      document.getElementById('jobId').value = jobId;

      pollTimer = setInterval(async () => {
        try {
          const response = await fetch('?status=' + encodeURIComponent(jobId), {cache: 'no-store', credentials: 'same-origin'});
          if (!response.ok) return;
          renderStatus(await response.json());
        } catch (_) {}
      }, 180);

      try {
        const response = await fetch('?action=install', {
          method: 'POST',
          body: new FormData(form),
          cache: 'no-store',
          credentials: 'same-origin',
          headers: {'Accept': 'application/json'}
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'The installation could not be completed.');
        if (pollTimer) clearInterval(pollTimer);
        renderStatus({message: result.message || 'Installation complete. Opening the File Manager…', percent: 100});
        if (!result.installer_removed) {
          progressDetails.textContent = 'The File Manager is installed, but setup_mfm.php could not delete itself. Remove it manually after the redirect.';
        }
        progressDetails.textContent += ' Redirecting…';
        setTimeout(() => { window.location.assign(result.redirect); }, 1200);
      } catch (error) {
        finishing = false;
        showError(error && error.message ? error.message : 'The installation request failed. Check the server and try again.');
      }
    });
  })();
  </script>
</body>
</html>
