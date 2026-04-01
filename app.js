// app.js – Modal, Add/Edit, Drag & Drop, Flatpickr + One upload auto desktop/mobile

(function () {
  const appEl = document.getElementById('app');
  if (!appEl) return;

  const ACTIVE_TAB = appEl.dataset.activeTab || 'all';
  const OPEN_MODAL_FLAG = appEl.dataset.openModal === '1';
  const EDIT_ID = appEl.dataset.editId || '';

  const modalEl = document.getElementById('bannerModal');
  const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
  const form = document.getElementById('bannerForm');
  const btnSave = document.getElementById('btnSaveBanner');
  const btnAdd = document.getElementById('btnAddBanner');
  const tbody = document.getElementById('bannerTableBody');

  let isSubmitting = false;
  let fpStart = null;
  let fpEnd = null;

  // =========================
  // One Upload -> Auto Desktop/Mobile + Preview
  // =========================
  const bannerFilesInput = document.getElementById('fieldBannerFiles');
  const previewWrap = document.getElementById('uploadPreviewWrap');

  function setHiddenFile(inputEl, file) {
    if (!inputEl || !file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    inputEl.files = dt.files;
  }

  function clearHiddenFile(inputEl) {
    if (!inputEl) return;
    const dt = new DataTransfer();
    inputEl.files = dt.files;
  }

  function isDesktopSize(w, h) {
    return w === 1600 && h === 500;
  }
  function isMobileSize(w, h) {
    return (w === 1040 && h === 1040) || (w === 786 && h === 432);
  }

  function getImageSize(file) {
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        resolve({ w: img.naturalWidth, h: img.naturalHeight });
        URL.revokeObjectURL(url);
      };
      img.onerror = () => {
        resolve({ w: 0, h: 0 });
        URL.revokeObjectURL(url);
      };
      img.src = url;
    });
  }

  function clearPreview() {
    if (previewWrap) previewWrap.innerHTML = '';
  }

  function appendAlert(type, text) {
    if (!previewWrap) return;
    const el = document.createElement('div');
    el.className = `alert alert-${type} py-2 w-100 mb-0`;
    el.textContent = text;
    previewWrap.appendChild(el);
  }

  function renderPreview(file, label, meta) {
    if (!previewWrap) return;

    const card = document.createElement('div');
    card.className = 'upload-preview-card';

    const img = document.createElement('img');
    img.className = 'upload-preview-img';
    img.alt = label;

    const badge = document.createElement('div');
    badge.className = 'upload-preview-badge';
    badge.textContent = label;

    const text = document.createElement('div');
    text.className = 'upload-preview-text';
    text.textContent = `${file.name} (${meta.w}x${meta.h}, ${Math.round(file.size / 1024)} KB)`;

    card.appendChild(img);
    card.appendChild(badge);
    card.appendChild(text);

    const reader = new FileReader();
    reader.onload = () => { img.src = reader.result; };
    reader.readAsDataURL(file);

    previewWrap.appendChild(card);
  }

  function resetUploadPreview() {
    clearPreview();

    if (bannerFilesInput) bannerFilesInput.value = '';

    const desktopFile = document.getElementById('fieldDesktopFile');
    const mobileFile = document.getElementById('fieldMobileFile');
    clearHiddenFile(desktopFile);
    clearHiddenFile(mobileFile);
  }

  async function handleBannerFilesChange() {
    const desktopFile = document.getElementById('fieldDesktopFile');
    const mobileFile = document.getElementById('fieldMobileFile');
    if (!desktopFile || !mobileFile || !bannerFilesInput) return;

    clearPreview();
    clearHiddenFile(desktopFile);
    clearHiddenFile(mobileFile);

    let files = Array.from(bannerFilesInput.files || []);
    if (!files.length) return;

    // จำกัดไม่เกิน 2 ไฟล์ (Desktop+Mobile)
    if (files.length > 2) {
      appendAlert('warning', 'เลือกได้สูงสุด 2 ไฟล์เท่านั้น (Desktop + Mobile)');
      bannerFilesInput.value = '';
      return;
    }

    let desktopSet = false;
    let mobileSet = false;

    // ตรวจจับขนาด + assign
    for (const f of files) {
      const meta = await getImageSize(f);

      let label = 'ไม่รองรับ';
      if (isDesktopSize(meta.w, meta.h)) {
        label = 'Desktop (1600x500)';
        setHiddenFile(desktopFile, f);
        desktopSet = true;
      } else if (isMobileSize(meta.w, meta.h)) {
        label = 'Mobile (1040x1040 / 786x432)';
        setHiddenFile(mobileFile, f);
        mobileSet = true;
      }

      renderPreview(f, label, meta);
    }

    // ถ้ามีไฟล์ไม่รองรับ ให้เตือน + เคลียร์ hidden เพื่อกันส่งไฟล์ผิด
    const desktopHidden = desktopFile.files?.[0];
    const mobileHidden = mobileFile.files?.[0];

    if (!desktopHidden && !mobileHidden) {
      appendAlert('danger', 'ไฟล์ที่เลือกไม่ตรงขนาดที่รองรับ กรุณาเลือกใหม่');
      return;
    }

    // เตือนถ้าไม่ครบ
    if (!desktopSet || !mobileSet) {
      appendAlert('warning', 'ยังเลือกไฟล์ไม่ครบ: ต้องมี Desktop 1600x500 และ Mobile 1040x1040 หรือ 786x432');
    }
  }

  if (bannerFilesInput) {
    bannerFilesInput.addEventListener('change', handleBannerFilesChange);
  }

  // =========================
  // Image mode toggle (show/hide by radio)
  // =========================
  function setImageInputsState() {
    const modeUploadEl = document.getElementById('imageModeUpload');
    const mode = (modeUploadEl && modeUploadEl.checked) ? 'upload' : 'path';

    const pathFields = document.getElementById('pathFields');
    const uploadFields = document.getElementById('uploadFields');

    const desktopPath = document.getElementById('fieldDesktopPath');
    const mobilePath = document.getElementById('fieldMobilePath');

    const desktopFile = document.getElementById('fieldDesktopFile'); // hidden
    const mobileFile = document.getElementById('fieldMobileFile');   // hidden
    const bannerFiles = document.getElementById('fieldBannerFiles'); // real picker

    if (mode === 'path') {
      // show path, hide upload
      if (pathFields) pathFields.classList.remove('d-none');
      if (uploadFields) uploadFields.classList.add('d-none');

      // enable path input
      if (desktopPath) { desktopPath.disabled = false; desktopPath.required = true; }
      if (mobilePath) { mobilePath.disabled = false; mobilePath.required = true; }

      // disable upload
      if (bannerFiles) { bannerFiles.disabled = true; bannerFiles.required = false; }
      if (desktopFile) desktopFile.disabled = true;
      if (mobileFile) mobileFile.disabled = true;

      // clear upload files/preview
      resetUploadPreview();
    } else {
      // hide path, show upload
      if (pathFields) pathFields.classList.add('d-none');
      if (uploadFields) uploadFields.classList.remove('d-none');

      // disable path
      if (desktopPath) { desktopPath.disabled = true; desktopPath.required = false; }
      if (mobilePath) { mobilePath.disabled = true; mobilePath.required = false; }

      // enable upload
      if (bannerFiles) { bannerFiles.disabled = false; bannerFiles.required = true; }
      if (desktopFile) desktopFile.disabled = false;
      if (mobileFile) mobileFile.disabled = false;
    }
  }

  // =========================
  // Pages required (>=1)
  // =========================
  function getPagesCheckedCount() {
    return document.querySelectorAll('input[name="pages[]"]:checked').length;
  }

  function showPagesError(show) {
    const el = document.getElementById('pagesError');
    if (!el) return;
    if (show) el.classList.remove('d-none');
    else el.classList.add('d-none');
  }

  function bindPagesLiveValidation() {
    document.querySelectorAll('input[name="pages[]"]').forEach(cb => {
      cb.addEventListener('change', () => {
        showPagesError(getPagesCheckedCount() === 0);
      });
    });
  }

  // =========================
  // Reset form
  // =========================
  function resetForm() {
    if (!form) return;
    form.reset();

    const idEl = document.getElementById('fieldId');
    const titleEl = document.getElementById('fieldTitle');
    const linkUrlEl = document.getElementById('fieldLinkUrl');
    const linkNewtabEl = document.getElementById('fieldLinkNewtab');
    const activeEl = document.getElementById('fieldActive');
    const desktopPath = document.getElementById('fieldDesktopPath');
    const mobilePath = document.getElementById('fieldMobilePath');
    const modalTitleEl = document.getElementById('modalTitle');

    if (idEl) idEl.value = '';
    if (titleEl) titleEl.value = '';
    if (linkUrlEl) linkUrlEl.value = '';
    if (linkNewtabEl) linkNewtabEl.checked = true;
    if (activeEl) activeEl.checked = true;
    if (desktopPath) desktopPath.value = '';
    if (mobilePath) mobilePath.value = '';
    if (modalTitleEl) modalTitleEl.textContent = 'Add Banner';

    // default priority = Medium
    const prM = document.getElementById('priorityMedium');
    const prH = document.getElementById('priorityHigh');
    const prL = document.getElementById('priorityLow');
    if (prM && prH && prL) { prM.checked = true; prH.checked = false; prL.checked = false; }

    document.querySelectorAll('input[name="pages[]"]').forEach(cb => cb.checked = false);
    showPagesError(false);

    // default mode = path
    const imgModePath = document.getElementById('imageModePath');
    const imgModeUpload = document.getElementById('imageModeUpload');
    if (imgModePath && imgModeUpload) {
      imgModePath.checked = true;
      imgModeUpload.checked = false;
    }

    resetUploadPreview();
    setImageInputsState();

    if (fpStart) fpStart.clear();
    if (fpEnd) fpEnd.clear();

    isSubmitting = false;
    if (btnSave) btnSave.disabled = false;
  }

  // =========================
  // Submit validation
  // =========================
  if (form) {
    form.addEventListener('submit', function (e) {
      // title
      const title = (document.getElementById('fieldTitle')?.value || '').trim();
      if (!title) {
        e.preventDefault();
        alert('กรุณากรอกชื่อ Banner ให้ครบ');
        return;
      }

      // link_url
      const linkUrl = (document.getElementById('fieldLinkUrl')?.value || '').trim();
      if (!linkUrl) {
        e.preventDefault();
        alert('กรุณากรอกลิงก์ปลายทางให้ครบ');
        return;
      }

      // schedule (optional)
      const startAt = (document.getElementById('fieldStartAt')?.value || '').trim();
      const endAt = (document.getElementById('fieldEndAt')?.value || '').trim();

      // end only not allowed
      if (endAt && !startAt) {
        e.preventDefault();
        alert('หากกำหนด End ต้องกำหนด Start ด้วย');
        return;
      }

      // both present: start must be < end
      if (startAt && endAt) {
        const s = new Date(startAt.replace(' ', 'T'));
        const en = new Date(endAt.replace(' ', 'T'));
        if (!isNaN(s) && !isNaN(en) && s >= en) {
          e.preventDefault();
          alert('Start ต้องน้อยกว่า End');
          return;
        }
      }

      // pages >=1
      const pagesCount = getPagesCheckedCount();
      if (pagesCount === 0) {
        e.preventDefault();
        showPagesError(true);
        const pagesGroup = document.getElementById('pagesGroup');
        if (pagesGroup) pagesGroup.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      } else {
        showPagesError(false);
      }

      // image mode
      const modeUpload = document.getElementById('imageModeUpload')?.checked;

      const desktopPath = (document.getElementById('fieldDesktopPath')?.value || '').trim();
      const mobilePath = (document.getElementById('fieldMobilePath')?.value || '').trim();

      const desktopFile = document.getElementById('fieldDesktopFile')?.files?.[0];
      const mobileFile = document.getElementById('fieldMobileFile')?.files?.[0];

      if (modeUpload) {
        if (!desktopFile || !mobileFile) {
          e.preventDefault();
          alert('กรุณาอัปโหลดรูปให้ครบ: Desktop 1600x500 และ Mobile 1040x1040 หรือ 786x432');
          return;
        }
      } else {
        if (!desktopPath || !mobilePath) {
          e.preventDefault();
          alert('กรุณาระบุ path รูป Desktop และ Mobile ให้ครบ');
          return;
        }
      }

      if (isSubmitting) {
        e.preventDefault();
        return;
      }
      isSubmitting = true;
      if (btnSave) btnSave.disabled = true;
    });
  }

  // =========================
  // Add button
  // =========================
  if (btnAdd && modal) {
    btnAdd.addEventListener('click', function () {
      resetForm();
      modal.show();
    });
  }

  // =========================
  // Image mode toggle (radio)
  // =========================
  const imgModePath = document.getElementById('imageModePath');
  const imgModeUpload = document.getElementById('imageModeUpload');
  if (imgModePath && imgModeUpload) {
    imgModePath.addEventListener('change', setImageInputsState);
    imgModeUpload.addEventListener('change', setImageInputsState);
  }

  // =========================
  // Edit button
  // =========================
  document.querySelectorAll('.btnEdit').forEach(btn => {
    btn.addEventListener('click', function () {
      if (!modal) return;
      resetForm();

      const data = this.getAttribute('data-banner');
      if (!data) return;
      const banner = JSON.parse(data);

      const idEl = document.getElementById('fieldId');
      const titleEl = document.getElementById('fieldTitle');
      const linkUrlEl = document.getElementById('fieldLinkUrl');
      const activeEl = document.getElementById('fieldActive');
      const linkNewtabEl = document.getElementById('fieldLinkNewtab');
      const desktopPath = document.getElementById('fieldDesktopPath');
      const mobilePath = document.getElementById('fieldMobilePath');
      const modalTitleEl = document.getElementById('modalTitle');

      if (idEl) idEl.value = banner.id || '';
      if (modalTitleEl) modalTitleEl.textContent = 'Edit Banner';
      if (titleEl) titleEl.value = banner.title || '';
      if (linkUrlEl) linkUrlEl.value = banner.link_url || '';
      if (activeEl) activeEl.checked = !!banner.is_active;
      if (linkNewtabEl) linkNewtabEl.checked = (banner.link_target === '_blank');
      if (desktopPath) desktopPath.value = banner.desktop_img || '';
      if (mobilePath) mobilePath.value = banner.mobile_img || '';

      // priority
      const pr = (banner.priority || 'medium').toString().toLowerCase();
      const prH = document.getElementById('priorityHigh');
      const prM = document.getElementById('priorityMedium');
      const prL = document.getElementById('priorityLow');
      if (prH && prM && prL) {
        prH.checked = (pr === 'high');
        prM.checked = (pr === 'medium');
        prL.checked = (pr === 'low');
      }

      if (Array.isArray(banner.pages)) {
        banner.pages.forEach(p => {
          const cb = document.getElementById('page_' + p);
          if (cb) cb.checked = true;
        });
      }
      showPagesError(getPagesCheckedCount() === 0);

      if (fpStart && banner.start_at) fpStart.setDate(banner.start_at, false, 'Y-m-d H:i');
      if (fpEnd && banner.end_at) fpEnd.setDate(banner.end_at, false, 'Y-m-d H:i');

      // default = path mode (ให้แก้ path ง่าย)
      if (imgModePath && imgModeUpload) {
        imgModePath.checked = true;
        imgModeUpload.checked = false;
      }
      resetUploadPreview();
      setImageInputsState();

      modal.show();
    });
  });

  // =========================
  // Preview modal (Desktop/Mobile)
  // =========================
  const previewModalEl = document.getElementById('previewModal');
  const previewTitleEl = document.getElementById('previewTitle');
  const previewApprovalEl = document.getElementById('previewApproval');
  const previewImgWrapEl = document.getElementById('previewImgWrap');
  const previewImgEl = document.getElementById('previewImg');
  const previewLinkEl = document.getElementById('previewLink');
  const previewTargetEl = document.getElementById('previewTarget');
  const previewPagesEl = document.getElementById('previewPages');
  const previewRangeEl = document.getElementById('previewRange');
  const previewActiveEl = document.getElementById('previewActive');
  const previewPriorityEl = document.getElementById('previewPriority');
  const previewCreatedEl = document.getElementById('previewCreated');
  const btnPrevDesktop = document.getElementById('btnPrevDesktop');
  const btnPrevMobile = document.getElementById('btnPrevMobile');
  let previewModal = null;
  let currentPreviewBanner = null;

  function setPreviewMode(mode) {
    if (!btnPrevDesktop || !btnPrevMobile || !previewImgWrapEl) return;
    btnPrevDesktop.classList.toggle('active', mode === 'desktop');
    btnPrevMobile.classList.toggle('active', mode === 'mobile');
    previewImgWrapEl.classList.toggle('mode-mobile', mode === 'mobile');

    if (!currentPreviewBanner) return;
    const src = mode === 'mobile' ? (currentPreviewBanner.mobile_img || '') : (currentPreviewBanner.desktop_img || '');
    if (previewImgEl) previewImgEl.src = src ? src : '';
  }

  if (btnPrevDesktop) btnPrevDesktop.addEventListener('click', () => setPreviewMode('desktop'));
  if (btnPrevMobile) btnPrevMobile.addEventListener('click', () => setPreviewMode('mobile'));

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btnPreview');
    if (!btn) return;

    try {
      const banner = JSON.parse(btn.getAttribute('data-banner') || '{}');
      currentPreviewBanner = banner;

      // title + approval
      if (previewTitleEl) previewTitleEl.textContent = banner.title || '-';
      if (previewApprovalEl) {
        previewApprovalEl.textContent = btn.getAttribute('data-approval') || '-';
        previewApprovalEl.className = 'status-pill ' + (btn.getAttribute('data-approval-class') || 'success');
      }

      // image urls should already be absolute via asset_url on the list thumbnail, but banner values may be relative
      // In this UI pack, we store relative paths (e.g., upload/...) and render with asset_url. For preview, use the same strategy:
      function toAssetUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        if (path.startsWith('/')) return path;
        return path;
      }
      banner.desktop_img = toAssetUrl(banner.desktop_img);
      banner.mobile_img = toAssetUrl(banner.mobile_img);

      // Link
      const link = banner.link_url || '#';
      if (previewLinkEl) {
        previewLinkEl.textContent = link;
        previewLinkEl.href = link;
      }

      if (previewTargetEl) previewTargetEl.textContent = btn.getAttribute('data-target') || '-';
      if (previewPagesEl) previewPagesEl.textContent = btn.getAttribute('data-pages') || '-';
      if (previewRangeEl) previewRangeEl.textContent = btn.getAttribute('data-range') || '-';
      if (previewActiveEl) previewActiveEl.textContent = btn.getAttribute('data-active') || '-';
      if (previewPriorityEl) previewPriorityEl.textContent = btn.getAttribute('data-priority') || '-';
      if (previewCreatedEl) previewCreatedEl.textContent = btn.getAttribute('data-created') || '-';

      // default mode = desktop
      setPreviewMode('desktop');

      if (previewModalEl) {
        if (!previewModal) previewModal = new bootstrap.Modal(previewModalEl);
        previewModal.show();
      }
    } catch (err) {
      console.error('[Preview] invalid banner json', err);
      alert('ไม่สามารถเปิดตัวอย่างได้');
    }
  });

  // =========================
  // Drag & Drop reorder (Desktop + Mobile)
  // =========================
  if (tbody && ACTIVE_TAB !== 'all' && tbody.dataset.draggable === '1') {
    function sendReorderToServer() {
      const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
      const orders = rows.map((row, idx) => ({
        id: row.getAttribute('data-id'),
        order: idx + 1
      }));

      fetch('index.php?action=reorder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ page: ACTIVE_TAB, orders })
      })
        .then(r => r.json())
        .then(res => console.log('[Reorder] result', res))
        .catch(err => console.error('[Reorder] error', err));
    }

    let dragSrcRow = null;

    // Drag-start policy:
    // - Allow dragging from anywhere on the row EXCEPT interactive controls
    // - Still supports dragging from the grip icon if user prefers
    function isInteractiveTarget(target) {
      if (!target) return false;
      // Allow grip explicitly
      if (target.closest('.drag-handle')) return false;

      // Block common interactive elements to prevent accidental drag
      return !!target.closest('a, button, input, select, textarea, .dropdown, .dropdown-menu, .btn, .form-switch, .toggleActive');
    }

    tbody.addEventListener('dragstart', function (e) {
      const row = e.target.closest('tr[data-id]');
      if (!row) return;

      if (isInteractiveTarget(e.target)) {
        e.preventDefault();
        return;
      }

      dragSrcRow = row;
      row.classList.add('dragging');

      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.getAttribute('data-id') || '');
      }
    });

    function getRowFromPoint(clientX, clientY) {
      const el = document.elementFromPoint(clientX, clientY);
      return el ? el.closest('tr[data-id]') : null;
    }

    let lastOverRow = null;

    tbody.addEventListener('dragover', function (e) {
      if (!dragSrcRow) return;
      e.preventDefault();

      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

      const row = getRowFromPoint(e.clientX, e.clientY);

      // visual hover target
      if (lastOverRow && lastOverRow !== row) lastOverRow.classList.remove('drop-target');
      if (row && row !== dragSrcRow) {
        row.classList.add('drop-target');
        lastOverRow = row;
      }

      // If not over a row (e.g., above first row / below last row), allow insert to top/bottom
      const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
      if (!row) {
        if (rows.length === 0) return;
        const first = rows[0];
        const last = rows[rows.length - 1];
        const firstRect = first.getBoundingClientRect();
        const lastRect = last.getBoundingClientRect();
        if (e.clientY < firstRect.top) {
          tbody.insertBefore(dragSrcRow, first);
        } else if (e.clientY > lastRect.bottom) {
          tbody.appendChild(dragSrcRow);
        }
        return;
      }

      if (row === dragSrcRow) return;

      const rect = row.getBoundingClientRect();
      const offset = (e.clientY - rect.top) / (rect.height || 1);

      // Use nextElementSibling to avoid whitespace text nodes in tbody
      if (offset > 0.5) {
        const after = row.nextElementSibling;
        if (after !== dragSrcRow) tbody.insertBefore(dragSrcRow, after);
      } else {
        if (row !== dragSrcRow.nextElementSibling) tbody.insertBefore(dragSrcRow, row);
      }
    });

    tbody.addEventListener('drop', function (e) {
      if (!dragSrcRow) return;
      e.preventDefault();

      const droppedRow = dragSrcRow;
      if (lastOverRow) lastOverRow.classList.remove('drop-target');
      lastOverRow = null;
      droppedRow.classList.remove('dragging');
      dragSrcRow = null;

      droppedRow.classList.add('drop-highlight');
      setTimeout(() => droppedRow.classList.remove('drop-highlight'), 800);

      sendReorderToServer();
    });

    tbody.addEventListener('dragend', function () {
      if (dragSrcRow) {
        dragSrcRow.classList.remove('dragging');
        dragSrcRow = null;
      }
      if (lastOverRow) lastOverRow.classList.remove('drop-target');
      lastOverRow = null;
    });

    // touch
    let touchDraggingRow = null;

    function handleTouchStart(e) {
      const row = e.target.closest('tr[data-id]');
      if (!row) return;
      // For touch, require starting from the grip to avoid accidental reorder while scrolling
      const handle = e.target.closest('.drag-handle');
      if (!handle) return;
      touchDraggingRow = row;
      row.classList.add('dragging');
    }

    function handleTouchMove(e) {
      if (!touchDraggingRow || !e.touches || e.touches.length === 0) return;

      const touch = e.touches[0];
      const el = document.elementFromPoint(touch.clientX, touch.clientY);
      if (!el) return;

      const overRow = el.closest('tr[data-id]');

      // Allow moving to very top/bottom even if finger is not directly over a row
      const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
      if (!overRow) {
        if (rows.length === 0) return;
        const first = rows[0];
        const last = rows[rows.length - 1];
        const firstRect = first.getBoundingClientRect();
        const lastRect = last.getBoundingClientRect();
        if (touch.clientY < firstRect.top) {
          tbody.insertBefore(touchDraggingRow, first);
        } else if (touch.clientY > lastRect.bottom) {
          tbody.appendChild(touchDraggingRow);
        }
        e.preventDefault();
        return;
      }

      if (overRow === touchDraggingRow) return;
      if (overRow.parentNode !== tbody) return;

      const rect = overRow.getBoundingClientRect();
      const offset = (touch.clientY - rect.top) / (rect.height || 1);

      if (offset > 0.5) {
        const after = overRow.nextElementSibling;
        if (after !== touchDraggingRow) tbody.insertBefore(touchDraggingRow, after);
      } else {
        if (overRow !== touchDraggingRow.nextElementSibling) tbody.insertBefore(touchDraggingRow, overRow);
      }

      e.preventDefault();
    }

    function handleTouchEnd() {
      if (!touchDraggingRow) return;

      const droppedRow = touchDraggingRow;
      droppedRow.classList.remove('dragging');
      touchDraggingRow = null;

      droppedRow.classList.add('drop-highlight');
      setTimeout(() => droppedRow.classList.remove('drop-highlight'), 800);

      sendReorderToServer();
    }

    tbody.addEventListener('touchstart', handleTouchStart, { passive: true });
    tbody.addEventListener('touchmove', handleTouchMove, { passive: false });
    tbody.addEventListener('touchend', handleTouchEnd, { passive: true });
    tbody.addEventListener('touchcancel', handleTouchEnd, { passive: true });
  }

  // =========================
  // Flatpickr + auto-open modal
  // =========================
  document.addEventListener('DOMContentLoaded', function () {
    const startInput = document.getElementById('fieldStartAt');
    const endInput = document.getElementById('fieldEndAt');

    if (startInput) {
      fpStart = flatpickr(startInput, {
        enableTime: true,
        dateFormat: 'Y-m-d H:i',
        altInput: true,
        altFormat: 'd-m-Y H:i น.',
        time_24hr: true,
        minuteIncrement: 10
      });
    }

    if (endInput) {
      fpEnd = flatpickr(endInput, {
        enableTime: true,
        dateFormat: 'Y-m-d H:i',
        altInput: true,
        altFormat: 'd-m-Y H:i น.',
        time_24hr: true,
        minuteIncrement: 10
      });
    }

    // Quick actions: schedule helpers
    const btnClearSchedule = document.getElementById('btnClearSchedule');
    if (btnClearSchedule) {
      btnClearSchedule.addEventListener('click', () => {
        if (fpStart) fpStart.clear();
        if (fpEnd) fpEnd.clear();
      });
    }

    const btnNoEndSchedule = document.getElementById('btnNoEndSchedule');
    if (btnNoEndSchedule) {
      btnNoEndSchedule.addEventListener('click', () => {
        if (fpEnd) fpEnd.clear();
      });
    }


// =========================
// Quick toggle: is_active (table switch)
// =========================
const appRoot = document.getElementById('app');
const FORM_TOKEN = appRoot?.dataset?.formToken || '';

function bindActiveToggles() {
  document.querySelectorAll('.toggleActive').forEach((sw) => {
    if (sw.dataset.bound === '1') return;
    sw.dataset.bound = '1';

    sw.addEventListener('change', async () => {
      const id = sw.dataset.id;
      const nextVal = !!sw.checked;
      try {
        const res = await fetch('index.php?action=toggle_active', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Form-Token': FORM_TOKEN
          },
          body: JSON.stringify({ id, is_active: nextVal ? 1 : 0 })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          sw.checked = !nextVal; // revert
          alert(data.error || 'ไม่สามารถอัปเดตสถานะได้');
        } else {
          // ถ้า require approval เปิดอยู่ สถานะอาจกลับเป็น pending (ผู้ใช้จะเห็นเมื่อ refresh)
        }
      } catch (e) {
        sw.checked = !nextVal; // revert
        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
      }
    });
  });
}

    bindPagesLiveValidation();
    bindActiveToggles();
    setImageInputsState();

    if (OPEN_MODAL_FLAG && modal) {
      modal.show();
      // ถ้าเปิด modal หลัง post แล้วอยู่ใน upload mode แต่มีไฟล์ใน input → รี-render preview
      const uploadChecked = document.getElementById('imageModeUpload')?.checked;
      if (uploadChecked && bannerFilesInput?.files?.length) {
        handleBannerFilesChange();
      }
    } else if (EDIT_ID) {
      const editBtn = document.querySelector('.btnEdit[data-id="' + EDIT_ID + '"]');
      if (editBtn) editBtn.click();
    }
  });
})();
