<?php

/*
 * 问界会员登录安全组件。
 *
 * - 滑块验证只保存于当前 PHP 会话，短时、一次性使用。
 * - TOTP 遵循 RFC 4226 / RFC 6238：HMAC-SHA1、6 位、30 秒时间步。
 * - TOTP 密钥使用站点根目录外的独立 32 字节密钥加密后入库。
 */

if (!function_exists('feiniao_auth_base64url_encode')) {
    function feiniao_auth_base64url_encode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('feiniao_auth_base64url_decode')) {
    function feiniao_auth_base64url_decode($value)
    {
        $value = strtr((string)$value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($value, true);
    }
}

if (!function_exists('feiniao_auth_master_key')) {
    function feiniao_auth_master_key()
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $path = getenv('WENJIE_AUTH_KEY_FILE');
        if (!$path) {
            $path = '/www/wwwroot/wenjie/.secrets/auth-security.key';
        }
        $encoded = is_file($path) ? trim((string)@file_get_contents($path)) : '';
        $decoded = $encoded !== '' ? base64_decode($encoded, true) : false;
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('登录安全密钥不可用');
        }
        $key = $decoded;
        return $key;
    }
}

if (!function_exists('feiniao_auth_encrypt')) {
    function feiniao_auth_encrypt($plaintext)
    {
        $plaintext = (string)$plaintext;
        $key = feiniao_auth_master_key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return 's1.' . feiniao_auth_base64url_encode($nonce . $ciphertext);
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'wenjie-totp-v1');
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('登录安全数据加密失败');
        }
        return 'g1.' . feiniao_auth_base64url_encode($iv . $tag . $ciphertext);
    }
}

if (!function_exists('feiniao_auth_decrypt')) {
    function feiniao_auth_decrypt($encoded)
    {
        $encoded = (string)$encoded;
        $key = feiniao_auth_master_key();
        if (strpos($encoded, 's1.') === 0 && function_exists('sodium_crypto_secretbox_open')) {
            $raw = feiniao_auth_base64url_decode(substr($encoded, 3));
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return false;
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        }
        if (strpos($encoded, 'g1.') === 0) {
            $raw = feiniao_auth_base64url_decode(substr($encoded, 3));
            if ($raw === false || strlen($raw) <= 28) {
                return false;
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            return openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'wenjie-totp-v1');
        }
        return false;
    }
}

if (!function_exists('feiniao_auth_db')) {
    function feiniao_auth_db()
    {
        global $dbconn;
        return isset($dbconn) && $dbconn instanceof mysqli ? $dbconn : null;
    }
}

if (!function_exists('feiniao_auth_user_row')) {
    function feiniao_auth_user_row($userid)
    {
        $db = feiniao_auth_db();
        if (!$db) {
            return false;
        }
        $sql = 'SELECT id, userid, userpass, username, totp_enabled, totp_secret_enc, totp_last_counter '
             . 'FROM fn_user WHERE userid=? ORDER BY roomid DESC, id DESC LIMIT 1';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $userid = (string)$userid;
        $stmt->bind_param('s', $userid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : false;
        $stmt->close();
        return $row ?: false;
    }
}

if (!function_exists('feiniao_auth_password_verify')) {
    function feiniao_auth_password_verify($plain, $stored)
    {
        $plain = (string)$plain;
        $stored = (string)$stored;
        if ($stored !== '' && $stored[0] === '$') {
            return password_verify($plain, $stored);
        }
        return $stored !== '' && hash_equals($stored, md5($plain));
    }
}

if (!function_exists('feiniao_totp_base32_encode')) {
    function feiniao_totp_base32_encode($data)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $out = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= $alphabet[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        }
        return $out;
    }
}

if (!function_exists('feiniao_totp_base32_decode')) {
    function feiniao_totp_base32_decode($secret)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', (string)$secret));
        $buffer = 0;
        $bits = 0;
        $out = '';
        for ($i = 0; $i < strlen($secret); $i++) {
            $value = strpos($alphabet, $secret[$i]);
            if ($value === false) {
                return false;
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 255);
            }
        }
        return $out;
    }
}

if (!function_exists('feiniao_totp_secret')) {
    function feiniao_totp_secret()
    {
        return feiniao_totp_base32_encode(random_bytes(20));
    }
}

if (!function_exists('feiniao_totp_code_for_counter')) {
    function feiniao_totp_code_for_counter($secret, $counter)
    {
        $key = feiniao_totp_base32_decode($secret);
        if ($key === false || $key === '') {
            return '';
        }
        $counter = (int)$counter;
        $high = (int)floor($counter / 4294967296);
        $low = $counter & 0xffffffff;
        $message = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
               | ((ord($hash[$offset + 1]) & 0xff) << 16)
               | ((ord($hash[$offset + 2]) & 0xff) << 8)
               | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('feiniao_totp_match_counter')) {
    function feiniao_totp_match_counter($secret, $code, $window = 1, $at = null)
    {
        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== 6) {
            return false;
        }
        $time = $at === null ? time() : (int)$at;
        $counter = (int)floor($time / 30);
        for ($offset = -abs((int)$window); $offset <= abs((int)$window); $offset++) {
            $candidate = $counter + $offset;
            if ($candidate >= 0 && hash_equals(feiniao_totp_code_for_counter($secret, $candidate), $code)) {
                return $candidate;
            }
        }
        return false;
    }
}

if (!function_exists('feiniao_totp_uri')) {
    function feiniao_totp_uri($userid, $secret)
    {
        $issuer = '问界';
        $label = rawurlencode($issuer) . ':' . rawurlencode((string)$userid);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
             . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}

if (!function_exists('feiniao_totp_user_enabled')) {
    function feiniao_totp_user_enabled($userid)
    {
        $row = feiniao_auth_user_row($userid);
        return $row && (int)$row['totp_enabled'] === 1 && !empty($row['totp_secret_enc']);
    }
}

if (!function_exists('feiniao_totp_decrypt_row_secret')) {
    function feiniao_totp_decrypt_row_secret($row)
    {
        if (!$row || empty($row['totp_secret_enc'])) {
            return false;
        }
        try {
            return feiniao_auth_decrypt($row['totp_secret_enc']);
        } catch (Throwable $e) {
            error_log('Wenjie TOTP decrypt failed');
            return false;
        }
    }
}

if (!function_exists('feiniao_totp_enable_for_user')) {
    function feiniao_totp_enable_for_user($userid, $secret, $counter)
    {
        $db = feiniao_auth_db();
        if (!$db) {
            return false;
        }
        try {
            $encrypted = feiniao_auth_encrypt($secret);
        } catch (Throwable $e) {
            error_log('Wenjie TOTP encrypt failed');
            return false;
        }
        $stmt = $db->prepare('UPDATE fn_user SET totp_enabled=1, totp_secret_enc=?, totp_last_counter=?, totp_updated_at=NOW() WHERE userid=?');
        if (!$stmt) {
            return false;
        }
        $counter = (int)$counter;
        $userid = (string)$userid;
        $stmt->bind_param('sis', $encrypted, $counter, $userid);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('feiniao_totp_disable_for_user')) {
    function feiniao_totp_disable_for_user($userid)
    {
        $db = feiniao_auth_db();
        if (!$db) {
            return false;
        }
        $stmt = $db->prepare('UPDATE fn_user SET totp_enabled=0, totp_secret_enc=NULL, totp_last_counter=NULL, totp_updated_at=NOW() WHERE userid=?');
        if (!$stmt) {
            return false;
        }
        $userid = (string)$userid;
        $stmt->bind_param('s', $userid);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('feiniao_totp_consume_counter')) {
    function feiniao_totp_consume_counter($userid, $counter)
    {
        $db = feiniao_auth_db();
        if (!$db) {
            return false;
        }
        $stmt = $db->prepare('UPDATE fn_user SET totp_last_counter=? WHERE userid=? AND (totp_last_counter IS NULL OR totp_last_counter<?)');
        if (!$stmt) {
            return false;
        }
        $counter = (int)$counter;
        $userid = (string)$userid;
        $stmt->bind_param('isi', $counter, $userid, $counter);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok && $affected > 0;
    }
}

if (!function_exists('feiniao_login_captcha_token_valid')) {
    function feiniao_login_captcha_token_valid($token)
    {
        $state = isset($_SESSION['login_captcha_verified']) ? $_SESSION['login_captcha_verified'] : null;
        if (!is_array($state) || empty($state['hash']) || empty($state['expires'])) {
            return false;
        }
        if ((int)$state['expires'] < time() || !is_string($token) || $token === '') {
            unset($_SESSION['login_captcha_verified']);
            return false;
        }
        return hash_equals((string)$state['hash'], hash('sha256', $token));
    }
}

if (!function_exists('feiniao_login_captcha_consume')) {
    function feiniao_login_captcha_consume($token)
    {
        $valid = feiniao_login_captcha_token_valid($token);
        unset($_SESSION['login_captcha_verified']);
        return $valid;
    }
}

if (!function_exists('feiniao_auth_json')) {
    function feiniao_auth_json($payload, $status = 200)
    {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

