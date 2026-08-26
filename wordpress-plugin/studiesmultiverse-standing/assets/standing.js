/* Studies Multiverse — Standing Register search.
 *
 * The search index is fetched lazily, once, the first time someone actually
 * interacts with the box. Nobody pays for it on page load, and the server does
 * no query work at all — which is why this stays fast whether the record holds
 * four thousand institutions or forty thousand.
 *
 * No dependencies. No tracking. Progressive enhancement: without JavaScript the
 * form still submits and the server renders results.
 */
(function () {
  'use strict';

  var form = document.querySelector('.searchbox');
  if (!form) return;

  var input = form.querySelector('#sm-q');
  var out = form.querySelector('#sm-results');
  var scope = form.getAttribute('data-country') || '';
  var index = null;
  var loading = null;

  function load() {
    if (index) return Promise.resolve(index);
    if (loading) return loading;
    loading = fetch(window.SM_INDEX, { credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { index = (j && j.entries) || []; return index; })
      .catch(function () { index = []; return index; });
    return loading;
  }

  // Strip punctuation and symbols, not every non-Latin letter.
  //
  // This used to end with .replace(/[^a-z0-9 ]+/g, ' '), which erases Japanese
  // entirely. Two things went wrong at once: a Japanese institution's name
  // normalised to '' and scored 0, so it could never be found; and a Japanese
  // *query* also normalised to '', which indexOf('') treats as matching at
  // position 0 — every entry scoring 80. The register was both unsearchable and
  // confidently wrong, in the same line of code.
  var PUNCT = /[ -⁯⸀-⹿'!"#$%&()*+,\-./:;<=>?@[\]^_`{|}~]+/g;

  function norm(s) {
    return String(s || '')
      .toLowerCase()
      .normalize('NFKD').replace(/[̀-ͯ]/g, '')
      .replace(PUNCT, ' ')
      // Corporate noise, only meaningful for the Latin-script registers.
      .replace(/\b(the|of|and|pty|ltd|limited|inc|plc|university|college|school|institute)\b/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function score(entryName, q) {
    var n = norm(entryName);
    if (!n || !q) return 0;
    if (n === q) return 100;
    if (n.indexOf(q) === 0) return 80;
    if (n.indexOf(q) > -1) return 60;
    // every query word present somewhere
    var words = q.split(' ').filter(Boolean);
    var hit = words.filter(function (w) { return n.indexOf(w) > -1; }).length;
    if (words.length && hit === words.length) return 40;
    if (hit) return 10 + hit;
    return 0;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function render(results, q) {
    if (!q) { out.innerHTML = ''; return; }
    if (!results.length) {
      out.innerHTML = '<p class="small">Nothing on the registers we hold matches that. That does not mean ' +
        'the institution is not approved — it may be in a country whose register we do not yet cover, or ' +
        'listed under a different legal name. <a href="/standing/countries/">See which countries we cover</a>.</p>';
      return;
    }
    var html = '<ul>';
    results.forEach(function (r) {
      html += '<li><a href="/standing/' + esc(r.s) + '/' + esc(slugify(r.k)) + '/">' +
        '<span>' + esc(r.n) + (r.f ? '' : ' <span class="gone">no longer listed</span>') + '</span>' +
        '<span class="c">' + esc(r.c) + '</span></a></li>';
    });
    html += '</ul>';
    out.innerHTML = html;
  }

  function slugify(s) {
    return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function search() {
    var raw = input.value.trim();
    var q = norm(raw);
    if (q.length < 2) { out.innerHTML = ''; return; }

    load().then(function (entries) {
      var pool = scope ? entries.filter(function (e) { return e.s === scope; }) : entries;
      var scored = [];
      for (var i = 0; i < pool.length; i++) {
        var sc = score(pool[i].n, q);
        if (sc > 0) scored.push([sc, pool[i]]);
      }
      scored.sort(function (a, b) { return b[0] - a[0] || a[1].n.localeCompare(b[1].n); });
      render(scored.slice(0, 12).map(function (x) { return x[1]; }), raw);
    });
  }

  var t;
  function debounced() { clearTimeout(t); t = setTimeout(search, 120); }

  input.addEventListener('focus', load, { once: true });
  input.addEventListener('input', debounced);
  form.addEventListener('submit', function (e) {
    // Results are already on screen; no need to round-trip.
    if (index) { e.preventDefault(); search(); }
  });

  // Deep link: /standing/?q=...
  if (input.value.trim()) search();
})();
