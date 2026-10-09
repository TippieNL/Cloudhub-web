<?php
declare(strict_types=1);
namespace CloudHub\Services;
use PDO;
use CloudHub\Helpers\Http;

final class Auth {
    /**
     * How long a rotated-away session ID keeps working.
     *
     * Long enough for requests already in flight when the rotation happened,
     * short enough that a stolen predecessor ID is worth little.
     */
    private const ROTATION_GRACE_SECONDS = 60;

    /**
     * How long a cached role/enabled flag is trusted before it is re-read.
     *
     * The trade-off is staleness against a database query per request.
     */
    private const ACCOUNT_RECHECK_SECONDS = 60;

    public static function startSession(array $config): void {
        if(session_status()===PHP_SESSION_ACTIVE)return;
        $secure=Security::isHttps($config);
        ini_set('session.use_only_cookies','1');
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_trans_sid','0');

        /*
         * How long a signed-in session is meant to survive.
         *
         * Zero disables an expiry, and is read before the floors below, because
         * max(300, 0) is 300 -- a floor applied first would turn "never expire"
         * into a five-minute timeout, which is the opposite of what it was
         * asked for. A positive value keeps its floor and behaves as it always
         * did, so the capability is switched off rather than removed.
         */
        $idle=(int)($config['session_idle_seconds']??0);
        $absolute=(int)($config['session_absolute_seconds']??0);
        $idle=$idle>0?max(300,$idle):0;
        $absolute=$absolute>0?max($idle,$absolute):0;

        /*
         * The window the session may live in when nothing expires it.
         *
         * "Never" still needs a number here. gc_maxlifetime governs when PHP
         * deletes the session file, and it was never set -- so the default,
         * commonly twenty-four minutes, reaped sessions long before any of the
         * checks below had an opinion. Removing those checks alone would have
         * changed nothing anyone could notice.
         */
        $window=$absolute>0?$absolute:max(3600,(int)($config['session_lifetime_days']??30)*86400);
        ini_set('session.gc_maxlifetime',(string)$window);

        // Do not force session.save_path into Android shared storage.
        // PHP's files session handler performs UID ownership checks there and
        // can reject its own session files ("not created by your uid").
        // Use PHP's configured session handler/path instead.
        session_name('cloudhub_session');
        /*
         * A real cookie lifetime, not 0.
         *
         * 0 makes a browser-session cookie, which is discarded when the browser
         * closes -- and Android closes browsers on its own schedule, so that
         * alone signed people out repeatedly however long the server was
         * willing to keep the session. session_regenerate_id() reissues the
         * cookie from these parameters, so a rotated session keeps the lifetime
         * rather than reverting to a session cookie.
         */
        session_set_cookie_params(['lifetime'=>$window,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>(string)($config['session_samesite']??'Lax')]);
        session_start();

        $now=time();
        $expired=($absolute>0&&isset($_SESSION['created_at'])&&$now-(int)$_SESSION['created_at']>$absolute)
            ||($idle>0&&isset($_SESSION['last_seen_at'])&&$now-(int)$_SESSION['last_seen_at']>$idle);
        if($expired){
            self::destroySession();session_start();
        }
        $_SESSION['created_at']??=$now;$_SESSION['csrf']??=bin2hex(random_bytes(32));
        /*
         * Only move last_seen_at when it has actually aged, and not at all when
         * nothing reads it.
         *
         * session.lazy_write (on by default) skips writing the session file
         * when nothing in $_SESSION changed -- but stamping the clock on every
         * request changed it every time, so a gallery load's ~240 requests
         * meant ~240 session writes to Android flash. A minute of granularity
         * is invisible to the idle check above, whose floor is 300 seconds; and
         * with that check disabled the value has no reader at all, so writing
         * it would be a session write per minute for nothing.
         */
        if($idle>0&&$now-(int)($_SESSION['last_seen_at']??0)>=60)$_SESSION['last_seen_at']=$now;
        // A session carried forward from a rotation is only valid for the short
        // grace window below; once it lapses the successor ID is the only one
        // accepted. Requests still holding the old ID are shown the door here
        // rather than being silently handed an empty session.
        if(isset($_SESSION['obsolete_after'])&&$now>(int)$_SESSION['obsolete_after']){
            self::destroySession();session_start();
            $_SESSION['created_at']=$now;$_SESSION['last_seen_at']=$now;$_SESSION['csrf']=bin2hex(random_bytes(32));
        }

        $rotate=max(300,(int)($config['session_rotate_seconds']??900));
        $_SESSION['rotated_at']??=$now;
        if(isset($_SESSION['user_id'])&&!isset($_SESSION['obsolete_after'])&&$now-(int)$_SESSION['rotated_at']>=$rotate){
            // Do not delete the old session file: the file list fires many
            // parallel requests (one per image thumbnail, plus media streams),
            // and any sibling already queued on the previous ID would find
            // nothing, start an empty session under use_strict_mode, and get a
            // surprise 401. Stamp the predecessor with an expiry, keep it
            // readable for that grace period so in-flight requests still
            // authenticate, and let the check above retire it afterwards.
            $_SESSION['obsolete_after']=$now+self::ROTATION_GRACE_SECONDS;
            session_regenerate_id(false);
            unset($_SESSION['obsolete_after']);
            $_SESSION['rotated_at']=$now;
        }

        self::revalidateAccount($now);
    }

    /**
     * Re-read the signed-in account, at most once a minute.
     *
     * The role is cached in the session at login, so without this an account
     * that an administrator disables or demotes keeps its old access until the
     * person happens to sign out.
     *
     * Checking on every request would mean a database query per request --
     * including the forty-odd thumbnail requests a gallery fires, which
     * otherwise touch no database at all. Throttling bounds the stale window to
     * ACCOUNT_RECHECK_SECONDS at roughly one query per user per minute.
     *
     * Best effort: if the database is unreachable the session is left alone
     * rather than signing everybody out.
     */
    private static function revalidateAccount(int $now): void {
        if(!isset($_SESSION['user_id']))return;
        $last=(int)($_SESSION['account_checked_at']??0);
        if($last!==0&&$now-$last<self::ACCOUNT_RECHECK_SECONDS)return;

        try{
            $pdo=\CloudHub\Helpers\Db::connection();
            $status=(new \CloudHub\Repositories\UserRepository($pdo))->status((int)$_SESSION['user_id']);
        }catch(\Throwable $e){
            error_log('[auth] account revalidation skipped: '.$e->getMessage());
            return;
        }

        // Deleted or disabled: end the session now rather than at next sign-in.
        if($status===null||!$status['isActive']){
            self::destroySession();
            session_start();
            $_SESSION['created_at']=$now;$_SESSION['last_seen_at']=$now;$_SESSION['csrf']=bin2hex(random_bytes(32));
            return;
        }
        /*
         * Two-step verification was turned on after this session signed in
         * with a password alone -- on another device, typically, or before the
         * owner noticed someone else had the password. A password is no longer
         * enough for this account, so it is no longer enough to stay signed in
         * either: the session ends as a disabled account's does, and signing in
         * again asks for the code. The session that turned it on proved the
         * phone while doing so and is marked as verified.
         */
        if(!empty($status['twoFactor'])&&empty($_SESSION['two_factor_verified_at'])){
            self::destroySession();
            session_start();
            $_SESSION['created_at']=$now;$_SESSION['last_seen_at']=$now;$_SESSION['csrf']=bin2hex(random_bytes(32));
            return;
        }

        $_SESSION['role']=$status['role'];
        $_SESSION['account_checked_at']=$now;
    }
    /**
     * The password algorithm this build prefers.
     *
     * Argon2id where the runtime provides it, otherwise whatever PHP considers
     * current. Kept in one place so account creation, admin password resets and
     * the rehash-on-login path cannot drift apart.
     */
    public static function passwordAlgorithm(): string|int {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hashPassword(string $password): string {
        $hash = password_hash($password, self::passwordAlgorithm());
        if (!is_string($hash) || $hash === '') throw new \RuntimeException('Unable to hash the password', 500);
        return $hash;
    }

    public static function user(): ?array {return isset($_SESSION['user_id'])?['id'=>(int)$_SESSION['user_id'],'username'=>(string)($_SESSION['username']??''),'role'=>(string)($_SESSION['role']??'viewer')]:null;}
    public static function requireUser(): void {if(!self::user())Http::error(401,'UNAUTHORIZED','Authentication required');}
    public static function verifyCsrf(): void {Security::verifyCsrfRequest();}

    /**
     * How long a sign-in whose password was right waits for its SMS code
     * before the password has to be entered again.
     */
    public const SECOND_FACTOR_WINDOW = 900;

    /**
     * Check a username and password, and sign the account in when that is
     * all it takes.
     *
     * True means the session is now signed in. False means it is not -- which
     * is also the answer for an account with SMS two-step verification whose
     * password was right: the session then gets a fresh ID and CSRF token and
     * remembers which account is waiting for its code (pendingSecondFactor()),
     * but holds no user_id, so every route, WebDAV included, still treats it
     * as signed out. Only finishSecondFactor(), after the code has been
     * checked, signs it in. A caller that reads false as "refused" is
     * therefore never wrong in the dangerous direction.
     */
    public static function login(PDO $pdo,string $username,string $password): bool {
        // A new attempt abandons any sign-in still waiting for its code.
        unset($_SESSION['two_factor_login']);
        try {
            $stmt=$pdo->prepare('SELECT id, username, password_hash, is_active, role FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
        } catch (\PDOException $e) {
            // Compatibility with pre-Phase-6 databases. The migration should
            // still be applied; this fallback prevents login from hard-failing.
            if((string)$e->getCode()!=='42S22'&&!str_contains($e->getMessage(),"Unknown column 'role'"))throw $e;
            $stmt=$pdo->prepare("SELECT id, username, password_hash, is_active, 'viewer' AS role FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
        }
        $user=$stmt->fetch(PDO::FETCH_ASSOC);
        // An unknown username costs one hash with the algorithm real accounts
        // use, as their password_verify() does. Answering it with no hash work
        // at all told a stopwatch which usernames exist.
        if(!$user){self::hashPassword($password);return false;}
        if(!(bool)$user['is_active']||!password_verify($password,(string)$user['password_hash']))return false;
        // The password is proven either way, so its hash can be upgraded now.
        if(password_needs_rehash((string)$user['password_hash'],self::passwordAlgorithm())){
            $hash=self::hashPassword($password);$q=$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');$q->execute([$hash,(int)$user['id']]);
        }
        /*
         * Half-way, for an account with two-step verification. The session is
         * emptied and given a new ID before it remembers anything: whatever it
         * held before -- another account's sign-in, a fixated ID -- is not
         * carried into a session that has proven a password.
         *
         * A database error here propagates and the sign-in fails. Only a
         * database without the columns at all reads as "off" (see
         * TwoFactorRepository::state()), because nobody could have turned it
         * on there.
         */
        if((new \CloudHub\Repositories\TwoFactorRepository($pdo))->requiredFor((int)$user['id'])){
            session_regenerate_id(true);$now=time();
            $_SESSION=['created_at'=>$now,'last_seen_at'=>$now,'rotated_at'=>$now,'csrf'=>bin2hex(random_bytes(32)),
                'two_factor_login'=>['user'=>(int)$user['id'],'username'=>(string)$user['username'],'at'=>$now]];
            return false;
        }
        self::establish($pdo,$user,false);
        return true;
    }

    /**
     * The sign-in in this session that has proven its password and is waiting
     * for its SMS code, or null. Lapses after SECOND_FACTOR_WINDOW.
     *
     * @return array{user:int,username:string,at:int,challenge?:string}|null
     */
    public static function pendingSecondFactor(): ?array {
        $pending=$_SESSION['two_factor_login']??null;
        if(!is_array($pending)||!isset($pending['user'],$pending['at']))return null;
        if(time()-(int)$pending['at']>self::SECOND_FACTOR_WINDOW){unset($_SESSION['two_factor_login']);return null;}
        return $pending;
    }

    /** Forget the sign-in waiting for its code. */
    public static function abandonSecondFactor(): void {unset($_SESSION['two_factor_login']);}

    /**
     * Sign in the account whose second factor the caller has just verified.
     *
     * This can only ever finish what login() started: with no pending sign-in
     * -- no password proven in this session, or proven too long ago -- it does
     * nothing and returns null. The account is read again, so one disabled in
     * the meantime is not signed in, and the role is the current one.
     */
    public static function finishSecondFactor(PDO $pdo): ?array {
        $pending=self::pendingSecondFactor();
        if($pending===null)return null;
        unset($_SESSION['two_factor_login']);
        $stmt=$pdo->prepare('SELECT id, username, is_active, role FROM users WHERE id = ?');
        $stmt->execute([(int)$pending['user']]);
        $user=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$user||!(bool)$user['is_active'])return null;
        self::establish($pdo,$user,true);
        return self::user();
    }

    /**
     * Make this session the account's: a new ID, a new CSRF token, and the
     * account's identity and role.
     *
     * two_factor_verified_at records that this session passed a second
     * factor. When an account turns two-step verification on, it is what
     * decides which of its sessions keep going (see revalidateAccount()).
     */
    private static function establish(PDO $pdo,array $user,bool $secondFactor): void {
        session_regenerate_id(true);$now=time();
        unset($_SESSION['two_factor_login'],$_SESSION['two_factor_action']);
        $_SESSION['user_id']=(int)$user['id'];$_SESSION['username']=(string)$user['username'];$_SESSION['role']=(string)($user['role']??'viewer');$_SESSION['created_at']=$now;$_SESSION['last_seen_at']=$now;$_SESSION['rotated_at']=$now;$_SESSION['account_checked_at']=$now;$_SESSION['csrf']=bin2hex(random_bytes(32));
        if($secondFactor)$_SESSION['two_factor_verified_at']=$now;else unset($_SESSION['two_factor_verified_at']);
        $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$user['id']]);
    }
    public static function logout(): void {self::destroySession();}
    private static function destroySession(): void {
        $_SESSION=[];
        if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path']?:'/','domain'=>$p['domain']??'','secure'=>(bool)($p['secure']??false),'httponly'=>true,'samesite'=>(string)($p['samesite']??'Lax')]);}
        if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
    }
}
