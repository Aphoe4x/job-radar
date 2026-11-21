@php
$jobs = json_decode(file_get_contents(base_path('out/all.json')), true) ?: [];
$keep = ['family', 'title', 'company', 'location', 'url', 'posted', 'source', 'level', 'region', 'score', 'ageDays'];
$page = array_map(fn ($j) => array_intersect_key($j ?? [], array_flip($keep)), $jobs);
$esc = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$famLabel = fn ($f) => match ($f) { 'ai' => 'AI / ML engineering', 'fullstack' => 'Full-stack', 'frontend' => 'Frontend', 'backend' => 'Backend', 'wordpress' => 'WordPress', default => $f };
$famCount = []; foreach ($jobs as $j) $famCount[$j['family'] ?? 'backend'] = ($famCount[$j['family'] ?? 'backend'] ?? 0) + 1;
$regCount = []; foreach ($jobs as $j) $regCount[$j['region'] ?? 'check'] = ($regCount[$j['region'] ?? 'check'] ?? 0) + 1;
$famOrder = ['ai', 'fullstack', 'frontend', 'backend', 'wordpress'];
$regLabel = fn ($r) => match ($r) { 'africa' => 'Africa / Nigeria eligible', 'worldwide' => 'Worldwide', 'check' => 'Check region', 'no' => 'Region locked', default => $r };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Job radar — worldwide remote jobs for Nigeria-based applicants</title>
  <meta name="description" content="Free worldwide-remote job radar for Nigeria-based applicants, built with PHP/Laravel. {{ count($jobs) }} active roles.">
  <style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: "Inter", "Segoe UI Variable", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #f4f5f7;
      color: #1a1f2e;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }
    header.hero {
      position: relative;
      overflow: hidden;
      padding: 3.2rem 1.25rem 2.4rem;
      text-align: center;
      background: linear-gradient(135deg, #1e3a8a, #0ea5e9 40%, #6d28d9 110%);
      color: #fff;
    }
    header.hero::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        radial-gradient(520px 260px at 90% -10%, #fff3 4%, transparent 60%),
        radial-gradient(420px 240px at 0% 110%, #bae6fd 3%, transparent 60%),
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 100'%3E%3Ccircle cx='50' cy='20' r='8' fill='%23fff3' fill-opacity='0.55'/%3E%3Ccircle cx='180' cy='70' r='5' fill='%23fff3' fill-opacity='0.5'/%3E%3Ccircle cx='320' cy='20' r='6' fill='%23fff3' fill-opacity='0.6'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-size: 100% auto;
      opacity: 0.9;
    }
    header.hero .chip-grid {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 0.45rem;
      margin-top: 0.9rem;
    }
    header.hero .chip {
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.26);
      color: #fff;
      backdrop-filter: blur(8px);
      border-radius: 999px;
      padding: 0.3rem 0.7rem;
      font-size: 0.8rem;
      font-weight: 600;
    }
    header.hero h1 {
      margin: 0;
      font-size: 2.9rem;
      font-weight: 800;
      letter-spacing: -0.04em;
      background: linear-gradient(0deg, #fff -10%, #dbeafe 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      line-height: 1.05;
    }
    header.hero .tagline {
      margin: 0.7rem auto 0;
      max-width: 640px;
      color: rgba(255, 255, 255, 0.88);
      font-size: 1.05rem;
    }
    header.hero .meta {
      margin-top: 0.75rem;
      font-size: 0.85rem;
      color: rgba(255, 255, 255, 0.7);
    }
    header.hero strong { color: #fff; }
    main { max-width: 1180px; margin: 0 auto; padding: 2.5rem 1.25rem 3.5rem; }
    section.card {
      background: #fff;
      border: 1px solid #e6eaef;
      border-radius: 18px;
      padding: 1.25rem 1.35rem;
      margin-bottom: 1.6rem;
      box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03), 0 8px 24px rgba(16, 24, 40, 0.03);
    }
    .stats-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 0.75rem;
    }
    .stat {
      background: #f6f8fa;
      border: 1px solid #eaeef2;
      border-radius: 12px;
      padding: 0.9rem 1rem;
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
    }
    .stat .v { font-size: 1.35rem; font-weight: 700; color: #1a1f2e; }
    .stat .k { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: #57606a; font-weight: 600; }
    .controls { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin-bottom: 0.75rem; }
    .search-wrap { position: relative; flex: 1 1 260px; }
    .search-wrap input {
      width: 100%;
      border: 1px solid #d0d7de;
      border-radius: 12px;
      padding: 0.5rem 0.75rem 0.5rem 2.6rem;
      font: inherit;
      color: #1a1f2e;
      background: #fff;
      box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
    }
    .search-wrap input::placeholder { color: #8b949e; }
    .search-wrap::before {
      content: "\1F50E";
      position: absolute;
      left: 0.75rem;
      top: 50%;
      transform: translateY(-50%);
      font-size: 0.95rem;
    }
    .search-wrap input:focus, select:focus { outline: 2px solid #1e6fb0; outline-offset: -1px; }
    select {
      font: inherit;
      padding: 0.5rem 0.75rem;
      border: 1px solid #d0d7de;
      border-radius: 12px;
      background: #fff;
      color: #1a1f2e;
      min-width: 210px;
    }
    table { border-collapse: collapse; width: 100%; font-size: 0.92rem; }
    th, td { border-bottom: 1px solid #edf0f4; padding: 0.6rem 0.65rem; text-align: left; vertical-align: top; }
    th {
      background: #f8f9fc;
      font-weight: 600;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      white-space: nowrap;
    }
    th.sortable { cursor: pointer; position: relative; user-select: none; }
    th.sortable::after {
      content: "\2191";
      position: absolute;
      right: -0.25rem;
      opacity: 0.3;
    }
    th.sortable[data-dir="asc"]::after, th.sortable[data-dir="desc"]::after { opacity: 1; color: #1e6fb0; }
    th.sortable[data-dir="desc"]::after { content: "\2193"; }
    a.role { color: #1e6fb0; font-weight: 600; text-decoration: none; }
    a.role:hover { text-decoration: underline; }
    tr:hover td { background: #f5f8fd; }
    tr.appearing { animation: fadeIn 0.35s ease both; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    td.num, th.num { text-align: right; }
    .chip {
      display: inline-block;
      padding: 0.16rem 0.6rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 700;
      border: 1px solid transparent;
      letter-spacing: 0.03em;
    }
    .chip-ai { background: #dbeafe; color: #1e40af; border-color: #bfd4fb; }
    .chip-fullstack { background: #fff7c2; color: #7a5b00; border-color: #f2d24d; }
    .chip-frontend { background: #f4ebff; color: #5b36b0; border-color: #d7c0fb; }
    .chip-backend { background: #fbeee0; color: #8a4510; border-color: #f2c587; }
    .chip-wordpress { background: #ede9fe; color: #5b35a5; border-color: #c3b3f2; }
    .chip-worldwide { background: #e0f4e0; color: #155a18; border-color: #8ed78d; }
    .chip-africa { background: #dbeafe; color: #1e40af; border-color: #bfd4fb; }
    .chip-check { background: #fffbe6; color: #7a5b00; border-color: #f2d24d; }
    .chip-no { background: #ffe4e4; color: #a50000; border-color: #ffb4b4; }
    .row-meta { color: #6b7280; font-size: 0.82rem; }
    .stats-line { color: #6b7280; font-size: 0.88rem; margin: 0.75rem 0 0; }
    footer { text-align: center; color: #6b7280; font-size: 0.85rem; padding: 2rem 0 1.5rem; }
    footer a { color: #1e6fb0; }
    @media (max-width: 720px) {
      header.hero h1 { font-size: 2.2rem; }
      .stats-row { grid-template-columns: repeat(2, 1fr); }
      table { font-size: 0.85rem; }
      th, td { padding: 0.5rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      * { animation: none !important; }
    }
  </style>
</head>
<body>
<header class="hero">
  <h1>Job radar</h1>
  <p class="tagline">Enticing worldwide-remote jobs for Nigeria-based applicants. Free, no keys, PHP/Laravel.</p>
  <div class="chip-grid">
    @foreach ($famOrder as $f)
      <span class="chip">{{ $famLabel($f) }}</span>
    @endforeach
    <span class="chip">{{ count($jobs) }} active roles</span>
  </div>
  <div class="meta">For a Nigeria-based applicant. Remote-only, frontend / backend / full-stack / WordPress / AI &amp; ML.</div>
</header>

<main>
  <section class="card stats-row">
    <div class="stat"><div class="v" id="total">{{ count($jobs) }}</div><div class="k">Roles</div></div>
    <div class="stat"><div class="v">{{ count($famOrder) }}</div><div class="k">Role families</div></div>
    <div class="stat"><div class="v" id="regions">{{ implode(', ', array_map($regLabel, array_keys($regCount))) }}</div><div class="k">Regions in the shortlist</div></div>
    <div class="stat"><div class="v">Intern / junior / mid +</div><div class="k">Target levels</div></div>
  </section>

  <section class="card">
    <div class="controls">
      <div class="search-wrap">
        <input id="search" type="search" placeholder="Search roles, companies, locations…" aria-label="Search jobs">
      </div>
      <select id="family" aria-label="Filter by family">
        <option value="">All families ({{ count($jobs) }})</option>
        @foreach ($famOrder as $f)
          <option value="{{ $f }}">{{ $famLabel($f) }} ({{ $famCount[$f] ?? 0 }})</option>
        @endforeach
      </select>
      <select id="region" aria-label="Filter by region">
        <option value="">All regions ({{ count($jobs) }})</option>
        @foreach (['worldwide', 'africa', 'check', 'no'] as $r)
          @if (($regCount[$r] ?? 0) > 0)
            <option value="{{ $r }}">{{ $regLabel($r) }} ({{ $regCount[$r] }})</option>
          @endif
        @endforeach
      </select>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="sortable" data-col="family">Family</th>
            <th class="sortable" data-col="title">Role</th>
            <th class="sortable" data-col="company">Company</th>
            <th class="sortable" data-col="region">Region</th>
            <th class="sortable" data-col="level">Level</th>
            <th class="num sortable" data-col="score">Score</th>
            <th class="sortable" data-col="posted">Posted</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody id="jobs-tbody"></tbody>
      </table>
    </div>
    <p class="stats-line">Showing <b id="shown">{{ count($jobs) }}</b> of <b>{{ count($jobs) }}</b> roles.</p>
  </section>
</main>

<footer>
  <a href="https://github.com/Aphoe4x/job-radar">View the job-radar repo on GitHub</a> ·
  <a href="https://github.com/Aphoe4x/job-radar/blob/main/app/Console/Commands/RadarCommand.php">RadarCommand (php artisan radar)</a>
</footer>
<script>
(function () {
  'use strict';
  const jobs = {!! json_encode($page, JSON_HEX_TAG) !!};
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const chipClass = { ai: 'chip-ai', fullstack: 'chip-fullstack', frontend: 'chip-frontend', backend: 'chip-backend', wordpress: 'chip-wordpress' };
  const regionClass = (r) => 'chip-' + r;

  const search = document.getElementById('search');
  const family = document.getElementById('family');
  const region = document.getElementById('region');
  const tbody = document.getElementById('jobs-tbody');
  const shown = document.getElementById('shown');

  let sortCol = 'score';
  let sortAsc = false;

  const filtered = () => {
    const q = search.value.trim().toLowerCase();
    const f = family.value;
    const r = region.value;
    return jobs.filter((j) =>
      (!q || (j.title + ' ' + j.company + ' ' + (j.location || '')).toLowerCase().includes(q)) &&
      (!f || j.family === f) &&
      (!r || j.region === r)
    );
  };

  const render = () => {
    let list = filtered().slice();
    list.sort((a, b) => {
      const A = Number(a[sortCol] ?? 0) || 0;
      const B = Number(b[sortCol] ?? 0) || 0;
      if (A === B) return 0;
      return sortAsc ? A - B : B - A;
    });
    tbody.innerHTML = list.map((j) => `
      <tr class="appearing">
        <td><span class="${esc(chipClass[j.family] || 'chip-default')}">${esc(j.family)}</span></td>
        <td><a class="role" href="${esc(j.url)}" target="_blank" rel="noopener">${esc(j.title)}</a></td>
        <td>${esc(j.company)}</td>
        <td><span class="${esc(regionClass(j.region))}">${esc(j.region)}</span></td>
        <td>${esc(j.level)}</td>
        <td class="num">${esc(j.score)}</td>
        <td>${esc(j.posted ? j.posted.slice(0, 10) : 'date unknown')}</td>
        <td>${esc(j.source)}</td>
      </tr>`).join('');
    shown.textContent = list.length;
    document.getElementById('total').textContent = jobs.length;
  };

  for (const th of document.querySelectorAll('thead th.sortable')) {
    th.addEventListener('click', () => {
      if (th.dataset.col === sortCol) sortAsc = !sortAsc;
      else { sortCol = th.dataset.col; sortAsc = false; }
      document.querySelectorAll('thead th.sortable').forEach((t) => { if (t !== th) delete t.dataset.dir; });
      th.dataset.dir = sortAsc ? 'asc' : 'desc';
      render();
    });
  }
  [search, family, region].forEach((el) => el.addEventListener('input', render));
  render();
})();
</script>
</body>
</html>
