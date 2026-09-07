<?php

/*
 * Third-party draw feed adapter. Credentials belong in draw_feed.local.php,
 * which is intentionally excluded from the deployment package.
 */
function feiniao_draw_defaults()
{
    return array(
        'timeout' => 8,
        'feeds' => array(
            // 1 澳洲幸运10 ← api68 lotCode=10012
            1 => array(
                'enabled' => true,
                'format' => 'api68',
                'url' => 'https://api.api68.com/pks/getLotteryPksInfo.do?lotCode=10012',
                'lotCode' => '10012',
                'issue_digits' => 0,
                'interval' => 300,
                'close_seconds' => 0,
                'number_count' => 10,
                'number_min' => 1,
                'number_max' => 10,
                'unique_numbers' => true,
            ),
            2 => array(
                'enabled' => true,
                'format' => 'atmkai',
                'url' => 'https://www.atmkai.com/api/draws/latest',
                'gid' => 'pk10_fast',
                'issue_digits' => 0,
                'interval' => 300,
                'close_seconds' => 0,
                'number_count' => 10,
                'number_min' => 1,
                'number_max' => 10,
                'unique_numbers' => true,
            ),
            // 3 极速飞艇 ← api68 lotCode=10035
            3 => array(
                'enabled' => true,
                'format' => 'api68',
                'url' => 'https://api.api68.com/pks/getLotteryPksInfo.do?lotCode=10035',
                'lotCode' => '10035',
                'issue_digits' => 0,
                'interval' => 75,
                'close_seconds' => 0,
                'number_count' => 10,
                'number_min' => 1,
                'number_max' => 10,
                'unique_numbers' => true,
            ),
            // 4 极速赛车 ← api68 lotCode=10037
            4 => array(
                'enabled' => true,
                'format' => 'api68',
                'url' => 'https://api.api68.com/pks/getLotteryPksInfo.do?lotCode=10037',
                'lotCode' => '10037',
                'issue_digits' => 0,
                'interval' => 75,
                'close_seconds' => 0,
                'number_count' => 10,
                'number_min' => 1,
                'number_max' => 10,
                'unique_numbers' => true,
            ),
            5 => array(
                'enabled' => true,
                'format' => 'atmkai',
                'url' => 'https://www.atmkai.com/api/draws/latest',
                'gid' => 'pc28_fast',
                'issue_digits' => 0,
                'interval' => 280,
                // next_time=整期时长；提前封盘只看后台 fengtime，避免与 close_seconds 重复扣减
                'close_seconds' => 0,
                'number_count' => 3,
                'number_min' => 0,
                'number_max' => 9,
                'unique_numbers' => false,
            ),
            6 => array(
                'enabled' => true,
                'format' => 'atmkai',
                'url' => 'https://www.atmkai.com/api/draws/latest',
                'gid' => 'speed_ball',
                'issue_digits' => 0,
                'interval' => 90,
                'close_seconds' => 0,
                'number_count' => 10,
                'number_min' => 1,
                'number_max' => 10,
                'unique_numbers' => true,
            ),
            // 7 弹珠PK10
            7 => array(
                'enabled' => true,
                'format' => 'atmkai',
                'url' => 'https://www.atmkai.com/api/draws/latest',
                'gid' => 'pk10_fast',
                'issue_digits' => 0,
                'interval' => 300,
                'close_seconds' => 0,
                'number_count' => 10,
                'number_min' => 1,
                'number_max' => 10,
                'unique_numbers' => true,
            ),
            // 8 极速时时彩 ← api68 lotCode=10036
            8 => array(
                'enabled' => true,
                'format' => 'api68',
                'url' => 'https://api.api68.com/CQShiCai/getBaseCQShiCai.do?lotCode=10036',
                'lotCode' => '10036',
                'issue_digits' => 0,
                'interval' => 75,
                'close_seconds' => 0,
                'number_count' => 5,
                'number_min' => 0,
                'number_max' => 9,
                'unique_numbers' => false,
            ),
            // 9 澳洲幸运5 ← api68 lotCode=10010
            9 => array(
                'enabled' => true,
                'format' => 'api68',
                'url' => 'https://api.api68.com/CQShiCai/getBaseCQShiCai.do?lotCode=10010',
                'lotCode' => '10010',
                'issue_digits' => 0,
                'interval' => 300,
                'close_seconds' => 0,
                'number_count' => 5,
                'number_min' => 0,
                'number_max' => 9,
                'unique_numbers' => false,
            ),
            // 10 弹珠六合彩
            10 => array(
                'enabled' => true,
                'format' => 'atmkai',
                'url' => 'https://www.atmkai.com/api/draws/latest',
                'gid' => 'lhc_fast',
                'issue_digits' => 0,
                'interval' => 600,
                'close_seconds' => 0,
                'number_count' => 7,
                'number_min' => 1,
                'number_max' => 49,
                'unique_numbers' => true,
            ),
        ),
    );
}

function feiniao_draw_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = feiniao_draw_defaults();
    $localFile = __DIR__ . '/draw_feed.local.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $config = array_replace_recursive($config, $local);
        }
    }

    foreach ($config['feeds'] as $typeId => $feed) {
        $envUrl = getenv('FEINIAO_DRAW_FEED_' . $typeId . '_URL');
        if ($envUrl !== false && $envUrl !== '') {
            $config['feeds'][$typeId]['url'] = $envUrl;
            $config['feeds'][$typeId]['enabled'] = true;
        }
        $envGid = getenv('FEINIAO_DRAW_FEED_' . $typeId . '_GID');
        if ($envGid !== false && $envGid !== '') {
            $config['feeds'][$typeId]['gid'] = $envGid;
        }
    }
    return $config;
}

function feiniao_draw_path($data, $path)
{
    if ($path === '') {
        return null;
    }
    $value = $data;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

function feiniao_draw_first($data, $paths)
{
    foreach (explode('|', $paths) as $path) {
        $value = feiniao_draw_path($data, trim($path));
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

function feiniao_draw_timestamp($value)
{
    $ms = feiniao_draw_timestamp_ms($value);
    return $ms > 0 ? (int) floor($ms / 1000) : 0;
}

/** P1-03：尽量保留源数据毫秒；数字秒则 ×1000（后三位为 000） */
function feiniao_draw_timestamp_ms($value)
{
    if (is_numeric($value)) {
        $n = (float) $value;
        if ($n > 20000000000) {
            return (int) $n; // 已是毫秒
        }
        if ($n > 0) {
            return (int) round($n * 1000);
        }
        return 0;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return 0;
    }
    try {
        $dt = new DateTime($text, new DateTimeZone('Asia/Shanghai'));
        $sec = $dt->getTimestamp();
        $micro = (int) $dt->format('u');
        return $sec * 1000 + (int) floor($micro / 1000);
    } catch (Exception $e) {
        $timestamp = strtotime($text);
        return $timestamp === false ? 0 : ((int) $timestamp * 1000);
    }
}

function feiniao_draw_numbers($raw)
{
    if (is_array($raw)) {
        $numbers = $raw;
    } else {
        $numbers = preg_split('/[\\s,|+]+/', trim((string) $raw));
    }
    $result = array();
    foreach ($numbers as $number) {
        if ($number === '' || !preg_match('/^\\d+$/', (string) $number)) {
            throw new RuntimeException('第三方接口包含非法开奖号码');
        }
        $result[] = (string) ((int) $number);
    }
    return $result;
}

function feiniao_draw_issue($value, $digits)
{
    $value = trim((string) $value);
    if (!preg_match('/^\\d+$/', $value)) {
        throw new RuntimeException('第三方接口缺少有效期号');
    }
    if ((int) $digits > 0) {
        $value = substr($value, -((int) $digits));
    }
    return $value;
}

function feiniao_draw_http_get($url, $timeout, $headers = array())
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('第三方接口地址无效');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL 扩展未启用');
    }

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min(5, (int) $timeout));
    curl_setopt($curl, CURLOPT_TIMEOUT, (int) $timeout);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    $requestHeaders = array('Accept: application/json', 'User-Agent: FeiNiaoDrawFeed/1.0');
    foreach ((array) $headers as $header) {
        if (is_string($header) && strpos($header, ':') !== false) {
            $requestHeaders[] = $header;
        }
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    if (PHP_VERSION_ID < 80000) {
        curl_close($curl);
    }
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('第三方接口请求失败' . ($error ? ': ' . $error : '，HTTP ' . $status));
    }
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('第三方接口未返回 JSON 数据');
    }
    return $payload;
}

function feiniao_draw_parse($payload, $feed)
{
    $format = isset($feed['format']) ? strtolower($feed['format']) : 'standard';
    if ($format === 'atmkai') {
        $gid = isset($feed['gid']) ? trim((string) $feed['gid']) : '';
        if ($gid === '') {
            throw new RuntimeException('开奖网接口缺少游戏标识');
        }
        $record = null;
        foreach ($payload as $item) {
            if (is_array($item) && isset($item['gid']) && (string) $item['gid'] === $gid) {
                $record = $item;
                break;
            }
        }
        if (!is_array($record)) {
            throw new RuntimeException('开奖网接口未找到游戏 ' . $gid);
        }

        $numbers = isset($record['balls']) && is_array($record['balls']) ? $record['balls'] : array();
        $status = isset($record['status']) ? strtolower(trim((string) $record['status'])) : '';
        $marketState = isset($record['marketState']) ? strtolower(trim((string) $record['marketState'])) : '';
        // AtmKai 相位（不能只看 balls 是否为空）：
        // - publishing：仍有 balls，或 status=drawing / market=closed（Result 动画中 balls 常已清空）
        // - betting：status=live 且 market=open，且 drawAt 仍在未来（与直播倒计时同源）
        // 注意：periodSeconds 常虚高（弹珠PK10 报 300，实际期距约 240~250s），开盘倒计时必须跟 drawAt。
        $drawingLike = in_array($status, array('drawing', 'result', 'settling'), true)
            || in_array($marketState, array('closed', 'sealed', 'drawing'), true);
        if (!empty($numbers) || $drawingLike) {
            if (!empty($numbers)) {
                $raw = array(
                    'issue' => isset($record['issue']) ? $record['issue'] : null,
                    'numbers' => $numbers,
                    'opened_at' => isset($record['drawAt']) ? $record['drawAt'] : null,
                    'next_issue' => null,
                    'next_at' => null,
                    'market_state' => $marketState !== '' ? $marketState : (isset($record['marketState']) ? $record['marketState'] : null),
                    'period_seconds' => isset($record['periodSeconds']) ? $record['periodSeconds'] : null,
                    'live_phase' => 'publishing',
                    'feed_status' => $status,
                );
            } else {
                $history = isset($record['history']) && is_array($record['history']) ? $record['history'] : array();
                $latest = isset($history[0]) && is_array($history[0]) ? $history[0] : null;
                if (!is_array($latest) || empty($latest['balls'])) {
                    throw new RuntimeException('开奖网接口尚未返回已开奖数据');
                }
                $raw = array(
                    'issue' => isset($latest['issue']) ? $latest['issue'] : null,
                    'numbers' => $latest['balls'],
                    'opened_at' => isset($latest['drawAt']) ? $latest['drawAt'] : null,
                    'next_issue' => isset($record['issue']) ? $record['issue'] : null,
                    // 结果展示中 drawAt 常已过期；保留原值，禁止采集侧用 periodSeconds 垫成 4~5 分钟假倒计时
                    'next_at' => isset($record['drawAt']) ? $record['drawAt'] : null,
                    'market_state' => $marketState !== '' ? $marketState : 'closed',
                    'period_seconds' => isset($record['periodSeconds']) ? $record['periodSeconds'] : null,
                    'live_phase' => 'publishing',
                    'feed_status' => $status,
                );
            }
        } else {
            $history = isset($record['history']) && is_array($record['history']) ? $record['history'] : array();
            $latest = isset($history[0]) && is_array($history[0]) ? $history[0] : null;
            if (!is_array($latest) || empty($latest['balls'])) {
                throw new RuntimeException('开奖网接口尚未返回已开奖数据');
            }
            $nextAtCandidate = isset($record['drawAt']) ? $record['drawAt'] : null;
            $nextAtTs = $nextAtCandidate !== null ? feiniao_draw_timestamp($nextAtCandidate) : 0;
            // 必须 status=live 且 market=open 才算直播倒计时；drawAt 已过点则仍保持空档（禁止垫假倒计时）
            $bettingLike = in_array($status, array('live', 'open'), true)
                && in_array($marketState, array('open', ''), true)
                && ($nextAtTs <= 0 || $nextAtTs > time());
            // 开盘期必须紧接接口给出的最新实际结果期。上游偶发“当前期已跳到
            // 328、history 仍停在 302”的断档包；若接受它，系统会拿旧结果给新期
            // 结算，造成错账、错图、封盘链无法闭合。断档时 fail-closed 等待恢复。
            $latestIssue = isset($latest['issue']) ? trim((string) $latest['issue']) : '';
            $currentIssue = isset($record['issue']) ? trim((string) $record['issue']) : '';
            if ($bettingLike && ctype_digit($latestIssue) && ctype_digit($currentIssue)
                && (int) $currentIssue !== ((int) $latestIssue + 1)) {
                // 当前期号跳过了历史最新结果，说明上游结果链路已断。
                // 不能把未知期号当成正常开盘，否则会继续下注/发机器人单。
                $bettingLike = false;
                $marketState = 'stale_gap';
            }
            $raw = array(
                'issue' => isset($latest['issue']) ? $latest['issue'] : null,
                'numbers' => $latest['balls'],
                'opened_at' => isset($latest['drawAt']) ? $latest['drawAt'] : null,
                'next_issue' => isset($record['issue']) ? $record['issue'] : null,
                'next_at' => $nextAtCandidate,
                'market_state' => $marketState !== '' ? $marketState : (isset($record['marketState']) ? $record['marketState'] : null),
                'period_seconds' => isset($record['periodSeconds']) ? $record['periodSeconds'] : null,
                'live_phase' => $bettingLike ? 'betting' : 'publishing',
                'feed_status' => $status,
            );
        }
        // 保留上游 history，供采集器在短暂断链恢复时逐期补结算。
        // 绝不能只取 history[0] 后把数据库从旧期直接跳到新期，否则中间真实投注会丢失。
        $raw['history_rows'] = isset($record['history']) && is_array($record['history'])
            ? $record['history'] : array();
    } elseif ($format === 'api68') {
        if (isset($payload['errorCode']) && (string) $payload['errorCode'] !== '0' && (int) $payload['errorCode'] !== 0) {
            throw new RuntimeException('API68 返回错误码 ' . $payload['errorCode']);
        }
        $data = feiniao_draw_path($payload, 'result.data');
        if (!is_array($data) && isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        }
        if (!is_array($data)) {
            throw new RuntimeException('API68 响应结构异常');
        }
        $raw = array(
            'issue' => isset($data['preDrawIssue']) ? $data['preDrawIssue'] : null,
            'numbers' => isset($data['preDrawCode']) ? $data['preDrawCode'] : null,
            'opened_at' => isset($data['preDrawTime']) ? $data['preDrawTime'] : null,
            'next_issue' => isset($data['drawIssue']) ? $data['drawIssue'] : null,
            'next_at' => isset($data['drawTime']) ? $data['drawTime'] : null,
            'server_time' => isset($data['serverTime']) ? $data['serverTime'] : null,
        );
    } elseif ($format === 'openapi110') {
        $data = feiniao_draw_path($payload, 'data.0');
        if (!is_array($data)) {
            throw new RuntimeException('110API 响应结构异常');
        }
        $raw = array(
            'issue' => isset($data['expect']) ? $data['expect'] : null,
            'numbers' => isset($data['opencode']) ? $data['opencode'] : null,
            'opened_at' => isset($data['opentime']) ? $data['opentime'] : null,
            'next_issue' => null,
            'next_at' => null,
        );
    } else {
        $dataPath = isset($feed['data_path']) ? $feed['data_path'] : '';
        $data = $dataPath === '' ? $payload : feiniao_draw_path($payload, $dataPath);
        if (!is_array($data)) {
            throw new RuntimeException('第三方接口数据节点异常');
        }
        $raw = array(
            'issue' => feiniao_draw_first($data, isset($feed['issue_path']) ? $feed['issue_path'] : 'issue|preDrawIssue|expect|currentIssue'),
            'numbers' => feiniao_draw_first($data, isset($feed['numbers_path']) ? $feed['numbers_path'] : 'numbers|openCode|open_num|preDrawCode|opencode'),
            'opened_at' => feiniao_draw_first($data, isset($feed['opened_at_path']) ? $feed['opened_at_path'] : 'openedAt|openTime|preDrawTime|opentime|time'),
            'next_issue' => feiniao_draw_first($data, isset($feed['next_issue_path']) ? $feed['next_issue_path'] : 'nextIssue|drawIssue|next_issue'),
            'next_at' => feiniao_draw_first($data, isset($feed['next_at_path']) ? $feed['next_at_path'] : 'nextAt|nextTime|drawTime|next_time'),
        );
    }

    $digits = isset($feed['issue_digits']) ? (int) $feed['issue_digits'] : 0;
    $openedAt = feiniao_draw_timestamp($raw['opened_at']);
    if ($openedAt <= 0) {
        throw new RuntimeException('第三方接口缺少有效开奖时间');
    }
    $issue = feiniao_draw_issue($raw['issue'], $digits);
    $numbers = feiniao_draw_numbers($raw['numbers']);
    $expectedCount = isset($feed['number_count']) ? (int) $feed['number_count'] : 0;
    if ($expectedCount && count($numbers) !== $expectedCount) {
        throw new RuntimeException('第三方接口开奖号码数量错误');
    }
    $seen = array();
    foreach ($numbers as $number) {
        if (isset($feed['number_min']) && (int) $number < (int) $feed['number_min']) {
            throw new RuntimeException('第三方接口开奖号码超出下限');
        }
        if (isset($feed['number_max']) && (int) $number > (int) $feed['number_max']) {
            throw new RuntimeException('第三方接口开奖号码超出上限');
        }
        if (!empty($feed['unique_numbers'])) {
            if (isset($seen[$number])) {
                throw new RuntimeException('第三方接口开奖号码重复');
            }
            $seen[$number] = true;
        }
    }

    $nextIssue = $raw['next_issue'] !== null && $raw['next_issue'] !== ''
        ? feiniao_draw_issue($raw['next_issue'], $digits)
        : (string) ((int) $issue + 1);
    $nextAtMs = feiniao_draw_timestamp_ms(isset($raw['next_at']) ? $raw['next_at'] : null);
    $nextAt = $nextAtMs > 0 ? (int) floor($nextAtMs / 1000) : 0;
    $interval = isset($feed['interval']) ? (int) $feed['interval'] : 0;
    if (!empty($raw['period_seconds']) && (int) $raw['period_seconds'] > 0) {
        $interval = (int) $raw['period_seconds'];
    }
    $livePhaseRaw = isset($raw['live_phase']) ? strtolower(trim((string) $raw['live_phase'])) : '';
    if ($nextAt <= $openedAt) {
        // 直播结果动画中：禁止用 periodSeconds（弹珠PK10 常虚高到 300）伪造下一期，
        // 否则会在视频还在 Result 时顶栏就冒出 4 分钟倒计时。
        if ($format === 'atmkai' && $livePhaseRaw === 'publishing') {
            $nextAt = $openedAt > 0 ? $openedAt : (time() - 1);
            $nextAtMs = $nextAt * 1000;
        } else {
            $closeSeconds = isset($feed['close_seconds']) ? (int) $feed['close_seconds'] : 0;
            if ($interval <= 0) {
                throw new RuntimeException('第三方接口缺少下一期开奖时间');
            }
            $nextAt = $openedAt + $interval - $closeSeconds;
            if ($nextAt <= $openedAt) {
                $nextAt = $openedAt + $interval;
            }
            $nextAtMs = $nextAt * 1000;
        }
    }
    // api68：接口偶发晚翻期。serverTime 已过 drawTime 时，下注窗口先翻到下一期，避免整期落后官网
    if ($format === 'api68' && $interval > 0) {
        $serverTs = !empty($raw['server_time']) ? feiniao_draw_timestamp($raw['server_time']) : time();
        if ($serverTs <= 0) {
            $serverTs = time();
        }
        $rolls = 0;
        while ($nextAt > 0 && $serverTs >= $nextAt && $rolls < 8) {
            if (!preg_match('/^\d+$/', (string) $nextIssue)) {
                break;
            }
            $nextIssue = (string) ((int) $nextIssue + 1);
            $nextAt += $interval;
            $nextAtMs = $nextAt * 1000;
            $rolls++;
        }
    }
    // AtmKai：仅给「非直播」玩法短 grace。直播玩法过点必须保持开奖中，
    // 禁止把 next_at 垫回未来（否则封盘后倒计时复活，直播还在跑）。
    $typeIdForGrace = isset($feed['__typeId']) ? (int) $feed['__typeId'] : 0;
    $hasLive = $typeIdForGrace > 0 && function_exists('feiniao_has_live') && feiniao_has_live($typeIdForGrace);
    if ($format === 'atmkai' && !$hasLive && isset($raw['market_state']) && strtolower((string) $raw['market_state']) === 'open' && $nextAt <= time()) {
        $grace = isset($feed['open_grace_seconds']) ? (int) $feed['open_grace_seconds'] : max(20, min(40, $interval));
        if ($grace < 10) {
            $grace = 10;
        }
        if (time() - $nextAt <= $grace) {
            $nextAt = $nextAt + $grace;
            $nextAtMs = $nextAt * 1000;
        }
    }
    if ($nextAtMs <= 0 && $nextAt > 0) {
        $nextAtMs = $nextAt * 1000;
    }
    $historyRows = array();
    if ($format === 'atmkai' && !empty($raw['history_rows']) && is_array($raw['history_rows'])) {
        foreach ($raw['history_rows'] as $historyRow) {
            if (!is_array($historyRow) || empty($historyRow['issue']) || empty($historyRow['balls'])) {
                continue;
            }
            try {
                $historyIssue = feiniao_draw_issue($historyRow['issue'], $digits);
                $historyNumbers = feiniao_draw_numbers($historyRow['balls']);
                if ($expectedCount && count($historyNumbers) !== $expectedCount) {
                    continue;
                }
                $historyOpenedAt = feiniao_draw_timestamp(isset($historyRow['drawAt']) ? $historyRow['drawAt'] : null);
                if ($historyOpenedAt <= 0) {
                    continue;
                }
                $historyRows[] = array(
                    'issue' => $historyIssue,
                    'numbers' => $historyNumbers,
                    'code' => implode(',', $historyNumbers),
                    'opened_at' => $historyOpenedAt,
                    // 历史项仅用于补结算；不能据此制造下一期倒计时。
                    'next_issue' => (string) ((int) $historyIssue + 1),
                    'next_at' => $historyOpenedAt,
                    'next_at_ms' => $historyOpenedAt * 1000,
                    'interval' => $interval,
                    'market_state' => 'closed',
                    'market_term' => (string) ((int) $historyIssue + 1),
                    'live_phase' => 'publishing',
                );
            } catch (Throwable $ignoreHistoryRow) {
                // 一条损坏的历史记录不能污染当前期；连续性校验会拒绝不完整补链。
            }
        }
        usort($historyRows, function ($a, $b) {
            return (int) $a['issue'] <=> (int) $b['issue'];
        });
    }
    return array(
        'issue' => $issue,
        'numbers' => $numbers,
        'code' => implode(',', $numbers),
        'opened_at' => $openedAt,
        'next_issue' => $nextIssue,
        'next_at' => $nextAt,
        'next_at_ms' => $nextAtMs,
        'interval' => $interval,
        // 第三方开盘信号（atmkai: marketState=open）；直播空档以此为准，不写死秒数
        'market_state' => isset($raw['market_state']) ? strtolower(trim((string) $raw['market_state'])) : '',
        'market_term' => $nextIssue,
        // publishing=结果包动画中；betting=直播倒计时已用 drawAt
        'live_phase' => isset($raw['live_phase']) ? strtolower(trim((string) $raw['live_phase'])) : '',
        'history' => $historyRows,
    );
}

/**
 * AtmKai 双重核对：latest 负责当前状态，history 负责已开奖期号链。
 * 两个接口不一致时禁止把当前期当作正常开盘，等待上游恢复一致。
 */
function feiniao_atmkai_history_check($feed, $payload, $timeout)
{
    $gid = isset($feed['gid']) ? trim((string) $feed['gid']) : '';
    if ($gid === '') {
        throw new RuntimeException('开奖网双重核对缺少游戏标识');
    }

    $record = null;
    foreach ((array) $payload as $item) {
        if (is_array($item) && isset($item['gid']) && (string) $item['gid'] === $gid) {
            $record = $item;
            break;
        }
    }
    if (!is_array($record) || empty($record['issue'])) {
        throw new RuntimeException('开奖网双重核对未找到当前游戏');
    }

    $historyUrl = 'https://www.atmkai.com/api/draws/'
        . rawurlencode($gid) . '/history?limit=3';
    $historyPayload = feiniao_draw_http_get($historyUrl, min(5, max(2, (int) $timeout)));
    if (!is_array($historyPayload) || !isset($historyPayload[0]) || !is_array($historyPayload[0])) {
        throw new RuntimeException('开奖网历史接口返回为空');
    }

    $currentIssue = trim((string) $record['issue']);
    $historyIssue = trim((string) ($historyPayload[0]['issue'] ?? ''));
    if (!ctype_digit($currentIssue) || !ctype_digit($historyIssue)) {
        throw new RuntimeException('开奖网双重核对期号无效');
    }
    $issueGap = (int) $currentIssue - (int) $historyIssue;
    // betting 时当前期通常是最近已开奖期+1；同一期状态包允许 gap=0。
    if ($issueGap < 0 || $issueGap > 1) {
        throw new RuntimeException(
            '开奖网 latest/history 期号断档：latest=' . $currentIssue
            . ', history=' . $historyIssue
        );
    }

    $expectedCount = isset($feed['number_count']) ? (int) $feed['number_count'] : 0;
    $historyCount = 0;
    foreach (array_slice($historyPayload, 0, 3) as $historyRow) {
        if (!is_array($historyRow) || empty($historyRow['issue']) || empty($historyRow['balls'])) {
            throw new RuntimeException('开奖网历史接口记录不完整');
        }
        $historyNumbers = feiniao_draw_numbers($historyRow['balls']);
        if ($expectedCount > 0 && count($historyNumbers) !== $expectedCount) {
            throw new RuntimeException('开奖网历史接口开奖号码数量错误');
        }
        $historyCount++;
    }
    if ($historyCount < 1) {
        throw new RuntimeException('开奖网历史接口没有有效记录');
    }

    return array(
        'ok' => 1,
        'latest_issue' => $currentIssue,
        'history_issue' => $historyIssue,
        'issue_gap' => $issueGap,
        'checked_at' => time(),
    );
}

function feiniao_fetch_draw($typeId)
{
    $config = feiniao_draw_config();
    if (!isset($config['feeds'][$typeId])) {
        throw new RuntimeException('未找到游戏采集配置');
    }
    $feed = $config['feeds'][$typeId];
    if (empty($feed['enabled']) || empty($feed['url'])) {
        throw new RuntimeException('该游戏尚未配置第三方开奖接口');
    }
    $feed['__typeId'] = (int) $typeId;
    $timeout = isset($config['timeout']) ? $config['timeout'] : 8;
    $payload = feiniao_draw_http_get(
        $feed['url'],
        $timeout,
        isset($feed['headers']) ? $feed['headers'] : array()
    );
    if (strtolower((string) ($feed['format'] ?? '')) === 'atmkai') {
        // 第二接口只做一致性核验，不直接拿历史数据开盘或补造号码。
        feiniao_atmkai_history_check($feed, $payload, $timeout);
    }
    return feiniao_draw_parse($payload, $feed);
}
