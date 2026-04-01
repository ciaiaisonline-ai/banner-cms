<?php
// index.php – Hero Banner Dashboard

session_start();
date_default_timezone_set('Asia/Bangkok');

define('BANNER_JSON_PATH', __DIR__ . '/banner-data/banners.json');
define('UPLOAD_DIR', __DIR__ . '/upload/');
define('CONFIG_JSON_PATH', __DIR__ . '/banner-data/config.json');
define('PAGES_JSON_PATH', __DIR__ . '/banner-data/pages.json');

if (!file_exists(__DIR__ . '/banner-data')) {
    mkdir(__DIR__ . '/banner-data', 0775, true);
}
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0775, true);
}

if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(16));
}

// ---------- helpers ----------
function read_config() {
    if (!file_exists(CONFIG_JSON_PATH)) {
        $default = ['require_approval' => true];
        file_put_contents(CONFIG_JSON_PATH, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $default;
    }
    $json = file_get_contents(CONFIG_JSON_PATH);
    $cfg = json_decode($json, true);
    if (!is_array($cfg)) $cfg = [];
    if (!array_key_exists('require_approval', $cfg)) $cfg['require_approval'] = true;
    return $cfg;
}

function write_config($cfg) {
    $fp = fopen(CONFIG_JSON_PATH, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function read_pages() {
    if (!file_exists(PAGES_JSON_PATH)) {
        $default = [
            ['key'=>'store','label'=>'Store'],
            ['key'=>'phones','label'=>'Phones'],
            ['key'=>'tablets','label'=>'Tablets'],
            ['key'=>'promotions','label'=>'Promotions'],
        ];
        file_put_contents(PAGES_JSON_PATH, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $default;
    }
    $json = file_get_contents(PAGES_JSON_PATH);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function pages_to_map($pagesArr) {
    $map = [];
    foreach ($pagesArr as $p) {
        $k = trim($p['key'] ?? '');
        $l = trim($p['label'] ?? '');
        if ($k !== '' && $l !== '') $map[$k] = $l;
    }
    return $map;
}

// helper: get per-page order for sorting in tab
if (!function_exists('banner_order_for_page')) {
    function banner_order_for_page($banner, $pageKey) {
        // new format: page_orders is a map like {store:1, phones:3}
        if (isset($banner['page_orders']) && is_array($banner['page_orders'])) {
            if (isset($banner['page_orders'][$pageKey])) return (int)$banner['page_orders'][$pageKey];
        }

        // backward compatible: older data may only have a single 'order'
        if (isset($banner['order'])) return (int)$banner['order'];

        // if no order, push to bottom
        return PHP_INT_MAX;
    }
}

// helper: find max per-page order among existing banners
function get_max_page_order($banners, $pageKey) {
    $max = 0;
    foreach ($banners as $b) {
        if (!in_array($pageKey, $b['pages'] ?? [])) continue;
        $o = banner_order_for_page($b, $pageKey);
        if ($o !== PHP_INT_MAX && $o > $max) $max = $o;
    }
    return $max;
}

function read_banners() {
    if (!file_exists(BANNER_JSON_PATH)) {
        file_put_contents(BANNER_JSON_PATH, json_encode([]));
    }
    $json = file_get_contents(BANNER_JSON_PATH);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function write_banners($banners) {
    $fp = fopen(BANNER_JSON_PATH, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode(array_values($banners), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function clean_filename($name) {
    $name = preg_replace('/\s+/', '-', $name);
    $name = preg_replace('/[^A-Za-z0-9\.\-_]/', '', $name);
    return strtolower($name);
}

function validate_image_mime($file) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    $mime = @mime_content_type($file['tmp_name']);
    return $mime && in_array($mime, $allowed, true);
}

function validate_image_dimensions($file, $allowedSizes) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, 'ไฟล์มีปัญหาในการอัปโหลด'];

    $maxBytes = 1024 * 1024;
    if (!isset($file['size']) || $file['size'] > $maxBytes) {
        $kb = isset($file['size']) ? round($file['size'] / 1024) : 0;
        return [false, "ขนาดไฟล์ต้องไม่เกิน 1MB (ตอนนี้ประมาณ {$kb} KB)"];
    }

    $size = @getimagesize($file['tmp_name']);
    if (!$size) return [false, 'ไม่สามารถอ่านขนาดรูปได้'];

    $w = (int)$size[0];
    $h = (int)$size[1];

    foreach ($allowedSizes as $pair) {
        if ($w === (int)$pair[0] && $h === (int)$pair[1]) {
            return [true, ''];
        }
    }

    $allowedText = implode(' หรือ ', array_map(fn($p) => "{$p[0]}x{$p[1]}", $allowedSizes));
    return [false, "ขนาดรูปต้องเป็น {$allowedText} (ตอนนี้ {$w}x{$h})"];
}

// ---- validate schedule (start/end optional) ----
function validate_schedule_fields($start_at, $end_at) {
    $start_at = trim((string)$start_at);
    $end_at   = trim((string)$end_at);

    // end only is not allowed
    if ($end_at !== '' && $start_at === '') {
        return [false, 'กรุณาระบุ Start เมื่อกำหนด End'];
    }

    if ($start_at !== '' && $end_at !== '') {
        $s = strtotime($start_at);
        $e = strtotime($end_at);
        if (!$s || !$e) {
            return [false, 'รูปแบบวันเวลาไม่ถูกต้อง'];
        }
        if ($s >= $e) {
            return [false, 'Start ต้องน้อยกว่า End'];
        }
    }
    return [true, ''];
}

// ---- validate image dimensions from PATH/URL (server-side) ----
function fetch_image_head_bytes($url, $maxBytes = 262144) { // 256KB
    // Try cURL first (more reliable than allow_url_fopen)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'banner-cms/1.0',
            CURLOPT_RANGE          => '0-' . ($maxBytes - 1),
        ]);
        $data = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($data === false || $code < 200 || $code >= 300) {
            return [null, "ไม่สามารถดึงรูปจาก URL ได้ (HTTP {$code})"];
        }
        if ($ct && stripos($ct, 'image/') !== 0) {
            return [null, 'ไฟล์ปลายทางไม่ใช่รูปภาพ'];
        }
        return [$data, ''];
    }

    // Fallback: file_get_contents (requires allow_url_fopen)
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'follow_location' => 1,
            'header' => "User-Agent: banner-cms/1.0\r\n",
        ]
    ]);
    $data = @file_get_contents($url, false, $ctx, 0, $maxBytes);
    if ($data === false) return [null, 'ไม่สามารถดึงรูปจาก URL ได้'];
    return [$data, ''];
}

function build_current_origin() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if ($host === '') return '';
    return $scheme . '://' . $host;
}

function validate_image_path_dimensions($path, $allowedSizes) {
    $path = trim((string)$path);
    if ($path === '') return [false, 'กรุณาระบุ Path รูป'];

    $w = 0; $h = 0;

    // full URL
    if (preg_match('#^https?://#i', $path)) {
        [$bytes, $err] = fetch_image_head_bytes($path);
        if (!$bytes) return [false, $err ?: 'ไม่สามารถตรวจสอบรูปจาก URL ได้'];

        $info = @getimagesizefromstring($bytes);
        if (!$info) return [false, 'ไม่สามารถอ่านขนาดรูปจาก URL ได้'];
        $w = (int)$info[0]; $h = (int)$info[1];
    } else {
        // normalize relative path
        if ($path[0] !== '/') $path = '/' . $path;

        // Try filesystem under this project first (works for uploaded assets stored inside the CMS folder)
        $local = realpath(__DIR__ . $path);
        if ($local && file_exists($local)) {
            $info = @getimagesize($local);
            if (!$info) return [false, 'ไม่สามารถอ่านขนาดรูปได้'];
            $w = (int)$info[0]; $h = (int)$info[1];
        } else {
            // Fallback: validate via current site origin (works when assets live outside the CMS folder but are web-accessible)
            $origin = build_current_origin();
            if ($origin === '') return [false, 'ไม่สามารถตรวจสอบ Path ได้ (ไม่พบ Host ของเซิร์ฟเวอร์)'];

            [$bytes, $err] = fetch_image_head_bytes($origin . $path);
            if (!$bytes) return [false, $err ?: 'ไม่สามารถดึงรูปจาก Path ได้'];
            $info = @getimagesizefromstring($bytes);
            if (!$info) return [false, 'ไม่สามารถอ่านขนาดรูปจาก Path ได้'];
            $w = (int)$info[0]; $h = (int)$info[1];
        }
    }

    foreach ($allowedSizes as $pair) {
        if ($w === (int)$pair[0] && $h === (int)$pair[1]) {
            return [true, ''];
        }
    }
    $allowedText = implode(' หรือ ', array_map(fn($p) => "{$p[0]}x{$p[1]}", $allowedSizes));
    return [false, "ขนาดรูปต้องเป็น {$allowedText} (ตอนนี้ {$w}x{$h})"];
}
// ---- change tracking helpers ----
function pick_display_fields($b) {
    $out = [
        'title' => $b['title'] ?? '',
        'link_url' => $b['link_url'] ?? '',
        'link_target' => $b['link_target'] ?? '_self',
        'desktop_img' => $b['desktop_img'] ?? '',
        'mobile_img' => $b['mobile_img'] ?? '',
        'pages' => array_values($b['pages'] ?? []),
        'is_active' => !empty($b['is_active']),
        'start_at' => $b['start_at'] ?? '',
        'end_at' => $b['end_at'] ?? '',
        'priority' => $b['priority'] ?? 'medium',
        'ga4' => $b['ga4'] ?? [],
        'page_orders' => $b['page_orders'] ?? [],
    ];
    // stable sort pages
    if (is_array($out['pages'])) sort($out['pages']);
    return $out;
}

function snapshot_fields($b) {
    return [
        'title' => $b['title'] ?? '',
        'link_url' => $b['link_url'] ?? '',
        'link_target' => $b['link_target'] ?? '_self',
        'priority' => $b['priority'] ?? 'medium',
        'pages' => $b['pages'] ?? [],
        'desktop_img' => $b['desktop_img'] ?? '',
        'mobile_img' => $b['mobile_img'] ?? '',
        'start_at' => $b['start_at'] ?? '',
        'end_at' => $b['end_at'] ?? '',
        'is_active' => !empty($b['is_active']),
        'ga4' => $b['ga4'] ?? [],
        'page_orders' => $b['page_orders'] ?? [],
    ];
}

function compute_diff($before, $after) {
    $diff = [];

    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($keys as $k) {
        $bv = $before[$k] ?? null;
        $av = $after[$k] ?? null;

        // normalize arrays
        if (is_array($bv) && is_array($av)) {
            // for pages: show added/removed
            if ($k === 'pages') {
                $bset = $bv; $aset = $av;
                sort($bset); sort($aset);
                if ($bset !== $aset) {
                    $added = array_values(array_diff($aset, $bset));
                    $removed = array_values(array_diff($bset, $aset));
                    $noteParts = [];
                    if (!empty($added)) $noteParts[] = '+' . implode(', +', $added);
                    if (!empty($removed)) $noteParts[] = '-' . implode(', -', $removed);
                    $diff[] = [
                        'field' => 'pages',
                        'before' => $bset,
                        'after' => $aset,
                        'change_type' => 'update',
                        'note' => implode(' ', $noteParts),
                    ];
                }
                continue;
            }

            // generic array compare (including page_orders/ga4)
            if ($bv != $av) {
                $diff[] = [
                    'field' => $k,
                    'before' => $bv,
                    'after' => $av,
                    'change_type' => 'update',
                ];
            }
            continue;
        }

        if ($bv !== $av) {
            $diff[] = [
                'field' => $k,
                'before' => $bv,
                'after' => $av,
                'change_type' => 'update',
            ];
        }
    }

    return $diff;
}

function append_change_log(&$banner, $action, $diff = null, $comment = null) {
    if (!isset($banner['change_history']) || !is_array($banner['change_history'])) {
        $banner['change_history'] = [];
    }
    $banner['change_history'][] = [
        'changed_at' => date('c'),
        'changed_by' => $_SESSION['user'] ?? null,
        'action' => $action,
        'diff' => $diff,
        'comment' => $comment,
    ];
}


/**
 * process_uploaded_image:
 *  - check mime
 *  - check size + dimension
 *  - check duplicate by hash
 *  - move to /upload
 * return [path, hash, error]
 * if duplicate, $outDuplicates will be filled with list of banners that use same file
 */
function process_uploaded_image($file, $allowedSizes, $existingMap, &$outDuplicates) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return ['', '', 'ไฟล์มีปัญหาในการอัปโหลด'];
    }

    if (!validate_image_mime($file)) {
        return ['', '', 'ชนิดไฟล์ไม่รองรับ (อนุญาต jpg, png, webp)'];
    }

    [$validDim, $msgDim] = validate_image_dimensions($file, $allowedSizes);
    if (!$validDim) return ['', '', $msgDim];

    // ✅ สำคัญ: คำนวณ hash เพื่อตรวจไฟล์ซ้ำ
    $hash = @hash_file('sha256', $file['tmp_name']);
    if ($hash && isset($existingMap[$hash])) {
        $outDuplicates = $existingMap[$hash];
        return ['', '', 'ไฟล์นี้ถูกใช้งานแล้วใน Banner อื่น (ไฟล์ซ้ำ)'];
    }

    $clean = clean_filename($file['name']);
    $target = UPLOAD_DIR . $clean;

    if (file_exists($target)) {
        $info = pathinfo($clean);
        $clean = ($info['filename'] ?? 'img') . '-' . time() . '.' . ($info['extension'] ?? 'jpg');
        $target = UPLOAD_DIR . $clean;
    }

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['', '', 'ไม่สามารถบันทึกรูปได้'];
    }

    return ['/upload/' . $clean, $hash, ''];
}

// normalize multi files: banner_files[]
function normalize_files_array($files) {
    $out = [];
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) return $out;

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name'     => $files['name'][$i] ?? '',
            'type'     => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i] ?? 0,
        ];
    }
    return $out;
}

// classify by dimension
function classify_banner_file($file) {
    $size = @getimagesize($file['tmp_name']);
    if (!$size) return ['unknown', 0, 0];
    $w = (int)$size[0];
    $h = (int)$size[1];

    if ($w === 1600 && $h === 500) return ['desktop', $w, $h];
    if (($w === 1040 && $h === 1040) || ($w === 786 && $h === 432)) return ['mobile', $w, $h];

    return ['unknown', $w, $h];
}

function asset_url($path) {
    if (!$path) return '';
    if (preg_match('#^https?://#', $path)) return $path;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    if ($scriptDir === '' || $scriptDir === '/') return $protocol . $host . $path;
    return $protocol . $host . $scriptDir . $path;
}

function format_thai_date($isoOrTs, $withTime = false) {
    if (!$isoOrTs) return '';
    $ts = is_numeric($isoOrTs) ? intval($isoOrTs) : strtotime($isoOrTs);
    if (!$ts) return '';

    $today = date('Y-m-d');
    $thisDate = date('Y-m-d', $ts);

    $months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];

    $d = date('j', $ts);
    $m = $months[intval(date('n', $ts))];
    $y = intval(date('Y', $ts)) + 543;
    $time = date('H:i', $ts) . ' น.';

    if (!$withTime) {
        if ($thisDate === $today) return 'วันนี้';
        return "{$d} {$m} {$y}";
    }

    if ($thisDate === $today) return "วันนี้ {$time}";
    return "{$d} {$m} {$y} {$time}";
}

function get_schedule_and_status($b) {
    $nowTs = time();
    $start = $b['start_at'] ?? '';
    $end   = $b['end_at'] ?? '';
    $isActive = !empty($b['is_active']);

    $range = 'ไม่กำหนดเวลา';
    if ($start && $end) {
        $range = format_thai_date($start, true) . ' - ' . format_thai_date($end, true);
    } elseif ($start) {
        $range = 'เริ่ม ' . format_thai_date($start, true);
    } elseif ($end) {
        $range = 'ถึง ' . format_thai_date($end, true);
    }

    $status = 'ปิด';
    $detail = '';
    $class  = 'bg-secondary';

    $startTs = $start ? strtotime($start) : null;
    $endTs   = $end ? strtotime($end) : null;

    if ($isActive) {
        if ($startTs && $startTs > $nowTs) {
            $status = 'ปิด';
            $detail = 'รอเริ่มแสดง';
        } elseif ($endTs && $endTs < $nowTs) {
            $status = 'ปิด';
            $detail = 'หมดเวลา';
        } else {
            $status = 'กำลังแสดง';
            $class  = 'bg-success';
        }
    } else {
        if ($startTs && $startTs > $nowTs) $detail = 'ยังไม่เปิดใช้งาน';
        elseif ($endTs && $endTs < $nowTs) $detail = 'หมดเวลาแล้ว';
    }

    $approval = $b['approval_status'] ?? 'approved';
    if ($approval === 'pending') {
        $status = 'รออนุมัติ';
        $class  = 'bg-warning text-dark';
    } elseif ($approval === 'rejected') {
        $status = 'Reject';
        $class  = 'bg-danger';
    } elseif ($detail) {
        $status .= ' - ' . $detail;
    }

    return [$range, $status, $class];
}

// ---------- AJAX: reorder ----------
if (isset($_GET['action']) && $_GET['action'] === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['orders']) || !isset($data['page'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
        exit;
    }

    $pageKey = trim((string)$data['page']);
    if ($pageKey === '' || $pageKey === 'all') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid page']);
        exit;
    }

    $orders = $data['orders'];
    $map = [];
    foreach ($orders as $o) {
        if (!isset($o['id'])) continue;
        $map[$o['id']] = intval($o['order'] ?? 0);
    }

    $banners = read_banners();
    foreach ($banners as &$b) {
        if (!isset($map[$b['id'] ?? ''])) continue;
        // only update if banner belongs to this page
        if (!in_array($pageKey, $b['pages'] ?? [])) continue;

        if (!isset($b['page_orders']) || !is_array($b['page_orders'])) $b['page_orders'] = [];
        $b['page_orders'][$pageKey] = $map[$b['id']];

        // keep legacy order in sync for backward compatibility
        $b['order'] = $map[$b['id']];
    }
    unset($b);
    write_banners($banners);

    echo json_encode(['status' => 'ok']);
    exit;
}


// ---------- AJAX: toggle active ----------
if (isset($_GET['action']) && $_GET['action'] === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $token = $_SERVER['HTTP_X_FORM_TOKEN'] ?? '';
    if (!$token || $token !== ($_SESSION['form_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid token']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $id = $payload['id'] ?? '';
    $isActive = !empty($payload['is_active']);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing id']);
        exit;
    }

    $cfg = read_config();
    $banners = read_banners();
    $found = false;

    foreach ($banners as &$bn) {
        if (($bn['id'] ?? '') !== $id) continue;

        $before = snapshot_fields($bn);

        // apply change
        $bn['is_active'] = $isActive;

        // if require approval: editing an approved banner must go pending + diff
        if (!empty($cfg['require_approval']) && (($bn['approval_status'] ?? 'approved') === 'approved')) {
            // baseline
            if (empty($bn['last_approved_snapshot']) || !is_array($bn['last_approved_snapshot'])) {
                $bn['last_approved_snapshot'] = $before;
            }

            $after = snapshot_fields($bn);
            $bn['pending_change_summary'] = compute_diff($bn['last_approved_snapshot'], $after);
            $bn['approval_status'] = 'pending';
            $bn['requested_at'] = date('Y-m-d H:i');
            append_change_log($bn, 'edit');
        }

        $found = true;
        break;
    }
    unset($bn);

    if (!$found) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    write_banners($banners);
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    $id = $_POST['id'] ?? '';
    if ($id) {
        $banners = read_banners();
        $banners = array_values(array_filter($banners, fn($b) => ($b['id'] ?? '') !== $id));
        write_banners($banners);
    }
    $tab = $_GET['tab'] ?? 'all';
    header('Location: index.php?tab=' . urlencode($tab) . '&deleted=1');
    exit;
}

// ---------- clone ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'clone') {
    $id = $_POST['id'] ?? '';
    $tab = $_GET['tab'] ?? 'all';

    if ($id) {
        $banners = read_banners();
        $config  = read_config();
        $requireApproval = !empty($config['require_approval']);

        $src = null;
        foreach ($banners as $b) {
            if (($b['id'] ?? '') === $id) { $src = $b; break; }
        }

        if ($src) {
            $new = $src;
            $new['id'] = 'banner_' . time() . '_' . rand(1000, 9999);
            $new['created_at'] = date('c');
            $new['is_active'] = false;
            $new['is_copy'] = true;

            // reset approval for cloned banner
            $new['approval_status'] = $requireApproval ? 'pending' : 'approved';
            $new['requested_at'] = $requireApproval ? date('c') : null;
            $new['approved_at']  = null;
            $new['rejected_at']  = null;

            // reset change tracking fields
            $new['pending_change_summary'] = null;
            $new['last_approved_snapshot'] = null;
            $new['last_approved_at'] = null;
            $new['change_history'] = [];

            // set per-page orders to bottom for each selected page
            $page_orders = [];
            $pages = $new['pages'] ?? [];
            if (!is_array($pages)) $pages = [];
            foreach ($pages as $pKey) {
                $page_orders[$pKey] = get_max_page_order($banners, $pKey) + 1;
            }
            $new['page_orders'] = $page_orders;

            // legacy order fallback (first page)
            $defaultPage = ($pages[0] ?? 'store');
            $new['order'] = $page_orders[$defaultPage] ?? (get_max_page_order($banners, $defaultPage) + 1);

            append_change_log($new, 'create', null, 'clone');

            $banners[] = $new;
            write_banners($banners);
        }
    }

    header('Location: index.php?tab=' . urlencode($tab) . '&cloned=1');
    exit;
}

// ---------- save ----------
$formErrors = ['desktop' => '', 'mobile' => '', 'general' => ''];
$formData = [];
$duplicateInfo = ['desktop' => [], 'mobile' => []];
$openModalAfterPost = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save') {
    $formData = $_POST;

    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
        $formErrors['general'] = 'การส่งข้อมูลไม่ถูกต้อง หรือส่งซ้ำ';
        $openModalAfterPost = true;
    } else {
        $title       = trim($_POST['title'] ?? '');
        $link_url    = trim($_POST['link_url'] ?? '');
        $link_target = isset($_POST['link_newtab']) ? '_blank' : '_self';
        $is_active   = isset($_POST['is_active']) ? true : false;

        $priority   = strtolower(trim($_POST['priority'] ?? 'medium'));
        if (!in_array($priority, ['high','medium','low'], true)) $priority = 'medium';

        $start_at    = trim($_POST['start_at'] ?? '');
        $end_at      = trim($_POST['end_at'] ?? '');
        // ---- schedule validation (optional) ----
        [$schedOk, $schedMsg] = validate_schedule_fields($start_at, $end_at);
        if (!$schedOk) {
            if ($formErrors['general'] === '') $formErrors['general'] = $schedMsg;
        }


        $pages       = $_POST['pages'] ?? [];
        $id          = $_POST['id'] ?? '';

        $desktop_path_text = trim($_POST['desktop_path'] ?? '');
        $mobile_path_text  = trim($_POST['mobile_path'] ?? '');
        $image_mode  = $_POST['image_mode'] ?? 'path';

        $banners = read_banners();
        $config  = read_config();
        $requireApproval = !empty($config['require_approval']);

        $existingIndex = null;
        foreach ($banners as $i => $b) {
            if (($b['id'] ?? '') === $id) { $existingIndex = $i; break; }
        }
        $existing = $existingIndex !== null ? $banners[$existingIndex] : null;
        $currentId = $existing['id'] ?? null;

        // ---- validate required ----
        if ($title === '') {
            if ($formErrors['general'] === '') $formErrors['general'] = 'กรุณากรอกชื่อ Banner ให้ครบ';
        }
        if (empty($pages) || !is_array($pages)) {
            if ($formErrors['general'] === '') $formErrors['general'] = 'กรุณาเลือกหน้าอย่างน้อย 1 หน้า';
        }

        // ---- build hash maps exclude current ----
        $desktopMap = [];
        $mobileMap  = [];
        foreach ($banners as $b) {
            if (($b['id'] ?? null) === $currentId) continue;
            if (!empty($b['desktop_hash'])) {
                $desktopMap[$b['desktop_hash']][] = ['id' => $b['id'] ?? '', 'title' => $b['title'] ?? ''];
            }
            if (!empty($b['mobile_hash'])) {
                $mobileMap[$b['mobile_hash']][] = ['id' => $b['id'] ?? '', 'title' => $b['title'] ?? ''];
            }
        }

        $desktop_img  = $existing['desktop_img'] ?? '';
        $mobile_img   = $existing['mobile_img'] ?? '';
        $desktop_hash = $existing['desktop_hash'] ?? '';
        $mobile_hash  = $existing['mobile_hash'] ?? '';

        // ---- IMAGE MODE: UPLOAD ----
        if ($image_mode === 'upload') {

            // banner_files[] (เลือก 1–2 ไฟล์)
            $multi = [];
            if (!empty($_FILES['banner_files'])) {
                $multi = normalize_files_array($_FILES['banner_files']);
            }

            if (!empty($multi)) {
                if (count($multi) > 2) {
                    $formErrors['general'] = 'อัปโหลดได้สูงสุด 2 ไฟล์เท่านั้น (Desktop + Mobile)';
                } else {
                    $foundDesktop = null;
                    $foundMobile  = null;

                    foreach ($multi as $f) {
                        [$type, $w, $h] = classify_banner_file($f);
                        if ($type === 'desktop') {
                            $foundDesktop = $f;
                        } elseif ($type === 'mobile') {
                            $foundMobile = $f;
                        } else {
                            $formErrors['general'] = "พบไฟล์ขนาดไม่รองรับ ({$w}x{$h}) กรุณาอัปโหลดเฉพาะ 1600x500 และ 1040x1040/786x432";
                            break;
                        }
                    }

                    if (!$formErrors['general']) {
                        if (!$foundDesktop) $formErrors['desktop'] = 'ไม่พบไฟล์ Desktop (1600x500)';
                        if (!$foundMobile)  $formErrors['mobile']  = 'ไม่พบไฟล์ Mobile (1040x1040 หรือ 786x432)';

                        if (!$formErrors['desktop'] && $foundDesktop) {
                            $dupList = [];
                            [$path, $hash, $err] = process_uploaded_image($foundDesktop, [[1600,500]], $desktopMap, $dupList);
                            if ($err) {
                                $formErrors['desktop'] = $err;
                                if (!empty($dupList)) $duplicateInfo['desktop'] = $dupList;
                            } else {
                                $desktop_img = $path; $desktop_hash = $hash;
                                $formData['desktop_path'] = $path;
                            }
                        }

                        if (!$formErrors['mobile'] && $foundMobile) {
                            $dupList = [];
                            [$path, $hash, $err] = process_uploaded_image($foundMobile, [[1040,1040],[786,432]], $mobileMap, $dupList);
                            if ($err) {
                                $formErrors['mobile'] = $err;
                                if (!empty($dupList)) $duplicateInfo['mobile'] = $dupList;
                            } else {
                                $mobile_img = $path; $mobile_hash = $hash;
                                $formData['mobile_path'] = $path;
                            }
                        }
                    }
                }

            } else {
                // fallback: desktop_file / mobile_file
                if (!empty($_FILES['desktop_file']['name'])) {
                    $dupList = [];
                    [$path, $hash, $err] = process_uploaded_image($_FILES['desktop_file'], [[1600, 500]], $desktopMap, $dupList);
                    if ($err) {
                        $formErrors['desktop'] = $err;
                        if (!empty($dupList)) $duplicateInfo['desktop'] = $dupList;
                    } else {
                        $desktop_img  = $path;
                        $desktop_hash = $hash;
                        $formData['desktop_path'] = $path;
                    }
                } else {
                    $formErrors['desktop'] = 'กรุณาอัปโหลด Desktop';
                }

                if (!empty($_FILES['mobile_file']['name'])) {
                    $dupList = [];
                    [$path, $hash, $err] = process_uploaded_image($_FILES['mobile_file'], [[1040, 1040], [786, 432]], $mobileMap, $dupList);
                    if ($err) {
                        $formErrors['mobile'] = $err;
                        if (!empty($dupList)) $duplicateInfo['mobile'] = $dupList;
                    } else {
                        $mobile_img  = $path;
                        $mobile_hash = $hash;
                        $formData['mobile_path'] = $path;
                    }
                } else {
                    $formErrors['mobile'] = 'กรุณาอัปโหลด Mobile';
                }
            }
        }

        // ---- IMAGE MODE: PATH ----
        if ($image_mode === 'path') {
            if ($desktop_path_text !== '') $desktop_img = $desktop_path_text;
            if ($mobile_path_text  !== '') $mobile_img  = $mobile_path_text;

            if ($desktop_img === '') $formErrors['desktop'] = 'กรุณาระบุ Desktop Path';
            if ($mobile_img  === '') $formErrors['mobile']  = 'กรุณาระบุ Mobile Path';

            // server-side validate dimensions for path/url mode
            if (!$formErrors['desktop'] && $desktop_img) {
                [$ok, $msg] = validate_image_path_dimensions($desktop_img, [[1600,500]]);
                if (!$ok) $formErrors['desktop'] = $msg;
            }
            if (!$formErrors['mobile'] && $mobile_img) {
                [$ok, $msg] = validate_image_path_dimensions($mobile_img, [[1040,1040],[786,432]]);
                if (!$ok) $formErrors['mobile'] = $msg;
            }
        }

        // ---- final required check ----
        if ($desktop_img === '' || $mobile_img === '') {
            if ($formErrors['general'] === '') $formErrors['general'] = 'กรุณาระบุรูป Desktop และ Mobile ให้ครบ';
        }

        if ($formErrors['desktop'] || $formErrors['mobile'] || $formErrors['general']) {
            if ($formErrors['general'] === '') $formErrors['general'] = 'กรุณาตรวจสอบข้อมูลให้ครบถ้วนและถูกต้องก่อนบันทึก';
            $openModalAfterPost = true;
        } else {
            // GA4 click name from mobile filename (no ext)
            $mobile_name = '';
            if ($mobile_img) {
                $pathOnly = explode('?', $mobile_img, 2)[0];
                $base = basename($pathOnly);
                $dotPos = strrpos($base, '.');
                $mobile_name = ($dotPos !== false) ? substr($base, 0, $dotPos) : $base;
            }

            $now = date('c');

            if ($existing) {
                $prevApproval = $existing['approval_status'] ?? 'approved';

                $banners[$existingIndex]['title']       = $title;
                $banners[$existingIndex]['link_url']    = $link_url;
                $banners[$existingIndex]['link_target'] = $link_target;

                $banners[$existingIndex]['desktop_img']  = $desktop_img;
                $banners[$existingIndex]['mobile_img']   = $mobile_img;
                $banners[$existingIndex]['desktop_hash'] = $desktop_hash;
                $banners[$existingIndex]['mobile_hash']  = $mobile_hash;

                $banners[$existingIndex]['pages']     = $pages;
                $banners[$existingIndex]['is_active'] = $is_active;
                $banners[$existingIndex]['start_at']  = $start_at;
                $banners[$existingIndex]['end_at']    = $end_at;

                $banners[$existingIndex]['priority'] = $priority;

                // ensure per-page orders exist ONLY for selected pages (rebuild)
                $oldPo = $banners[$existingIndex]['page_orders'] ?? [];
                if (!is_array($oldPo)) $oldPo = [];
                $newPo = [];
                foreach ($pages as $pg) {
                    if (isset($oldPo[$pg])) {
                        $newPo[$pg] = (int)$oldPo[$pg];
                    } else {
                        $newPo[$pg] = get_max_page_order($banners, $pg) + 1;
                    }
                }
                $banners[$existingIndex]['page_orders'] = $newPo;



                $banners[$existingIndex]['ga4'] = [
                    'section_name' => 'herobanner',
                    'click_banner' => $mobile_name,
                ];

                // ---- change tracking (diff for approver) ----
                $beforeSnap = $existing['last_approved_snapshot'] ?? null;
                if (!$beforeSnap || !is_array($beforeSnap)) {
                    $beforeSnap = pick_display_fields($existing);
                }
                $afterSnap = pick_display_fields($banners[$existingIndex]);
                $diff = compute_diff($beforeSnap, $afterSnap);

                // store for approver when banner goes to pending
                $banners[$existingIndex]['pending_change_summary'] = $diff;

                if ($requireApproval) {
                    // any edit -> pending (except already pending)
                    if ($prevApproval !== 'pending') {
                        $banners[$existingIndex]['approval_status'] = 'pending';
                        $banners[$existingIndex]['requested_at'] = $now;
                        $banners[$existingIndex]['rejected_at'] = null;
                        $banners[$existingIndex]['approved_at'] = null;
                    }
                } else {
                    // no approval mode: auto approve & refresh snapshot
                    $banners[$existingIndex]['approval_status'] = 'approved';
                    $banners[$existingIndex]['approved_at'] = $now;
                    $banners[$existingIndex]['pending_change_summary'] = null;
                    $banners[$existingIndex]['last_approved_snapshot'] = pick_display_fields($banners[$existingIndex]);
                    $banners[$existingIndex]['last_approved_at'] = $now;
                    append_change_log($banners[$existingIndex], 'approve');
                }
                append_change_log($banners[$existingIndex], 'edit', $banners[$existingIndex]['pending_change_summary'] ?? null);
            } else {
                $newId = 'banner_' . time() . '_' . rand(1000, 9999);
                $page_orders = [];
                foreach ($pages as $pKey) {
                    $page_orders[$pKey] = get_max_page_order($banners, $pKey) + 1;
                }
                $maxOrder = ($page_orders[$pages[0] ?? 'store'] ?? 1);

                $approvalStatus = $requireApproval ? 'pending' : 'approved';
                $requestedAt = $requireApproval ? $now : null;

                $newBanner = [
                    'id'          => $newId,
                    'title'       => $title,
                    'link_url'    => $link_url,
                    'link_target' => $link_target,

                    'desktop_img'  => $desktop_img,
                    'mobile_img'   => $mobile_img,
                    'desktop_hash' => $desktop_hash,
                    'mobile_hash'  => $mobile_hash,

                    'pages'       => $pages,
                    'slot'        => 1,
                    'is_active'   => $is_active,
                    'start_at'    => $start_at,
                    'end_at'      => $end_at,
                    'created_at'  => $now,
                    'page_orders' => $page_orders,
                    'order'       => $maxOrder, // legacy fallback
                    'priority'    => $priority, 
                    'ga4'         => [
                        'section_name' => 'herobanner',
                        'click_banner' => $mobile_name,
                    ],
                    'is_copy'     => false,
                    'approval_status' => $approvalStatus,
                    'requested_at'    => $requestedAt,
                    'approved_at'     => null,
                    'rejected_at'     => null,
                    'pending_change_summary' => null,
                    'last_approved_snapshot' => null,
                    'last_approved_at' => null,
                    'change_history'  => [],
                    ];
                append_change_log($newBanner, 'create');
                $banners[] = $newBanner;
            }

            write_banners($banners);
            $_SESSION['form_token'] = bin2hex(random_bytes(16));

            $returnTo = $_POST['return_to'] ?? '';
            if ($returnTo === 'admin_pending') {
                header('Location: admin.php?status=pending&updated=1');
            } elseif ($returnTo === 'admin_approved') {
                header('Location: admin.php?status=approved&updated=1');
            } elseif ($returnTo === 'admin_rejected') {
                header('Location: admin.php?status=rejected&updated=1');
            } elseif ($returnTo === 'admin_all') {
                header('Location: admin.php?status=all&updated=1');
            } else {
                header('Location: index.php?saved=1');
            }
            exit;
        }
    }
}

// ---------- dashboard data ----------
$banners = read_banners();

// ✅ pages จาก config กลาง
$pagesArr = read_pages();
$pageOptions = pages_to_map($pagesArr);
// ----- tab labels with counts -----
$tabCounts = ['all' => count($banners)];
foreach ($pageOptions as $k => $_lbl) { $tabCounts[$k] = 0; }
foreach ($banners as $_b) {
    foreach (array_unique($_b['pages'] ?? []) as $_p) {
        if (isset($tabCounts[$_p])) $tabCounts[$_p] += 1;
    }
}
$tabs = ['all' => 'ทั้งหมด (' . $tabCounts['all'] . ')'];
foreach ($pageOptions as $k => $lbl) {
    $tabs[$k] = $lbl . ' (' . ($tabCounts[$k] ?? 0) . ')';
}

$activeTab = $_GET['tab'] ?? 'all';
if (!isset($tabs[$activeTab])) $activeTab = 'all';

$filtered = array_values(array_filter($banners, function($b) use ($activeTab) {
    if ($activeTab === 'all') return true;
    return in_array($activeTab, $b['pages'] ?? []);
}));

if ($activeTab === 'all') {
    usort($filtered, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
} else {
    usort($filtered, fn($a, $b) => banner_order_for_page($a, $activeTab) <=> banner_order_for_page($b, $activeTab));
}

// For per-page drag & drop, show all rows in that tab (disable pagination)
if ($activeTab !== 'all') {
    $_GET['page'] = 1;
}

$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage  = ($activeTab === 'all') ? 10 : 10000;
$total    = count($filtered);
$totalPages = max(1, ceil($total / $perPage));
$offset   = ($page - 1) * $perPage;
$display  = array_slice($filtered, $offset, $perPage);

$startAtForm = $formData['start_at'] ?? '';
$endAtForm   = $formData['end_at'] ?? '';
$editIdParam = $_GET['edit_id'] ?? '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Banner Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
<link rel="stylesheet" href="styles.css">
</head>
<body class="bg-light">
<div id="app"
     class="container py-4"
     data-active-tab="<?= htmlspecialchars($activeTab) ?>"
     data-open-modal="<?= $openModalAfterPost ? '1' : '0' ?>"
     data-edit-id="<?= htmlspecialchars($editIdParam) ?>"
     data-form-token="<?= htmlspecialchars($_SESSION['form_token']) ?>" >

    <div class="topbar">
    <div class="brand">
        <div class="brand-icon"><i class="bi bi-grid-3x3-gap"></i></div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1>Banner Management</h1>
            <?php if (!empty($config['require_approval'])): ?>
                <span class="badge-approval">ต้องอนุมัติ</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="admin.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Admin</a>
        <button class="btn btn-primary btn-sm" id="btnAddBanner"><i class="bi bi-plus-lg"></i> สร้าง Banner</button>
    </div>
</div>
</div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success py-2 mb-3">บันทึก Banner เรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-info py-2 mb-3">ลบ Banner แล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['cloned'])): ?>
        <div class="alert alert-warning py-2 mb-3">Clone Banner แล้ว (สถานะปิด / แสดง copy)</div>
    <?php endif; ?>

    <div class="card-panel p-4">
    <div class="mb-3">
        <p class="section-title">รายการ Banner</p>
        <p class="section-subtitle">จัดการ Banner ทั้งหมดในระบบ เลือกแท็บเพื่อดูและจัดลำดับตามหน้า</p>
    </div>

    <ul class="nav nav-pills mb-3" role="tablist">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>"
                   href="?tab=<?= htmlspecialchars($key) ?>">
                   <?= htmlspecialchars($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="table-responsive">
        <table class="table table-sm align-middle dashboard-table">
            <thead>
    <tr>
        <th style="width:34px;"></th>
        <th style="width:110px;">รูปภาพ</th>
        <th>ชื่อ</th>
        <th>หน้า</th>
        <th style="width:140px;">กำหนดเวลา</th>
        <th style="width:120px;">เปิดใช้งาน</th>
        <th style="width:140px;">สถานะ</th>
        <th style="width:70px;"></th>
    </tr>
</thead>
            <tbody id="bannerTableBody" data-draggable="<?= $activeTab === 'all' ? '0' : '1' ?>">
                <?php if (empty($display)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มี Banner</td></tr>
                <?php else: ?>
                    <?php foreach ($display as $idx => $b): ?>
    <?php
        [$range, $_statusText, $_statusClass] = get_schedule_and_status($b);
        $gaName = $b['ga4']['click_banner'] ?? '';
        $rangeText = ($range === 'ไม่กำหนดเวลา') ? 'แสดงตลอด' : $range;

        $approval = $b['approval_status'] ?? 'approved';
        $approvalLabel = 'อนุมัติแล้ว';
        $approvalClass = 'success';
        if ($approval === 'pending') { $approvalLabel = 'รออนุมัติ'; $approvalClass = 'pending'; }
        elseif ($approval === 'rejected') { $approvalLabel = 'ถูกปฏิเสธ'; $approvalClass = 'danger'; }

        // page labels
        $pageLabels = [];
        foreach (($b['pages'] ?? []) as $_p) { $pageLabels[] = $pageOptions[$_p] ?? $_p; }
    ?>
    <tr
        <?php if ($activeTab !== 'all'): ?>
            class="draggable-row"
            draggable="true"
        <?php endif; ?>
        data-id="<?= htmlspecialchars($b['id']) ?>">
        <td class="text-muted">
            <?php if ($activeTab !== 'all'): ?>
                <i class="bi bi-grip-vertical drag-handle" title="ลากเพื่อจัดลำดับ"></i>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($b['desktop_img'])): ?>
                <img src="<?= htmlspecialchars(asset_url($b['desktop_img'])) ?>" class="thumb" alt="">
            <?php endif; ?>
        </td>
        <td>
            <div class="fw-semibold"><?= htmlspecialchars($b['title'] ?? '') ?></div>
            <?php if ($gaName): ?>
                <div class="small text-muted">GA4: <?= htmlspecialchars($gaName) ?></div>
            <?php endif; ?>
            <?php if (!empty($b['is_copy'])): ?>
                <span class="status-pill pending mt-1">copy</span>
            <?php endif; ?>
        </td>
        <td class="text-muted"><?= htmlspecialchars(implode(', ', $pageLabels)) ?></td>
        <td class="text-muted small"><?= htmlspecialchars($rangeText) ?></td>
        <td>
            <div class="form-check form-switch m-0">
                <input class="form-check-input toggleActive"
                       type="checkbox"
                       data-id="<?= htmlspecialchars($b['id']) ?>"
                       <?= !empty($b['is_active']) ? 'checked' : '' ?>>
            </div>
        </td>
        <td>
            <span class="status-pill <?= $approvalClass ?>"><?= htmlspecialchars($approvalLabel) ?></span>
        </td>
        <td class="text-end">
            <div class="dropdown">
                <button class="action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-three-dots"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <?php
                            $previewPagesLower = strtolower(implode(', ', $pageLabels));
                            $previewTarget = (($b['link_target'] ?? '_self') === '_blank') ? 'แท็บใหม่' : 'หน้าเดิม';
                            $previewActive = !empty($b['is_active']) ? 'ใช่' : 'ไม่';
                            $previewPriority = ($b['priority'] ?? 'medium');
                            $priorityLabel = ($previewPriority === 'high') ? 'สูง' : (($previewPriority === 'low') ? 'ต่ำ' : 'ปานกลาง');
                            $createdText = '';
                            if (!empty($b['created_at'])) {
                                $ts = strtotime($b['created_at']);
                                if ($ts) $createdText = date('d M Y H:i', $ts);
                            }
                        ?>
                        <button type="button"
                                class="dropdown-item btnPreview"
                                data-banner='<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                data-pages="<?= htmlspecialchars($previewPagesLower) ?>"
                                data-range="<?= htmlspecialchars($rangeText) ?>"
                                data-target="<?= htmlspecialchars($previewTarget) ?>"
                                data-active="<?= htmlspecialchars($previewActive) ?>"
                                data-priority="<?= htmlspecialchars($priorityLabel) ?>"
                                data-created="<?= htmlspecialchars($createdText) ?>"
                                data-approval="<?= htmlspecialchars($approvalLabel) ?>"
                                data-approval-class="<?= htmlspecialchars($approvalClass) ?>">
                            <i class="bi bi-eye"></i> ดูตัวอย่าง
                        </button>
                    </li>
                    <li>
                        <button type="button"
                                class="dropdown-item btnEdit"
                                data-id="<?= htmlspecialchars($b['id']) ?>"
                                data-banner='<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <i class="bi bi-pencil"></i> แก้ไข
                        </button>
                    </li>
                    <li>
                        <form method="post"
                              action="index.php?action=clone&tab=<?= htmlspecialchars($activeTab) ?>"
                              class="m-0 p-0">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($b['id']) ?>">
                            <button class="dropdown-item" type="submit">
                                <i class="bi bi-files"></i> Clone
                            </button>
                        </form>
                    </li>

                    <?php if ($activeTab !== 'all'): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post"
                                  action="index.php?action=delete&tab=<?= htmlspecialchars($activeTab) ?>"
                                  onsubmit="return confirm('ยืนยันลบ Banner นี้?');"
                                  class="m-0 p-0">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($b['id']) ?>">
                                <button class="dropdown-item text-danger" type="submit">
                                    <i class="bi bi-trash"></i> ลบ
                                </button>
                            </form>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <nav aria-label="Banner pagination">
        <ul class="pagination justify-content-end">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link"
                   href="?tab=<?= htmlspecialchars($activeTab) ?>&page=<?= max(1, $page-1) ?>">Prev</a>
            </li>
            <?php for ($i=1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                    <a class="page-link"
                       href="?tab=<?= htmlspecialchars($activeTab) ?>&page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link"
                   href="?tab=<?= htmlspecialchars($activeTab) ?>&page=<?= min($totalPages, $page+1) ?>">Next</a>
            </li>
        </ul>
    </nav>
</div>

<!-- Modal Add/Edit -->
<div class="modal fade" id="bannerModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <form id="bannerForm" method="post" action="index.php?action=save" enctype="multipart/form-data">
        <input type="hidden" name="id" id="fieldId" value="<?= htmlspecialchars($formData['id'] ?? '') ?>">
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
        <input type="hidden" name="return_to"
               value="<?= htmlspecialchars($_GET['return_to'] ?? ($formData['return_to'] ?? '')) ?>">

        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">
            <?= isset($formData['id']) && $formData['id'] ? 'Edit Banner' : 'Add Banner' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body banner-modal-body">
          <?php if ($formErrors['general']): ?>
            <div class="alert alert-warning py-2 mb-3">
              <?= htmlspecialchars($formErrors['general']) ?>
            </div>
          <?php endif; ?>

          <div class="row g-3">
            <!-- LEFT: Images -->
            <div class="col-lg-7">
              <div class="modal-section">
                <div class="modal-section-head">
                  <div>
                    <div class="modal-section-title">รูป Banner</div>
                    <div class="modal-section-subtitle">เลือกใส่รูปแบบ Path หรือ Upload</div>
                  </div>
                </div>

                <?php $imgMode = $formData['image_mode'] ?? 'path'; ?>

                <div class="segment-tabs mb-3" role="group" aria-label="เลือกวิธีใส่รูป">
                  <input class="btn-check" type="radio" name="image_mode" id="imageModePath"
                         value="path" <?= $imgMode === 'path' ? 'checked' : '' ?>>
                  <label class="btn" for="imageModePath"><i class="bi bi-link-45deg me-1"></i>ใช้ Path</label>

                  <input class="btn-check" type="radio" name="image_mode" id="imageModeUpload"
                         value="upload" <?= $imgMode === 'upload' ? 'checked' : '' ?>>
                  <label class="btn" for="imageModeUpload"><i class="bi bi-upload me-1"></i>Upload</label>
                </div>

                <!-- PATH mode fields -->
                <div id="pathFields" class="row g-3 mb-2 <?= $imgMode === 'path' ? '' : 'd-none' ?>">
                  <div class="col-12">
                    <div class="text-muted small">
                      Desktop: <b>1600x500</b> / Mobile: <b>1040x1040</b> หรือ <b>786x432</b>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label mb-1">Desktop Path <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="desktop_path" id="fieldDesktopPath"
                           placeholder="เช่น /images/banner-desktop.webp หรือ https://..."
                           value="<?= htmlspecialchars($formData['desktop_path'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label mb-1">Mobile Path <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="mobile_path" id="fieldMobilePath"
                           placeholder="เช่น /images/banner-mobile.webp หรือ https://..."
                           value="<?= htmlspecialchars($formData['mobile_path'] ?? '') ?>">
                  </div>
                </div>

                <!-- UPLOAD mode -->
                <div id="uploadFields" class="mb-2 <?= $imgMode === 'upload' ? '' : 'd-none' ?>">
                  <div class="upload-box">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                      <div>
                        <div class="fw-medium">อัปโหลดรูป</div>
                        <div class="text-muted small">เลือกได้ 1–2 ไฟล์ (ระบบจะพยายามแยก Desktop/Mobile ให้อัตโนมัติ)</div>
                      </div>
                      <div class="text-muted small">≤ 1MB / jpg png webp</div>
                    </div>

                    <div class="mt-2">
                      <input type="file"
                             class="form-control"
                             name="banner_files[]"
                             id="fieldBannerFiles"
                             accept=".jpg,.jpeg,.png,.webp"
                             multiple>

                      <div class="text-muted small mt-1">
                        Desktop: 1600x500 / Mobile: 1040x1040 หรือ 786x432
                      </div>
                    </div>

                    <div id="uploadPreviewWrap" class="mt-3 d-flex flex-wrap gap-2"></div>

                    <input type="file" class="d-none" name="desktop_file" id="fieldDesktopFile" accept=".jpg,.jpeg,.png,.webp">
                    <input type="file" class="d-none" name="mobile_file"  id="fieldMobileFile"  accept=".jpg,.jpeg,.png,.webp">

                    <?php if ($formErrors['desktop']): ?>
                      <div class="text-danger small mt-2">
                        Desktop: <?= htmlspecialchars($formErrors['desktop']) ?>
                        <?php if (!empty($duplicateInfo['desktop'])): ?>
                          <button class="btn btn-link btn-sm p-0 ms-1" type="button"
                                  data-bs-toggle="collapse" data-bs-target="#dupDesktopDetail">
                            ดูเพิ่มเติม
                          </button>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($duplicateInfo['desktop'])): ?>
                        <div class="collapse mt-1" id="dupDesktopDetail">
                          <ul class="small text-muted mb-0">
                            <?php foreach ($duplicateInfo['desktop'] as $d): ?>
                              <li>Banner: <?= htmlspecialchars($d['title'] ?? '-') ?> (ID: <?= htmlspecialchars($d['id'] ?? '-') ?>)</li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($formErrors['mobile']): ?>
                      <div class="text-danger small mt-2">
                        Mobile: <?= htmlspecialchars($formErrors['mobile']) ?>
                        <?php if (!empty($duplicateInfo['mobile'])): ?>
                          <button class="btn btn-link btn-sm p-0 ms-1" type="button"
                                  data-bs-toggle="collapse" data-bs-target="#dupMobileDetail">
                            ดูเพิ่มเติม
                          </button>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($duplicateInfo['mobile'])): ?>
                        <div class="collapse mt-1" id="dupMobileDetail">
                          <ul class="small text-muted mb-0">
                            <?php foreach ($duplicateInfo['mobile'] as $d): ?>
                              <li>Banner: <?= htmlspecialchars($d['title'] ?? '-') ?> (ID: <?= htmlspecialchars($d['id'] ?? '-') ?>)</li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>

              </div>
            </div>

            <!-- RIGHT: Details -->
            <div class="col-lg-5">
              <div class="modal-section">
                <div class="modal-section-head">
                  <div>
                    <div class="modal-section-title">ข้อมูลหลัก</div>
                    <div class="modal-section-subtitle">ชื่อ, หน้า, ความสำคัญ และสถานะ</div>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">ชื่อ Banner <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" id="fieldTitle"
                           value="<?= htmlspecialchars($formData['title'] ?? '') ?>" required>
                  </div>

                  <div class="col-12">
                    <label class="form-label mb-2">หน้า <span class="text-danger">*</span></label>
                    <div class="pages-pills d-flex flex-wrap gap-2">
                      <?php foreach ($pageOptions as $value => $label): ?>
                        <?php
                          $checked = '';
                          if (isset($formData['pages']) && is_array($formData['pages']) && in_array($value, $formData['pages'])) {
                            $checked = 'checked';
                          }
                        ?>
                        <input class="btn-check page-checkbox" type="checkbox" name="pages[]"
                               value="<?= htmlspecialchars($value) ?>" id="page_<?= htmlspecialchars($value) ?>" <?= $checked ?>>
                        <label class="btn btn-outline-secondary btn-sm rounded-pill" for="page_<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></label>
                      <?php endforeach; ?>
                    </div>

                    <div id="pagesError" class="text-danger small mt-2 d-none">
                      กรุณาเลือกหน้าอย่างน้อย 1 หน้า
                    </div>
                  </div>

                  <div class="col-12">
                    <label class="form-label mb-2">Priority</label>
                    <?php $priorityVal = strtolower($formData['priority'] ?? 'medium'); ?>
                    <div class="btn-group w-100" role="group" aria-label="priority">
                      <input class="btn-check" type="radio" name="priority" id="priorityHigh" value="high" <?= $priorityVal === 'high' ? 'checked' : '' ?>>
                      <label class="btn btn-outline-secondary" for="priorityHigh">High</label>

                      <input class="btn-check" type="radio" name="priority" id="priorityMedium" value="medium" <?= $priorityVal === 'medium' ? 'checked' : '' ?>>
                      <label class="btn btn-outline-secondary" for="priorityMedium">Medium</label>

                      <input class="btn-check" type="radio" name="priority" id="priorityLow" value="low" <?= $priorityVal === 'low' ? 'checked' : '' ?>>
                      <label class="btn btn-outline-secondary" for="priorityLow">Low</label>
                    </div>
                  </div>

                  <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between">
                      <div>
                        <div class="fw-medium">เปิดใช้งาน</div>
                        <div class="text-muted small">ปิดเพื่อไม่ให้แสดงบนหน้าเว็บ</div>
                      </div>
                      <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" name="is_active" id="fieldActive"
                               <?= !isset($formData['is_active']) || $formData['is_active']=='on' ? 'checked' : '' ?>>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-section">
                <div class="modal-section-head">
                  <div>
                    <div class="modal-section-title">ลิงก์</div>
                    <div class="modal-section-subtitle">กำหนด URL และวิธีเปิดลิงก์</div>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">ลิงก์ปลายทาง <span class="text-danger">*</span></label>
                    <input type="url" class="form-control" name="link_url" id="fieldLinkUrl"
                           placeholder="https://"
                           value="<?= htmlspecialchars($formData['link_url'] ?? '') ?>" required>
                  </div>

                  <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between">
                      <div>
                        <div class="fw-medium">เปิดลิงก์ในแท็บใหม่</div>
                        <div class="text-muted small">แนะนำสำหรับหน้าโปรโมชั่น/แคมเปญ</div>
                      </div>
                      <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" name="link_newtab" id="fieldLinkNewtab"
                               <?= !isset($formData['link_newtab']) || $formData['link_newtab'] === 'on' ? 'checked' : '' ?>>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-section">
                <div class="modal-section-head">
                  <div>
                    <div class="modal-section-title">กำหนดเวลา</div>
                    <div class="modal-section-subtitle">เว้นว่างได้ = แสดงตลอด</div>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-md-6 col-lg-12">
                    <label class="form-label">Start</label>
                    <input type="text" class="form-control datetime-picker" name="start_at" id="fieldStartAt"
                           value="<?= htmlspecialchars($startAtForm) ?>"
                           placeholder="เลือกวันและเวลา">
                  </div>

                  <div class="col-md-6 col-lg-12">
                    <label class="form-label">End</label>
                    <input type="text" class="form-control datetime-picker" name="end_at" id="fieldEndAt"
                           value="<?= htmlspecialchars($endAtForm) ?>"
                           placeholder="เลือกวันและเวลา">
                  </div>

                  <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearSchedule">
                      <i class="bi bi-x-circle me-1"></i>ล้างเวลา
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnNoEndSchedule">
                      <i class="bi bi-infinity me-1"></i>ไม่กำหนด End
                    </button>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSaveBanner">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content preview-modal">
      <div class="modal-header">
        <div>
          <div class="d-flex align-items-center gap-2">
            <h5 class="modal-title mb-0" id="previewTitle">-</h5>
            <span class="status-pill success" id="previewApproval">-</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-2">
        <div class="preview-toggle" role="tablist" aria-label="preview toggle">
          <button type="button" class="preview-toggle-btn active" id="btnPrevDesktop" data-mode="desktop">
            <i class="bi bi-display"></i> Desktop
          </button>
          <button type="button" class="preview-toggle-btn" id="btnPrevMobile" data-mode="mobile">
            <i class="bi bi-phone"></i> Mobile
          </button>
        </div>

        <div class="preview-img-wrap" id="previewImgWrap">
          <img id="previewImg" src="" alt="preview" />
        </div>

        <div class="preview-kv" id="previewKV">
          <div class="preview-row">
            <div class="label">Link</div>
            <div class="value"><a href="#" target="_blank" rel="noopener" id="previewLink">-</a></div>
          </div>
          <div class="preview-row">
            <div class="label">เปิดลิงก์</div>
            <div class="value" id="previewTarget">-</div>
          </div>
          <div class="preview-row">
            <div class="label">หน้า</div>
            <div class="value" id="previewPages">-</div>
          </div>
          <div class="preview-row">
            <div class="label">กำหนดเวลา</div>
            <div class="value" id="previewRange">-</div>
          </div>
          <div class="preview-row">
            <div class="label">เปิดใช้งาน</div>
            <div class="value" id="previewActive">-</div>
          </div>
          <div class="preview-row">
            <div class="label">ความสำคัญ</div>
            <div class="value" id="previewPriority">-</div>
          </div>
          <div class="preview-row">
            <div class="label">สร้างเมื่อ</div>
            <div class="value" id="previewCreated">-</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="app.js"></script>
</body>
</html>