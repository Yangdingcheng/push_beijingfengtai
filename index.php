<?php
require_once __DIR__ . '/common.php';
ensure_data_files();

$config = load_json(CONFIG_FILE, []);
$state = load_json(STATE_FILE, []);

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $config['sendkey'] = trim($_POST['sendkey'] ?? '');
        $config['check_token'] = trim($_POST['check_token'] ?? '');
        $config['site_name'] = trim($_POST['site_name'] ?? '北京丰台公开招聘监控');
        $config['push_on_first_run'] = isset($_POST['push_on_first_run']);
        save_json(CONFIG_FILE, $config);
        $msg = '配置已保存';
    }

    if ($action === 'test_push') {
        $ret = push_serverchan(
            $config['sendkey'] ?? '',
            ($config['site_name'] ?? '监控') . ' 测试消息',
            "这是一条测试推送。\n\n发送时间：" . date('Y-m-d H:i:s')
        );
        $msg = '测试推送结果：' . ($ret['msg'] ?? '');
    }

    if ($action === 'run_check') {
        $ret = run_check();
        $state = load_json(STATE_FILE, []);
        $msg = '手动检测结果：' . ($ret['msg'] ?? '');
    }
}

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$checkUrl = $scheme . $_SERVER['HTTP_HOST'] . $scriptDir . '/check.php?token=' . urlencode($config['check_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>丰台公开招聘监控</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f5f7fb;
            padding: 30px;
            margin: 0;
        }
        .wrap {
            max-width: 900px;
            margin: 0 auto;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 18px rgba(0,0,0,.06);
        }
        h2, h3 {
            margin-top: 0;
        }
        input[type=text] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-sizing: border-box;
        }
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            background: #1677ff;
            color: #fff;
            cursor: pointer;
            margin-right: 10px;
        }
        .btn2 {
            background: #13a66a;
        }
        .mono {
            word-break: break-all;
            background: #f6f6f6;
            padding: 10px;
            border-radius: 8px;
        }
        label {
            display: block;
            margin: 12px 0 6px;
            font-weight: 600;
        }
        .msg {
            padding: 12px;
            background: #eef7ff;
            border-left: 4px solid #1677ff;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .small {
            color: #666;
            font-size: 13px;
        }
        .row {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2>北京丰台公开招聘监控</h2>
        <p class="small">监控地址：<a href="https://www.bjft.gov.cn/xxfb/gkzp/" target="_blank">https://www.bjft.gov.cn/xxfb/gkzp/</a></p>
        <?php if ($msg): ?>
            <div class="msg"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>运行状态</h3>
        <div class="row">上次检测时间：<?php echo htmlspecialchars($state['last_check'] ?? '未执行', ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="row">上次结果：<?php echo htmlspecialchars($state['last_result'] ?? '未知', ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="row">上次新增数量：<?php echo htmlspecialchars((string)($state['last_new_count'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="row">错误信息：<?php echo htmlspecialchars($state['last_error'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>

        <form method="post" style="margin-top:15px;">
            <input type="hidden" name="action" value="run_check">
            <button class="btn btn2" type="submit">立即检测一次</button>
        </form>
    </div>

    <div class="card">
        <h3>配置</h3>
        <form method="post">
            <input type="hidden" name="action" value="save_config">

            <label>站点名称</label>
            <input type="text" name="site_name" value="<?php echo htmlspecialchars($config['site_name'] ?? '北京丰台公开招聘监控', ENT_QUOTES, 'UTF-8'); ?>">

            <label>Server酱 SendKey</label>
            <input type="text" name="sendkey" value="<?php echo htmlspecialchars($config['sendkey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <label>计划任务 Token</label>
            <input type="text" name="check_token" value="<?php echo htmlspecialchars($config['check_token'] ?? 'change_me_123456', ENT_QUOTES, 'UTF-8'); ?>">

            <label>
                <input type="checkbox" name="push_on_first_run" <?php echo !empty($config['push_on_first_run']) ? 'checked' : ''; ?>>
                首次运行也推送
            </label>

            <br><br>
            <button class="btn" type="submit">保存配置</button>
        </form>
    </div>

    <div class="card">
        <h3>测试推送</h3>
        <form method="post">
            <input type="hidden" name="action" value="test_push">
            <button class="btn" type="submit">发送测试消息</button>
        </form>
    </div>

    <div class="card">
        <h3>宝塔计划任务地址</h3>
        <div class="mono"><?php echo htmlspecialchars($checkUrl, ENT_QUOTES, 'UTF-8'); ?></div>
        <p class="small">宝塔计划任务可用 curl 或 wget 定时访问这个地址。</p>
    </div>
</div>
</body>
</html>