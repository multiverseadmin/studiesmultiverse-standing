/* Studies Multiverse: Standing Register search.
 *
 * The search index is fetched lazily, once, the first time someone actually
 * interacts with the box. Nobody pays for it on page load, and the server does
 * no query work at all, which is why this stays fast whether the record holds
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
  // position 0, every entry scoring 80. The register was both unsearchable and
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
        'the institution is not approved. It may be in a country whose register we do not yet cover, or ' +
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

/* The offer-letter code check.
 *
 * Separate from the name search on purpose. Name search answers "does this
 * institution exist on the register", which a forged letter passes: the
 * institution is usually real. This answers "do the codes printed on the
 * letter belong together", which is the part that does not survive checking.
 *
 * All of the judgement lives on the server, in the check endpoint. This only
 * puts the fields on the page and prints back what it is told, verbatim. It
 * decides nothing, and it never says an offer is fake. */
(function () {
  var forms = document.querySelectorAll('form.sm-verify');
  if (!forms.length) { return; }

  // The search box has its own esc() inside its own closure; this file keeps
  // the two widgets in separate scopes on purpose, so this one needs its own.
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function render(out, payload) {
    // A register we hold only as a change record cannot confirm a code, and
    // the API says so in `reason`. This ignored it and fell through to
    // "Enter at least one code above" - told to someone who had just entered
    // one. Saying nothing is the one thing a check like this must never do.
    if (payload && payload.checkable === false) {
      var why = '<p class="small">' + esc(payload.reason || 'We cannot confirm codes against this register.') + '</p>';
      if (payload.official_source) {
        why += '<p class="small"><a href="' + esc(payload.official_source) + '" rel="noopener">Check with the publisher</a>.</p>';
      }
      out.innerHTML = why;
      return;
    }
    var checks = (payload && payload.checks) || {};
    var names = Object.keys(checks);
    if (!names.length) {
      out.innerHTML = '<p class="small">Enter at least one code above.</p>';
      return;
    }
    var html = '<ul class="sm-verify-list">';
    for (var i = 0; i < names.length; i++) {
      var c = checks[names[i]] || {};
      // A check reports either found or match. Absent means not applicable.
      var settled = (c.found === true) || (c.match === true);
      var failed = (c.found === false) || (c.match === false);
      var mark = settled ? 'confirmed' : (failed ? 'not confirmed' : '');
      html += '<li><b>' + (mark || '') + '</b><small>' + (c.says || '') + '</small></li>';
    }
    html += '</ul>';
    out.innerHTML = html;
  }

  for (var f = 0; f < forms.length; f++) {
    (function (form) {
      var out = form.querySelector('.sm-verify-out');
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var params = new URLSearchParams();
        params.set('country', form.getAttribute('data-country') || '');
        var inputs = form.querySelectorAll('input[name]');
        var any = false;
        for (var i = 0; i < inputs.length; i++) {
          var v = (inputs[i].value || '').trim();
          if (v) { params.set(inputs[i].name, v); any = true; }
        }
        if (!any) {
          out.innerHTML = '<p class="small">Enter at least one code above.</p>';
          return;
        }
        out.innerHTML = '<p class="small">Checking the register.</p>';
        fetch('/wp-json/standing/v1/check?' + params.toString(), { credentials: 'omit' })
          .then(function (r) { return r.json(); })
          .then(function (j) { render(out, j); })
          .catch(function () {
            // Never guess on failure. Say the check did not run.
            out.innerHTML = '<p class="small">The check could not run just now. '
              + 'That is a fault at our end and says nothing about the institution.</p>';
          });
      });
    })(forms[f]);
  }
})();
