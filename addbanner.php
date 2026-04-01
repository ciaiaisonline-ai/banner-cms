<?php
// addbanner.php – Full page Add Banner

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

$pagesArr = read_pages();
$pageOptions = pages_to_map($pagesArr);

// defaults
$formData = [];
$imgMode = 'path';
$priorityVal = 'medium';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Add Banner</title>
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

<div id="app" class="container py-4" data-active-tab="all" data-open-modal="0" data-edit-id="">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-0">Add Banner</h1>
      <div class="text-muted small">Full page add (บันทึกแล้วจะกลับไปหน้า Dashboard)</div>
    </div>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">

      <form id="bannerForm" method="post" action="index.php?action=save" enctype="multipart/form-data">
        <input type="hidden" name="id" id="fieldId" value="">
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
        <input type="hidden" name="return_to" value="">

        <div class="mb-3">
          <label class="form-label">ชื่อ Banner</label>
          <input type="text" class="form-control" name="title" id="fieldTitle" value="">
        </div>

        <div class="mb-3">
          <label class="form-label d-block">Priority</label>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="priority" id="priorityHigh" value="high">
            <label class="form-check-label" for="priorityHigh">High</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="priority" id="priorityMedium" value="medium" checked>
            <label class="form-check-label" for="priorityMedium">Medium</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="priority" id="priorityLow" value="low">
            <label class="form-check-label" for="priorityLow">Low</label>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label d-block">เลือกวิธีใส่รูป</label>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="image_mode" id="imageModePath" value="path" checked>
            <label class="form-check-label" for="imageModePath">ใช้ Path</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="image_mode" id="imageModeUpload" value="upload">
            <label class="form-check-label" for="imageModeUpload">Upload</label>
          </div>
        </div>

        <!-- PATH mode fields -->
        <div id="pathFields" class="row mb-2">
          <div class="col-md-6">
            <label class="form-label mb-1">Desktop Path</label>
            <input type="text" class="form-control" name="desktop_path" id="fieldDesktopPath"
                   placeholder="https://www.ais.th/content/dam/ais/consumer/XXX.png" value="">
          </div>
          <div class="col-md-6 mt-3 mt-md-0">
            <label class="form-label mb-1">Mobile Path</label>
            <input type="text" class="form-control" name="mobile_path" id="fieldMobilePath"
                   placeholder="https://www.ais.th/content/dam/ais/consumer/XXX.png" value="">
          </div>
        </div>

        <!-- UPLOAD mode -->
        <div id="uploadFields" class="row mb-3">
          <div class="col-12">
            <label class="form-label mb-1">อัปโหลดรูป (เลือกได้ 1–2 ไฟล์)</label>

            <input type="file" class="form-control" name="banner_files[]" id="fieldBannerFiles"
                   accept=".jpg,.jpeg,.png,.webp" multiple>

            <div class="text-muted small mt-1">
              * Desktop: 1600x500 (≤1MB) / Mobile: 1040x1040 หรือ 786x432 (≤1MB)
            </div>

            <div id="uploadPreviewWrap" class="mt-2 d-flex flex-wrap gap-2"></div>

            <input type="file" class="d-none" name="desktop_file" id="fieldDesktopFile" accept=".jpg,.jpeg,.png,.webp">
            <input type="file" class="d-none" name="mobile_file"  id="fieldMobileFile"  accept=".jpg,.jpeg,.png,.webp">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">ลิงก์ปลายทาง</label>
          <input type="url" class="form-control" name="link_url" id="fieldLinkUrl" placeholder="https://" value="">
          <div class="form-check mt-1">
            <input class="form-check-input" type="checkbox" name="link_newtab" id="fieldLinkNewtab" checked>
            <label class="form-check-label" for="fieldLinkNewtab">New Tab</label>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label d-block">สถานะ</label>
          <div class="form-check form-switch form-switch-lg">
            <input class="form-check-input" type="checkbox" name="is_active" id="fieldActive" checked>
            <label class="form-check-label" for="fieldActive">เปิดใช้งาน</label>
          </div>
        </div>

        <div class="mb-3" id="pagesGroup">
          <label class="form-label">ใช้ Banner นี้ในหน้า <span class="text-danger">*</span></label>
          <div class="row">
            <?php foreach ($pageOptions as $value => $label): ?>
              <div class="col-6 col-md-3">
                <div class="form-check">
                  <input class="form-check-input page-checkbox" type="checkbox" name="pages[]"
                         value="<?= htmlspecialchars($value) ?>" id="page_<?= htmlspecialchars($value) ?>">
                  <label class="form-check-label" for="page_<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div id="pagesError" class="text-danger small mt-2 d-none">กรุณาเลือกหน้าอย่างน้อย 1 หน้า</div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Start (วันเวลาเริ่มแสดง)</label>
            <input type="text" class="form-control" name="start_at" id="fieldStartAt" placeholder="YYYY-MM-DD HH:mm" value="">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">End (วันเวลาสิ้นสุด)</label>
            <input type="text" class="form-control" name="end_at" id="fieldEndAt" placeholder="YYYY-MM-DD HH:mm" value="">
          </div>
        </div>

        <div class="d-flex gap-2 justify-content-end">
          <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary" id="btnSaveBanner">Save Banner</button>
        </div>

      </form>

    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="app.js"></script>
</body>
</html>
