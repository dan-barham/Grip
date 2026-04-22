<?php
// ============================================================
//  GRIP — REST API  v2  (multi-user, roles, bookings)
//  VERSION: RENTER-CAPABLE-20260414
// ============================================================

// Catch fatal errors and return as JSON instead of HTML
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'PHP fatal: ' . $e['message'], 'file' => basename($e['file']), 'line' => $e['line']]);
    }
});

// Diagnostic route — no config needed
if (($_GET['r'] ?? '') === 'diag') {
    header('Content-Type: application/json');
    echo json_encode([
        'php'     => PHP_VERSION,
        'dir'     => basename(__DIR__),
        'files'   => array_values(array_filter(array_map('basename', glob(__DIR__ . '/*.php') ?: []))),
        'config'  => file_exists(__DIR__ . '/grip-config.php') ? 'found' : 'MISSING',
        'pdf'     => file_exists(__DIR__ . '/grip-pdf.php')    ? 'found' : 'missing',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';
if(file_exists(__DIR__.'/grip-version.php')) require_once __DIR__ . '/grip-version.php';
if(!defined('GRIP_VERSION')) define('GRIP_VERSION','1.0.34');
if(file_exists(__DIR__.'/grip-pdf.php')) require_once __DIR__ . '/grip-pdf.php';

// Don't set JSON content-type for the iCal feed — it overrides ours
$_route_peek = trim($_GET['r'] ?? '', '/');
if(strpos($_route_peek, 'ical') !== 0) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://danbarham.com', 'https://www.danbarham.com'];
if (in_array($origin, $allowed, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

session_name(SESSION_NAME);
session_set_cookie_params(['lifetime'=>SESSION_MAXAGE,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
session_start();

// ── Helpers ──────────────────────────────────────────────────
function json_out(mixed $data, int $code=200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function err(string $msg, int $code=400): never { json_out(['error'=>$msg], $code); }
function body(): array { $r=file_get_contents('php://input'); return $r?(json_decode($r,true)??[]):[]; }
function uid(): string { return '_'.bin2hex(random_bytes(4)); }
function current_user(): ?array { return $_SESSION['user']??null; }
function require_auth(): array { $u=current_user(); if(!$u)err('Not authenticated',401); return $u; }

// ── Settings helper ──────────────────────────────────────────
function get_setting(string $key, string $default=''): string {
    static $cache = null;
    if($cache === null){
        try {
            $rows = db()->query("SELECT `key`,`value` FROM grip_settings")->fetchAll();
            $cache = [];
            foreach($rows as $r) $cache[$r['key']] = $r['value'];
        } catch(\Exception $e){ $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function require_role(string ...$roles): array {
    $u=require_auth();
    if(!in_array($u['role'],$roles,true))err('Insufficient permissions',403);
    return $u;
}

// ── Router ───────────────────────────────────────────────────
$method=$_SERVER['REQUEST_METHOD'];
// Quick version check — GET ?r=version
if(($method==='GET')&&(($_GET['r']??'')==='version')){
    json_out([
        'version'      => defined('GRIP_VERSION') ? GRIP_VERSION : '?',
        'build_date'   => defined('GRIP_VERSION_DATE') ? GRIP_VERSION_DATE : '?',
        'version_full' => defined('GRIP_VERSION_FULL') ? GRIP_VERSION_FULL : GRIP_VERSION,
        'app'          => 'GRIP Gear Tracker',
        'roles'        => ['admin','viewer','renter'],
    ]);
}
$route=trim($_GET['r']??'','/');
$seg=$route===''?[]:explode('/',$route);
$resource=$seg[0]??''; $id=$seg[1]??null;

// ════════════════════════════════════════════════════════════
//  AUTH
// ════════════════════════════════════════════════════════════
if($resource==='auth'){
    $action=$id??'';

    if($method==='POST'&&$action==='status'){
        $n=(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $u=current_user();
        json_out(['setup'=>$n===0,'authed'=>(bool)$u,
            'user'=>$u?['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role']]:null]);
    }

    if($method==='POST'&&$action==='setup'){
        $b=body();
        if((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)err('Already set up');
        $hash=trim($b['hash']??''); $username=trim($b['username']??'admin');
        if(strlen($hash)!==64)err('Invalid hash');
        if(!$username)err('Username required');
        $uid=uid();
        db()->prepare('INSERT INTO users (id,username,pw_hash,role) VALUES (?,?,?,?)')->execute([$uid,$username,$hash,'admin']);
        $_SESSION['user']=['id'=>$uid,'username'=>$username,'role'=>'admin'];
        json_out(['ok'=>true,'role'=>'admin','username'=>$username]);
    }

    if($method==='POST'&&$action==='login'){
        $b=body();
        $hash=trim($b['hash']??''); $username=trim($b['username']??'');
        $remember=(bool)($b['remember']??false);
        if(strlen($hash)!==64)err('Invalid hash');
        if(!$username)err('Username required');
        $st=db()->prepare('SELECT * FROM users WHERE username=?'); $st->execute([$username]); $u=$st->fetch();
        if(!$u||!hash_equals($u['pw_hash'],$hash))err('Incorrect username or password',401);
        $_SESSION['user']=['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role']];
        if($remember){
            // Extend session cookie to 30 days
            $lifetime = 30 * 24 * 60 * 60;
            session_regenerate_id(true);
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        json_out(['ok'=>true,'role'=>$u['role'],'username'=>$u['username']]);
    }

    if($method==='POST'&&$action==='logout'){session_destroy();json_out(['ok'=>true]);}

    if($method==='POST'&&$action==='reset'){
        $b=body(); $hash=$b['hash']??'';
        if(strlen($hash)!==64)err('Invalid hash');
        $row=db()->query('SELECT pw_hash FROM users ORDER BY created_at LIMIT 1')->fetch();
        if(!$row||!hash_equals($row['pw_hash'],$hash))err('Incorrect password',401);
        db()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach(['activity_log','gear','days','jobs','inventory','users'] as $t)db()->exec("TRUNCATE TABLE `$t`");
        db()->exec('SET FOREIGN_KEY_CHECKS=1');
        session_destroy(); json_out(['ok'=>true]);
    }

    err('Unknown auth action',404);
}

$me=require_auth();

// ════════════════════════════════════════════════════════════
//  USERS (admin only for write)
// ════════════════════════════════════════════════════════════
if($resource==='users'){
    if($method==='GET'&&$id==='me'){
        $me=require_auth();
        $st=db()->prepare('SELECT id,username,role,first_name,last_name,email,phone,org,bio,created_at FROM users WHERE id=?');
        $st->execute([$me['id']]);
        json_out($st->fetch()?:[]);
    }
    if($method==='GET'&&!$id){
        require_role('admin');
        json_out(db()->query('SELECT id,username,role,first_name,last_name,email,phone,org,bio,created_at FROM users ORDER BY created_at')->fetchAll());
    }
    if($method==='POST'&&!$id){
        require_role('admin');
        $b=body(); $un=trim($b['username']??''); $hash=$b['hash']??''; $role=$b['role']??'viewer';
        if(!$un)err('Username required');
        if(strlen($hash)!==64)err('Invalid hash');
        if(!in_array($role,['admin','operator','viewer','renter']))err('Invalid role');
        $ex=db()->prepare('SELECT COUNT(*) FROM users WHERE username=?'); $ex->execute([$un]);
        if((int)$ex->fetchColumn()>0)err('Username already taken');
        $uid=uid();
        db()->prepare('INSERT INTO users (id,username,pw_hash,role) VALUES (?,?,?,?)')->execute([$uid,$un,$hash,$role]);
        json_out(['id'=>$uid,'username'=>$un,'role'=>$role]);
    }
    if($method==='PUT'&&$id){
        $me=require_auth();
        $b=body();
        // Profile fields — admin or the user themselves
        $is_self = $me['id']===$id;
        if(!$is_self) require_role('admin');

        // Handle login email change (new_username)
        $new_un = isset($b['new_username']) ? trim($b['new_username']) : null;
        if($new_un){
            if(!filter_var($new_un, FILTER_VALIDATE_EMAIL)) err('New login must be a valid email address');
            $taken=db()->prepare('SELECT COUNT(*) FROM users WHERE username=? AND id!=?');
            $taken->execute([$new_un,$id]);
            if((int)$taken->fetchColumn()>0) err('That email address is already in use');
            db()->prepare('UPDATE users SET username=? WHERE id=?')->execute([$new_un,$id]);
            // Update session if changing own login
            if($is_self) $_SESSION['user']['username']=$new_un;
        }

        // Profile fields
        if(isset($b['first_name'])||isset($b['last_name'])||isset($b['email'])||isset($b['phone'])||isset($b['org'])||isset($b['bio'])){
            db()->prepare('UPDATE users SET first_name=?,last_name=?,email=?,phone=?,org=?,bio=? WHERE id=?')
                ->execute([trim($b['first_name']??''),trim($b['last_name']??''),trim($b['email']??''),
                    trim($b['phone']??''),trim($b['org']??''),trim($b['bio']??''),$id]);
        }

        // Password change
        $hash=$b['hash']??null;
        if($hash&&strlen($hash)===64) db()->prepare('UPDATE users SET pw_hash=? WHERE id=?')->execute([$hash,$id]);

        // Role change — admin only
        $role=$b['role']??null;
        if($role){
            require_role('admin');
            if(!in_array($role,['admin','operator','viewer','renter']))err('Invalid role');
            db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$id]);
        }

        json_out(['ok'=>true]);
    }
    if($method==='DELETE'&&$id){
        require_role('admin');
        if($id===$me['id'])err('Cannot delete your own account');
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  JOBS
// ════════════════════════════════════════════════════════════
if($resource==='jobs'){
    if($method==='GET'&&!$id){
        $jobs=db()->query('SELECT * FROM jobs ORDER BY sort_order,created_at')->fetchAll();
        foreach($jobs as &$j){
            $st=db()->prepare('SELECT * FROM days WHERE job_id=? ORDER BY sort_order,id'); $st->execute([$j['id']]);
            $days=$st->fetchAll();
            foreach($days as &$d){
                $gs=db()->prepare('SELECT * FROM gear WHERE day_id=? ORDER BY sort_order,id'); $gs->execute([$d['id']]);
                $d['gear']=array_map('gear_row',$gs->fetchAll());
                $d['date']=$d['shoot_date']??''; unset($d['shoot_date']);
            }
            $j['days']=$days;
        }
        json_out($jobs);
    }
    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $jid=uid();
        db()->prepare('INSERT INTO jobs (id,name,director,co,notes) VALUES (?,?,?,?,?)')->execute([$jid,$b['name']??'Untitled',$b['director']??'',$b['co']??'',$b['notes']??'']);
        json_out(['id'=>$jid]);
    }
    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        db()->prepare('UPDATE jobs SET name=?,director=?,co=?,notes=?,archived=? WHERE id=?')->execute([$b['name']??'',$b['director']??'',$b['co']??'',$b['notes']??'',(int)($b['archived']??0),$id]);
        json_out(['ok'=>true]);
    }
    if($method==='DELETE'&&$id){
        require_role('admin');
        db()->prepare('DELETE FROM jobs WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  DAYS
// ════════════════════════════════════════════════════════════
if($resource==='days'){
    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $did=uid(); $date=($b['date']??'')?:null;
        db()->prepare('INSERT INTO days (id,job_id,label,shoot_date,location) VALUES (?,?,?,?,?)')->execute([$did,$b['job_id'],$b['label']??'Untitled',$date,$b['location']??'']);
        json_out(['id'=>$did]);
    }
    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body(); $date=($b['date']??'')?:null;
        db()->prepare('UPDATE days SET label=?,shoot_date=?,location=? WHERE id=?')->execute([$b['label']??'',$date,$b['location']??'',$id]);
        json_out(['ok'=>true]);
    }
    if($method==='DELETE'&&$id){
        require_role('admin');
        db()->prepare('DELETE FROM days WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  GEAR
// ════════════════════════════════════════════════════════════
if($resource==='gear'){
    // Auto-add inventory_id column if not present
    static $gear_migrated = false;
    if(!$gear_migrated){
        try { db()->exec("ALTER TABLE gear ADD COLUMN inventory_id VARCHAR(20) DEFAULT '' AFTER condition"); } catch(\Exception $e){}
        $gear_migrated = true;
    }
    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $gid=uid();
        $inv_id = $b['inventory_id'] ?? '';
        try {
            db()->prepare('INSERT INTO gear (id,day_id,name,cat,asset_id,qty,value,notes,status,condition,inventory_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$gid,$b['day_id'],$b['name']??'',$b['cat']??'Other',$b['asset_id']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$b['status']??'in',$b['condition']??'Good',$inv_id]);
        } catch(\PDOException $e) {
            // Fallback without newer columns
            try { db()->prepare('INSERT INTO gear (id,day_id,name,cat,asset_id,qty,value,notes,status,condition) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$gid,$b['day_id'],$b['name']??'',$b['cat']??'Other',$b['asset_id']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$b['status']??'in',$b['condition']??'Good']);
            } catch(\PDOException $e2) {
                db()->prepare('INSERT INTO gear (id,day_id,name,cat,asset_id,qty,value,notes,status) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$gid,$b['day_id'],$b['name']??'',$b['cat']??'Other',$b['asset_id']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$b['status']??'in']);
            }
        }
        json_out(['id'=>$gid]);
    }
    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        db()->prepare('UPDATE gear SET name=?,cat=?,asset_id=?,qty=?,value=?,notes=?,status=?,condition=? WHERE id=?')->execute([$b['name']??'',$b['cat']??'Other',$b['asset_id']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$b['status']??'in',$b['condition']??'Good',$id]);
        json_out(['ok'=>true]);
    }
    if($method==='DELETE'&&$id){
        require_role('admin','operator');
        db()->prepare('DELETE FROM gear WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
    if($method==='POST'&&$id==='bulk-status'){
        require_role('admin','operator');
        $b=body(); $ids=$b['ids']??[]; $status=$b['status']??'in';
        if(!$ids)json_out(['ok'=>true]);
        $ph=implode(',',array_fill(0,count($ids),'?'));
        db()->prepare("UPDATE gear SET status=? WHERE id IN ($ph)")->execute(array_merge([$status],$ids));
        json_out(['ok'=>true]);
    }
    if($method==='POST'&&$id==='bulk-delete'){
        require_role('admin','operator');
        $b=body(); $ids=$b['ids']??[];
        if(!$ids)json_out(['ok'=>true]);
        $ph=implode(',',array_fill(0,count($ids),'?'));
        db()->prepare("DELETE FROM gear WHERE id IN ($ph)")->execute($ids);
        json_out(['ok'=>true]);
    }
    if($method==='POST'&&$id==='copy'){
        require_role('admin','operator');
        try {
        $b=body(); $src=$b['ids']??[]; $dest=$b['dest_day_id']??'';
        if(!$dest)err('dest_day_id required');
        if((int)($b['clear_dest']??0)===1)db()->prepare('DELETE FROM gear WHERE day_id=?')->execute([$dest]);
        $st=db()->prepare('SELECT * FROM gear WHERE id=?');
        foreach($src as $sid){
            $st->execute([$sid]); $g=$st->fetch(); if(!$g)continue;
            try {
                db()->prepare('INSERT INTO gear (id,day_id,name,cat,asset_id,qty,value,notes,status,condition) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([uid(),$dest,$g['name'],$g['cat'],$g['asset_id']??'',$g['qty'],$g['value'],$g['notes']??'','in',$g['condition']??'Good']);
            } catch(\PDOException $e){
                // Fallback for older schemas without condition column
                db()->prepare('INSERT INTO gear (id,day_id,name,cat,asset_id,qty,value,notes,status) VALUES (?,?,?,?,?,?,?,?,?)')->execute([uid(),$dest,$g['name'],$g['cat'],$g['asset_id']??'',$g['qty'],$g['value'],$g['notes']??'','in']);
            }
        }
        json_out(['ok'=>true]);
        } catch(\Exception $ex){ err('Copy failed: '.$ex->getMessage(), 500); }
    }
}

// ════════════════════════════════════════════════════════════
//  BOOKINGS  (date-range model: start_date → end_date)
// ════════════════════════════════════════════════════════════

if($resource==='inventory'){
    // ── Conflicts sub-route: GET /inventory/conflicts?start=&end= ──
    if($method==='GET' && $id==='conflicts'){
        require_auth();
        $start = $_GET['start'] ?? '';
        $end   = $_GET['end']   ?? $start;
        if(!$start) err('start date required');

        $sql = "
            SELECT i.id as inv_id, g.status
            FROM gear g
            JOIN days d   ON d.id = g.day_id
            JOIN inventory i ON (i.asset_id != '' AND i.asset_id = g.asset_id)
            WHERE d.shoot_date BETWEEN ? AND ? AND g.asset_id != ''
            UNION ALL
            SELECT i.id as inv_id, 'in' as status
            FROM gear g
            JOIN days d   ON d.id = g.day_id
            JOIN inventory i ON ((g.asset_id = '' OR g.asset_id IS NULL) AND i.name = g.name)
            WHERE d.shoot_date BETWEEN ? AND ?
        ";
        $rows = db()->prepare($sql);
        $rows->execute([$start, $end, $start, $end]);
        $all = $rows->fetchAll();

        $out = []; $booked = [];
        foreach($all as $r){
            if($r['status']==='out' && !in_array($r['inv_id'],$out))
                $out[] = $r['inv_id'];
            elseif(!in_array($r['inv_id'],$booked) && !in_array($r['inv_id'],$out))
                $booked[] = $r['inv_id'];
        }

        $brStmt = db()->prepare("SELECT items FROM borrow_requests WHERE status='approved' AND start_date<=? AND end_date>=?");
        $brStmt->execute([$end, $start]);
        foreach($brStmt->fetchAll() as $br){
            $items = json_decode($br['items']??'[]',true) ?: [];
            foreach($items as $it){
                $iid = $it['inventory_id'] ?? ($it['id'] ?? '');
                if($iid && !in_array($iid,$booked) && !in_array($iid,$out))
                    $booked[] = $iid;
            }
        }
        json_out(['out'=>$out,'booked'=>$booked]);
    }

    if($method==='GET'&&!$id){
        json_out(array_map('inv_row',db()->query('SELECT * FROM inventory ORDER BY sort_order,id')->fetchAll()));
    }
    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $iid=uid();
        try {
            db()->prepare('INSERT INTO inventory (id,name,cat,asset_id,qty,value,notes,condition) VALUES (?,?,?,?,?,?,?,?)')->execute([$iid,$b['name']??'',$b['cat']??'Other',$b['assetId']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$b['condition']??'Good']);
        } catch(\PDOException $e) {
            // Fallback: condition column doesn't exist yet (migration not run)
            if(strpos($e->getMessage(),'condition')!==false){
                db()->prepare('INSERT INTO inventory (id,name,cat,asset_id,qty,value,notes) VALUES (?,?,?,?,?,?,?)')->execute([$iid,$b['name']??'',$b['cat']??'Other',$b['assetId']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'']);
            } else { throw $e; }
        }
        json_out(['id'=>$iid]);
    }
    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        try {
            db()->prepare('UPDATE inventory SET name=?,cat=?,asset_id=?,qty=?,value=?,notes=?,condition=? WHERE id=?')->execute([$b['name']??'',$b['cat']??'Other',$b['assetId']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$b['condition']??'Good',$id]);
        } catch(\PDOException $e) {
            if(strpos($e->getMessage(),'condition')!==false){
                db()->prepare('UPDATE inventory SET name=?,cat=?,asset_id=?,qty=?,value=?,notes=? WHERE id=?')->execute([$b['name']??'',$b['cat']??'Other',$b['assetId']??'',(int)($b['qty']??1),(float)($b['value']??0),$b['notes']??'',$id]);
            } else { throw $e; }
        }
        json_out(['ok'=>true]);
    }
    if($method==='DELETE'&&$id){
        require_role('admin');
        db()->prepare('DELETE FROM inventory WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  LOG
// ════════════════════════════════════════════════════════════
if($resource==='log'){
    if($method==='GET')json_out(db()->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT 200')->fetchAll());
    if($method==='POST'&&!$id){
        $b=body();
        db()->prepare('INSERT INTO activity_log (log_time,html,type,user_id) VALUES (?,?,?,?)')->execute([$b['t']??'',$b['html']??'',$b['type']??'add',$me['id']??null]);
        json_out(['ok'=>true]);
    }
    if($method==='DELETE'){require_role('admin');db()->exec('TRUNCATE TABLE activity_log');json_out(['ok'=>true]);}
}

// ════════════════════════════════════════════════════════════
//  MAIL  — POST /mail/send
// ════════════════════════════════════════════════════════════
if($resource==='mail'){
    // GET /mail/diag — reports the state of mail config without
    // leaking secrets. Admin-only. Useful when the frontend shows a
    // "Send failed" error and you need to know which piece is missing.
    if($method==='GET' && $id==='diag'){
        require_role('admin');
        $driver = defined('MAIL_DRIVER') ? MAIL_DRIVER : null;
        $out = [
            'driver'        => $driver,
            'from_name'     => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : null,
            'from_addr'     => defined('MAIL_FROM_ADDR') ? MAIL_FROM_ADDR : null,
            'phpmailer'     => file_exists(__DIR__.'/vendor/autoload.php') ? 'installed' : 'missing',
            'php_mail_fn'   => function_exists('mail'),
        ];
        if($driver === 'smtp'){
            $out['smtp_host']   = defined('SMTP_HOST') ? SMTP_HOST : null;
            $out['smtp_port']   = defined('SMTP_PORT') ? SMTP_PORT : null;
            $out['smtp_user']   = defined('SMTP_USER') ? SMTP_USER : null;
            $out['smtp_pass']   = defined('SMTP_PASS') ? (SMTP_PASS ? '(set, '.strlen(SMTP_PASS).' chars)' : '(empty)') : null;
            $out['smtp_secure'] = defined('SMTP_SECURE') ? SMTP_SECURE : null;
        }
        // Quick sanity summary of what's missing
        $issues = [];
        if(!$driver) $issues[] = 'MAIL_DRIVER not defined';
        if(!defined('MAIL_FROM_ADDR') || !MAIL_FROM_ADDR) $issues[] = 'MAIL_FROM_ADDR missing or empty';
        if($driver === 'smtp'){
            foreach(['SMTP_HOST','SMTP_USER','SMTP_PASS','SMTP_PORT','SMTP_SECURE'] as $c)
                if(!defined($c)) $issues[] = "$c not defined";
            if($out['phpmailer']==='missing') $issues[] = 'PHPMailer not installed (run: composer require phpmailer/phpmailer)';
        }
        if($driver && $driver !== 'smtp' && !function_exists('mail'))
            $issues[] = 'PHP mail() function not available on this server';
        $out['issues'] = $issues;
        $out['status'] = $issues ? 'bad' : 'ok';
        json_out($out);
    }

    if($method==='POST'&&$id==='send'){
        require_role('admin','operator');

        // Guard against undefined constants — return a clear JSON error
        // rather than letting PHP fatal on "Undefined constant MAIL_DRIVER".
        foreach(['MAIL_DRIVER','MAIL_FROM_NAME','MAIL_FROM_ADDR'] as $_c){
            if(!defined($_c)) err("Mail config incomplete: missing $_c in config.php", 500);
        }
        if(MAIL_DRIVER === 'smtp'){
            foreach(['SMTP_HOST','SMTP_USER','SMTP_PASS','SMTP_PORT','SMTP_SECURE'] as $_c){
                if(!defined($_c)) err("SMTP config incomplete: missing $_c in config.php", 500);
            }
        }

        $b         = body();
        $to        = trim($b['to']        ?? '');
        $sub       = trim($b['subject']   ?? '');
        $txt       = trim($b['text']      ?? '');
        $htm       = $b['html']           ?? '';
        $from_name = trim($b['from_name'] ?? MAIL_FROM_NAME);
        $from_addr = MAIL_FROM_ADDR;

        // ── Attachment ────────────────────────────────────────
        // Two supported paths, both optional:
        //   (1) attach_html + attach_filename — frontend supplies a ready
        //       HTML document to attach as text/html. Used by default
        //       because it leverages the buildJobHTML cover-page layout
        //       and works on any server with no extra dependencies.
        //   (2) job_id with grip_job_pdf() available — server generates
        //       a real PDF attachment. Kept for backwards compatibility
        //       with setups that ship grip-pdf.php.
        $attach_data = null;
        $attach_name = null;
        $attach_type = 'application/octet-stream';

        $attach_html_in = $b['attach_html']     ?? '';
        $attach_fname   = trim($b['attach_filename'] ?? '');
        if($attach_html_in && $attach_fname){
            $attach_data = $attach_html_in;
            $attach_name = preg_replace('/[^a-zA-Z0-9_\-\. ]/','_',$attach_fname);
            $attach_type = 'text/html';
        } else {
            $pdf_job_id  = trim($b['job_id'] ?? '');
            if($pdf_job_id && function_exists('grip_job_pdf')){
                $jst = db()->prepare('SELECT * FROM jobs WHERE id=?');
                $jst->execute([$pdf_job_id]);
                $pdf_job = $jst->fetch();
                if($pdf_job){
                    $dst = db()->prepare('SELECT * FROM days WHERE job_id=? ORDER BY sort_order,id');
                    $dst->execute([$pdf_job_id]);
                    $pdf_days = $dst->fetchAll();
                    $gst = db()->prepare('SELECT * FROM gear WHERE day_id=? ORDER BY sort_order,id');
                    foreach($pdf_days as &$pd){
                        $gst->execute([$pd['id']]);
                        $pd['gear'] = $gst->fetchAll();
                        $pd['date'] = $pd['shoot_date'] ?? '';
                    }
                    $pdf_job['days'] = $pdf_days;
                    $attach_data = grip_job_pdf($pdf_job);
                    $safe        = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pdf_job['name'] ?? 'Equipment_List');
                    $attach_name = $safe.'_Equipment_List.pdf';
                    $attach_type = 'application/pdf';
                }
            }
            // If job_id was sent but grip_job_pdf() isn't available,
            // we just skip the attachment rather than failing the send.
        }

        // CC / BCC — comma-separated lists
        $parse_addr_list = function(string $raw): array {
            if($raw === '') return [];
            $addrs = array_filter(array_map('trim', explode(',', $raw)));
            foreach($addrs as $a){
                if(!filter_var($a, FILTER_VALIDATE_EMAIL))
                    err("Invalid email address in CC/BCC: $a");
            }
            return array_values($addrs);
        };
        $cc_list  = $parse_addr_list(trim($b['cc']  ?? ''));
        $bcc_list = $parse_addr_list(trim($b['bcc'] ?? ''));

        if(!$to  || !filter_var($to, FILTER_VALIDATE_EMAIL)) err('Invalid recipient email');
        if(!$sub) err('Subject is required');
        if(!$txt) err('Message body is required');

        // ── SMTP via PHPMailer ────────────────────────────────
        if(MAIL_DRIVER === 'smtp'){
            $vendor = __DIR__ . '/vendor/autoload.php';
            if(!file_exists($vendor)) err('PHPMailer not installed — run: composer require phpmailer/phpmailer', 500);
            require $vendor;
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = SMTP_SECURE === 'ssl'
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int)SMTP_PORT;
                $mail->CharSet    = 'UTF-8';
                $mail->Encoding   = 'base64';
                $mail->setFrom($from_addr, $from_name);
                $mail->addAddress($to);
                foreach($cc_list  as $a) $mail->addCC($a);
                foreach($bcc_list as $a) $mail->addBCC($a);
                $mail->Subject = $sub;
                $mail->isHTML(true);
                $mail->Body    = $htm ?: nl2br(htmlspecialchars($txt));
                $mail->AltBody = $txt;
                if($attach_data && $attach_name)
                    $mail->addStringAttachment($attach_data, $attach_name, 'base64', $attach_type);
                $mail->send();
                json_out(['ok'=>true,'driver'=>'smtp']);
            } catch(\Exception $e){
                err('SMTP error: '.$mail->ErrorInfo, 500);
            }
        }

        // ── PHP built-in mail() — multipart/mixed with optional attachment ──
        $outer_boundary = bin2hex(random_bytes(12));
        $alt_boundary   = bin2hex(random_bytes(12));

        $from_hdr = $from_name ? '"'.$from_name.'" <'.$from_addr.'>' : $from_addr;
        $hdr_lines = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$outer_boundary.'"',
            'From: '.$from_hdr,
            'Reply-To: '.$from_addr,
            'X-Mailer: GRIP-Gear-Tracker',
        ];
        if($cc_list)  $hdr_lines[] = 'Cc: ' .implode(', ', $cc_list);
        if($bcc_list) $hdr_lines[] = 'Bcc: '.implode(', ', $bcc_list);
        $headers = implode("\r\n", $hdr_lines);

        $body_html = $htm ?: '<html><body><pre>'.htmlspecialchars($txt).'</pre></body></html>';

        // Inner multipart/alternative (text + html)
        $alt_part = "--{$alt_boundary}\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                  . quoted_printable_encode($txt)."\r\n"
                  . "--{$alt_boundary}\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                  . quoted_printable_encode($body_html)."\r\n"
                  . "--{$alt_boundary}--";

        // Outer multipart/mixed
        $msg = "--{$outer_boundary}\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$alt_boundary}\"\r\n\r\n"
             . $alt_part."\r\n";

        // Attachment part (dynamic content-type — text/html, application/pdf, etc.)
        if($attach_data && $attach_name){
            $attach_b64 = chunk_split(base64_encode($attach_data));
            $safe_name  = addslashes($attach_name);
            $msg .= "--{$outer_boundary}\r\n"
                 .  "Content-Type: {$attach_type}; name=\"{$safe_name}\"\r\n"
                 .  "Content-Transfer-Encoding: base64\r\n"
                 .  "Content-Disposition: attachment; filename=\"{$safe_name}\"\r\n\r\n"
                 .  $attach_b64."\r\n";
        }

        $msg .= "--{$outer_boundary}--";

        $ok = mail($to, $sub, $msg, $headers);
        if(!$ok) err('mail() returned false — check your server MTA configuration', 500);
        json_out(['ok'=>true,'driver'=>'mail']);
    }
    err('Unknown mail action', 404);
}

// ════════════════════════════════════════════════════════════
//  GEAR VALUE LOOKUP  — POST /ai/value
//  Fuzzy keyword match against a built-in price table.
//  No API key required — works entirely offline.
// ════════════════════════════════════════════════════════════
if($resource==='ai'){
    if($method==='POST'&&$id==='value'){
        require_role('admin','operator');
        $name = strtolower(trim(body()['name']??''));
        if(!$name) err('name required');

        // ── Price table (CAD) ─────────────────────────────────
        // Each entry: [keywords[], value, note]
        // Matched by: ALL keywords must appear in the item name.
        // Most specific entries first — first match wins.
        $table = [
            // ── Cameras ──────────────────────────────────────
            [['alexa','65'],                   185000,'ARRI Alexa 65 body'],
            [['alexa','mini lf'],               72000,'ARRI Alexa Mini LF body'],
            [['alexa','mini'],                  42000,'ARRI Alexa Mini body'],
            [['alexa','35'],                    55000,'ARRI Alexa 35 body'],
            [['amira'],                         32000,'ARRI Amira body'],
            [['venice','2'],                    58000,'Sony Venice 2 body'],
            [['venice'],                        42000,'Sony Venice body'],
            [['fx9'],                            9500,'Sony FX9 body'],
            [['fx6'],                            5200,'Sony FX6 body'],
            [['fx3'],                            3800,'Sony FX3 body'],
            [['burano'],                        36000,'Sony Burano body'],
            [['c70'],                            4200,'Canon C70 body'],
            [['c300','mk iii'],                 16000,'Canon C300 Mk III body'],
            [['c300'],                          10000,'Canon C300 body'],
            [['c500','mk ii'],                  16500,'Canon C500 Mk II body'],
            [['c500'],                          10500,'Canon C500 body'],
            [['c700'],                          32000,'Canon C700 body'],
            [['r5c'],                            4800,'Canon R5C body'],
            [['r5'],                             4300,'Canon R5 body'],
            [['bmpcc','6k'],                     2800,'Blackmagic 6K body'],
            [['bmpcc','4k'],                     1800,'Blackmagic 4K body'],
            [['ursa','mini pro'],                5500,'BMPCC Ursa Mini Pro'],
            [['ursa'],                           4800,'Blackmagic Ursa body'],
            [['red','monstro'],                 35000,'RED Monstro 8K body'],
            [['red','helium'],                  20000,'RED Helium 8K body'],
            [['red','gemini'],                  18000,'RED Gemini 5K body'],
            [['red','dragon'],                  15000,'RED Dragon body'],
            [['red','komodo'],                   6500,'RED Komodo 6K body'],
            [['red','v-raptor'],                40000,'RED V-Raptor body'],
            [['red','raptor'],                  22000,'RED Raptor body'],
            [['gh6'],                            2200,'Panasonic GH6 body'],
            [['s5 ii'],                          2600,'Panasonic S5 II body'],
            [['s5'],                             2200,'Panasonic S5 body'],
            [['z9'],                             6500,'Nikon Z9 body'],
            [['z8'],                             4500,'Nikon Z8 body'],
            // ── Lenses ───────────────────────────────────────
            [['master prime'],                  22000,'ARRI Master Prime lens'],
            [['ultra prime'],                   12000,'ARRI Ultra Prime lens'],
            [['signature prime'],               16000,'ARRI Signature Prime'],
            [['sigma','cine','14mm'],            9000,'Sigma Cine 14mm T2'],
            [['sigma','cine','18mm'],            8000,'Sigma Cine 18mm T1.5'],
            [['sigma','cine','20mm'],            8200,'Sigma Cine 20mm T1.5'],
            [['sigma','cine','24mm'],            7800,'Sigma Cine 24mm T1.5'],
            [['sigma','cine','35mm'],            7500,'Sigma Cine 35mm T1.5'],
            [['sigma','cine','50mm'],            7500,'Sigma Cine 50mm T1.5'],
            [['sigma','cine','85mm'],            7500,'Sigma Cine 85mm T1.5'],
            [['sigma','cine','135mm'],           8200,'Sigma Cine 135mm T2'],
            [['zeiss','supreme','18mm'],        18000,'Zeiss Supreme 18mm T1.5'],
            [['zeiss','supreme','25mm'],        16000,'Zeiss Supreme 25mm T1.5'],
            [['zeiss','supreme','35mm'],        16000,'Zeiss Supreme 35mm T1.5'],
            [['zeiss','supreme','50mm'],        16000,'Zeiss Supreme 50mm T1.5'],
            [['zeiss','supreme','85mm'],        16000,'Zeiss Supreme 85mm T1.5'],
            [['zeiss','cp.3','18mm'],            5500,'Zeiss CP.3 18mm T2.9'],
            [['zeiss','cp.3','25mm'],            5000,'Zeiss CP.3 25mm T2.1'],
            [['zeiss','cp.3','35mm'],            5000,'Zeiss CP.3 35mm T2.1'],
            [['zeiss','cp.3','50mm'],            5000,'Zeiss CP.3 50mm T2.1'],
            [['zeiss','cp.3','85mm'],            5000,'Zeiss CP.3 85mm T2.1'],
            [['cooke','s4','18mm'],             12000,'Cooke S4/i 18mm T2'],
            [['cooke','s4','25mm'],             11500,'Cooke S4/i 25mm T2'],
            [['cooke','s4','32mm'],             11500,'Cooke S4/i 32mm T2'],
            [['cooke','s4','50mm'],             11500,'Cooke S4/i 50mm T2'],
            [['cooke','s4','75mm'],             11500,'Cooke S4/i 75mm T2'],
            [['cooke','s4','100mm'],            11500,'Cooke S4/i 100mm T2'],
            [['canon','cn-e','14mm'],            6500,'Canon CN-E 14mm T3.1'],
            [['canon','cn-e','24mm'],            6000,'Canon CN-E 24mm T1.5'],
            [['canon','cn-e','35mm'],            6000,'Canon CN-E 35mm T1.5'],
            [['canon','cn-e','50mm'],            6000,'Canon CN-E 50mm T1.3'],
            [['canon','cn-e','85mm'],            6500,'Canon CN-E 85mm T1.3'],
            [['canon','cn-e','135mm'],           7000,'Canon CN-E 135mm T2.2'],
            [['rokinon','xeen','14mm'],          1500,'Rokinon Xeen 14mm T3.1'],
            [['rokinon','xeen','24mm'],          1400,'Rokinon Xeen 24mm T1.5'],
            [['rokinon','xeen','35mm'],          1400,'Rokinon Xeen 35mm T1.5'],
            [['rokinon','xeen','50mm'],          1400,'Rokinon Xeen 50mm T1.5'],
            [['rokinon','xeen','85mm'],          1400,'Rokinon Xeen 85mm T1.5'],
            [['fujinon','premista','19-45'],    90000,'Fujinon Premista 19-45mm zoom'],
            [['fujinon','premista','28-100'],   90000,'Fujinon Premista 28-100mm zoom'],
            [['angénieux','optimo','15-40'],    55000,'Angénieux Optimo 15-40mm'],
            [['angénieux','optimo','28-76'],    50000,'Angénieux Optimo 28-76mm'],
            [['angenieux','optimo','28-76'],    50000,'Angénieux Optimo 28-76mm'],
            [['leica','summicron'],              8000,'Leica Summicron-C lens'],
            [['leica','thalia'],                14000,'Leica Thalia prime lens'],
            // ── Lights ───────────────────────────────────────
            [['skypanel','s360'],               55000,'ARRI SkyPanel S360-C'],
            [['skypanel','s120'],               28000,'ARRI SkyPanel S120-C'],
            [['skypanel','s60'],                 9500,'ARRI SkyPanel S60-C'],
            [['skypanel','s30'],                 5500,'ARRI SkyPanel S30-C'],
            [['skypanel','s60-c'],               9500,'ARRI SkyPanel S60-C'],
            [['arri','hmi','6000'],             14000,'ARRI HMI 6000W fresnel'],
            [['arri','hmi','4000'],             10000,'ARRI HMI 4000W fresnel'],
            [['arri','hmi','2500'],              7500,'ARRI HMI 2500W fresnel'],
            [['arri','hmi','1200'],              4500,'ARRI HMI 1200W fresnel'],
            [['arri','m18'],                    10000,'ARRI M18 HMI'],
            [['arri','m90'],                    18000,'ARRI M90 LED fresnel'],
            [['kino flo','celeb 450'],           4500,'Kino Flo Celeb 450 LED DMX'],
            [['kino flo','celeb 250'],           3200,'Kino Flo Celeb 250 LED'],
            [['kino flo','diva','401'],          2500,'Kino Flo Diva-Lite 401'],
            [['kino flo','diva'],                2000,'Kino Flo Diva-Lite'],
            [['kino flo','freestyle','31'],      3500,'Kino Flo Freestyle 31 LED'],
            [['kino flo'],                       1800,'Kino Flo fixture'],
            [['aputure','600x'],                 2200,'Aputure LS 600x Pro'],
            [['aputure','600d'],                 1800,'Aputure LS 600d Pro'],
            [['aputure','300x'],                 1100,'Aputure LS 300x'],
            [['aputure','300d'],                  900,'Aputure LS 300d Mk II'],
            [['aputure','120d'],                  700,'Aputure LS 120d Mk II'],
            [['aputure','nova p300'],            3500,'Aputure Nova P300c LED panel'],
            [['aputure','nova p600'],            5500,'Aputure Nova P600c LED panel'],
            [['litepanels','gemini','2x1'],      4500,'Litepanels Gemini 2x1 RGBWW'],
            [['litepanels','astra 6x'],          3600,'Litepanels Astra 6X Bi-Color'],
            [['litepanels','astra'],             2800,'Litepanels Astra panel'],
            [['nanlite','forza 500b'],           1300,'Nanlite Forza 500B II'],
            [['nanlite','forza 300b'],            900,'Nanlite Forza 300B'],
            [['nanlite','pavotube ii'],           600,'Nanlite PavoTube II'],
            [['astera','titan'],                 1400,'Astera Titan tube'],
            [['astera','helios'],                1200,'Astera Helios tube'],
            [['astera','ax1'],                    900,'Astera AX1 pixel tube'],
            [['quasar','rainbow','2'],            600,'Quasar Science Rainbow 2'],
            [['quasar','q-lion'],                 450,'Quasar Science Q-Lion'],
            [['creamsource','vortex 8'],         5500,'Creamsource Vortex8 LED'],
            [['creamsource','vortex4'],          3500,'Creamsource Vortex4 LED'],
            [['fiilex','q8'],                    2800,'Fiilex Q8 fresnel LED'],
            [['dedolight','dled9'],              1800,'Dedolight DLED9 spot'],
            [['dedolight'],                      1200,'Dedolight fixture'],
            // ── Grip ─────────────────────────────────────────
            [['dana dolly'],                     5000,'Dana Dolly track system'],
            [['doorway dolly'],                  2200,'Doorway dolly'],
            [['super peewee'],                   8000,'Super PeeWee dolly'],
            [['speed rail','1.5"','20'],          180,'Speed Rail 1.5" 20ft'],
            [['speed rail'],                      120,'Speed Rail section'],
            [['fisher 10'],                     18000,'Fisher Model 10 dolly'],
            [['fisher 11'],                     22000,'Fisher Model 11 dolly'],
            [['c-stand','40'],                    320,'Matthews C-Stand 40"'],
            [['c-stand','20'],                    240,'Matthews C-Stand 20"'],
            [['c-stand'],                         280,'C-Stand'],
            [['baby stand'],                      120,'Baby stand'],
            [['hi-roller'],                       550,'Hi-Roller stand'],
            [['low roller'],                      480,'Low Roller stand'],
            [['bead board','4x8'],                 80,'4x8 Bead Board'],
            [['show card'],                        15,'Show Card'],
            [['duvetyne'],                        120,'Duvetyne fabric (yard)'],
            [['black wrap'],                       45,'Black Wrap roll'],
            [['sandbag','15'],                     40,'Sandbag 15lb'],
            [['sandbag','25'],                     55,'Sandbag 25lb'],
            [['sandbag'],                          35,'Sandbag'],
            [['junior pin'],                       80,'Junior pin adapter'],
            [['baby pin'],                         60,'Baby pin adapter'],
            [['cardellini'],                       85,'Cardellini clamp'],
            [['mafer'],                            75,'Mafer clamp'],
            [['menace arm'],                      220,'Menace arm'],
            [['combo stand'],                     380,'Combo stand'],
            [['century stand'],                   280,'Century stand'],
            [['grid clamp'],                       45,'Grid clamp'],
            [['pelican','1510'],                  350,'Pelican 1510 case'],
            [['pelican','1610'],                  450,'Pelican 1610 case'],
            [['pelican'],                         320,'Pelican hard case'],
            // ── Audio ─────────────────────────────────────────
            [['sound devices','888'],            4200,'Sound Devices 888 recorder'],
            [['sound devices','702t'],           2800,'Sound Devices 702T recorder'],
            [['sound devices','mixpre-10'],      2200,'Sound Devices MixPre-10 II'],
            [['sound devices','mixpre-6'],       1200,'Sound Devices MixPre-6 II'],
            [['sound devices','mixpre-3'],        800,'Sound Devices MixPre-3 II'],
            [['scorpio'],                        12000,'Zaxcom Nomad/Scorpio'],
            [['nomad'],                           6500,'Zaxcom Nomad recorder'],
            [['maxx'],                            4500,'Zaxcom MAXX recorder'],
            [['tentacle sync'],                   220,'Tentacle Sync timecode box'],
            [['deity','s-mic 2'],                 450,'Deity S-Mic 2 shotgun'],
            [['rode','ntg5'],                     700,'Rode NTG5 shotgun mic'],
            [['rode','ntg3'],                     550,'Rode NTG3 shotgun mic'],
            [['sennheiser','mke600'],             380,'Sennheiser MKE 600'],
            [['sennheiser','416'],                950,'Sennheiser MKH 416 shotgun'],
            [['sennheiser','mkh50'],             1200,'Sennheiser MKH 50 supercardioid'],
            [['sennheiser','mkh60'],             1100,'Sennheiser MKH 60 shotgun'],
            [['rycote','blimp'],                  650,'Rycote Blimp windshield'],
            [['rycote','cyclone'],                900,'Rycote Cyclone'],
            [['lectrosonics','ssm'],             1400,'Lectrosonics SSM transmitter'],
            [['lectrosonics','smqv'],            1600,'Lectrosonics SMQV transmitter'],
            [['lectrosonics','srb'],             1800,'Lectrosonics SRb receiver'],
            [['lectrosonics','src'],             2200,'Lectrosonics SRc receiver'],
            [['lectrosonics','venue2'],          3500,'Lectrosonics Venue2 receiver'],
            [['wisycom','mtp40'],                1200,'Wisycom MTP40 transmitter'],
            [['zaxcom','trx900'],               1500,'Zaxcom TRX900 transmitter'],
            [['boom pole'],                       350,'Boom pole'],
            [['k-tek','traveler'],                450,'K-Tek Traveler boom'],
            [['remote audio','boom'],             480,'Remote Audio boom'],
            // ── Power ─────────────────────────────────────────
            [['anton bauer','dionic xt 90'],      320,'Anton Bauer Dionic XT 90'],
            [['anton bauer','dionic','150'],       480,'Anton Bauer Dionic 150Wh'],
            [['anton bauer','cine','150'],         520,'Anton Bauer Cine 150Wh'],
            [['vmount','150'],                    320,'V-Mount 150Wh battery'],
            [['vmount','90'],                     220,'V-Mount 90Wh battery'],
            [['v-mount','150'],                   320,'V-Mount 150Wh battery'],
            [['v-mount','90'],                    220,'V-Mount 90Wh battery'],
            [['swx','hypercore','150'],           500,'Core SWX HyperCore 150Wh'],
            [['swx','hypercore'],                 380,'Core SWX HyperCore battery'],
            [['gold mount','90'],                 220,'Gold Mount 90Wh battery'],
            [['gold mount','150'],                380,'Gold Mount 150Wh battery'],
            [['np-f970'],                          80,'Sony NP-F970 battery'],
            [['np-f550'],                          45,'Sony NP-F550 battery'],
            [['idxc70'],                          280,'IDX C-70 battery charger'],
            [['idx'],                             220,'IDX battery/charger'],
            [['powerhouse','1500'],               350,'Wagan PowerHouse 1500'],
            [['goal zero','yeti 1500'],           2200,'Goal Zero Yeti 1500X'],
            [['goal zero','yeti 500'],             800,'Goal Zero Yeti 500X'],
            [['generator','honda','eu3000'],      2400,'Honda EU3000i generator'],
            [['generator','honda','eu2200'],      1500,'Honda EU2200i generator'],
            // ── Support ───────────────────────────────────────
            [['sachtler','flowtech 75'],          3400,'Sachtler Flowtech 75 tripod'],
            [['sachtler','flowtech 100'],         4200,'Sachtler Flowtech 100 tripod'],
            [['sachtler','ace xl'],               2200,'Sachtler Ace XL tripod'],
            [['sachtler','video 18'],             4800,'Sachtler Video 18 head'],
            [['sachtler','video 20'],             6500,'Sachtler Video 20 head'],
            [['sachtler','aktiv8'],               3800,'Sachtler Aktiv8 head'],
            [['miller','solo 75'],                2200,'Miller Solo 75CF tripod'],
            [['miller','air'],                    1800,'Miller Air tripod'],
            [['vinten','vision blue'],            3500,'Vinten Vision Blue tripod'],
            [['vinten','vision 100'],             5200,'Vinten Vision 100 head'],
            [['fluid head'],                      1800,'Fluid head'],
            [['ronin 4d'],                        8500,'DJI Ronin 4D gimbal'],
            [['ronin-s'],                         1100,'DJI Ronin-S gimbal'],
            [['ronin sc'],                         750,'DJI Ronin SC gimbal'],
            [['movi pro'],                        6500,'Freefly MōVI Pro gimbal'],
            [['movi xl'],                         9000,'Freefly MōVI XL gimbal'],
            [['movi m10'],                        4500,'Freefly MōVI M10 gimbal'],
            [['steadicam','masterclass'],        28000,'Steadicam Masterclass'],
            [['steadicam','aero'],               15000,'Steadicam Aero'],
            [['steadicam','pilot'],               5500,'Steadicam Pilot'],
            [['easyrig','vario 5'],               2800,'EasyRig Vario 5'],
            [['easyrig'],                         2200,'EasyRig'],
            [['shoulder rig'],                     650,'Shoulder rig'],
            [['mattebox'],                         850,'Matte box'],
            [['arri mb-20'],                      3500,'ARRI MB-20 matte box'],
            [['arri mb-19'],                      2800,'ARRI MB-19 matte box'],
            [['follow focus'],                     480,'Follow focus unit'],
            [['cmotion'],                         4500,'cmotion wireless follow focus'],
            [['arri wcu-4'],                      5500,'ARRI WCU-4 wireless FIZ'],
            // ── Cables ───────────────────────────────────────
            [['sdi','bnc','50'],                   55,'BNC 50ft SDI cable'],
            [['sdi','bnc','25'],                   35,'BNC 25ft SDI cable'],
            [['bnc','50'],                         55,'BNC 50ft cable'],
            [['bnc','25'],                         35,'BNC 25ft cable'],
            [['xlr','50'],                         60,'XLR 50ft cable'],
            [['xlr','25'],                         35,'XLR 25ft cable'],
            [['xlr','10'],                         20,'XLR 10ft cable'],
            [['xlr'],                              28,'XLR cable'],
            [['hdmi','15'],                        25,'HDMI 15ft cable'],
            [['stingers','25'],                    45,'25ft stinger extension'],
            [['stingers','50'],                    65,'50ft stinger extension'],
            [['bates','25'],                       55,'25ft Bates cable'],
            [['bates','50'],                       75,'50ft Bates cable'],
            // ── Monitors / Accessories ────────────────────────
            [['smallhd','502'],                  1200,'SmallHD 502 monitor'],
            [['smallhd','702'],                  2200,'SmallHD 702 Touch monitor'],
            [['smallhd','1703'],                 4500,'SmallHD 1703 HDR monitor'],
            [['atomos','shogun'],                1800,'Atomos Shogun recorder/monitor'],
            [['atomos','ninja'],                  950,'Atomos Ninja monitor/recorder'],
            [['teradek','bolt 4k','750'],         8500,'Teradek Bolt 4K 750 TX/RX'],
            [['teradek','bolt 4k','500'],         6500,'Teradek Bolt 4K 500'],
            [['teradek','bolt','500'],            4500,'Teradek Bolt 500'],
            [['teradek','bolt','300'],            3200,'Teradek Bolt 300'],
            [['drone','inspire 3'],              16000,'DJI Inspire 3 drone'],
            [['drone','inspire 2'],               8000,'DJI Inspire 2 drone'],
            [['mavic','3 cine'],                  5500,'DJI Mavic 3 Cine drone'],
        ];

        $q = strtolower($name);
        $best_value = 0;
        $best_note  = '';
        $best_score = 0;

        foreach($table as [$keywords, $value, $note]){
            $matches = 0;
            foreach($keywords as $kw){
                if(strpos($q, $kw) !== false) $matches++;
            }
            if($matches === count($keywords)){
                // All keywords matched — score by specificity (total keyword chars)
                $score = array_sum(array_map('strlen', $keywords));
                if($score > $best_score){
                    $best_score = $score;
                    $best_value = $value;
                    $best_note  = $note;
                }
            }
        }

        // Fuzzy fallback: if no exact match, try partial single-keyword hits
        // and return a category-level estimate with low confidence
        if($best_value === 0){
            $cat_estimates = [
                'camera'=>8000,'lens'=>5000,'cine lens'=>7000,
                'light'=>2500,'led'=>1800,'hmi'=>4000,'fresnel'=>2200,
                'tripod'=>1800,'head'=>2200,'gimbal'=>3500,'dolly'=>4000,
                'mic'=>600,'microphone'=>600,'recorder'=>1500,'transmitter'=>1200,
                'battery'=>280,'charger'=>350,
                'cable'=>45,'xlr'=>30,'bnc'=>45,
                'monitor'=>1200,'stand'=>200,'sandbag'=>40,
            ];
            foreach($cat_estimates as $kw=>$est){
                if(strpos($q,$kw)!==false){
                    $best_value = $est;
                    $best_note  = 'Category estimate — verify actual price';
                    break;
                }
            }
        }

        json_out(['value'=>$best_value,'note'=>$best_note]);
    }
    err('Unknown ai action', 404);
}

// ════════════════════════════════════════════════════════════
//  CONTACTS  — global address book
// ════════════════════════════════════════════════════════════
if($resource==='contacts'){

    if($method==='GET'&&!$id){
        json_out(db()->query('SELECT * FROM contacts ORDER BY name')->fetchAll());
    }

    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $cid=uid();
        db()->prepare('INSERT INTO contacts (id,name,email,phone,company,role,notes) VALUES (?,?,?,?,?,?,?)')
            ->execute([$cid,trim($b['name']??''),trim($b['email']??''),trim($b['phone']??''),
                trim($b['company']??''),trim($b['role']??''),trim($b['notes']??'')]);
        json_out(['id'=>$cid]);
    }

    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        db()->prepare('UPDATE contacts SET name=?,email=?,phone=?,company=?,role=?,notes=? WHERE id=?')
            ->execute([trim($b['name']??''),trim($b['email']??''),trim($b['phone']??''),
                trim($b['company']??''),trim($b['role']??''),trim($b['notes']??''),$id]);
        json_out(['ok'=>true]);
    }

    if($method==='DELETE'&&$id){
        require_role('admin','operator');
        db()->prepare('DELETE FROM contacts WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  PRODUCTION COMPANIES  — discrete company records with contact info
// ════════════════════════════════════════════════════════════
if($resource==='production_companies'){

    // Auto-migrate: create table if missing (idempotent, cheap)
    db()->exec("CREATE TABLE IF NOT EXISTS production_companies (
        id         VARCHAR(20)  PRIMARY KEY,
        name       VARCHAR(255) NOT NULL,
        email      VARCHAR(255) DEFAULT '',
        phone      VARCHAR(80)  DEFAULT '',
        website    VARCHAR(255) DEFAULT '',
        address    TEXT,
        notes      TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pc_name (name)
    ) ENGINE=InnoDB");

    if($method==='GET'&&!$id){
        json_out(db()->query('SELECT * FROM production_companies ORDER BY name')->fetchAll());
    }

    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $pid=uid();
        $name = trim($b['name']??'');
        if(!$name) err('Company name required');
        try {
            db()->prepare('INSERT INTO production_companies (id,name,email,phone,website,address,notes) VALUES (?,?,?,?,?,?,?)')
                ->execute([$pid, $name,
                    trim($b['email']??''), trim($b['phone']??''),
                    trim($b['website']??''), trim($b['address']??''),
                    trim($b['notes']??'')]);
        } catch(PDOException $e) {
            if($e->getCode()==='23000') err('A company with that name already exists.', 409);
            throw $e;
        }
        json_out(['id'=>$pid]);
    }

    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        $name = trim($b['name']??'');
        if(!$name) err('Company name required');
        try {
            db()->prepare('UPDATE production_companies SET name=?,email=?,phone=?,website=?,address=?,notes=? WHERE id=?')
                ->execute([$name,
                    trim($b['email']??''), trim($b['phone']??''),
                    trim($b['website']??''), trim($b['address']??''),
                    trim($b['notes']??''), $id]);
        } catch(PDOException $e) {
            if($e->getCode()==='23000') err('A company with that name already exists.', 409);
            throw $e;
        }
        json_out(['ok'=>true]);
    }

    if($method==='DELETE'&&$id){
        require_role('admin','operator');
        db()->prepare('DELETE FROM production_companies WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  JOB TEMPLATES — reusable gear-list templates
// ════════════════════════════════════════════════════════════
if($resource==='job_templates'){

    // Auto-migrate
    db()->exec("CREATE TABLE IF NOT EXISTS job_templates (
        id          VARCHAR(20)  PRIMARY KEY,
        name        VARCHAR(255) NOT NULL,
        description TEXT,
        co          VARCHAR(255) DEFAULT '',
        notes       TEXT,
        gear        JSON,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_jt_name (name)
    ) ENGINE=InnoDB");

    if($method==='GET'&&!$id){
        $rows = db()->query('SELECT * FROM job_templates ORDER BY name')->fetchAll();
        foreach($rows as &$r){
            if(isset($r['gear']) && is_string($r['gear'])){
                $dec = json_decode($r['gear'], true);
                $r['gear'] = is_array($dec) ? $dec : [];
            }
        }
        json_out($rows);
    }

    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body(); $tid=uid();
        $name = trim($b['name']??'');
        if(!$name) err('Template name required');
        $gearJson = json_encode($b['gear'] ?? []);
        try {
            db()->prepare('INSERT INTO job_templates (id,name,description,co,notes,gear) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, $name,
                    trim($b['description']??''), trim($b['co']??''),
                    trim($b['notes']??''), $gearJson]);
        } catch(PDOException $e) {
            if($e->getCode()==='23000') err('A template with that name already exists.', 409);
            throw $e;
        }
        json_out(['id'=>$tid]);
    }

    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        $name = trim($b['name']??'');
        if(!$name) err('Template name required');
        $gearJson = json_encode($b['gear'] ?? []);
        try {
            db()->prepare('UPDATE job_templates SET name=?,description=?,co=?,notes=?,gear=? WHERE id=?')
                ->execute([$name,
                    trim($b['description']??''), trim($b['co']??''),
                    trim($b['notes']??''), $gearJson, $id]);
        } catch(PDOException $e) {
            if($e->getCode()==='23000') err('A template with that name already exists.', 409);
            throw $e;
        }
        json_out(['ok'=>true]);
    }

    if($method==='DELETE'&&$id){
        require_role('admin','operator');
        db()->prepare('DELETE FROM job_templates WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  JOB CONTACTS  — contacts assigned to a specific job
// ════════════════════════════════════════════════════════════
if($resource==='job_contacts'){

    // GET /job_contacts/{job_id} — list contacts for a job
    if($method==='GET'){
        $jid = $id ?? '';
        if(!$jid) err('job_id required');
        $rows = db()->prepare(
            'SELECT jc.*,c.name,c.email,c.phone,c.company FROM job_contacts jc
             JOIN contacts c ON c.id=jc.contact_id
             WHERE jc.job_id=? ORDER BY c.name');
        $rows->execute([$jid]);
        json_out($rows->fetchAll());
    }

    // POST /job_contacts — assign contact to job (upsert)
    if($method==='POST'&&!$id){
        require_role('admin','operator');
        $b=body();
        $job_id     = trim($b['job_id']??'');
        $contact_id = trim($b['contact_id']??'');
        $role       = trim($b['role']??'');
        $include    = (int)($b['email_include']??1);

        // Check if already exists
        $existing = db()->prepare('SELECT id FROM job_contacts WHERE job_id=? AND contact_id=?');
        $existing->execute([$job_id, $contact_id]);
        $row = $existing->fetch();
        if($row){
            // Update in place
            db()->prepare('UPDATE job_contacts SET role=?,email_include=? WHERE id=?')
                ->execute([$role,$include,$row['id']]);
            json_out(['ok'=>true,'id'=>$row['id']]);
        } else {
            $jcid = uid();
            db()->prepare('INSERT INTO job_contacts (id,job_id,contact_id,role,email_include) VALUES (?,?,?,?,?)')
                ->execute([$jcid,$job_id,$contact_id,$role,$include]);
            json_out(['ok'=>true,'id'=>$jcid]);
        }
    }

    // PUT /job_contacts/{id} — update role or email_include
    if($method==='PUT'&&$id){
        require_role('admin','operator');
        $b=body();
        db()->prepare('UPDATE job_contacts SET role=?,email_include=? WHERE id=?')
            ->execute([trim($b['role']??''),(int)($b['email_include']??1),$id]);
        json_out(['ok'=>true]);
    }

    // DELETE /job_contacts/{id}
    if($method==='DELETE'&&$id){
        require_role('admin','operator');
        db()->prepare('DELETE FROM job_contacts WHERE id=?')->execute([$id]);
        json_out(['ok'=>true]);
    }
}

// ════════════════════════════════════════════════════════════
//  JOB EMAIL HTML  — GET /jobs/{id}/html
//  Returns a self-contained HTML document for the job,
//  identical to what the web app builds in buildJobHTML().
//  Use this from a native app to get the email body HTML
//  without needing to replicate the rendering logic.
//
//  Also supports:
//    GET /jobs/{id}/html?message=URL-encoded+covering+text
//  which prepends the message above the gear list.
//
//  Swift usage:
//    GET ?r=jobs/{id}/html
//    GET ?r=jobs/{id}/html&message=Hi+there...
//    Content-Type of response: text/html
// ════════════════════════════════════════════════════════════
if($resource==='jobs' && $id && isset($segments[2]) && $segments[2]==='html'){
    require_auth();
    $job_id = $id;
    $message = trim($_GET['message'] ?? '');

    // ── Fetch job ─────────────────────────────────────────
    $jst = db()->prepare('SELECT * FROM jobs WHERE id=?');
    $jst->execute([$job_id]);
    $job = $jst->fetch();
    if(!$job) err('Job not found', 404);

    // ── Fetch days ────────────────────────────────────────
    $dst = db()->prepare('SELECT * FROM days WHERE job_id=? ORDER BY sort_order, id');
    $dst->execute([$job_id]);
    $days = $dst->fetchAll();

    // ── Fetch gear for each day ───────────────────────────
    $gst = db()->prepare('SELECT * FROM gear WHERE day_id=? ORDER BY sort_order, id');
    foreach($days as &$d){
        $gst->execute([$d['id']]);
        $d['gear']     = $gst->fetchAll();
        $d['date']     = $d['shoot_date'] ?? '';
        unset($d['shoot_date']);
    }
    $job['days'] = $days;

    // ── Render HTML ───────────────────────────────────────
    $html = job_html($job, $message);

    // Return as HTML (not JSON)
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// ════════════════════════════════════════════════════════════
//  ICAL FEED  — GET ?r=ical  (optionally &token=xxx)
//  Returns an iCalendar (.ics) subscription feed of all
//  shoot days. Secured by a static token set in config.php:
//    define('ICAL_TOKEN', 'your-secret-here');
//  Subscribe URL (Google Calendar → "From URL"):
//    https://yourdomain.com/grip/api.php?r=ical&token=YOUR_TOKEN
// ════════════════════════════════════════════════════════════
if($resource==='ical'){

    // GET ?r=ical/url — return the subscribe URL for the frontend to display
    if($method==='GET' && $id==='url'){
        require_auth(); // only logged-in users can see this
        // Point to the standalone grip-ical.php — avoids session/routing complexity
        $scheme = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $dir    = rtrim(dirname($_SERVER['REQUEST_URI']), '/');
        $base   = $scheme.'://'.$_SERVER['HTTP_HOST'].$dir.'/grip-ical.php';
        $tok = defined('ICAL_TOKEN') ? trim(ICAL_TOKEN) : '';
        if($tok === ''){
            json_out(['configured'=>false,'url'=>null]);
        } else {
            json_out(['configured'=>true,'url'=>$base.'?token='.urlencode($tok)]);
        }
    }

    $token = trim($_GET['token'] ?? '');
    $defined_token = defined('ICAL_TOKEN') ? trim(ICAL_TOKEN) : '';
    // Ignore _v cache-buster param — it's just for Google Calendar URL uniqueness
    if($defined_token === ''){
        require_auth(); // fall back to session if no token configured
    } elseif($token !== $defined_token){
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid or missing token.';
        exit;
    }

    $rows = db()->query(
        "SELECT j.id as job_id, j.name as job_name, j.director, j.co,
                d.id as day_id, d.label, d.shoot_date, d.location
         FROM jobs j JOIN days d ON d.job_id=j.id
         WHERE j.archived=0 AND d.shoot_date IS NOT NULL AND d.shoot_date != ''
         ORDER BY d.shoot_date"
    )->fetchAll();

    $now  = gmdate('Ymd') . 'T' . gmdate('His') . 'Z';
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'grip');

    $out  = "BEGIN:VCALENDAR\r\n";
    $out .= "VERSION:2.0\r\n";
    $out .= "PRODID:-//GRIP Gear Tracker//EN\r\n";
    $out .= "CALSCALE:GREGORIAN\r\n";
    $out .= "METHOD:PUBLISH\r\n";
    $out .= "X-WR-CALNAME:GRIP Shoot Days\r\n";
    $out .= "X-WR-CALDESC:Shoot schedule from GRIP Gear Tracker\r\n";
    $out .= "REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n";

    foreach($rows as $r){
        // Parse date safely — stored as YYYY-MM-DD
        $parts = explode('-', $r['shoot_date']);
        if(count($parts) !== 3) continue;
        [$yr, $mo, $dy] = $parts;
        $date  = sprintf('%04d%02d%02d', (int)$yr, (int)$mo, (int)$dy);
        // DTEND is exclusive next day (RFC 5545 §3.6.1)
        $dtend = date('Ymd', mktime(12, 0, 0, (int)$mo, (int)$dy + 1, (int)$yr));

        $summary = ical_fold('SUMMARY',
            ical_esc($r['job_name'] . ' - ' . $r['label']));
        $loc = $r['location']
            ? ical_fold('LOCATION', ical_esc($r['location']))
            : null;
        $desc_parts = ['Job: ' . ical_esc($r['job_name'])];
        if(!empty($r['director'])) $desc_parts[] = 'Director: ' . ical_esc($r['director']);
        if(!empty($r['co']))       $desc_parts[] = 'Co: '       . ical_esc($r['co']);
        $desc = ical_fold('DESCRIPTION', implode('\n', $desc_parts));

        $out .= "BEGIN:VEVENT\r\n";
        // Strip leading underscore from id — UIDs must not start with special chars
        $uid_val = ltrim($r['day_id'], '_');
        $out .= "UID:grip-" . $uid_val . "@" . $host . "\r\n";
        $out .= "DTSTAMP:{$now}\r\n";
        $out .= "DTSTART;VALUE=DATE:{$date}\r\n";
        $out .= "DTEND;VALUE=DATE:{$dtend}\r\n";
        $out .= $summary;
        if($loc) $out .= $loc;
        $out .= $desc;
        $out .= "END:VEVENT\r\n";
    }
    $out .= "END:VCALENDAR\r\n";

    // Serve with strict headers Google Calendar expects
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="grip-shoot-days.ics"');
    header('Cache-Control: public, max-age=3600');  // allow Google to cache for 1 hour
    header('Pragma: public');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// Escape value per RFC 5545 §3.3.11
function ical_esc(string $s): string {
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace(';',  '\;',   $s);
    $s = str_replace(',',  '\,',   $s);
    $s = str_replace(["\r\n","\r","\n"], '\n', $s);
    return $s;
}

// Fold property lines at 75 octets per RFC 5545 §3.1
function ical_fold(string $prop, string $val): string {
    $line  = $prop . ':' . $val;
    $out   = '';
    $bytes = 0;
    $len   = strlen($line);
    for($i = 0; $i < $len; ){
        $b = ord($line[$i]);
        if     ($b < 0x80) $clen = 1;
        elseif ($b < 0xE0) $clen = 2;
        elseif ($b < 0xF0) $clen = 3;
        else               $clen = 4;
        $clen = min($clen, $len - $i);
        if($bytes + $clen > 75 && $bytes > 0){
            $out  .= "\r\n ";
            $bytes = 1;
        }
        $out   .= substr($line, $i, $clen);
        $bytes += $clen;
        $i     += $clen;
    }
    return $out . "\r\n";
}

// ════════════════════════════════════════════════════════════
//  BORROW REQUESTS
// ════════════════════════════════════════════════════════════
if($resource==='borrow'){

    // GET /borrow/requests — admin sees all, renter sees own
    if($method==='GET'&&$id==='requests'){
        $me=require_auth();
        try {
            // Create table if missing, add budget column if missing
            db()->exec("CREATE TABLE IF NOT EXISTS borrow_requests (
                id            VARCHAR(20)   PRIMARY KEY,
                user_id       VARCHAR(20)   NOT NULL,
                start_date    DATE          NOT NULL,
                end_date      DATE          NOT NULL,
                items         JSON          NOT NULL,
                contact_name  VARCHAR(255)  DEFAULT '',
                contact_email VARCHAR(255)  DEFAULT '',
                contact_phone VARCHAR(80)   DEFAULT '',
                contact_org   VARCHAR(255)  DEFAULT '',
                notes         TEXT          DEFAULT '',
                budget        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status        VARCHAR(20)   NOT NULL DEFAULT 'pending',
                reason        TEXT          DEFAULT '',
                resolved_by   VARCHAR(20)   DEFAULT NULL,
                archived      TINYINT(1)    NOT NULL DEFAULT 0,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB");
            // Add missing columns to existing installs
            $col_check=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrow_requests' AND COLUMN_NAME='budget'")->fetchColumn();
            if(!$col_check) db()->exec("ALTER TABLE borrow_requests ADD COLUMN budget DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            $col_arch=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrow_requests' AND COLUMN_NAME='archived'")->fetchColumn();
            if(!$col_arch) db()->exec("ALTER TABLE borrow_requests ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");

            $show_archived=(int)($_GET['archived']??0);
            $arch_clause=$show_archived?'':' AND (br.archived IS NULL OR br.archived=0)';

            if($me['role']==='admin'){
                $rows=db()->query(
                    "SELECT br.*, u.username, u.first_name, u.last_name
                     FROM borrow_requests br
                     JOIN users u ON u.id=br.user_id
                     WHERE 1=1{$arch_clause}
                     ORDER BY br.created_at DESC"
                )->fetchAll();
            } else {
                $st=db()->prepare(
                    "SELECT br.*, u.username, u.first_name, u.last_name
                     FROM borrow_requests br
                     JOIN users u ON u.id=br.user_id
                     WHERE br.user_id=?{$arch_clause} ORDER BY br.created_at DESC");
                $st->execute([$me['id']]);
                $rows=$st->fetchAll();
            }
            // Decode items JSON and enrich with live inventory names
            $inv_cache=[];
            $inv_st=db()->query('SELECT id,name,cat,asset_id,`condition` FROM inventory');
            foreach($inv_st->fetchAll() as $inv) $inv_cache[$inv['id']]=$inv;

            foreach($rows as &$r){
                $raw=json_decode($r['items'],true)??[];
                $r['items']=array_map(function($item) use($inv_cache){
                    $iid=$item['inventory_id']??($item['id']??'');
                    if($iid && isset($inv_cache[$iid])){
                        $inv=$inv_cache[$iid];
                        return [
                            'inventory_id'=>$iid,
                            'name'=>$inv['name'],
                            'cat' =>$inv['cat'],
                            'qty' =>$item['qty']??1,
                            'asset_id'=>$inv['asset_id']??'',
                        ];
                    }
                    // Legacy item with no inventory_id — keep as-is
                    return array_merge(['inventory_id'=>''],$item);
                },$raw);
            }
            json_out($rows);
        } catch(\Exception $e){
            json_out(['_error'=>$e->getMessage()]);
        }
    }

    // POST /borrow/admin-request — admin creates a request on behalf of a user
    if($method==='POST'&&$id==='admin-request'){
        require_role('admin');
        $b=body();
        $user_id = trim($b['user_id']??'');
        $name    = trim($b['name']  ?? '');
        $email   = trim($b['email'] ?? '');
        $phone   = trim($b['phone'] ?? '');
        $org     = trim($b['org']   ?? '');
        $start   = trim($b['start_date'] ?? '');
        $end     = trim($b['end_date']   ?? '');
        $items   = $b['items'] ?? [];
        $notes   = trim($b['notes'] ?? '');
        $budget  = (float)($b['budget'] ?? 0);

        if(!$user_id) err('user_id required');
        if(!$name)    err('Name is required');
        if(!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) err('Valid email required');
        if(!$start)   err('Start date required');
        if(!$end)     err('End date required');
        if(empty($items)) err('No items selected');

        // Verify user exists
        $usr=db()->prepare('SELECT id FROM users WHERE id=?'); $usr->execute([$user_id]);
        if(!$usr->fetch()) err('User not found',404);

        // Auto-add budget column if needed
        $col_check=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrow_requests' AND COLUMN_NAME='budget'")->fetchColumn();
        if(!$col_check) db()->exec("ALTER TABLE borrow_requests ADD COLUMN budget DECIMAL(10,2) NOT NULL DEFAULT 0.00");

        $rid=uid();
        db()->prepare(
            'INSERT INTO borrow_requests (id,user_id,start_date,end_date,items,contact_name,contact_email,contact_phone,contact_org,notes,budget,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$rid,$user_id,$start,$end,json_encode($items),$name,$email,$phone,$org,$notes,$budget,'pending']);

        json_out(['ok'=>true,'id'=>$rid]);
    }

    // POST /borrow/request — submit a new request
    if($method==='POST'&&$id==='request'){
        $me=require_auth();
        $b=body();
        $name  = trim($b['name']  ?? '');
        $email = trim($b['email'] ?? '');
        $phone = trim($b['phone'] ?? '');
        $org   = trim($b['org']   ?? '');
        $start = trim($b['start_date'] ?? '');
        $end   = trim($b['end_date']   ?? '');
        $items = $b['items'] ?? [];
        $notes = trim($b['notes'] ?? '');

        if(!$name)  err('Name is required');
        if(!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) err('Valid email required');
        if(!$start) err('Start date required');
        if(!$end)   err('End date required');
        if(empty($items)) err('No items selected');

        // Save to DB
        $rid=uid();
        $budget = (float)($b['budget'] ?? 0);
        // Auto-add budget column if missing (handles installs before this migration)
        $col_check=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrow_requests' AND COLUMN_NAME='budget'")->fetchColumn();
            if(!$col_check) db()->exec("ALTER TABLE borrow_requests ADD COLUMN budget DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        db()->prepare(
            'INSERT INTO borrow_requests (id,user_id,start_date,end_date,items,contact_name,contact_email,contact_phone,contact_org,notes,budget)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$rid,$me['id'],$start,$end,json_encode($items),$name,$email,$phone,$org,$notes,$budget]);

        // Resolve inventory names for items (items now store inventory_id + qty only)
        $inv_map=[];
        $iids=array_filter(array_map(fn($i)=>$i['inventory_id']??($i['id']??''),$items));
        if($iids){
            $ph=implode(',',array_fill(0,count($iids),'?'));
            $ist=db()->prepare("SELECT id,name,cat FROM inventory WHERE id IN ($ph)");
            $ist->execute(array_values($iids));
            foreach($ist->fetchAll() as $row) $inv_map[$row['id']]=$row;
        }
        $resolved=array_map(function($it) use($inv_map){
            $iid=$it['inventory_id']??($it['id']??'');
            $inv=$inv_map[$iid]??null;
            return ['name'=>$inv?$inv['name']:($it['name']??'Unknown item'),
                    'cat' =>$inv?$inv['cat'] :($it['cat']??'Other'),
                    'qty' =>$it['qty']??1];
        },$items);

        // Send notification email to owner
        $fmt=fn($d)=>(new DateTime($d))->format('M j, Y');
        $subject='Gear Borrow Request from '.$name;
        $item_list='';
        foreach($resolved as $it) $item_list.='  - '.$it['name'].' x'.(int)$it['qty']."\n";
        $txt="Gear borrow request\n\nFrom: {$name}\nEmail: {$email}\n"
            .($phone?"Phone: {$phone}\n":'').($org?"Org: {$org}\n":'')
            .($budget?"Budget: CA\${$budget}\n":'')
            ."\nDates: ".$fmt($start)." to ".$fmt($end)."\n\nItems:\n".$item_list
            .($notes?"\nNotes: {$notes}":'');

        $rows_htm='';
        foreach([['Name',$name],['Email',$email],['Phone',$phone],['Organisation',$org],
                 ['Budget',$budget?'CA$'.number_format((float)$budget,0):'']] as [$l,$v])
            if($v) $rows_htm.='<tr><td style="padding:4px 10px;color:#666">'.$l.'</td><td style="padding:4px 10px">'.htmlspecialchars($v).'</td></tr>';
        $rows_htm.='<tr><td style="padding:4px 10px;color:#666">Dates</td><td style="padding:4px 10px"><strong>'
            .$fmt($start).' to '.$fmt($end).'</strong></td></tr>';
        // Group by category for the email
        $by_cat=[];
        foreach($resolved as $it) $by_cat[$it['cat']??'Other'][]=$it;
        $il='<table style="border-collapse:collapse;width:100%;margin:6px 0">';
        foreach($by_cat as $cat=>$citems){
            $il.='<tr><td colspan="2" style="padding:6px 10px 2px;font-size:11px;font-weight:700;text-transform:uppercase;color:#888">'
                .htmlspecialchars($cat).'</td></tr>';
            foreach($citems as $it)
                $il.='<tr><td style="padding:2px 10px 2px 18px">'
                    .htmlspecialchars($it['name']).'</td>'
                    .'<td style="padding:2px 10px;color:#666;white-space:nowrap">&times;'.(int)$it['qty'].'</td></tr>';
        }
        $il.='</table>';
        $htm='<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#111;max-width:600px;margin:0 auto">'
            .'<h2 style="border-bottom:3px solid #f0a020;padding-bottom:8px">📦 Gear Borrow Request</h2>'
            .'<table style="border-collapse:collapse;margin-bottom:14px">'.$rows_htm.'</table>'
            .'<h3 style="margin:0 0 6px">Items Requested</h3>'.$il
            .($notes?'<p style="margin-top:12px"><strong>Notes:</strong> '.nl2br(htmlspecialchars($notes)).'</p>':'')
            .'<hr style="margin-top:20px;border:none;border-top:1px solid #eee">'
            .'<p style="color:#999;font-size:11px">Request ID: '.$rid.' · Sent via GRIP Gear Tracker</p>'
            .'</body></html>';

        $to=MAIL_FROM_ADDR; $from_addr=MAIL_FROM_ADDR;
        if(MAIL_DRIVER==='smtp'){
            $vendor=__DIR__.'/vendor/autoload.php';
            if(file_exists($vendor)){
                require $vendor;
                $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
                try{
                    $mail->isSMTP();$mail->Host=SMTP_HOST;$mail->SMTPAuth=true;
                    $mail->Username=SMTP_USER;$mail->Password=SMTP_PASS;
                    $mail->SMTPSecure=SMTP_SECURE==='ssl'?\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port=(int)SMTP_PORT;$mail->setFrom($from_addr,'GRIP Gear Tracker');
                    $mail->addAddress($to);$mail->addReplyTo($email,$name);
                    $mail->Subject=$subject;$mail->isHTML(true);$mail->Body=$htm;$mail->AltBody=$txt;
                    $mail->send();
                }catch(\Exception $e){ /* log but don't fail */ }
            }
        } else {
            $b2=bin2hex(random_bytes(12));$b3=bin2hex(random_bytes(12));
            $headers=implode("\r\n",['MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="'.$b2.'"',
                'From: GRIP Gear Tracker <'.$from_addr.'>',
                'Reply-To: '.$name.' <'.$email.'>']);
            $alt="--{$b3}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                .quoted_printable_encode($txt)."\r\n--{$b3}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                .quoted_printable_encode($htm)."\r\n--{$b3}--";
            $msg="--{$b2}\r\nContent-Type: multipart/alternative; boundary=\"{$b3}\"\r\n\r\n{$alt}\r\n--{$b2}--";
            @mail($to,$subject,$msg,$headers);
        }
        json_out(['ok'=>true,'id'=>$rid]);
    }

    // PUT /borrow/edit/{id} — admin full edit of a request
    if($method==='PUT'&&$id==='edit'&&($seg[2]??'')){
        require_role('admin');
        $eid=$seg[2];
        $b=body();
        $start  = trim($b['start_date']??'');
        $end    = trim($b['end_date']??'');
        $name   = trim($b['contact_name']??'');
        $email  = trim($b['contact_email']??'');
        $phone  = trim($b['contact_phone']??'');
        $org    = trim($b['contact_org']??'');
        $notes  = trim($b['notes']??'');
        $budget = (float)($b['budget']??0);
        $items  = $b['items']??null;

        $sets=[]; $vals=[];
        if($start){ $sets[]='start_date=?'; $vals[]=$start; }
        if($end)  { $sets[]='end_date=?';   $vals[]=$end; }
        $sets[]='contact_name=?';  $vals[]=$name;
        $sets[]='contact_email=?'; $vals[]=$email;
        $sets[]='contact_phone=?'; $vals[]=$phone;
        $sets[]='contact_org=?';   $vals[]=$org;
        $sets[]='notes=?';         $vals[]=$notes;
        $sets[]='budget=?';        $vals[]=$budget;
        if($items!==null){ $sets[]='items=?'; $vals[]=json_encode($items); }
        $vals[]=$eid;
        db()->prepare('UPDATE borrow_requests SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
        json_out(['ok'=>true]);
    }

    // PUT /borrow/requests/{id} — approve or deny
    if($method==='PUT'&&$id==='requests'&&($seg[2]??'')){
        $me=require_role('admin');
        $rid_to_resolve=$seg[2];
        $b=body();
        $status=trim($b['status']??'');
        $reason=trim($b['reason']??'');
        $notify=($b['notify']??true)!==false; // default true; pass notify:false to skip email
        if(!in_array($status,['approved','denied'])) err('Invalid status');

        $st=db()->prepare('SELECT * FROM borrow_requests WHERE id=?');
        $st->execute([$rid_to_resolve]);
        $req=$st->fetch();
        if(!$req) err('Request not found: '.$rid_to_resolve,404);

        db()->prepare('UPDATE borrow_requests SET status=?,reason=?,resolved_by=? WHERE id=?')
            ->execute([$status,$reason,$me['id'],$rid_to_resolve]);

        // Email the renter (only if notify flag is set)
        $to=trim($req['contact_email']??'');
        if($notify&&$to&&filter_var($to,FILTER_VALIDATE_EMAIL)){
            $fmt=fn($d)=>(new DateTime($d))->format('M j, Y');
            $approved=$status==='approved';
            $subject=($approved?'✅ Borrow Request Approved':'❌ Borrow Request Declined').' — GRIP Gear Tracker';
            $colour=$approved?'#2e7d32':'#c62828';
            $word=$approved?'Approved':'Declined';
            $items_raw=json_decode($req['items'],true)??[];
            // Resolve inventory names
            $inv_ids=array_filter(array_map(fn($i)=>$i['inventory_id']??($i['id']??''),$items_raw));
            $inv_map2=[];
            if($inv_ids){
                $ph2=implode(',',array_fill(0,count($inv_ids),'?'));
                $ist2=db()->prepare("SELECT id,name FROM inventory WHERE id IN ($ph2)");
                $ist2->execute(array_values($inv_ids));
                foreach($ist2->fetchAll() as $row) $inv_map2[$row['id']]=$row['name'];
            }
            $items=array_map(fn($i)=>['name'=>$inv_map2[$i['inventory_id']??($i['id']??'')]??($i['name']??'Unknown'),'qty'=>$i['qty']??1],$items_raw);
            $il='<ul style="margin:6px 0;padding-left:18px">';
            foreach($items as $it) $il.='<li>'.htmlspecialchars($it['name']).' &times;'.(int)$it['qty'].'</li>';
            $il.='</ul>';
            $reason_block=$reason?'<p style="margin-top:14px;padding:10px 14px;background:#f5f5f5;border-radius:4px;border-left:3px solid '.$colour.'"><strong>Message from Dan:</strong><br>'.nl2br(htmlspecialchars($reason)).'</p>':'';
            $htm='<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#111;max-width:600px;margin:0 auto">'
                .'<h2 style="border-bottom:3px solid '.$colour.';padding-bottom:8px;color:'.$colour.'">'.($approved?'✅':'❌').' Your Borrow Request has been '.$word.'</h2>'
                .'<p>Hi '.htmlspecialchars($req['contact_name']).', your request for the following items has been <strong>'.$word.'</strong>:</p>'
                .$il
                .'<p><strong>Dates:</strong> '.$fmt($req['start_date']).' → '.$fmt($req['end_date']).'</p>'
                .$reason_block
                .($approved?'<p style="margin-top:16px">Please confirm the pickup arrangements with '.htmlspecialchars(get_setting("owner_name","the owner")).'.</p>':'')
                .'<hr style="margin-top:20px;border:none;border-top:1px solid #eee"><p style="color:#999;font-size:11px">GRIP Gear Tracker</p>'
                .'</body></html>';
            $txt=($approved?'Your borrow request has been APPROVED':'Your borrow request has been DECLINED')."\n\n"
                .'Dates: '.$fmt($req['start_date']).' to '.$fmt($req['end_date'])."\n"
                .($reason?'Message: '.$reason."\n":'');

            $from_addr=MAIL_FROM_ADDR;
            if(MAIL_DRIVER==='smtp'){
                $vendor=__DIR__.'/vendor/autoload.php';
                if(file_exists($vendor)){
                    require $vendor;
                    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
                    try{
                        $mail->isSMTP();$mail->Host=SMTP_HOST;$mail->SMTPAuth=true;
                        $mail->Username=SMTP_USER;$mail->Password=SMTP_PASS;
                        $mail->SMTPSecure=SMTP_SECURE==='ssl'?\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port=(int)SMTP_PORT;$mail->setFrom($from_addr,'GRIP Gear Tracker');
                        $mail->addAddress($to);$mail->Subject=$subject;
                        $mail->isHTML(true);$mail->Body=$htm;$mail->AltBody=$txt;$mail->send();
                    }catch(\Exception $e){}
                }
            } else {
                $b2=bin2hex(random_bytes(12));$b3=bin2hex(random_bytes(12));
                $headers=implode("\r\n",['MIME-Version: 1.0',
                    'Content-Type: multipart/mixed; boundary="'.$b2.'"',
                    'From: GRIP Gear Tracker <'.$from_addr.'>']);
                $alt="--{$b3}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                    .quoted_printable_encode($txt)."\r\n--{$b3}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                    .quoted_printable_encode($htm)."\r\n--{$b3}--";
                $msg="--{$b2}\r\nContent-Type: multipart/alternative; boundary=\"{$b3}\"\r\n\r\n{$alt}\r\n--{$b2}--";
                @mail($to,$subject,$msg,$headers);
            }
        }
        json_out(['ok'=>true]);
    }

    // DELETE /borrow/requests/{id} — hard delete
    if($method==='DELETE'&&$id==='requests'&&($seg[2]??'')){
        require_role('admin');
        db()->prepare('DELETE FROM borrow_requests WHERE id=?')->execute([$seg[2]]);
        json_out(['ok'=>true]);
    }

    // PUT /borrow/archive/{id} — soft archive/unarchive
    if($method==='PUT'&&$id==='archive'&&($seg[2]??'')){
        require_role('admin');
        $archived=(int)(body()['archived']??1);
        // Auto-add archived column if missing
        $col=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrow_requests' AND COLUMN_NAME='archived'")->fetchColumn();
        if(!$col) db()->exec("ALTER TABLE borrow_requests ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
        db()->prepare('UPDATE borrow_requests SET archived=? WHERE id=?')->execute([$archived,$seg[2]]);
        json_out(['ok'=>true]);
    }

    err('Unknown borrow action',404);
}



// ══════════════════════════════════════════════════════════
//  SETTINGS
// ══════════════════════════════════════════════════════════
if($resource==='settings'){
    db()->exec("CREATE TABLE IF NOT EXISTS grip_settings (
        `key`   VARCHAR(80)  PRIMARY KEY,
        `value` TEXT         NOT NULL DEFAULT ''
    ) ENGINE=InnoDB");

    if($method==='GET'){
        require_auth();
        $rows = db()->query("SELECT `key`,`value` FROM grip_settings")->fetchAll();
        $out = [];
        foreach($rows as $r) $out[$r['key']] = $r['value'];
        json_out($out);
    }

    if($method==='POST'){
        require_role('admin');
        $b = body();
        $allowed = ['app_name','owner_name','owner_email','currency_symbol',
                    'borrow_intro','email_footer','date_format','categories_json'];
        $stmt = db()->prepare("INSERT INTO grip_settings (`key`,`value`) VALUES (?,?)
                               ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        foreach($allowed as $key){
            if(isset($b[$key])) $stmt->execute([$key, trim($b[$key])]);
        }
        json_out(['ok'=>true]);
    }
}

err('Not found',404);

// ════════════════════════════════════════════════════════════
//  job_html() — PHP port of buildJobHTML() from index.html
// ════════════════════════════════════════════════════════════
function job_html(array $job, string $message = ''): string {
    $CATS      = ['Camera','Lens','Light','Grip','Audio','Power','Support','Cable','Other'];
    $CAT_COLOR = [
        'Camera'=>'#f0a020','Lens'=>'#48b85c','Light'=>'#f0c040','Grip'=>'#7c7cff',
        'Audio'=>'#e04848','Power'=>'#20c0c0','Support'=>'#b060e0','Cable'=>'#888','Other'=>'#aaa',
    ];

    $today = date('F j, Y');

    // ── Aggregate totals ──────────────────────────────────
    $all_gear   = [];
    foreach($job['days'] as $d) $all_gear = array_merge($all_gear, $d['gear'] ?? []);
    $total_val   = array_sum(array_map(fn($g)=>((float)($g['value']??0))*((int)($g['qty']??1)), $all_gear));
    $total_units = array_sum(array_map(fn($g)=>(int)($g['qty']??1), $all_gear));
    $total_items = count($all_gear);
    $days_with_gear = count(array_filter($job['days'], fn($d)=>!empty($d['gear'])));

    // ── CSS (identical to JS version) ────────────────────
    $css = '
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:"Helvetica Neue",Arial,sans-serif;font-size:10pt;color:#111;background:#fff;line-height:1.45;}
    .wrap{max-width:760px;margin:0 auto;padding:24px;}
    .msg{font-family:Arial,sans-serif;font-size:10pt;color:#111;padding:0 0 20px;border-bottom:1px solid #ddd;margin-bottom:24px;line-height:1.6;}
    .doc-header{border-bottom:2.5px solid #111;padding-bottom:12px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-end;}
    .doc-title{font-size:20pt;font-weight:700;letter-spacing:-.02em;line-height:1;}
    .doc-sub{font-size:9pt;color:#555;margin-top:4px;}
    .doc-logo{font-size:7.5pt;color:#aaa;letter-spacing:.1em;text-transform:uppercase;text-align:right;line-height:1.5;}
    .meta-row{display:flex;flex-wrap:wrap;gap:6px 18px;margin-bottom:18px;font-size:9pt;color:#555;}
    .meta-row strong{color:#111;}
    .summary{border:1.5px solid #111;border-radius:3px;margin-bottom:20px;overflow:hidden;}
    .summary-label{font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#555;padding:7px 12px 0;border-bottom:1px solid #ddd;background:#f8f8f8;}
    .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);}
    .s-cell{padding:8px 12px;border-right:1px solid #ddd;}
    .s-cell:last-child{border-right:none;background:#111;color:#fff;}
    .s-val{font-size:14pt;font-weight:700;line-height:1;}
    .s-cell:last-child .s-val{font-size:17pt;color:#fff;}
    .s-lbl{font-size:7.5pt;color:#888;margin-top:3px;}
    .s-cell:last-child .s-lbl{color:#bbb;}
    .day-block{margin-bottom:28px;page-break-inside:avoid;}
    .day-head{background:#111;color:#fff;padding:7px 10px;display:flex;justify-content:space-between;align-items:baseline;}
    .day-name{font-size:10.5pt;font-weight:700;}
    .day-meta{font-size:8pt;color:#bbb;}
    table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-top:1px;}
    thead tr{background:#f0f0f0;}
    thead th{padding:5px 7px;text-align:left;font-size:7.5pt;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:1.5px solid #ccc;font-weight:600;}
    thead th.r{text-align:right;}
    tbody tr{border-bottom:1px solid #eee;}
    tbody tr:last-child{border-bottom:none;}
    tbody tr.out{background:#fff8f8;border-left:3px solid #c00;}
    tbody tr.in{border-left:3px solid transparent;}
    td{padding:5px 7px;vertical-align:middle;}
    td.name{font-weight:500;color:#111;}
    td.r{text-align:right;font-variant-numeric:tabular-nums;}
    td.status-in{color:#1b5e20;background:#e8f5e9;border-radius:3px;padding:2px 5px;font-size:7.5pt;font-weight:600;white-space:nowrap;}
    td.status-out{color:#b71c1c;background:#ffebee;border-radius:3px;padding:2px 5px;font-size:7.5pt;font-weight:600;white-space:nowrap;}
    .cat-row td{background:#f7f7f7;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#555;padding:4px 7px;border-top:1.5px solid #ccc;border-bottom:1px solid #ddd;}
    .cat-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle;}
    .day-total td{font-weight:700;font-size:8.5pt;background:#f0f0f0;border-top:1.5px solid #bbb;}
    .breakdown{margin-top:16px;page-break-inside:avoid;}
    .breakdown caption{font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#555;padding-bottom:5px;text-align:left;}
    .breakdown th{background:#f0f0f0;font-size:7.5pt;font-weight:700;text-transform:uppercase;padding:4px 8px;border:1px solid #ddd;letter-spacing:.04em;}
    .breakdown th.r{text-align:right;}
    .breakdown td{font-size:8.5pt;padding:4px 8px;border:1px solid #eee;}
    .breakdown td.r{text-align:right;font-variant-numeric:tabular-nums;}
    .breakdown .tot td{font-weight:700;background:#111;color:#fff;border-color:#111;}
    .doc-footer{margin-top:22px;padding-top:8px;border-top:1px solid #ccc;font-size:7.5pt;color:#aaa;display:flex;justify-content:space-between;}
    .job-notes{background:#fffbf0;border:1px solid #f0d080;border-radius:3px;padding:8px 10px;margin-bottom:16px;font-size:8.5pt;color:#555;}
    .job-notes strong{color:#111;}
    ';

    // ── Helper: format CAD value ──────────────────────────
    $fmt = fn(float $v):string => $v ? '$'.number_format($v, 0, '.', ',') : '—';

    // ── Helper: format date ───────────────────────────────
    $fmt_date = function(string $iso): string {
        if(!$iso) return '';
        try { return (new DateTime($iso))->format('M j, Y'); } catch(\Exception $e){ return $iso; }
    };

    // ── Covering message ──────────────────────────────────
    $msg_html = '';
    if($message !== ''){
        $safe = nl2br(htmlspecialchars($message, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
        $msg_html = '<div class="msg">'.$safe.'</div>';
    }

    // ── Days HTML ─────────────────────────────────────────
    $days_html = '';
    foreach($job['days'] as $d){
        $gear = $d['gear'] ?? [];
        if(!$gear) continue;

        // Group by category
        $grouped = [];
        foreach($CATS as $cat){
            $items = array_values(array_filter($gear, fn($g)=>($g['cat']??'Other')===$cat));
            if($items) $grouped[$cat] = $items;
        }

        $d_val   = array_sum(array_map(fn($g)=>((float)($g['value']??0))*((int)($g['qty']??1)), $gear));
        $d_units = array_sum(array_map(fn($g)=>(int)($g['qty']??1), $gear));
        $d_out   = count(array_filter($gear, fn($g)=>($g['status']??'in')==='out'));

        $rows = '';
        foreach($grouped as $cat => $items){
            $col = $CAT_COLOR[$cat] ?? '#aaa';
            $rows .= '<tr class="cat-row"><td colspan="6">'
                   . '<span class="cat-dot" style="background:'.$col.'"></span>'
                   . htmlspecialchars($cat).'</td></tr>';

            foreach($items as $g){
                $name  = htmlspecialchars($g['name'] ?? '');
                $asset = htmlspecialchars($g['asset_id'] ?? '');
                $notes = htmlspecialchars($g['notes'] ?? '');
                $cond  = $g['condition'] ?? 'Good';
                $qty   = (int)($g['qty'] ?? 1);
                $val   = (float)($g['value'] ?? 0);
                $status = ($g['status'] ?? 'in') === 'out' ? 'out' : 'in';
                $status_class = $status === 'out' ? 'status-out' : 'status-in';
                $status_label = $status === 'out' ? 'OUT' : 'IN';

                $name_cell = $name;
                if($notes) $name_cell .= '<br><span style="font-size:7.5pt;color:#888;font-weight:400">↳ '.$notes.'</span>';
                if($cond && $cond !== 'Good') $name_cell .= ' <span style="font-size:7pt;color:#e04848">('.$cond.')</span>';

                $rows .= '<tr class="'.$status.'">
                    <td class="name">'.$name_cell.'</td>
                    <td style="color:#888;font-size:8pt;">'.($asset ?: '—').'</td>
                    <td class="r">'.$qty.'</td>
                    <td class="r">'.($val ? $fmt($val) : '—').'</td>
                    <td class="r">'.($val ? $fmt($val * $qty) : '—').'</td>
                    <td style="text-align:center"><span class="'.$status_class.'">'.$status_label.'</span></td>
                </tr>';
            }
        }

        // Day total row
        $rows .= '<tr class="day-total">
            <td colspan="2">Day Total</td>
            <td class="r">'.$d_units.'</td>
            <td></td>
            <td class="r">'.($d_val ? $fmt($d_val) : '—').'</td>
            <td style="text-align:center;font-size:7.5pt;color:#888">'.($d_out ? $d_out.' out' : '').'</td>
        </tr>';

        $day_meta_parts = array_filter([$d['date'] ? $fmt_date($d['date']) : '', $d['location'] ?? '']);
        $day_meta = implode(' · ', $day_meta_parts);

        $days_html .= '<div class="day-block">
            <div class="day-head">
                <div class="day-name">'.htmlspecialchars($d['label'] ?? '').'</div>
                <div class="day-meta">'.htmlspecialchars($day_meta).'</div>
            </div>
            <table>
                <thead><tr>
                    <th>Item</th><th>Asset ID</th><th class="r">Qty</th>
                    <th class="r">Unit (CAD)</th><th class="r">Total (CAD)</th>
                    <th style="text-align:center">Status</th>
                </tr></thead>
                <tbody>'.$rows.'</tbody>
            </table>
        </div>';
    }

    // ── Category breakdown ────────────────────────────────
    $breakdown_rows = '';
    foreach($CATS as $cat){
        $items = array_filter($all_gear, fn($g)=>($g['cat']??'Other')===$cat);
        if(!$items) continue;
        $units = array_sum(array_map(fn($g)=>(int)($g['qty']??1), $items));
        $val   = array_sum(array_map(fn($g)=>((float)($g['value']??0))*((int)($g['qty']??1)), $items));
        $col   = $CAT_COLOR[$cat] ?? '#aaa';
        $breakdown_rows .= '<tr>
            <td><span class="cat-dot" style="background:'.$col.'"></span>'.htmlspecialchars($cat).'</td>
            <td class="r">'.count($items).'</td>
            <td class="r">'.$units.'</td>
            <td class="r">'.($val ? $fmt($val) : '—').'</td>
        </tr>';
    }
    $breakdown_html = $breakdown_rows ? '
        <table class="breakdown">
            <caption>Equipment Value by Category</caption>
            <thead><tr><th>Category</th><th class="r">Items</th><th class="r">Units</th><th class="r">Value (CAD)</th></tr></thead>
            <tbody>'.$breakdown_rows.'</tbody>
            <tfoot><tr class="tot">
                <td>TOTAL</td>
                <td class="r">'.$total_items.'</td>
                <td class="r">'.$total_units.'</td>
                <td class="r">$'.number_format($total_val, 0, '.', ',').'</td>
            </tr></tfoot>
        </table>' : '';

    // ── Meta row ──────────────────────────────────────────
    $meta_parts = [];
    if(!empty($job['director'])) $meta_parts[] = '<span><strong>Director:</strong> '.htmlspecialchars($job['director']).'</span>';
    if(!empty($job['co']))       $meta_parts[] = '<span><strong>Production Co:</strong> '.htmlspecialchars($job['co']).'</span>';
    $meta_parts[] = '<span><strong>Days:</strong> '.$days_with_gear.'</span>';
    $meta_parts[] = '<span><strong>Prepared:</strong> '.$today.'</span>';

    $notes_html = !empty($job['notes'])
        ? '<div class="job-notes"><strong>Notes:</strong> '.htmlspecialchars($job['notes']).'</div>'
        : '';

    $job_name = htmlspecialchars($job['name'] ?? 'Untitled');
    $total_val_fmt = '$'.number_format($total_val, 0, '.', ',');

    // ── Assemble ──────────────────────────────────────────
    return '<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>'.$job_name.' — GRIP Equipment List</title>
<style>'.$css.'</style>
</head><body><div class="wrap">

'.$msg_html.'

<div class="doc-header">
    <div>
        <div class="doc-title">'.$job_name.'</div>
        <div class="doc-sub">Equipment List &amp; Insurance Schedule</div>
    </div>
    <div class="doc-logo">GRIP<br>Gear Tracker<br>'.$today.'</div>
</div>

<div class="meta-row">'.implode('', $meta_parts).'</div>

'.$notes_html.'

<div class="summary">
    <div class="summary-label">Summary</div>
    <div class="summary-grid">
        <div class="s-cell"><div class="s-val">'.$total_items.'</div><div class="s-lbl">Line Items</div></div>
        <div class="s-cell"><div class="s-val">'.$total_units.'</div><div class="s-lbl">Total Units</div></div>
        <div class="s-cell"><div class="s-val">'.$days_with_gear.'</div><div class="s-lbl">Shoot Days</div></div>
        <div class="s-cell"><div class="s-val">'.$total_val_fmt.'</div><div class="s-lbl">Total Insured Value (CAD)</div></div>
    </div>
</div>

'.$days_html.'

'.$breakdown_html.'

<div class="doc-footer">
    <span>GRIP Gear Tracker &middot; '.$job_name.'</span>
    <span>Total Insured Value: '.$total_val_fmt.' CAD</span>
</div>

</div></body></html>';
}


function gear_row(array $r):array{return['id'=>$r['id'],'name'=>$r['name'],'cat'=>$r['cat'],'assetId'=>$r['asset_id'],'qty'=>(int)$r['qty'],'value'=>(float)$r['value'],'notes'=>$r['notes'],'status'=>$r['status'],'condition'=>$r['condition']??'Good'];}
function inv_row(array $r):array{return['id'=>$r['id'],'name'=>$r['name'],'cat'=>$r['cat'],'assetId'=>$r['asset_id'],'qty'=>(int)$r['qty'],'value'=>(float)$r['value'],'notes'=>$r['notes'],'condition'=>$r['condition']??'Good'];}
