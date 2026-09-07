<?php
if ($load != 5) die('..');
function sql_write_log($data)
{
    $t = date("YmdHis") . rand(00001, 99999);
    $dir = dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__))) . "/sql_error";
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $data = print_r($data, 1) . "\r\n------------------------------\r\n\r\n";
    file_put_contents($dir . "/" . $t . '.log', $data, FILE_APPEND);
    return $t;
}

function db_connect_params()
{
    if (!empty($GLOBALS['db_conn_params']) && is_array($GLOBALS['db_conn_params'])) {
        return $GLOBALS['db_conn_params'];
    }
    if (!empty($GLOBALS['db']) && is_array($GLOBALS['db'])) {
        return array(
            'host' => isset($GLOBALS['db']['host']) ? $GLOBALS['db']['host'] : 'localhost',
            'user' => isset($GLOBALS['db']['user']) ? $GLOBALS['db']['user'] : '',
            'pass' => isset($GLOBALS['db']['pass']) ? $GLOBALS['db']['pass'] : '',
            'name' => isset($GLOBALS['db']['name']) ? $GLOBALS['db']['name'] : '',
            'port' => isset($GLOBALS['db']['port']) ? (int)$GLOBALS['db']['port'] : 3306,
        );
    }
    return null;
}

function db_connect($host = null, $name = null, $pass = null, $dbname = null, $port = 3306)
{
    // 无参调用：用上次保存的参数重连（采集器 sleep 后、断线重连）
    if ($host === null || $name === null || $dbname === null) {
        $p = db_connect_params();
        if (!$p) {
            errorc__("系统繁忙，请稍后重试");
        }
        $host = $p['host'];
        $name = $p['user'];
        $pass = $p['pass'];
        $dbname = $p['name'];
        $port = isset($p['port']) ? (int)$p['port'] : 3306;
    }
    $GLOBALS['db_conn_params'] = array(
        'host' => $host,
        'user' => $name,
        'pass' => $pass,
        'name' => $dbname,
        'port' => (int)$port,
    );

    // 已有可用连接则复用，避免同请求重复建连
    if (!empty($GLOBALS['dbcontent']) && !empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
        $pingOk = false;
        try {
            $pingOk = @mysqli_ping($GLOBALS['D']);
        } catch (\Error $e) {
            // PHP 8.2: mysqli object already closed — force reconnect
        }
        if ($pingOk) {
            return $GLOBALS['D'];
        }
        @mysqli_close($GLOBALS['D']);
        $GLOBALS['D'] = null;
        $GLOBALS['dbcontent'] = false;
    }

    // 1040 Too many connections：多轮退避重试
    $db = null;
    $lastErrno = 0;
    $lastError = '';
    for ($i = 0; $i < 8; $i++) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($host, $name, $pass, $dbname, (int)$port);
        if ($db && !$db->connect_errno) {
            break;
        }
        $lastErrno = $db ? (int)$db->connect_errno : (int)mysqli_connect_errno();
        $lastError = $db ? (string)$db->connect_error : (string)mysqli_connect_error();
        // PHP 8.2 在连接构造失败时可能已经将 mysqli 对象标记为 closed；
        // 再调用 mysqli_close() 会直接触发「mysqli object is already closed」Fatal，
        // 使常驻采集器启动即退出。失败对象无需显式 close，直接丢弃并重试。
        $db = null;
        if (!in_array($lastErrno, array(1040, 2002, 2003, 2006), true)) {
            break;
        }
        usleep(250000 + $i * 200000); // 0.25s ~ 1.65s
    }
    if (!$db || $db->connect_errno) {
        $code = $lastErrno ?: ($db ? $db->connect_errno : -1);
        $msg = $lastError ?: ($db ? $db->connect_error : 'unknown');
        sql_write_log(array('数据库链接出错', $code, $msg));
        // 面向用户：不要再 dump Array()；1040 给可理解提示
        if ((int)$code === 1040) {
            errorc__("系统繁忙（数据库连接已满），请稍后重试");
        }
        errorc__("系统繁忙，请稍后重试");
    }
    $GLOBALS['D'] = $db;
    $GLOBALS['dbcontent'] = true;
    // 缩短空闲占用，降低 Sleep 堆积（长驻采集器 sleep 前会主动 close）
    @mysqli_query($db, "SET SESSION wait_timeout=30, interactive_timeout=30");
    db_query("set time_zone = '+8:00';");
    // 客服消息包含 Emoji，连接层必须与 utf8mb4 字段保持一致。
    db_query("SET NAMES utf8mb4");

    static $shutdownRegistered = false;
    if (!$shutdownRegistered) {
        $shutdownRegistered = true;
        register_shutdown_function(function () {
            if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
                @mysqli_close($GLOBALS['D']);
            }
            $GLOBALS['D'] = null;
            $GLOBALS['dbcontent'] = false;
        });
    }
    return $db;
}

/** 长驻脚本休眠前释放连接，避免 Sleep 占坑 */
function db_release()
{
    if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
        @mysqli_close($GLOBALS['D']);
    }
    $GLOBALS['D'] = null;
    $GLOBALS['dbcontent'] = false;
}

function db_query($sqlstr, $is = false)
{
    if ($GLOBALS['dbcontent'] !== true || empty($GLOBALS['D'])) {
        db_connect();
    } elseif ($GLOBALS['D'] instanceof mysqli) {
        $pingOk = false;
        try {
            $pingOk = @mysqli_ping($GLOBALS['D']);
        } catch (\Error $e) {
            // PHP 8.2: mysqli object already closed — force reconnect
        }
        if (!$pingOk) {
            db_release();
            db_connect();
        }
    }
    $db = $GLOBALS['D'];
    $queryr = $db->query($sqlstr);
    $GLOBALS['db_query_'] = $queryr;
    $GLOBALS['D'] = $db;
    if (!$queryr || $db->errno > 0) {
        // 断线重试一次
        if (in_array((int)$db->errno, array(2006, 2013), true)) {
            db_release();
            db_connect();
            $db = $GLOBALS['D'];
            $queryr = $db->query($sqlstr);
            $GLOBALS['db_query_'] = $queryr;
            $GLOBALS['D'] = $db;
        }
    }
    $sqlerrorid = null;
    if (!$queryr || $db->errno > 0) {
        $sqlerrorid = sql_write_log(array('语句执行出错', $db->errno, $db->error, $sqlstr));
    }
    if (!$is && !empty($sqlerrorid)) {
        errorc__("系统出现错误,请将错误编码告知管理员,错误编码:[{$sqlerrorid}]");
    }
    return $queryr;
}

function db_fetch_array()
{
    return @$GLOBALS['db_query_']->fetch_array();
}

function db_fetch_assoc()
{
    return @$GLOBALS['db_query_']->fetch_assoc();
}

function db_findall($table)
{
    return db_query("SELECT * FROM {$table}");
}

function db_getid()
{
    return $GLOBALS['D']->insert_id;
}

function db_real_escape_string($str)
{
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        db_connect();
    }
    return $GLOBALS['D']->real_escape_string($str);
}

function db_close()
{
    return db_release();
}

function errorc__($text, $tt = '')
{
    die($text);
}

function select_query($table = "", $ziduan = "", $where = "", $order = "", $limit = "")
{
    if (!$ziduan) {
        $ziduan = "*";
    }
    $sqlstr = "SELECT " . $ziduan . " FROM " . db_make_safe_field($table);
    if ($where) {
        if (is_array($where)) {
            $a514 = array();
            foreach ($where as $whename => $wheval) {
                $a2 = db_make_safe_field($whename);
                if (is_array($wheval)) {
                    $wheval_typ = $wheval["sqltype"];
                    $wheval_val = db_escape_string($wheval["value"]);
                    if ($a2 == "default") $a2 = "`default`";
                    if ($wheval_typ == "LIKE") $a514[] = "" . $a2 . " LIKE '%" . $wheval_val . "%'";
                    if ($wheval_typ == "NEQ") $a514[] = "" . $a2 . "!='" . $wheval_val . "'";
                    if ($wheval_typ == ">") $a514[] = "" . $a2 . ">" . $wheval_val . "";
                    if ($wheval_typ == "<") $a514[] = "" . $a2 . "<" . $wheval_val . "";
                    if ($wheval_typ == "<=") $a514[] = "" . $whename . "<=" . $wheval_val . "";
                    if ($wheval_typ == ">=") $a514[] = "" . $whename . ">=" . $wheval_val . "";
                    if ($wheval_typ == "TABLEJOIN") $a514[] = "" . $a2 . "=" . $wheval_val . "";
                    continue;
                }
                if (substr($a2, 0, 3) == "MD5") {
                    $a2 = explode("(", $whename, 2);
                    $a2 = explode(")", $a2[1], 2);
                    $a2 = db_make_safe_field($a2[0]);
                    $a2 = "MD5(" . $a2 . ")";
                } else {
                    if (strpos($a2, ".")) {
                        $a2 = explode(".", $a2);
                        $a2 = "`" . $a2[0] . "`.`" . $a2[1] . "`";
                    } else {
                        $a2 = "`" . $a2 . "`";
                    }
                }
                $a514[] = "" . $a2 . "='" . db_escape_string($wheval) . "'";
            }
            $sqlstr .= " WHERE " . implode(" AND ", $a514);
        } else {
            $sqlstr .= " WHERE " . $where;
        }
    }
    if (is_array($order)) {
        $sqlstr .= " ORDER BY " . implode(",", $order);
    }
    if ($limit) {
        if (strpos($limit, ",")) {
            $limit = explode(",", $limit);
            $limit = (int)$limit[0] . "," . (int)$limit[1];
        } else {
            $limit = (int)$limit;
        }
        $sqlstr .= " LIMIT " . $limit;
    }
    $queryr = db_query($sqlstr);
    return $queryr;
}

function update_query($table, $str, $where)
{
    $sqlstr = "UPDATE " . db_make_safe_field($table) . " SET ";
    foreach ($str as $a2 => $wheval) {
        $sqlstr .= "`" . db_make_safe_field($a2) . "`=";
        if ($wheval === "now()") {
            $sqlstr .= "'" . date("Y-m-d H:i:s") . "',";
            continue;
        }
        if ($wheval === "+1") {
            $sqlstr .= "`" . $a2 . "`+1,";
            continue;
        }
        if (is_array($wheval) && isset($wheval['type']) && $wheval['type'] == "AES_ENCRYPT") {
            $sqlstr .= sprintf("AES_ENCRYPT('%s','%s'),", db_escape_string($wheval['text']), db_escape_string($wheval['hashkey']));
            continue;
        }
        if ($wheval === "NULL") {
            $sqlstr .= "NULL,";
            continue;
        }
        if (substr($wheval, 0, 2) === "+=" && db_is_valid_amount(substr($wheval, 2))) {
            $sqlstr .= "`" . $a2 . "`+" . substr($wheval, 2) . ",";
            continue;
        }
        if (substr($wheval, 0, 2) === "-=" && db_is_valid_amount(substr($wheval, 2))) {
            $sqlstr .= "`" . $a2 . "`-" . substr($wheval, 2) . ",";
            continue;
        }
        $sqlstr .= "'" . db_escape_string($wheval) . "',";
    }
    $sqlstr = substr($sqlstr, 0, 0 - 1);
    if (is_array($where)) {
        $sqlstr .= " WHERE";
        foreach ($where as $a2 => $wheval) {
            if (substr($a2, 0, 4) == "MD5(") {
                $a2 = "MD5(" . db_make_safe_field(substr($a2, 4, 0 - 1)) . ")";
            } else {
                $a2 = db_make_safe_field($a2);
                if ($a2 == "order") {
                    $a2 = "`order`";
                }
            }
            $sqlstr .= " " . $a2 . "='" . db_escape_string($wheval) . "' AND";
        }
        $sqlstr = substr($sqlstr, 0, 0 - 4);
    } else {
        if ($where) {
            $sqlstr .= " WHERE " . $where;
        }
    }
    $queryr = db_query($sqlstr);
    return $queryr;
}

function insert_query($table, $str, &$id = null)
{
    $userReservation = false;
    if (strtolower(trim((string)$table, "` ")) === 'fn_user'
        && function_exists('feiniao_reserve_user_room')
        && isset($str['userid']) && array_key_exists('roomid', $str)) {
        $reserve = feiniao_reserve_user_room((string)$str['userid'], intval($str['roomid']));
        if ($reserve !== true) {
            return false;
        }
        $userReservation = true;
    }

    $a529 = $a530 = "";
    $sqlstr = "INSERT INTO " . db_make_safe_field($table) . " ";
    foreach ($str as $a2 => $wheval) {
        $a529 .= "`" . db_make_safe_field($a2) . "`,";
        if ($wheval === "now()") {
            $a530 .= "'" . date("Y-m-d H:i:s") . "',";
            continue;
        }
        if ($wheval === "NULL") {
            $a530 .= "NULL,";
            continue;
        }
        $a530 .= "'" . db_escape_string($wheval) . "',";
    }
    $a529 = substr($a529, 0, 0 - 1);
    $a530 = substr($a530, 0, 0 - 1);
    $sqlstr .= "(" . $a529 . ") VALUES (" . $a530 . ")";
    $queryr = db_query($sqlstr);
    if (!$queryr && $userReservation && function_exists('feiniao_release_user_room_reservation')) {
        feiniao_release_user_room_reservation((string)$str['userid'], intval($str['roomid']));
    }
    $id = db_getid();
    return $queryr;
}

function delete_query($table, $where)
{
    $sqlstr = "DELETE FROM " . db_make_safe_field($table) . " WHERE";
    if (is_array($where)) {
        foreach ($where as $a2 => $wheval) {
            $sqlstr .= db_build_quoted_field($a2) . "='" . db_escape_string($wheval) . "' AND ";
        }
        $sqlstr = substr($sqlstr, 0, 0 - 4);
    } else {
        $sqlstr .= " " . $where;
    }
    $queryr = db_query($sqlstr);
}

function db_build_quoted_field($a2)
{
    $a531 = "`";
    $a532 = explode(".", $a2, 3);
    foreach ($a532 as $k => $a19) {
        $a533 = db_make_safe_field($a19);
        if ($a533 !== $a19) {
            exit("Unexpected input field parameter in database query.");
        }
        $a532[$k] = $a531 . $a533 . $a531;
    }
    return implode(".", $a532);
}

function full_query($sqlstr, $a7Handle = null)
{
    $queryr = db_query($sqlstr);
    return $queryr;
}

function get_query_val($table, $ziduan, $where, $a29erby = "", $a29erbyorder = "", $limit = "", $a512 = "")
{
    select_query($table, $ziduan, $where, $a29erby, $a29erbyorder, $limit, $a512);
    $a209 = db_fetch_array();
    return $a209[0];
}

function get_query_vals($table, $ziduan, $where, $a29erby = "", $a29erbyorder = "", $limit = "", $a512 = "")
{
    select_query($table, $ziduan, $where, $a29erby, $a29erbyorder, $limit, $a512);
    $a209 = db_fetch_array();
    return $a209;
}

function db_escape_string($str)
{
    $str = db_real_escape_string($str);
    return $str;
}

function db_escape_array($str)
{
    $str = array_map("db_escape_string", $str);
    return $str;
}

function db_escape_numarray($str)
{
    $str = array_map("intval", $str);
    return $str;
}

function db_make_safe_field($ziduan)
{
    return $ziduan;
}

function db_is_valid_amount($a535)
{
    return preg_match('/^-?[0-9\\.]+$/', $a535) === 1 ? true : false;
}

/** S-07：整型白名单，拼进 SQL 前强制 (int) */
function feiniao_sql_int($v){ return (int)$v; }
/** S-07：字符串转义快捷方式 */
function feiniao_sql_str($v){ return db_escape_string((string)$v); }
