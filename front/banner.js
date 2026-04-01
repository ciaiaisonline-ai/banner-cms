// banner.js

// ====== BASE_URL จากตำแหน่งไฟล์ JS ======
// เช่น script src: https://www.ais.th/banner-cms/front/banner.js
// จะได้ BASE_URL = https://www.ais.th/banner-cms
const BASE_URL = (function () {
  let script = document.currentScript;
  if (!script) {
    const scripts = document.getElementsByTagName('script');
    script = scripts[scripts.length - 1];
  }
  if (!script || !script.src) {
    return window.location.origin; // fallback
  }

  const url = new URL(script.src, window.location.href);
  let path = url.pathname; // เช่น /banner-cms/front/banner.js

  // ตัด /front/banner.js ออก เหลือ /banner-cms
  path = path.replace(/\/front\/[^\/]*$/, '');
  if (path.endsWith('/') && path !== '/') {
    path = path.slice(0, -1);
  }

  return url.origin + path; // https://www.ais.th/banner-cms
})();

// ====== CONFIG ======
const AUTO_PLAY_MS     = 5000;                   // auto slide ทุก 5 วิ
const BANNERS_JSON_URL = BASE_URL + '/banner-data/banners.json';
const CONFIG_JSON_URL  = BASE_URL + '/banner-data/config.json';

// ✅ อ่าน CURRENT_PAGE จาก HTML / global / body / fallback
function getCurrentPage() {
  const slider = document.getElementById('heroSlider');

  // 1) แนะนำ: <div id="heroSlider" data-page="store">
  const fromDiv = slider && slider.dataset && slider.dataset.page
    ? String(slider.dataset.page).trim()
    : '';
  if (fromDiv) return fromDiv;

  // 2) fallback: window.CURRENT_PAGE = 'store'
  const fromGlobal = (typeof window.CURRENT_PAGE === 'string')
    ? window.CURRENT_PAGE.trim()
    : '';
  if (fromGlobal) return fromGlobal;

  // 3) fallback: <body data-page="store">
  const fromBody = document.body && document.body.dataset && document.body.dataset.page
    ? String(document.body.dataset.page).trim()
    : '';
  if (fromBody) return fromBody;

  // 4) default
  return 'store';
}

const CURRENT_PAGE = getCurrentPage();

// ====== Helper ======
function resolveUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;   // full URL
  return BASE_URL.replace(/\/$/, '') + path;     // ต่อกับโดเมน+โฟลเดอร์ banner-cms
}

function isBannerActive(b, requireApproval) {
  if (!b || !b.is_active) return false;
  // approval rules
  const st = b.approval_status || 'approved';
  if (st === 'rejected') return false;
  if (requireApproval === true && st !== 'approved') return false;

  const now = new Date();
  let startOk = true;
  let endOk   = true;

  if (b.start_at) {
    const s = new Date(String(b.start_at).replace(' ', 'T'));
    if (!isNaN(s)) startOk = (now >= s);
  }
  if (b.end_at) {
    const e = new Date(String(b.end_at).replace(' ', 'T'));
    if (!isNaN(e)) endOk = (now <= e);
  }
  return startOk && endOk;
}

function pageMatch(b) {
  if (!b || !Array.isArray(b.pages)) return false;
  return b.pages.includes(CURRENT_PAGE);
}

// ====== GA4 Tracking Helper ======
function trackHeroBannerClick(banner) {
  if (!banner) return;

  const sectionName = (banner.ga4 && banner.ga4.section_name) || 'header_herobanner';
  const bannerName  = (banner.ga4 && banner.ga4.click_banner) || banner.title || '';
  const bannerId    = banner.id || '';

  const payload = {
    event: 'click_banner',
    section_name: sectionName,
    banner_name: bannerName,
    banner_id: bannerId,
    page_key: CURRENT_PAGE
  };

  // debug
  console.log('[GA4][HeroBanner] Click payload:', payload);

  // dataLayer
  if (Array.isArray(window.dataLayer)) {
    window.dataLayer.push(payload);
  }

  // gtag
  if (typeof window.gtag === 'function') {
    window.gtag('event', 'click_banner', {
      section_name: sectionName,
      banner_name: bannerName,
      banner_id: bannerId,
      page_key: CURRENT_PAGE
    });
  }
}

// ====== Build Slider ======
(async function initHeroSlider() {
  const container = document.getElementById('heroSlider');
  if (!container) {
    console.warn('[HeroBanner] #heroSlider not found in DOM');
    return;
  }

  let bannerData = [];
  try {
    const res = await fetch(BANNERS_JSON_URL, { cache: 'no-cache' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    bannerData = await res.json();
  } catch (e) {
    console.error('[HeroBanner] Load banners.json error:', e);
    container.innerHTML = '<div style="color:#fff;padding:20px;text-align:center;">No banner</div>';
    return;
  }

  if (!Array.isArray(bannerData) || !bannerData.length) {
    container.innerHTML = '<div style="color:#fff;padding:20px;text-align:center;">No banner</div>';
    return;
  }

  const requireApproval = !!window.__HERO_BANNER_REQUIRE_APPROVAL__;

  const banners = bannerData
    .filter(b => pageMatch(b) && isBannerActive(b, requireApproval))
    .sort((a, b) => {
      const ao = (a.page_orders && a.page_orders[CURRENT_PAGE] != null) ? Number(a.page_orders[CURRENT_PAGE]) : null;
      const bo = (b.page_orders && b.page_orders[CURRENT_PAGE] != null) ? Number(b.page_orders[CURRENT_PAGE]) : null;
      if (ao != null && bo != null && ao !== bo) return ao - bo;
      if (ao != null && bo == null) return -1;
      if (ao == null && bo != null) return 1;
      const at = a.created_at || '';
      const bt = b.created_at || '';
      return bt.localeCompare(at);
    });

  if (!banners.length) {
    container.innerHTML = '<div style="color:#fff;padding:20px;text-align:center;">No banner</div>';
    return;
  }

  let currentIndex = 0;
  let timer = null;

  // track สำหรับเลื่อน
  const track = document.createElement('div');
  track.className = 'hero-slider-inner';

  // create slides DOM
  banners.forEach((b, index) => {
    const slide = document.createElement('div');
    slide.className = 'hero-slide';
    slide.dataset.index = String(index);

    const a = document.createElement('a');
    a.href = b.link_url || '#';
    a.target = b.link_target || '_self';
    a.rel = (a.target === '_blank') ? 'noopener' : '';

    a.addEventListener('click', function () {
      trackHeroBannerClick(b);
    });

    const picture = document.createElement('picture');

    const sourceMb = document.createElement('source');
    sourceMb.media = '(max-width: 768px)';
    sourceMb.srcset = resolveUrl(b.mobile_img);
    picture.appendChild(sourceMb);

    const img = document.createElement('img');
    img.src = resolveUrl(b.desktop_img || b.mobile_img);
    img.alt = b.title || '';
    picture.appendChild(img);

    a.appendChild(picture);

    const cap = document.createElement('div');
    cap.className = 'hero-slide-caption';
    cap.textContent = b.title || '';
    a.appendChild(cap);

    slide.appendChild(a);
    track.appendChild(slide);
  });

  // arrows
  const btnPrev = document.createElement('button');
  btnPrev.className = 'hero-arrow prev';
  btnPrev.type = 'button';
  btnPrev.innerHTML = '&#10094;';

  const btnNext = document.createElement('button');
  btnNext.className = 'hero-arrow next';
  btnNext.type = 'button';
  btnNext.innerHTML = '&#10095;';

  // dots
  const dotsWrap = document.createElement('div');
  dotsWrap.className = 'hero-dots';
  banners.forEach((b, idx) => {
    const dot = document.createElement('button');
    dot.className = 'hero-dot' + (idx === 0 ? ' active' : '');
    dot.type = 'button';
    dot.dataset.index = String(idx);
    dotsWrap.appendChild(dot);
  });

  // mount
  container.appendChild(track);
  container.appendChild(btnPrev);
  container.appendChild(btnNext);
  container.appendChild(dotsWrap);

  const slides = Array.from(container.querySelectorAll('.hero-slide'));
  const dots   = Array.from(container.querySelectorAll('.hero-dot'));

  function updateDots() {
    dots.forEach((d, idx) => {
      d.classList.toggle('active', idx === currentIndex);
    });
  }

  function showSlide(i) {
    const total = slides.length;
    if (total === 0) return;
    currentIndex = (i + total) % total;
    track.style.transform = `translateX(-${currentIndex * 100}%)`;
    updateDots();
  }

  function nextSlide() { showSlide(currentIndex + 1); }
  function prevSlide() { showSlide(currentIndex - 1); }

  btnNext.addEventListener('click', () => {
    nextSlide();
    restartAuto();
  });

  btnPrev.addEventListener('click', () => {
    prevSlide();
    restartAuto();
  });

  dots.forEach(dot => {
    dot.addEventListener('click', () => {
      const idx = parseInt(dot.dataset.index, 10) || 0;
      showSlide(idx);
      restartAuto();
    });
  });

  function startAuto() {
    if (AUTO_PLAY_MS > 0) {
      timer = setInterval(nextSlide, AUTO_PLAY_MS);
    }
  }

  function restartAuto() {
    if (timer) clearInterval(timer);
    startAuto();
  }

  // ====== Touch / Swipe Support ======
  let startX = 0;
  let currentX = 0;
  let isDragging = false;

  function onTouchStart(e) {
    if (!e.touches || e.touches.length === 0) return;
    startX = e.touches[0].clientX;
    currentX = startX;
    isDragging = true;
    if (timer) clearInterval(timer);
  }

  function onTouchMove(e) {
    if (!isDragging || !e.touches || e.touches.length === 0) return;
    currentX = e.touches[0].clientX;
  }

  function onTouchEnd() {
    if (!isDragging) return;
    const diff = currentX - startX;
    const threshold = 50;

    if (Math.abs(diff) > threshold) {
      if (diff < 0) nextSlide();
      else prevSlide();
    } else {
      showSlide(currentIndex);
    }

    isDragging = false;
    startX = 0;
    currentX = 0;
    startAuto();
  }

  container.addEventListener('touchstart', onTouchStart, { passive: true });
  container.addEventListener('touchmove', onTouchMove, { passive: true });
  container.addEventListener('touchend', onTouchEnd);

  // init
  showSlide(0);
  startAuto();

  console.log('[HeroBanner] CURRENT_PAGE =', CURRENT_PAGE);
})();
