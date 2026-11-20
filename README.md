# Job radar — free worldwide-remote jobs for Nigeria-based applicants

A free, no-key job-search pipeline for **Nigeria-based applicants** who want
worldwide-remote roles: frontend, backend, full-stack, WordPress, and AI/ML.

**Live view (current shortlist):** https://aphoe4x.github.io/job-radar/

## What it does

`radar.mjs` pulls from public job sources, keeps only roles a Nigeria-based
applicant can realistically take (worldwide, Africa/Nigeria, or region
unclear), matches target role families and levels, and writes:

- `out/shortlist.md` — human-readable, ranked, grouped by role family
- `out/shortlist.csv` — one row per job, for spreadsheets
- `out/all.json` — every normalized job, for re-filtering later

No API keys, no paid services, no LLM calls — the radar fetches only public
job feeds.

## Usage

```
node radar.mjs                      # defaults: last 30 days, min score 35
node radar.mjs --days 14 --min-score 40
```

Requirements: Node.js 18+ (24 recommended). No other dependencies.

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

## Output

`out/shortlist.md` groups the roles by family, with a link per role
(title, company, region, level, score and posted date). `out/shortlist.csv`
has the same data as one row per job. `out/all.json` has every normalized
job so you can re-filter locally with other tools.

## Refresh

Re-run `node radar.mjs` and push the new `out/` files — the GitHub Pages
site renders `out/all.json`.
