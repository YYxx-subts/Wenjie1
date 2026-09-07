<?php
/** 创建并回填应用层每日统计明细；CLI only。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/config.php';
if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) exit("DB not connected\n");
$D = $GLOBALS['D'];
function daily_items_sql($sql, $label) {
    global $D;
    if (!$D->query($sql)) { fwrite(STDERR, "ERROR [$label]: {$D->error}\n"); exit(1); }
    echo "OK: $label\n";
}

daily_items_sql(
    "CREATE TABLE IF NOT EXISTS `fn_user_daily_stat_items` (
        `order_table` varchar(32) NOT NULL,
        `order_id` int NOT NULL,
        `roomid` int NOT NULL,
        `userid` varchar(191) NOT NULL,
        `biz_date` date NOT NULL,
        `turnover` decimal(20,2) NOT NULL DEFAULT '0.00',
        `win_amount` decimal(20,2) NOT NULL DEFAULT '0.00',
        `bet_count` bigint NOT NULL DEFAULT 0,
        `updated_at` datetime NOT NULL,
        PRIMARY KEY (`order_table`,`order_id`),
        KEY `idx_user_date` (`roomid`,`userid`,`biz_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'create fn_user_daily_stat_items'
);

daily_items_sql("TRUNCATE TABLE `fn_user_daily_stat_items`", 'reset daily stat items');
foreach (array('fn_order','fn_pcorder','fn_mtorder','fn_jsscorder','fn_jssscorder','fn_sscorder','fn_azxy5order','fn_lhcorder') as $table) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    daily_items_sql(
        "INSERT INTO `fn_user_daily_stat_items` (`order_table`,`order_id`,`roomid`,`userid`,`biz_date`,`turnover`,`win_amount`,`bet_count`,`updated_at`)
         SELECT '{$safe}',`id`,`roomid`,`userid`,
                IF(TIME(`addtime`) < '07:00:00', DATE_SUB(DATE(`addtime`), INTERVAL 1 DAY), DATE(`addtime`)),
                `money`,IF(`status` > 0,CAST(`status` AS DECIMAL(20,2)),0),1,NOW()
         FROM `{$safe}` WHERE (`status` > 0 OR `status` < 0)",
        "backfill items {$safe}"
    );
}
echo "ALL DAILY STAT ITEMS OK\n";
