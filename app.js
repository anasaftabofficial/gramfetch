(function () {
  'use strict';

  var TYPES = {
    video:    { ph: 'Paste Instagram video link here',    sub: 'Save Instagram videos to your phone or computer as MP4 files.' },
    reels:    { ph: 'Paste Instagram Reels link here',    sub: 'Save Instagram Reels in their original vertical format.' },
    photo:    { ph: 'Paste Instagram photo link here',    sub: 'Save Instagram photos at the largest available size.' },
    carousel: { ph: 'Paste Instagram carousel link here', sub: 'Save every photo and video from a multi slide post.' },
    story:    { ph: 'Paste Instagram story link here',    sub: 'Save stories from public accounts before they expire.' },
    profile:  { ph: 'Paste profile link or @username',    sub: 'View and save full size Instagram profile pictures.' }
  };

  var currentType = 'video';
  var $ = function (id) { return document.getElementById(id); };
  var form = $('form'), input = $('url'), goBtn = $('goBtn'), errorBox = $('error');
  var results = $('results'), grid = $('grid');

  function setType(type) {
    if (!TYPES[type]) return;
    currentType = type;
    document.querySelectorAll('.tab').forEach(function (t) {
      var on = t.getAttribute('data-type') === type;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    input.placeholder = TYPES[type].ph;
    $('heroSub').textContent = TYPES[type].sub;
  }

  document.querySelectorAll('.tab').forEach(function (t) {
    t.addEventListener('click', function () { setType(t.getAttribute('data-type')); input.focus(); });
  });
  document.querySelectorAll('.nav a[data-tab]').forEach(function (a) {
    a.addEventListener('click', function () { setType(a.getAttribute('data-tab')); closeMenu(); });
  });

  // Auto pick the right tab from the pasted link
  function guessType(url) {
    if (/\/stories\//.test(url)) return 'story';
    if (/\/reels?\//.test(url)) return 'reels';
    return null;
  }

  // Mobile menu
  var menuBtn = document.querySelector('.menu-btn'), nav = $('nav');
  function closeMenu() { nav.classList.remove('open'); menuBtn.setAttribute('aria-expanded', 'false'); }
  menuBtn.addEventListener('click', function () {
    var open = nav.classList.toggle('open');
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // Paste button
  $('pasteBtn').addEventListener('click', function () {
    if (!navigator.clipboard || !navigator.clipboard.readText) { input.focus(); return; }
    navigator.clipboard.readText().then(function (text) {
      input.value = (text || '').trim();
      var g = guessType(input.value);
      if (g) setType(g);
      input.focus();
    }).catch(function () { input.focus(); });
  });

  function showError(msg) { errorBox.textContent = msg; errorBox.hidden = false; }
  function clearError() { errorBox.hidden = true; errorBox.textContent = ''; }
  function setLoading(on) { goBtn.disabled = on; goBtn.classList.toggle('loading', on); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearError();
    var url = input.value.trim();
    if (!url) { showError('Please paste an Instagram link first.'); return; }

    var g = guessType(url);
    if (g) setType(g);

    setLoading(true);
    var body = new URLSearchParams({ url: url, type: currentType });

    fetch('api.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error. Please try again.' }; }); })
      .then(function (data) {
        if (!data.ok) { showError(data.error || 'Could not fetch this link.'); results.hidden = true; return; }
        render(data);
      })
      .catch(function () { showError('Network error. Check your connection and try again.'); })
      .then(function () { setLoading(false); });
  });

  function proxyUrl(src, name, inline) {
    var q = 'proxy.php?url=' + encodeURIComponent(src);
    if (name) q += '&name=' + encodeURIComponent(name);
    if (inline) q += '&inline=1';
    return q;
  }

  var ICON = '<svg viewBox="0 0 24 24"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19h14"/></svg>';

  function render(data) {
    grid.innerHTML = '';
    $('resultsUser').textContent = data.username ? '@' + data.username : 'Instagram';
    $('resultsTitle').textContent = data.title || '';

    var base = (data.username || 'instagram').replace(/[^A-Za-z0-9_-]/g, '');
    var stamp = Date.now().toString(36);

    data.items.forEach(function (item, i) {
      var card = document.createElement('div');
      card.className = 'item';

      var thumb = document.createElement('div');
      thumb.className = 'thumb';
      if (item.thumb) {
        var img = document.createElement('img');
        img.loading = 'lazy';
        img.alt = item.type === 'video' ? 'Video preview' : 'Photo preview';
        img.src = proxyUrl(item.thumb, null, true);
        thumb.appendChild(img);
      }
      var badge = document.createElement('span');
      badge.className = 'badge';
      badge.textContent = item.type === 'video' ? 'Video' : 'Photo';
      thumb.appendChild(badge);

      var a = document.createElement('a');
      a.className = 'dl';
      a.href = proxyUrl(item.url, base + '_' + stamp + '_' + (i + 1));
      a.setAttribute('download', '');
      a.innerHTML = ICON + '<span>Download ' + (item.type === 'video' ? 'Video' : 'Photo') + '</span>';

      card.appendChild(thumb);
      card.appendChild(a);
      grid.appendChild(card);
    });

    results.hidden = false;
    results.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  $('resetBtn').addEventListener('click', function () {
    results.hidden = true;
    input.value = '';
    clearError();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    input.focus();
  });

  $('year').textContent = new Date().getFullYear();
})();
