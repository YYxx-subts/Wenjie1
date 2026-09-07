<?php
/**
 * 每日用户统计表迁移：
 * - 按业务日（07:00~次日06:00）保存流水、中奖金额、有效注数；
 * - 先从现有订单表回填；
 * - 通过订单表触发器覆盖新增、结算、撤单和删除，避免业务分支漏记。
 * CLI only：php Public/migrate_daily_stats.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/config.php';
if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
    fwrite(STDERR, "ERROR: DB not connected\n");
    exit(1);
}
$D = $GLOBALS['D'];

function daily_stats_sql($sql, $label)
{
    global $D;
    if (!$D->query($sql)) {
        fwrite(STDERR, "ERROR [$label]: " . $D->error . "\n");
        exit(1);
    }
    echo "OK: $label\n";
}

daily_stats_sql(
    "CREATE TABLE IF NOT EXISTS `fn_user_daily_stats` (
        `roomid` int NOT NULL,
        `userid` varchar(191) NOT NULL,
        `biz_date` date NOT NULL,
        `turnover` decimal(20,2) NOT NULL DEFAULT '0.00',
        `win_amount` decimal(20,2) NOT NULL DEFAULT '0.00',
        `bet_count` bigint NOT NULL DEFAULT 0,
        `updated_at` datetime NOT NULL,
        PRIMARY KEY (`roomid`,`userid`,`biz_date`),
        KEY `idx_room_date` (`roomid`,`biz_date`),
        KEY `idx_user_date` (`userid`,`biz_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'create fn_user_daily_stats'
);

// 触发器统一使用与历史 feiniao_sum_bets 相同的口径：status>0 或 status<0 才计入。
daily_stats_sql("DROP PROCEDURE IF EXISTS `fn_user_daily_stats_apply_v1`", 'reset daily stats procedure');
daily_stats_sql(
    "CREATE PROCEDURE `fn_user_daily_stats_apply_v1`(
        IN p_roomid INT,
        IN p_userid VARCHAR(191),
        IN p_addtime DATETIME,
        IN p_money DECIMAL(20,2),
        IN p_status VARCHAR(64),
        IN p_sign INT
    )
    BEGIN
        DECLARE v_date DATE;
        DECLARE v_at DATETIME;
        DECLARE v_turnover DECIMAL(20,2) DEFAULT 0.00;
        DECLARE v_win DECIMAL(20,2) DEFAULT 0.00;
        DECLARE v_count BIGINT DEFAULT 0;
        SET v_at = IFNULL(p_addtime, NOW());
        SET v_date = IF(TIME(v_at) < '07:00:00', DATE_SUB(DATE(v_at), INTERVAL 1 DAY), DATE(v_at));
        IF p_status > 0 OR p_status < 0 THEN
            SET v_turnover = COALESCE(p_money, 0.00) * p_sign;
            SET v_win = IF(p_status > 0, CAST(p_status AS DECIMAL(20,2)), 0.00) * p_sign;
            SET v_count = p_sign;
            INSERT INTO `fn_user_daily_stats` (`roomid`,`userid`,`biz_date`,`turnover`,`win_amount`,`bet_count`,`updated_at`)
            VALUES (p_roomid, p_userid, v_date, v_turnover, v_win, v_count, NOW())
            ON DUPLICATE KEY UPDATE
                `turnover` = `turnover` + VALUES(`turnover`),
                `win_amount` = `win_amount` + VALUES(`win_amount`),
                `bet_count` = `bet_count` + VALUES(`bet_count`),
                `updated_at` = NOW();
        END IF;
    END",
    'create daily stats procedure'
);

$tables = array(
    'fn_order', 'fn_pcorder', 'fn_mtorder', 'fn_jsscorder',
    'fn_jssscorder', 'fn_sscorder', 'fn_azxy5order', 'fn_lhcorder'
);

// 新表只在本迁移中回填一次；若迁移中途失败，重跑前清空不会影响订单和余额。
daily_stats_sql("TRUNCATE TABLE `fn_user_daily_stats`", 'reset daily stats before backfill');
foreach ($tables as $table) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    daily_stats_sql(
        "INSERT INTO `fn_user_daily_stats` (`roomid`,`userid`,`biz_date`,`turnover`,`win_amount`,`bet_count`,`updated_at`)
         SELECT `roomid`,`userid`,
                IF(TIME(`addtime`) < '07:00:00', DATE_SUB(DATE(`addtime`), INTERVAL 1 DAY), DATE(`addtime`)),
                SUM(`money`), SUM(IF(`status` > 0, CAST(`status` AS DECIMAL(20,2)), 0)), COUNT(*), NOW()
         FROM `{$safe}`
         WHERE (`status` > 0 OR `status` < 0)
         GROUP BY `roomid`,`userid`,
                  IF(TIME(`addtime`) < '07:00:00', DATE_SUB(DATE(`addtime`), INTERVAL 1 DAY), DATE(`addtime`))
         ON DUPLICATE KEY UPDATE
             `turnover` = `turnover` + VALUES(`turnover`),
             `win_amount` = `win_amount` + VALUES(`win_amount`),
             `bet_count` = `bet_count` + VALUES(`bet_count`),
             `updated_at` = NOW()",
        "backfill {$safe}"
    );
}

foreach ($tables as $table) {
    $suffix = preg_replace('/[^a-zA-Z0-9_]/', '', substr($table, 3));
    $ai = "fn_uds_{$suffix}_ai";
    $au = "fn_uds_{$suffix}_au";
    $ad = "fn_uds_{$suffix}_ad";
    daily_stats_sql("DROP TRIGGER IF EXISTS `{$ai}`", "reset {$ai}");
    daily_stats_sql("DROP TRIGGER IF EXISTS `{$au}`", "reset {$au}");
    daily_stats_sql("DROP TRIGGER IF EXISTS `{$ad}`", "reset {$ad}");
    daily_stats_sql(
        "CREATE TRIGGER `{$ai}` AFTER INSERT ON `{$table}` FOR EACH ROW
         CALL `fn_user_daily_stats_apply_v1`(NEW.`roomid`,NEW.`userid`,NEW.`addtime`,NEW.`money`,NEW.`status`,1)",
        "create {$ai}"
    );
    daily_stats_sql(
        "CREATE TRIGGER `{$au}` AFTER UPDATE ON `{$table}` FOR EACH ROW
         BEGIN
             CALL `fn_user_daily_stats_apply_v1`(OLD.`roomid`,OLD.`userid`,OLD.`addtime`,OLD.`money`,OLD.`status`,-1);
             CALL `fn_user_daily_stats_apply_v1`(NEW.`roomid`,NEW.`userid`,NEW.`addtime`,NEW.`money`,NEW.`status`,1);
         END",
        "create {$au}"
    );
    daily_stats_sql(
        "CREATE TRIGGER `{$ad}` AFTER DELETE ON `{$table}` FOR EACH ROW
         CALL `fn_user_daily_stats_apply_v1`(OLD.`roomid`,OLD.`userid`,OLD.`addtime`,OLD.`money`,OLD.`status`,-1)",
        "create {$ad}"
    );
}

echo "ALL DAILY STATS MIGRATIONS OK\n";
