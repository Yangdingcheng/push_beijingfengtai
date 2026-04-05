<?php
date_default_timezone_set('Asia/Shanghai');

define('LIST_URL', 'https://www.bjft.gov.cn/xxfb/gkzp/');//网址内容可以替换，这里我以北京丰台校招为例
define('DATA_DIR', __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('STATE_FILE', DATA_DIR . '/state.json');

function ensure_data_files() {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }

    if (!file_exists(CONFIG_FILE)) {
        $defaultConfig = [
            'sendkey' => '',
            'push_on_first_run' => false,
            'check_token' => 'change_me_123456',//这里可以修改成你挂计划任务的密钥
            'site_name' => '北京丰台公开招聘监控'
        ];
        file_put_contents(CONFIG_FILE, json_encode($defaultConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    if (!file_exists(STATE_FILE)) {
        $defaultState = [
            'known_ids' => [],
            'last_check' => null,
            'last_result' => '未执行',
            'last_new_count' => 0,
            'last_error' => ''
        ];
        file_put_contents(STATE_FILE, json_encode($defaultState, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

function load_json($file, $default = []) {
    if (!file_exists($file)) return $default;
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

function save_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function fetch_url($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $code >= 400) {
        throw new Exception("抓取失败，HTTP状态码: {$code}，错误: {$err}");
    }

    return $html;
}

function clean_text($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function parse_articles($html) {
    $items = [];

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//a[@href]');

    foreach ($nodes as $node) {
        $href = trim($node->getAttribute('href'));
        $text = clean_text($node->textContent);

        if ($href === '' || $text === '') {
            continue;
        }

        $url = url_join(LIST_URL, $href);

        // 只保留公开招聘栏目下的链接，可以自行修改
        if (strpos($url, '/xxfb/gkzp/') === false) {
            continue;
        }

        // 只保留详情页
        if (!preg_match('/\.s?html?$/i', $url)) {
            continue;
        }

        // 从文本末尾提取日期
        if (!preg_match('/^(.*?)(\d{4}-\d{2}-\d{2})$/u', $text, $m)) {
            continue;
        }

        $title = trim($m[1]);
        $date = trim($m[2]);

        if (mb_strlen($title) < 5) {
            continue;
        }

        $id = md5($title . '|' . $url);

        $items[$id] = [
            'id' => $id,
            'title' => $title,
            'date' => $date,
            'url' => $url
        ];
    }

    $items = array_values($items);

    usort($items, function($a, $b) {
        return strcmp($b['date'] . $b['url'], $a['date'] . $a['url']);
    });

    return $items;
}

function url_join($base, $relative) {
    if (preg_match('/^https?:\/\//i', $relative)) {
        return $relative;
    }

    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';

    if (!$host) {
        return $relative;
    }

    if (strpos($relative, '/') === 0) {
        return $scheme . '://' . $host . $relative;
    }

    $path = $parts['path'] ?? '/';
    $dir = rtrim(dirname($path), '/\\');
    if ($dir === '.') {
        $dir = '';
    }

    return $scheme . '://' . $host . $dir . '/' . ltrim($relative, '/');
}

function push_serverchan($sendkey, $title, $desp) {
    if (!$sendkey) {
        return ['ok' => false, 'msg' => '未配置 SendKey'];
    }

    $api = "https://sctapi.ftqq.com/{$sendkey}.send";
    $postData = http_build_query([
        'title' => $title,
        'desp' => $desp
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'msg' => $err ?: '推送失败'];
    }

    return ['ok' => true, 'msg' => $resp];
}

function run_check() {
    ensure_data_files();

    $config = load_json(CONFIG_FILE, []);
    $state = load_json(STATE_FILE, []);

    try {
        $html = fetch_url(LIST_URL);
        $articles = parse_articles($html);

        // 调试时可以打开这一行
        // file_put_contents(DATA_DIR . '/debug_articles.json', json_encode($articles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (!$articles) {
            throw new Exception('没有解析到文章，可能页面结构变了');
        }

        $knownIds = isset($state['known_ids']) && is_array($state['known_ids']) ? $state['known_ids'] : [];
        $knownMap = array_flip($knownIds);
        $currentIds = array_column($articles, 'id');

        $newItems = [];
        foreach ($articles as $item) {
            if (!isset($knownMap[$item['id']])) {
                $newItems[] = $item;
            }
        }

        $isFirstRun = empty($knownIds);

        $state['known_ids'] = $currentIds;
        $state['last_check'] = date('Y-m-d H:i:s');
        $state['last_new_count'] = count($newItems);
        $state['last_error'] = '';

        if ($isFirstRun) {
            $state['last_result'] = '首次运行，已建立基线';
            save_json(STATE_FILE, $state);

            if (!empty($config['push_on_first_run']) && !empty($newItems)) {
                $lines = [];
                foreach (array_slice($newItems, 0, 10) as $item) {
                    $lines[] = "- [{$item['title']}]({$item['url']})\n  日期：{$item['date']}";
                }
                $desp = "首次初始化，当前页面文章如下：\n\n" . implode("\n\n", $lines);
                push_serverchan($config['sendkey'] ?? '', ($config['site_name'] ?? '监控') . ' 已初始化', $desp);
            }

            return [
                'ok' => true,
                'msg' => $state['last_result'],
                'new_items' => []
            ];
        }

        if (!empty($newItems)) {
            $lines = [];
            foreach (array_reverse(array_slice($newItems, 0, 10)) as $item) {
                $lines[] = "- [{$item['title']}]({$item['url']})\n  日期：{$item['date']}";
            }

            $desp = "检测到新文章：\n\n" . implode("\n\n", $lines);

            if (count($newItems) > 10) {
                $desp .= "\n\n另有 " . (count($newItems) - 10) . " 篇未展示。";
            }

            $pushRet = push_serverchan(
                $config['sendkey'] ?? '',
                ($config['site_name'] ?? '监控') . ' 新增 ' . count($newItems) . ' 篇',
                $desp
            );

            $state['last_result'] = '发现新文章 ' . count($newItems) . ' 篇，已尝试推送';
            save_json(STATE_FILE, $state);

            return [
                'ok' => true,
                'msg' => $state['last_result'],
                'push' => $pushRet,
                'new_items' => $newItems
            ];
        }

        $state['last_result'] = '暂无新文章';
        save_json(STATE_FILE, $state);

        return [
            'ok' => true,
            'msg' => $state['last_result'],
            'new_items' => []
        ];

    } catch (Exception $e) {
        $state['last_check'] = date('Y-m-d H:i:s');
        $state['last_result'] = '执行失败';
        $state['last_new_count'] = 0;
        $state['last_error'] = $e->getMessage();
        save_json(STATE_FILE, $state);

        return [
            'ok' => false,
            'msg' => $e->getMessage()
        ];
    }
}