<?php
/**
 * F-03 / F-06 / R-02 / R-08：持久化任务队列（租约回收 + 死信）
 */
function feiniao_job_queue_ensure()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        $ready = false;
        return false;
    }
    // P1-02：运行期只检查表存在，不静默 CREATE/ALTER；缺表则 fail-closed
    $res = @$GLOBALS['D']->query("SHOW TABLES LIKE 'fn_job_queue'");
    $ready = ($res && $res->fetch_row());
    if ($res) {
        $res->free();
    }
    if (!$ready) {
        error_log('[feiniao] fn_job_queue missing — run php Public/migrate_schema.php');
    }
    return $ready;
}

/** R-02：回收超时 running 任务 */
function feiniao_job_reclaim_stale($leaseSec = 120)
{
    if (!feiniao_job_queue_ensure()) {
        return 0;
    }
    $leaseSec = max(30, min(3600, (int) $leaseSec));
    $cutoff = date('Y-m-d H:i:s', time() - $leaseSec);
    $now = date('Y-m-d H:i:s');
    @$GLOBALS['D']->query(
        "UPDATE `fn_job_queue` SET `status`='failed', `available_at`='{$now}', `updated_at`='{$now}',
         `last_error`=CONCAT(IFNULL(`last_error`,''), ' | reclaimed stale lease')
         WHERE `status`='running' AND `locked_at` IS NOT NULL AND `locked_at` < '{$cutoff}'"
    );
    return !empty($GLOBALS['D']) ? (int) $GLOBALS['D']->affected_rows : 0;
}

function feiniao_job_enqueue($jobType, $typeId, $payload, $jobKey = '')
{
    if (!feiniao_job_queue_ensure()) {
        return false;
    }
    $jobType = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $jobType);
    $typeId = (int) $typeId;
    if ($jobKey === '') {
        $jobKey = $jobType . ':' . $typeId . ':' . md5(json_encode($payload));
    }
    $jobKey = substr(preg_replace('/\s+/', '', (string) $jobKey), 0, 190);
    $now = date('Y-m-d H:i:s');
    $payloadJson = db_escape_string(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $jobKeyEsc = db_escape_string($jobKey);
    $jobTypeEsc = db_escape_string($jobType);
    // running / done 不得回退；但 dead 不能永久封死展示补偿任务。
    // 开奖三图、结果文案失败后会使用同一个 job_key 重试，旧逻辑保留 dead，
    // 导致后续每一轮都看似“已入队”却永远不再执行。
    $sql = "INSERT INTO `fn_job_queue`
        (`job_key`,`job_type`,`type_id`,`payload`,`status`,`attempts`,`available_at`,`created_at`,`updated_at`)
        VALUES ('{$jobKeyEsc}','{$jobTypeEsc}',{$typeId},'{$payloadJson}','pending',0,'{$now}','{$now}','{$now}')
        ON DUPLICATE KEY UPDATE
          `updated_at`=VALUES(`updated_at`),
          `status`=IF(`status` IN ('done','running'),`status`,'pending'),
          `attempts`=IF(`status`='dead',0,`attempts`),
          `available_at`=IF(`status` IN ('done','running'),`available_at`,VALUES(`available_at`)),
          `payload`=IF(`status` IN ('done','running'),`payload`,VALUES(`payload`))";
    return (bool) @$GLOBALS['D']->query($sql);
}

function feiniao_job_claim_batch($limit = 3, $workerId = '', $jobType = '', $typeId = 0)
{
    if (!feiniao_job_queue_ensure()) {
        return array();
    }
    feiniao_job_reclaim_stale(120);
    $limit = max(1, min(20, (int) $limit));
    $workerId = $workerId !== '' ? $workerId : ('w' . getmypid());
    $workerEsc = db_escape_string(substr($workerId, 0, 60));
    $now = date('Y-m-d H:i:s');
    $typeFilter = '';
    if ($jobType !== '') {
        $jobType = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $jobType);
        $typeFilter = " AND `job_type` = '" . db_escape_string($jobType) . "'";
    }
    $typeId = (int) $typeId;
    if ($typeId > 0) {
        $typeFilter .= " AND `type_id` = {$typeId}";
    }
    $rows = array();
    $res = @$GLOBALS['D']->query(
        "SELECT `id` FROM `fn_job_queue`
         WHERE `status` IN ('pending','failed') AND `available_at` <= '{$now}' AND `attempts` < `max_attempts`{$typeFilter}
         /* 直播补偿必须优先当前期：旧期会被消费者的跨期闸门隔离，不能让它
            排在当前 status=2 之前，把界面长期卡在开奖中。 */
         ORDER BY `id` DESC LIMIT {$limit}"
    );
    if (!$res) {
        return array();
    }
    $ids = array();
    while ($r = $res->fetch_assoc()) {
        $ids[] = (int) $r['id'];
    }
    $res->free();
    foreach ($ids as $id) {
        @$GLOBALS['D']->query(
            "UPDATE `fn_job_queue` SET `status`='running', `locked_at`='{$now}', `locked_by`='{$workerEsc}',
             `attempts`=`attempts`+1, `updated_at`='{$now}'
             WHERE `id`={$id} AND `status` IN ('pending','failed')"
        );
        if (!empty($GLOBALS['D']) && (int) $GLOBALS['D']->affected_rows !== 1) {
            continue;
        }
        $one = @$GLOBALS['D']->query("SELECT * FROM `fn_job_queue` WHERE `id`={$id} LIMIT 1");
        if ($one && ($row = $one->fetch_assoc())) {
            $rows[] = $row;
        }
        if ($one) {
            $one->free();
        }
    }
    return $rows;
}

function feiniao_job_mark_done($id, $workerId = '')
{
    $id = (int) $id;
    $now = date('Y-m-d H:i:s');
    $extra = '';
    if ($workerId !== '') {
        $extra = " AND `locked_by`='" . db_escape_string(substr($workerId, 0, 60)) . "'";
    }
    @$GLOBALS['D']->query(
        "UPDATE `fn_job_queue` SET `status`='done', `updated_at`='{$now}', `last_error`='', `locked_by`=NULL
         WHERE `id`={$id} AND `status`='running'{$extra}"
    );
}

function feiniao_job_mark_failed($id, $error, $retryDelaySec = 15, $workerId = '')
{
    $id = (int) $id;
    $nowTs = time();
    $avail = date('Y-m-d H:i:s', $nowTs + max(5, (int) $retryDelaySec));
    $err = db_escape_string(substr((string) $error, 0, 1000));
    $ts = date('Y-m-d H:i:s', $nowTs);
    $extra = '';
    if ($workerId !== '') {
        $extra = " AND `locked_by`='" . db_escape_string(substr($workerId, 0, 60)) . "'";
    }
    // 先读 attempts，超限转 dead
    $one = @$GLOBALS['D']->query("SELECT `attempts`,`max_attempts` FROM `fn_job_queue` WHERE `id`={$id} LIMIT 1");
    $attempts = 0;
    $max = 8;
    if ($one && ($row = $one->fetch_assoc())) {
        $attempts = (int) $row['attempts'];
        $max = (int) $row['max_attempts'];
    }
    if ($one) {
        $one->free();
    }
    if ($attempts >= $max) {
        @$GLOBALS['D']->query(
            "UPDATE `fn_job_queue` SET `status`='dead', `updated_at`='{$ts}', `last_error`='{$err}', `locked_by`=NULL
             WHERE `id`={$id} AND `status`='running'{$extra}"
        );
        error_log('[feiniao][dead-job] id=' . $id . ' err=' . $error);
        return;
    }
    @$GLOBALS['D']->query(
        "UPDATE `fn_job_queue` SET `status`='failed', `available_at`='{$avail}', `updated_at`='{$ts}',
         `last_error`='{$err}', `locked_by`=NULL
         WHERE `id`={$id} AND `status`='running'{$extra}"
    );
}

function feiniao_job_count_dead()
{
    if (!feiniao_job_queue_ensure()) {
        return 0;
    }
    $res = @$GLOBALS['D']->query("SELECT COUNT(*) AS c FROM `fn_job_queue` WHERE `status`='dead'");
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return (int) (isset($row['c']) ? $row['c'] : 0);
}

function feiniao_job_requeue($id)
{
    $id = (int) $id;
    $now = date('Y-m-d H:i:s');
    @$GLOBALS['D']->query(
        "UPDATE `fn_job_queue` SET `status`='pending', `available_at`='{$now}', `updated_at`='{$now}',
         `attempts`=0, `locked_at`=NULL, `locked_by`=NULL, `last_error`=CONCAT(IFNULL(`last_error`,''),' | manual requeue')
         WHERE `id`={$id} AND `status` IN ('dead','failed')"
    );
    return !empty($GLOBALS['D']) && (int) $GLOBALS['D']->affected_rows === 1;
}
