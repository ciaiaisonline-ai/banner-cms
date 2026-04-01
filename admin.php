<?php
// admin.php – Approve / Reject Hero Banners + Manage Pages

session_start();
date_default_timezone_set('Asia/Bangkok');

define('BANNER_JSON_PATH', __DIR__ . '/banner-data/banners.json');
define('CONFIG_JSON_PATH', __DIR__ . '/banner-data/config.json');
define('PAGES_JSON_PATH', __DIR__ . '/banner-data/pages.json');

if (!file_exists(__DIR__ . '/banner-data')) {
    mkdir(__DIR__ . '/banner-data', 0775, true);
}

// ---------- helpers ----------
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

function read_config() {
    if (!file_exists(CONFIG_JSON_PATH)) {
        $default = ['require_approval' => true];
        file_put_contents(CONFIG_JSON_PATH, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $default;
    }
    $json = file_get_contents(CONFIG_JSON_PATH);
    $cfg = json_decode($json, true);
    if (!is_array($cfg)) $cfg = [];
    if (!array_key_exists('require_approval', $cfg)) {
        $cfg['require_approval'] = true;
    }
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

function write_pages($pagesArr) {
    $fp = fopen(PAGES_JSON_PATH, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode(array_values($pagesArr), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function sanitize_page_key($key) {
    $key = strtolower(trim($key));
    $key = preg_replace('/\s+/', '-', $key);
    $key = preg_replace('/[^a-z0-9\-_]/', '', $key);
    return $key;
}

/**
 * คืน URL เต็มพร้อม domain สำหรับ path ที่เก็บใน JSON
 */
function asset_url($path) {
    if (!$path) return '';
    if (preg_match('#^https?://#', $path)) return $path;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    if ($scriptDir === '' || $scriptDir === '/') {
        return $protocol . $host . $path;
    }
    return $protocol . $host . $scriptDir . $path;
}

function format_thai_date($isoOrTs, $withTime = true) {
    if (!$isoOrTs) return '-';
    $ts = is_numeric($isoOrTs) ? intval($isoOrTs) : strtotime($isoOrTs);
    if (!$ts) return '-';

    $months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    $d = date('j', $ts);
    $m = $months[intval(date('n', $ts))];
    $y = intval(date('Y', $ts)) + 543;
    $time = date('H:i', $ts) . ' น.';
    return $withTime ? "{$d} {$m} {$y} {$time}" : "{$d} {$m} {$y}";
}

// ---- change tracking helpers (for approver UI) ----
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
    if (is_array($out['pages'])) sort($out['pages']);
    return $out;
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

function pretty_value($v) {
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null) return 'null';
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return (string)$v;
}


// ---------- handle pages: add / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'add_page') {
    $key = sanitize_page_key($_POST['page_key'] ?? '');
    $label = trim($_POST['page_label'] ?? '');

    $pages = read_pages();

    $exists = false;
    foreach ($pages as $p) {
        if (($p['key'] ?? '') === $key) { $exists = true; break; }
    }

    if ($key !== '' && $label !== '' && !$exists) {
        $pages[] = ['key' => $key, 'label' => $label];
        write_pages($pages);
        header('Location: admin.php?pagesaved=1');
        exit;
    } else {
        header('Location: admin.php?pageerror=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'delete_page') {
    $key = sanitize_page_key($_POST['page_key'] ?? '');
    $pages = read_pages();
    $pages = array_values(array_filter($pages, fn($p) => (($p['key'] ?? '') !== $key)));
    write_pages($pages);
    header('Location: admin.php?pagedeleted=1');
    exit;
}

// ---------- handle config toggle ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'toggle_config') {
    $cfg = read_config();
    $cfg['require_approval'] = isset($_POST['require_approval']) ? true : false;
    write_config($cfg);
    header('Location: admin.php?cfgsaved=1');
    exit;
}

// ---------- handle approve / reject / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['__action'] ?? ''), ['approve','reject','delete'], true)) {

    $action  = $_POST['__action'];
    $id      = $_POST['id'] ?? '';
    $banners = read_banners();
    $updated = false;

    if ($action === 'delete') {
        $banners = array_values(array_filter(
            $banners,
            fn($b) => ($b['id'] ?? '') !== $id
        ));
        $updated = true;
    } else {
        foreach ($banners as &$b) {
            if (($b['id'] ?? '') === $id) {
                if ($action === 'approve') {
                    $b['approval_status'] = 'approved';
                    $b['approved_at'] = date('c');
                    $b['last_approved_snapshot'] = snapshot_fields($b);
                    $b['last_approved_at'] = date('c');
                    $b['pending_change_summary'] = null;
                } elseif ($action === 'reject') {
                    $b['approval_status'] = 'rejected';
                    $b['rejected_at']     = date('c');
                }
                $updated = true;
                break;
            }
        }
        unset($b);
    }

    if ($updated) write_banners($banners);

    header('Location: admin.php?updated=1');
    exit;
}

// ---------- build list ----------
$banners = read_banners();
$config  = read_config();
$pagesArr = read_pages();

$statusFilter = $_GET['status'] ?? 'pending';

$filtered = array_values(array_filter($banners, function($b) use ($statusFilter) {
    $st = $b['approval_status'] ?? 'approved';
    if ($statusFilter === 'all')      return true;
    if ($statusFilter === 'pending')  return $st === 'pending';
    if ($statusFilter === 'rejected') return $st === 'rejected';
    if ($statusFilter === 'approved') return $st === 'approved';
    return true;
}));

usort($filtered, function($a, $b) {
    $ta = $a['requested_at'] ?? $a['created_at'] ?? '';
    $tb = $b['requested_at'] ?? $b['created_at'] ?? '';
    return strcmp($tb, $ta);
});

$returnTo = 'admin_all';
if ($statusFilter === 'pending')      $returnTo = 'admin_pending';
elseif ($statusFilter === 'approved') $returnTo = 'admin_approved';
elseif ($statusFilter === 'rejected') $returnTo = 'admin_rejected';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Admin Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container py-4">

  <div class="topbar">
    <div class="brand">
      <div class="brand-icon"><i class="bi bi-shield-check"></i></div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h1>Admin Console</h1>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil-square"></i> Editor</a>
    </div>
  </div>

  <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success py-2">อัปเดตสถานะ Banner เรียบร้อยแล้ว</div>
  <?php endif; ?>
  <?php if (isset($_GET['cfgsaved'])): ?>
    <div class="alert alert-info py-2">บันทึกค่าการอนุมัติเรียบร้อยแล้ว</div>
  <?php endif; ?>
  <?php if (isset($_GET['pagesaved'])): ?>
    <div class="alert alert-success py-2">เพิ่ม Page ใหม่เรียบร้อยแล้ว</div>
  <?php endif; ?>
  <?php if (isset($_GET['pagedeleted'])): ?>
    <div class="alert alert-info py-2">ลบ Page แล้ว</div>
  <?php endif; ?>
  <?php if (isset($_GET['pageerror'])): ?>
    <div class="alert alert-warning py-2">เพิ่ม Page ไม่สำเร็จ (key ซ้ำ/ว่าง หรือ label ว่าง)</div>
  <?php endif; ?>

  <div class="admin-grid">

    <!-- LEFT: Approvals -->
    <div class="card-panel p-4">
      <div class="mb-3">
        <p class="section-title">รายการ Banner</p>
        <p class="section-subtitle">อนุมัติหรือปฏิเสธ Banner ที่รอดำเนินการ</p>
      </div>

      <?php
        $countPending = 0; $countApproved = 0; $countRejected = 0;
        foreach ($banners as $_b) {
          $st = $_b['approval_status'] ?? 'approved';
          if ($st === 'pending') $countPending++;
          elseif ($st === 'rejected') $countRejected++;
          else $countApproved++;
        }
        $countAll = count($banners);
      ?>

      <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link <?= $statusFilter==='pending'?'active':'' ?>" href="?status=pending">รออนุมัติ (<?= $countPending ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $statusFilter==='approved'?'active':'' ?>" href="?status=approved">อนุมัติแล้ว (<?= $countApproved ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $statusFilter==='rejected'?'active':'' ?>" href="?status=rejected">ถูกปฏิเสธ (<?= $countRejected ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $statusFilter==='all'?'active':'' ?>" href="?status=all">ทั้งหมด (<?= $countAll ?>)</a></li>
      </ul>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th style="width:110px;">รูป</th>
              <th>ชื่อ</th>
              <th style="width:160px;">หน้า</th>
              <th style="width:150px;">แก้ไขล่าสุด</th>
              <th style="width:140px;">สถานะ</th>
              <th style="width:220px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($filtered)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบ Banner</td></tr>
            <?php else: ?>
              <?php foreach ($filtered as $b): ?>
                <?php
                  $st = $b['approval_status'] ?? 'approved';
                  $pill = 'success'; $label = 'อนุมัติแล้ว';
                  if ($st === 'pending') { $pill = 'pending'; $label = 'รออนุมัติ'; }
                  if ($st === 'rejected') { $pill = 'danger'; $label = 'ถูกปฏิเสธ'; }

                  // change badge (edited)
                  $editCount = is_array($b['pending_change_summary'] ?? null) ? count($b['pending_change_summary']) : 0;

                  $pageLabels = [];
                  foreach (($b['pages'] ?? []) as $_p) {
                    $found = null;
                    foreach ($pagesArr as $pp) { if (($pp['key'] ?? '') === $_p) { $found = $pp['label'] ?? $_p; break; } }
                    $pageLabels[] = $found ?? $_p;
                  }
                  $lastTs = $b['requested_at'] ?? $b['created_at'] ?? '';
                ?>
                <tr>
                  <td>
                    <?php if (!empty($b['desktop_img'])): ?>
                      <img class="thumb" src="<?= htmlspecialchars(asset_url($b['desktop_img'])) ?>" alt="">
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($b['title'] ?? '') ?></div>
                    <?php if ($editCount > 0): ?>
                      <div class="small text-muted mt-1">
                        <span class="badge-approval">Edited (<?= $editCount ?>)</span>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted"><?= htmlspecialchars(implode(', ', $pageLabels)) ?></td>
                  <td class="text-muted small"><?= htmlspecialchars($lastTs) ?></td>
                  <td><span class="status-pill <?= $pill ?>"><?= $label ?></span></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                      <?php if ($st === 'pending'): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="__action" value="approve">
                          <input type="hidden" name="id" value="<?= htmlspecialchars($b['id']) ?>">
                          <button class="btn btn-primary btn-sm" type="submit">อนุมัติ</button>
                        </form>
                        <form method="post" class="m-0">
                          <input type="hidden" name="__action" value="reject">
                          <input type="hidden" name="id" value="<?= htmlspecialchars($b['id']) ?>">
                          <button class="btn btn-outline-secondary btn-sm" type="submit">ปฏิเสธ</button>
                        </form>
                      <?php endif; ?>

                      <button class="btn btn-outline-secondary btn-sm"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#diff<?= htmlspecialchars($b['id']) ?>"
                              aria-expanded="false">
                        รายละเอียด
                      </button>

                      <form method="post" class="m-0" onsubmit="return confirm('ยืนยันลบ Banner นี้?');">
                        <input type="hidden" name="__action" value="delete">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($b['id']) ?>">
                        <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-trash"></i></button>
                      </form>
                    </div>

                    <!-- Diff -->
                    <div class="collapse mt-2" id="diff<?= htmlspecialchars($b['id']) ?>">
                      <div class="card-panel p-3">
                        <?php if (!empty($b['pending_change_summary']) && is_array($b['pending_change_summary'])): ?>
                          <div class="small text-muted mb-2">Change Summary</div>
                          <div class="table-responsive">
                            <table class="table table-sm align-middle">
                              <thead>
                                <tr>
                                  <th>Field</th>
                                  <th>Before</th>
                                  <th>After</th>
                                  <th>Note</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php foreach ($b['pending_change_summary'] as $ch): ?>
                                  <tr>
                                    <td class="text-muted"><?= htmlspecialchars($ch['field'] ?? '') ?></td>
                                    <td><?= htmlspecialchars(is_scalar($ch['before'] ?? '') ? (string)($ch['before'] ?? '') : json_encode($ch['before'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></td>
                                    <td><?= htmlspecialchars(is_scalar($ch['after'] ?? '') ? (string)($ch['after'] ?? '') : json_encode($ch['after'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($ch['note'] ?? '') ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php else: ?>
                          <div class="text-muted small">ไม่มีรายการเปลี่ยนแปลง</div>
                        <?php endif; ?>
                      </div>
                    </div>

                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- RIGHT: Settings -->
    <div class="card-panel p-4">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <p class="section-title mb-0">ตั้งค่า</p>
        </div>
      </div>

      <div class="segment-tabs mb-3">
        <button class="btn active" type="button" id="segApproval">การอนุมัติ</button>
        <button class="btn" type="button" id="segPages">หน้า</button>
      </div>

      <div id="panelApproval">
        <form method="post">
          <input type="hidden" name="__action" value="toggle_config">
          <div class="mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="cfgRequireApproval"
                     name="require_approval" <?= $config['require_approval'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="cfgRequireApproval">
                ต้องการอนุมัติ Banner ใหม่ก่อนแสดง
              </label>
            </div>
          </div>
          <button class="btn btn-primary btn-sm" type="submit">บันทึก</button>
        </form>

        <div class="mt-3 card-panel p-3">
          <div class="small text-muted">Timezone</div>
          <div class="fw-semibold">Asia/Bangkok</div>
        </div>
      </div>

      <div id="panelPages" class="d-none">
        <div class="mb-2 small text-muted">เพิ่มหน้าใหม่</div>
        <form method="post" class="row g-2 align-items-end mb-3">
          <input type="hidden" name="__action" value="add_page">
          <div class="col-5">
            <label class="form-label mb-1">Key</label>
            <input class="form-control form-control-sm" name="page_key" placeholder="iphone" required>
          </div>
          <div class="col-7">
            <label class="form-label mb-1">Label</label>
            <input class="form-control form-control-sm" name="page_label" placeholder="iPhone" required>
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-lg"></i> เพิ่มหน้า</button>
          </div>
        </form>

        <div class="small text-muted mb-2">รายการหน้า</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Key</th>
                <th>Label</th>
                <th style="width:80px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pagesArr as $p): ?>
                <tr>
                  <td class="text-muted"><?= htmlspecialchars($p['key'] ?? '') ?></td>
                  <td><?= htmlspecialchars($p['label'] ?? '') ?></td>
                  <td class="text-end">
                    <form method="post" class="m-0" onsubmit="return confirm('ยืนยันลบ Page นี้?');">
                      <input type="hidden" name="__action" value="delete_page">
                      <input type="hidden" name="page_key" value="<?= htmlspecialchars($p['key'] ?? '') ?>">
                      <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const segApproval = document.getElementById('segApproval');
  const segPages = document.getElementById('segPages');
  const panelApproval = document.getElementById('panelApproval');
  const panelPages = document.getElementById('panelPages');

  function setSeg(which){
    if(which === 'approval'){
      segApproval.classList.add('active');
      segPages.classList.remove('active');
      panelApproval.classList.remove('d-none');
      panelPages.classList.add('d-none');
    }else{
      segPages.classList.add('active');
      segApproval.classList.remove('active');
      panelPages.classList.remove('d-none');
      panelApproval.classList.add('d-none');
    }
  }
  segApproval?.addEventListener('click', () => setSeg('approval'));
  segPages?.addEventListener('click', () => setSeg('pages'));
</script>
</body>
</html>
