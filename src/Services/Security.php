<?php
declare(strict_types=1);
namespace CloudHub\Services;
use CloudHub\Helpers\Http;

/** Central browser/request security policy shared by all routes. */
final class Security {
    private static ?string $cspNonce=null;

    public static function isHttps(array $config): bool {
        if(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')return true;
        if(($config['trust_proxy']??false)===true){
            $proto=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]));
            if($proto==='https')return true;
        }
        return (bool)($config['https_enabled']??false);
    }

    public static function cspNonce(): string {
        return self::$cspNonce??=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
    }

    public static function applyHeaders(array $config): void {
        if(headers_sent())return;
        $nonce=self::cspNonce();
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        // SAMEORIGIN rather than DENY: the preview dialog renders PDFs in a
        // same-origin <iframe>, and DENY blocks same-origin framing too, so the
        // PDF pane was always empty. Cross-origin framing stays blocked.
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'nonce-".$nonce."'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; frame-src 'self' blob:; connect-src 'self'; worker-src 'self' blob:");
        if(self::isHttps($config)&&($config['hsts_enabled']??false)){
            header('Strict-Transport-Security: max-age='.max(0,(int)($config['hsts_max_age']??31536000)).'; includeSubDomains');
        }
    }

    public static function assertProductionConfig(array $config): void {
        if(($config['app_env']??'production')!=='production')return;
        if(($config['require_https']??false)&&!self::isHttps($config)){
            Http::error(503,'HTTPS_REQUIRED','HTTPS is required by the production configuration');
        }
        if(($config['trust_proxy']??false)&&empty($config['app_url'])){
            throw new \RuntimeException('APP_URL must be configured when TRUST_PROXY=true');
        }
    }

    public static function verifyCsrfRequest(): void {
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(in_array($method,['GET','HEAD','OPTIONS'],true))return;
        if(strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''))==='cross-site'){
            Http::error(403,'CROSS_SITE_REQUEST','Cross-site state-changing requests are not allowed');
        }
        $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
        if(!is_string($token)||!isset($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],$token)){
            Http::error(419,'CSRF_FAILED','Invalid or expired CSRF token');
        }
    }
}
