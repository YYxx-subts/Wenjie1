<?php
/**
 * R-04：共享账本 — 事务内唯一 pay_key + 余额变更
 * 运行期不做 ALTER；缺列则 fail-closed。
 */

if (!function_exists('feiniao_ledger_pay_key_ready')) {
function feiniao_ledger_pay_key_ready()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        $ready = false;
        return false;
    }
    $res = @$GLOBALS['D']->query("SHOW INDEX FROM `fn_marklog` WHERE Key_name = 'uk_pay_key'");
    $ready = ($res && $res->fetch_assoc());
    if ($res) {
        $res->free();
    }
    if (!$ready) {
        error_log('[feiniao] ledger refuse: run php Public/migrate_schema.php (uk_pay_key missing)');
    }
    return $ready;
}
}

if (!function_exists('feiniao_ledger_begin')) {
function feiniao_ledger_begin()
{
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        db_connect();
    }
    @mysqli_begin_transaction($GLOBALS['D']);
}
function feiniao_ledger_commit()
{
    if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
        return (bool) @mysqli_commit($GLOBALS['D']);
    }
    return false;
}
function feiniao_ledger_rollback()
{
    if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
        @mysqli_rollback($GLOBALS['D']);
    }
}
function feiniao_ledger_affected()
{
    if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
        return (int) $GLOBALS['D']->affected_rows;
    }
    return 0;
}
function feiniao_ledger_errno()
{
    if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli) {
        return (int) $GLOBALS['D']->errno;
    }
    return 0;
}
/** 仅当 pay_key 已存在或明确唯一键冲突时视为幂等 */
function feiniao_ledger_is_dup_key($payKeyEsc)
{
    $errno = feiniao_ledger_errno();
    $dup = get_query_val('fn_marklog', 'id', "`pay_key` = '{$payKeyEsc}' LIMIT 1");
    if (!empty($dup)) {
        return true;
    }
    return $errno === 1062;
}
}

/**
 * @return true|false|'dup'  dup=幂等已存在
 */
if (!function_exists('feiniao_ledger_credit')) {
function feiniao_ledger_credit($userid, $room, $money, $payKey, $content, $type = '上分', $useOuterTx = false)
{
    if ((string) $userid === 'robot' || (string) $userid === 'system') {
        return false;
    }
    $money = (float) $money;
    $payKey = substr(preg_replace('/\s+/', '', (string) $payKey), 0, 96);
    $room = (int) $room;
    if ($money <= 0 || $payKey === '' || $room <= 0 || $userid === '') {
        return false;
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return false;
    }
    $ownTx = !$useOuterTx;
    try {
        if ($ownTx) {
            feiniao_ledger_begin();
        }
        $payKeyEsc = db_escape_string($payKey);
        $dup = get_query_val('fn_marklog', 'id', "`pay_key` = '{$payKeyEsc}' LIMIT 1");
        if (!empty($dup)) {
            if ($ownTx) {
                if (!feiniao_ledger_commit()) {
                    feiniao_ledger_rollback();
                    return false;
                }
            }
            return 'dup';
        }
        // 先插流水（唯一约束占位），再改余额
        $ins = insert_query('fn_marklog', array(
            'userid' => $userid,
            'type' => $type,
            'content' => (string) $content,
            'money' => $money,
            'roomid' => $room,
            'pay_key' => $payKey,
            'addtime' => 'now()',
        ));
        if ($ins === false) {
            // P0：外层事务不得把任意插入失败当成 dup，否则会提交「已撤单未退款」
            $isDup = feiniao_ledger_is_dup_key($payKeyEsc);
            if ($ownTx) {
                feiniao_ledger_rollback();
            }
            return $isDup ? 'dup' : false;
        }
        $uidEsc = db_escape_string((string) $userid);
        db_query(
            "UPDATE `fn_user` SET `money` = `money` + {$money}
             WHERE `userid` = '{$uidEsc}' AND `roomid` = {$room}"
        );
        if (feiniao_ledger_affected() !== 1) {
            if ($ownTx) {
                feiniao_ledger_rollback();
            } else {
                throw new RuntimeException('credit user missing');
            }
            return false;
        }
        if ($ownTx) {
            if (!feiniao_ledger_commit()) {
                feiniao_ledger_rollback();
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        if ($ownTx) {
            feiniao_ledger_rollback();
        }
        if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), 'uk_pay_key') !== false) {
            $payKeyEsc = db_escape_string($payKey);
            return feiniao_ledger_is_dup_key($payKeyEsc) ? 'dup' : false;
        }
        if ($e->getMessage() === 'credit user missing') {
            return false;
        }
        error_log('[feiniao] ledger credit failed: ' . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('feiniao_ledger_debit')) {
function feiniao_ledger_debit($userid, $room, $money, $payKey, $content, $useOuterTx = false)
{
    if ((string) $userid === 'robot' || (string) $userid === 'system') {
        return false;
    }
    $money = (float) $money;
    $payKey = substr(preg_replace('/\s+/', '', (string) $payKey), 0, 96);
    $room = (int) $room;
    if ($money <= 0 || $payKey === '' || $room <= 0 || $userid === '') {
        return false;
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return false;
    }
    $ownTx = !$useOuterTx;
    try {
        if ($ownTx) {
            feiniao_ledger_begin();
        }
        $payKeyEsc = db_escape_string($payKey);
        // 批量下注外层事务 + BET:表:新orderId 不会撞库，跳过预查
        $skipDupPrecheck = $useOuterTx && (strpos($payKey, 'BET:') === 0);
        if (!$skipDupPrecheck) {
            $dup = get_query_val('fn_marklog', 'id', "`pay_key` = '{$payKeyEsc}' LIMIT 1");
            if (!empty($dup)) {
                if ($ownTx) {
                    if (!feiniao_ledger_commit()) {
                        feiniao_ledger_rollback();
                        return false;
                    }
                }
                return 'dup';
            }
        }
        $ins = insert_query('fn_marklog', array(
            'userid' => $userid,
            'type' => '下分',
            'content' => (string) $content,
            'money' => $money,
            'roomid' => $room,
            'pay_key' => $payKey,
            'addtime' => 'now()',
        ));
        if ($ins === false) {
            $isDup = feiniao_ledger_is_dup_key($payKeyEsc);
            if ($ownTx) {
                feiniao_ledger_rollback();
            }
            return $isDup ? 'dup' : false;
        }
        $uidEsc = db_escape_string((string) $userid);
        db_query(
            "UPDATE `fn_user` SET `money` = `money` - {$money}
             WHERE `userid` = '{$uidEsc}' AND `roomid` = {$room} AND `money` >= {$money}"
        );
        if (feiniao_ledger_affected() !== 1) {
            if ($ownTx) {
                feiniao_ledger_rollback();
            } else {
                // 外层事务由调用方回滚
                throw new RuntimeException('insufficient balance');
            }
            return false;
        }
        if ($ownTx) {
            if (!feiniao_ledger_commit()) {
                feiniao_ledger_rollback();
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        if ($ownTx) {
            feiniao_ledger_rollback();
        }
        if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), 'uk_pay_key') !== false) {
            $payKeyEsc = db_escape_string($payKey);
            return feiniao_ledger_is_dup_key($payKeyEsc) ? 'dup' : false;
        }
        if ($e->getMessage() === 'insufficient balance') {
            return false;
        }
        error_log('[feiniao] ledger debit failed: ' . $e->getMessage());
        return false;
    }
}
}

/**
 * P0-02：撤单原子操作 — 订单 CAS 撤单 + 唯一退款同事务
 * @return array{ok:bool,msg?:string,dup?:bool}
 */
if (!function_exists('feiniao_cancel_order_atomic')) {
function feiniao_cancel_order_atomic($table, $orderId, $userid, $room, $payKeyPrefix = 'UREFUND')
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $orderId = (int) $orderId;
    $room = (int) $room;
    $userid = (string) $userid;
    if ($table === '' || $orderId <= 0 || $room <= 0 || $userid === '') {
        return array('ok' => false, 'msg' => '参数错误');
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return array('ok' => false, 'msg' => '请先执行 migrate_schema.php');
    }
    try {
        feiniao_ledger_begin();
        $uidEsc = db_escape_string($userid);
        // 行级条件更新：仅本人本房未结算可撤
        db_query(
            "UPDATE `{$table}` SET `status` = '已撤单'
             WHERE `id` = {$orderId} AND `roomid` = {$room}
               AND `userid` = '{$uidEsc}' AND `status` = '未结算'"
        );
        if (feiniao_ledger_affected() !== 1) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '仅未结算可撤或无权操作');
        }
        $row = get_query_vals($table, 'money,term,userid', array('id' => $orderId));
        if (!feiniao_daily_stats_reconcile_order($table, $orderId)) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '统计同步失败');
        }
        $money = $row ? (float) $row['money'] : 0;
        $term = $row && isset($row['term']) ? $row['term'] : '';
        if ($money > 0 && $userid !== 'robot') {
            $payKey = $payKeyPrefix . ':' . $table . ':' . $orderId;
            $r = feiniao_ledger_credit(
                $userid,
                $room,
                $money,
                $payKey,
                '撤单退还' . $term . '期下注',
                '上分',
                true
            );
            if ($r === false) {
                feiniao_ledger_rollback();
                return array('ok' => false, 'msg' => '退款失败');
            }
        }
        if (!feiniao_ledger_commit()) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '提交失败');
        }
        return array('ok' => true);
    } catch (Throwable $e) {
        feiniao_ledger_rollback();
        error_log('[feiniao] cancel_order_atomic: ' . $e->getMessage());
        return array('ok' => false, 'msg' => '撤单失败');
    }
}
}

/**
 * Agent 撤单：按房间校验（不强制 userid=操作者）
 */
if (!function_exists('feiniao_cancel_order_atomic_room')) {
function feiniao_cancel_order_atomic_room($table, $row, $room)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $orderId = (int) $row['id'];
    $room = (int) $room;
    $userid = (string) $row['userid'];
    if ($table === '' || $orderId <= 0 || (string) $row['status'] !== '未结算') {
        return array('ok' => false, 'msg' => '仅未结算可撤');
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return array('ok' => false, 'msg' => '请先执行 migrate_schema.php');
    }
    try {
        feiniao_ledger_begin();
        db_query(
            "UPDATE `{$table}` SET `status` = '已撤单'
             WHERE `id` = {$orderId} AND `roomid` = {$room} AND `status` = '未结算'"
        );
        if (feiniao_ledger_affected() !== 1) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '撤单失败或已处理');
        }
        if (!feiniao_daily_stats_reconcile_order($table, $orderId)) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '统计同步失败');
        }
        $money = (float) $row['money'];
        if ($money > 0 && $userid !== '' && $userid !== 'robot') {
            $r = feiniao_ledger_credit(
                $userid,
                $room,
                $money,
                'REFUND:' . $table . ':' . $orderId,
                '管理员退还' . $row['term'] . '期下注',
                '上分',
                true
            );
            if ($r === false) {
                feiniao_ledger_rollback();
                return array('ok' => false, 'msg' => '退款失败');
            }
        }
        if (!feiniao_ledger_commit()) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '提交失败');
        }
        return array('ok' => true);
    } catch (Throwable $e) {
        feiniao_ledger_rollback();
        error_log('[feiniao] cancel_order_atomic_room: ' . $e->getMessage());
        return array('ok' => false, 'msg' => '撤单失败');
    }
}
}
