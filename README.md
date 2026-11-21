# Job radar — free worldwide-remote jobs for Nigeria-based applicants (PHP/Laravel)

A Laravel app that pulls from public job APIs, keeps only roles a Nigeria-based
applicant can take, and writes the shortlist files to `out/`:

- `out/shortlist.md` — human-readable, ranked, grouped by role family
- `out/shortlist.csv` — one row per job, for spreadsheets
- `out/all.json` — every normalized job, for re-filtering later

No API keys, no paid services, no LLM calls.

**Live view (same data as the repo):** https://aphoe4x.github.io/job-radar/

## Usage

```
php artisan radar                       # defaults: last 30 days, min score 35
php artisan radar --days 14 --score 40
php artisan serve
# then open http://localhost:8000/jobs  — the shortlist page
```

Requirements: PHP 8.2+, Composer, Node not required.

## Sources (all free, public)

- Remotive API
- RemoteOK API
- Jobicy API
- Working Nomads
- Himalayas
- We Work Remotely (RSS)
- HN "Who is hiring?" (via Algolia)
- WordPress Jobs (wordpress.net)

## Roles and levels

Target families: **AI/ML** (ai, ml, llm, machine learning, data scientist,
platform engineer, …), **full-stack**, **frontend** (react, vue, angular, …),
**backend** (node, python, PHP, Java/.NET, …) and **WordPress**.

Target levels: **intern / junior / mid** (and above), worldwide / Africa /
Emea-or-unclear.

## Refresh

Re-run `php artisan radar` to re-fetch. The GitHub Pages site
(`gh-pages` branch) renders `out/all.json`; to point it at new data, push the
fresh `out/` files.
