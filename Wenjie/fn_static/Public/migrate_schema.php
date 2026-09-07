<?php
/**
 * R-03：一次性 schema migration（CLI）
 *   php Public/migrate_schema.php
 * 禁止在业务热路径执行 ALTER；失败必须以非 0 退出。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

$root = dirname(__DIR__);
require_once __DIR__ . '/config.php';

if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
    fwrite(STDERR, "ERROR: DB not connected\n");
    exit(1);
}
$D = $GLOBALS['D'];

function feiniao_mig_ok($D, $sql, $label)
{
    if (!$D->query($sql)) {
        fwrite(STDERR, "ERROR [$label]: " . $D->error . "\nSQL: $sql\n");
        exit(1);
    }
    echo "OK: $label\n";
}

function feiniao_mig_has_column($D, $table, $column)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $res = $D->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (!$res) {
        fwrite(STDERR, "ERROR: SHOW COLUMNS failed: " . $D->error . "\n");
        exit(1);
    }
    $ok = (bool) $res->fetch_assoc();
    $res->free();
    return $ok;
}

function feiniao_mig_has_index($D, $table, $index)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $index = preg_replace('/[^a-zA-Z0-9_]/', '', $index);
    $res = $D->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    if (!$res) {
        fwrite(STDERR, "ERROR: SHOW INDEX failed: " . $D->error . "\n");
        exit(1);
    }
    $ok = (bool) $res->fetch_assoc();
    $res->free();
    return $ok;
}

function feiniao_mig_applied($D, $version)
{
    $v = $D->real_escape_string($version);
    $res = $D->query("SELECT 1 FROM `fn_schema_migration` WHERE `version` = '{$v}' LIMIT 1");
    if (!$res) {
        return false;
    }
    $ok = (bool) $res->fetch_assoc();
    $res->free();
    return $ok;
}

function feiniao_mig_mark($D, $version)
{
    $v = $D->real_escape_string($version);
    $now = date('Y-m-d H:i:s');
    feiniao_mig_ok(
        $D,
        "INSERT INTO `fn_schema_migration` (`version`,`applied_at`) VALUES ('{$v}','{$now}')",
        "mark $version"
    );
}

feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_schema_migration` (
        `version` varchar(64) NOT NULL,
        `applied_at` datetime NOT NULL,
        PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_schema_migration'
);

// --- 20260731_pay_key ---
$ver = '20260731_pay_key';
if (!feiniao_mig_applied($D, $ver)) {
    if (!feiniao_mig_has_column($D, 'fn_marklog', 'pay_key')) {
        feiniao_mig_ok(
            $D,
            "ALTER TABLE `fn_marklog` ADD COLUMN `pay_key` varchar(96) NULL DEFAULT NULL",
            'add fn_marklog.pay_key'
        );
    } else {
        echo "SKIP: column pay_key exists\n";
    }
    if (!feiniao_mig_has_index($D, 'fn_marklog', 'uk_pay_key')) {
        feiniao_mig_ok(
            $D,
            "ALTER TABLE `fn_marklog` ADD UNIQUE KEY `uk_pay_key` (`pay_key`)",
            'add uk_pay_key'
        );
    } else {
        echo "SKIP: index uk_pay_key exists\n";
    }
    if (!feiniao_mig_has_index($D, 'fn_marklog', 'uk_pay_key')) {
        fwrite(STDERR, "FATAL: uk_pay_key still missing after migration\n");
        exit(1);
    }
    feiniao_mig_mark($D, $ver);
} else {
    echo "SKIP: $ver already applied\n";
}

// --- 20260731_job_queue ---
$ver2 = '20260731_job_queue';
if (!feiniao_mig_applied($D, $ver2)) {
    feiniao_mig_ok(
        $D,
        "CREATE TABLE IF NOT EXISTS `fn_job_queue` (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `job_key` varchar(191) NOT NULL,
            `job_type` varchar(32) NOT NULL,
            `type_id` int NOT NULL DEFAULT 0,
            `payload` mediumtext NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'pending',
            `attempts` int NOT NULL DEFAULT 0,
            `max_attempts` int NOT NULL DEFAULT 8,
            `available_at` datetime NOT NULL,
            `locked_at` datetime DEFAULT NULL,
            `locked_by` varchar(64) DEFAULT NULL,
            `last_error` text,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_job_key` (`job_key`),
            KEY `idx_status_avail` (`status`,`available_at`),
            KEY `idx_running_locked` (`status`,`locked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'fn_job_queue'
    );
    feiniao_mig_mark($D, $ver2);
} else {
    echo "SKIP: $ver2 already applied\n";
}

// P1-02：无论版本是否已记，逐列/逐索引校验并补齐
$ver3 = '20260731_job_queue_verify';
$needCols = array(
    'job_key' => "ALTER TABLE `fn_job_queue` ADD COLUMN `job_key` varchar(191) NOT NULL DEFAULT ''",
    'job_type' => "ALTER TABLE `fn_job_queue` ADD COLUMN `job_type` varchar(32) NOT NULL DEFAULT ''",
    'type_id' => "ALTER TABLE `fn_job_queue` ADD COLUMN `type_id` int NOT NULL DEFAULT 0",
    'payload' => "ALTER TABLE `fn_job_queue` ADD COLUMN `payload` mediumtext NOT NULL",
    'status' => "ALTER TABLE `fn_job_queue` ADD COLUMN `status` varchar(16) NOT NULL DEFAULT 'pending'",
    'attempts' => "ALTER TABLE `fn_job_queue` ADD COLUMN `attempts` int NOT NULL DEFAULT 0",
    'max_attempts' => "ALTER TABLE `fn_job_queue` ADD COLUMN `max_attempts` int NOT NULL DEFAULT 8",
    'available_at' => "ALTER TABLE `fn_job_queue` ADD COLUMN `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
    'locked_at' => "ALTER TABLE `fn_job_queue` ADD COLUMN `locked_at` datetime DEFAULT NULL",
    'locked_by' => "ALTER TABLE `fn_job_queue` ADD COLUMN `locked_by` varchar(64) DEFAULT NULL",
    'last_error' => "ALTER TABLE `fn_job_queue` ADD COLUMN `last_error` text",
    'created_at' => "ALTER TABLE `fn_job_queue` ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
    'updated_at' => "ALTER TABLE `fn_job_queue` ADD COLUMN `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
);
$tblExists = @$D->query("SHOW TABLES LIKE 'fn_job_queue'");
if (!$tblExists || !$tblExists->fetch_row()) {
    fwrite(STDERR, "FATAL: fn_job_queue missing after create\n");
    exit(1);
}
if ($tblExists) {
    $tblExists->free();
}
foreach ($needCols as $col => $alterSql) {
    if (!feiniao_mig_has_column($D, 'fn_job_queue', $col)) {
        feiniao_mig_ok($D, $alterSql, "add fn_job_queue.$col");
    }
}
if (!feiniao_mig_has_index($D, 'fn_job_queue', 'uk_job_key')) {
    feiniao_mig_ok($D, "ALTER TABLE `fn_job_queue` ADD UNIQUE KEY `uk_job_key` (`job_key`)", 'uk_job_key');
}
if (!feiniao_mig_has_index($D, 'fn_job_queue', 'idx_status_avail')) {
    feiniao_mig_ok($D, "ALTER TABLE `fn_job_queue` ADD KEY `idx_status_avail` (`status`,`available_at`)", 'idx_status_avail');
}
if (!feiniao_mig_has_index($D, 'fn_job_queue', 'idx_running_locked')) {
    feiniao_mig_ok($D, "ALTER TABLE `fn_job_queue` ADD KEY `idx_running_locked` (`status`,`locked_at`)", 'idx_running_locked');
}
if (!feiniao_mig_applied($D, $ver3)) {
    feiniao_mig_mark($D, $ver3);
}

// --- 20260731_rebate_vip_betreq：热路径禁止 DDL，部署时一次性补齐 ---
$ver4 = '20260731_rebate_vip_betreq';
if (!feiniao_mig_has_column($D, 'fn_user', 'rebate')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_user` ADD COLUMN `rebate` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '回水比例%'",
        'fn_user.rebate'
    );
}
if (!feiniao_mig_has_column($D, 'fn_setting', 'setting_rebate')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_setting` ADD COLUMN `setting_rebate` mediumtext NULL",
        'fn_setting.setting_rebate'
    );
}
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_vip_claim` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `roomid` int NOT NULL,
      `userid` varchar(64) NOT NULL,
      `claim_type` varchar(16) NOT NULL,
      `vip_level` tinyint NOT NULL DEFAULT 0,
      `money` decimal(14,2) NOT NULL DEFAULT 0.00,
      `period_key` varchar(32) NOT NULL DEFAULT '',
      `addtime` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_once` (`roomid`,`userid`,`claim_type`,`period_key`),
      KEY `idx_user` (`roomid`,`userid`,`addtime`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_vip_claim'
);
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_vip_festival` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `roomid` int NOT NULL,
      `title` varchar(64) NOT NULL DEFAULT '',
      `money` decimal(14,2) NOT NULL DEFAULT 0.00,
      `start_at` datetime NOT NULL,
      `end_at` datetime NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `addtime` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_room_status` (`roomid`,`status`,`start_at`,`end_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_vip_festival'
);
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_vip_reach` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `roomid` int NOT NULL,
      `userid` varchar(64) NOT NULL,
      `vip_level` tinyint NOT NULL DEFAULT 0,
      `reached_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_reach` (`roomid`,`userid`,`vip_level`),
      KEY `idx_user` (`roomid`,`userid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_vip_reach'
);
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_bet_request` (
      `req_key` varchar(96) NOT NULL,
      `order_table` varchar(32) NOT NULL DEFAULT '',
      `order_id` bigint NOT NULL DEFAULT 0,
      `created_at` datetime NOT NULL,
      PRIMARY KEY (`req_key`),
      KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_bet_request'
);
if (!feiniao_mig_applied($D, $ver4)) {
    feiniao_mig_mark($D, $ver4);
}

// --- 20260731_open_state_join_vipcol ---
$ver5 = '20260731_open_state_join_vipcol';
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_open_state` (
        `type` int NOT NULL,
        `issue` varchar(64) NOT NULL,
        `next_term` varchar(64) NOT NULL DEFAULT '',
        `status` varchar(16) NOT NULL DEFAULT 'processing',
        `market_state` varchar(16) NOT NULL DEFAULT '',
        `market_term` varchar(64) NOT NULL DEFAULT '',
        `updated_at` datetime NOT NULL,
        `published_at` datetime DEFAULT NULL,
        `last_error` text,
        PRIMARY KEY (`type`),
        KEY `idx_issue_status` (`issue`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_open_state'
);
if (!feiniao_mig_has_column($D, 'fn_open_state', 'market_state')) {
    feiniao_mig_ok($D, "ALTER TABLE `fn_open_state` ADD COLUMN `market_state` varchar(16) NOT NULL DEFAULT ''", 'fn_open_state.market_state');
}
if (!feiniao_mig_has_column($D, 'fn_open_state', 'market_term')) {
    feiniao_mig_ok($D, "ALTER TABLE `fn_open_state` ADD COLUMN `market_term` varchar(64) NOT NULL DEFAULT ''", 'fn_open_state.market_term');
}
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_join_apply` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `roomid` int(11) NOT NULL,
      `userid` varchar(64) NOT NULL,
      `username` varchar(128) DEFAULT '',
      `nickname` varchar(128) DEFAULT '',
      `remark` varchar(255) DEFAULT '',
      `join_remark` varchar(255) DEFAULT '',
      `apply_ip` varchar(64) DEFAULT '',
      `first_apply` tinyint(1) NOT NULL DEFAULT 1,
      `status` varchar(32) NOT NULL DEFAULT 'pending',
      `created_at` datetime DEFAULT NULL,
      `reviewed_at` datetime DEFAULT NULL,
      `operator` varchar(64) DEFAULT '',
      PRIMARY KEY (`id`),
      KEY `idx_room_status` (`roomid`,`status`),
      KEY `idx_room_user` (`roomid`,`userid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_join_apply'
);
if (!feiniao_mig_has_column($D, 'fn_setting', 'setting_vip')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_setting` ADD COLUMN `setting_vip` mediumtext NULL",
        'fn_setting.setting_vip'
    );
}
if (!feiniao_mig_applied($D, $ver5)) {
    feiniao_mig_mark($D, $ver5);
}

// --- 20260731_fn_open_uk_type_term ---
$ver6 = '20260731_fn_open_uk_type_term';
// term 若为 TEXT/BLOB，必须先改为 VARCHAR 才能建唯一索引
$colTerm = @$D->query("SHOW COLUMNS FROM `fn_open` LIKE 'term'");
$termType = '';
if ($colTerm && ($tr = $colTerm->fetch_assoc())) {
    $termType = strtolower((string) ($tr['Type'] ?? ''));
    $colTerm->free();
}
if ($termType !== '' && (strpos($termType, 'text') !== false || strpos($termType, 'blob') !== false || strpos($termType, 'varchar') === false)) {
    // 期号一般为数字串，64 足够；过长截断前先规范化
    @$D->query("UPDATE `fn_open` SET `term` = LEFT(`term`, 64) WHERE CHAR_LENGTH(`term`) > 64");
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_open` MODIFY COLUMN `term` varchar(64) NOT NULL DEFAULT ''",
        'fn_open.term -> varchar(64)'
    );
}
// 清理重复 (type,term) 保留最大 id
$dupRes = @$D->query(
    "SELECT `type`, `term`, MAX(`id`) AS keep_id, COUNT(*) AS c
     FROM `fn_open` GROUP BY `type`, `term` HAVING c > 1"
);
if ($dupRes) {
    while ($d = $dupRes->fetch_assoc()) {
        $type = (int) $d['type'];
        $term = $D->real_escape_string((string) $d['term']);
        $keep = (int) $d['keep_id'];
        feiniao_mig_ok(
            $D,
            "DELETE FROM `fn_open` WHERE `type`={$type} AND `term`='{$term}' AND `id`<>{$keep}",
            "dedupe fn_open type={$type} term={$term}"
        );
    }
    $dupRes->free();
}
if (!feiniao_mig_has_index($D, 'fn_open', 'uk_type_term')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_open` ADD UNIQUE KEY `uk_type_term` (`type`,`term`)",
        'uk_type_term on fn_open'
    );
}
if (!feiniao_mig_has_index($D, 'fn_open', 'uk_type_term')) {
    fwrite(STDERR, "FATAL: uk_type_term still missing after migration\n");
    exit(1);
}
if (!feiniao_mig_applied($D, $ver6)) {
    feiniao_mig_mark($D, $ver6);
}

// --- 20260801_fn_user_userpass_bcrypt：password_hash 约 60 字符，旧库 varchar(32) 会 1406 ---
$ver7 = '20260801_fn_user_userpass_bcrypt';
$colPass = @$D->query("SHOW COLUMNS FROM `fn_user` LIKE 'userpass'");
$passType = '';
if ($colPass && ($pr = $colPass->fetch_assoc())) {
    $passType = strtolower((string) ($pr['Type'] ?? ''));
    $colPass->free();
}
$needWiden = true;
if (preg_match('/varchar\((\d+)\)/', $passType, $m) && (int)$m[1] >= 255) {
    $needWiden = false;
} elseif (strpos($passType, 'text') !== false) {
    $needWiden = false;
}
if ($needWiden) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_user` MODIFY COLUMN `userpass` varchar(255) NOT NULL DEFAULT ''",
        'fn_user.userpass -> varchar(255)'
    );
}
if (!feiniao_mig_applied($D, $ver7)) {
    feiniao_mig_mark($D, $ver7);
}

// --- 20260801_login_log：会员登录/进房 + 房主后台登录日志 ---
$ver8 = '20260801_fn_login_log';
feiniao_mig_ok(
    $D,
    "CREATE TABLE IF NOT EXISTS `fn_login_log` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `roomid` int NOT NULL DEFAULT 0,
      `actor` varchar(64) NOT NULL DEFAULT '',
      `userid` varchar(64) NOT NULL DEFAULT '',
      `kind` varchar(16) NOT NULL DEFAULT 'member',
      `ip` varchar(64) NOT NULL DEFAULT '',
      `user_agent` varchar(512) NOT NULL DEFAULT '',
      `device` varchar(128) NOT NULL DEFAULT '',
      `location` varchar(128) NOT NULL DEFAULT '',
      `carrier` varchar(128) NOT NULL DEFAULT '',
      `created_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_room_time` (`roomid`,`created_at`),
      KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fn_login_log'
);
if (!feiniao_mig_applied($D, $ver8)) {
    feiniao_mig_mark($D, $ver8);
}

// --- 20260802_recent_open_index：近期图/走势​​图只取最近 20 期，禁止全表排序 ---
$ver9 = '20260802_recent_open_index';
if (!feiniao_mig_has_index($D, 'fn_open', 'idx_type_id')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_open` ADD KEY `idx_type_id` (`type`,`id`)",
        'fn_open idx_type_id'
    );
}
if (!feiniao_mig_applied($D, $ver9)) {
    feiniao_mig_mark($D, $ver9);
}

// --- 20260802_chat_hotpath_indexes：房间高频聊天轮询只走索引范围 ---
// ajax_chat 的两条核心查询是：roomid + game + id>lastId，以及房间最新 id。
// 没有复合索引时，用户量上来后会反复扫描 fn_chat，页面越聊越卡。
$ver10 = '20260802_chat_hotpath_indexes';
if (!feiniao_mig_has_index($D, 'fn_chat', 'idx_room_game_id')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_chat` ADD KEY `idx_room_game_id` (`roomid`,`game`,`id`)",
        'fn_chat idx_room_game_id'
    );
}
if (!feiniao_mig_has_index($D, 'fn_chat', 'idx_room_id')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_chat` ADD KEY `idx_room_id` (`roomid`,`id`)",
        'fn_chat idx_room_id'
    );
}
if (!feiniao_mig_has_index($D, 'fn_marklog', 'idx_room_user_time')) {
    feiniao_mig_ok(
        $D,
        "ALTER TABLE `fn_marklog` ADD KEY `idx_room_user_time` (`roomid`,`userid`,`addtime`)",
        'fn_marklog idx_room_user_time'
    );
}
if (!feiniao_mig_applied($D, $ver10)) {
    feiniao_mig_mark($D, $ver10);
}

echo "ALL MIGRATIONS OK\n";
exit(0);
