#!/usr/bin/env node
/**
 * Free worldwide-remote job radar.
 *
 * Pulls from public, no-key job APIs, keeps only roles that a Nigeria-based
 * applicant can realistically take (worldwide / Africa / EMEA or unclear),
 * matches the target role families and levels, then writes:
 *
 *   out/shortlist.md   human-readable, ranked, grouped by role family
 *   out/shortlist.csv  one row per job for spreadsheets
 *   out/all.json       every normalized job, for re-filtering later
 *
 * No API keys, no paid services, no LLM calls.
 *
 * Usage:
 *   node radar.mjs
 *   node radar.mjs --days 30 --min-score 40
 */
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(HERE, "out");

const args = process.argv.slice(2);
const argOf = (name, fallback) => {
  const i = args.indexOf(`--${name}`);
  return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
};
const MAX_AGE_DAYS = Number(argOf("days", "30"));
const MIN_SCORE = Number(argOf("score", "35"));
const UA = "Mozilla/5.0 (compatible; job-radar/1.0)";

/* ------------------------------------------------------------------ */
/* Role and level classification                                      */
/* ------------------------------------------------------------------ */

/**
 * Ordered: the first match wins, so put the most specific family first.
 * "Full-Stack Developer" must land in fullstack, not frontend, and
 * "AI Platform Engineer" must land in ai, not backend.
 */
const ROLE_PATTERNS = [
  ["wordpress", /\bwordpress\b|\bwoocommerce\b|\bwp\s?(developer|engineer)\b/i],
  ["fullstack", /\bfull[\s-]?stack\b|\bfullstack\b/i],
  ["ai", /\b(ai|ml)\b|\bmachine learning\b|\bml\s?engineer\b|\bllm\b|\bgenai\b|\bgenerative ai\b|\bnlp\b|\bdeep learning\b|\bdata scientist\b|\bmlops\b/i],
  ["frontend", /\bfront[\s-]?end\b|\bfrontend\b|\breact(\.js)?\b|\bvue(\.js)?\b|\bangular\b|\bsvelte\b|\bnext\.js\b|\bui\s?(engineer|developer)\b/i],
  ["backend", /\bback[\s-]?end\b|\bbackend\b|\bnode(\.js)?\b|\bdjango\b|\blaravel\b|\brails\b|\bspring\b|\bgolang\b|\bjava\b|\bpython\b|\bphp\b|\bruby\b|\bc#\b|\.net\b|\bdata engineer\b|\bplatform engineer\b|\bdevops\b|\bsre\b|\bsite reliability\b|\binfrastructure\b|\bcloud engineer\b/i],
];

/** A real engineering/development title. Non-technical titles are dropped even if the body mentions "AI". */
const TECH_TITLE = /\b(engineer|developer|programmer|software|full[\s-]?stack|front[\s-]?end|back[\s-]?end|wordpress|react|node|python|django|laravel|php|rails|golang|rust|ml|machine learning|ai|llm|data engineer|platform|devops|sre|site reliability|cloud|mobile|android|ios)\b/i;

/**
 * Titles that mention tech but are not hands-on development work for a
 * frontend/backend/full-stack engineer: interviewers, raters, email
 * marketers, sales engineers, analysts, and so on.
 */
const EXCLUDE_TITLE = /\b(support|analyst|instructor|accountant|coordinator|rater|raters|reviewer|reviewers|tester|testers|estimator|controller|recruiter|sales|marketing|operations|manager|animator|designer|writer|transcri\w*|attorney|nurse|teacher|administrator|audit|trainer|interviewer|interviewers|specialist|analytics engineer|marketo|hubspot|solutions engineer|implementation engineer|forward deployed sales|editor|video|virtual assistant|assistant|customer|success|advocate|evangelist|sourcer|scout|researcher|distribution)\b/i;

/**
 * The RSS/API "region" field is often "Anywhere in the World" even when the
 * title itself locks the role to one country ("Remote @ Colombia",
 * "U.S. Based, Remote Contractor"). Trust the title when it names a country.
 */
const TITLE_REGION_LOCK = /\b(u\.s\.|usa|us based|us-based|based in the us|united states|canada|colombia|brazil|mexico|argentina|india|pakistan|philippines|nigeria-based|uk|united kingdom|europe|emea only|latam|asia|poland|germany|france|spain|portugal|ukraine|israel|australia)\b/i;

/** First matching family wins, most specific first. Title decides first, then tags; the body only breaks ties for a generic title. */
function roleFamily(title, tags, text) {
  for (const [family, re] of ROLE_PATTERNS) {
    if (re.test(title)) return family;
  }
  for (const [family, re] of ROLE_PATTERNS) {
    if (re.test(tags)) return family;
  }
  // Generic titles only ("Software Engineer"): let the body pick the family.
  // A title like "Distribution Engineer" is not specific enough to fall back on.
  if (/\b(software engineer|software developer|web developer|platform engineer|product engineer)\b/i.test(title)) {
    const body = text.slice(0, 1200);
    for (const [family, re] of ROLE_PATTERNS) {
      if (re.test(body)) return family;
    }
  }
  return null;
}

const SENIOR = /\bsenior\b|\bsr\.?\b|\blead\b|\bstaff\b|\bprincipal\b|\bhead of\b|\bdirector\b|\bmanager\b|\bvp\b|\bchief\b|\barchitect\b/i;
const INTERN = /\bintern(ship)?\b|\btrainee\b|\bgraduate\s?(program|scheme)?\b|\bworking student\b/i;
const JUNIOR = /\bjunior\b|\bjr\.?\b|\bentry[\s-]?level\b|\bassociate\b|\bnew grad\b|\bfresher\b/i;

function levelOf(title, text) {
  const hay = `${title} ${text.slice(0, 400)}`;
  if (INTERN.test(title)) return "intern";
  if (JUNIOR.test(title)) return "junior";
  if (SENIOR.test(title)) return "senior";
  if (INTERN.test(hay)) return "intern";
  if (JUNIOR.test(hay)) return "junior";
  return "mid"; // unlabelled roles are most often mid-level
}

/* ------------------------------------------------------------------ */
/* Region eligibility for a Nigeria-based applicant                   */
/* ------------------------------------------------------------------ */

const WORLDWIDE = /world[\s-]?wide|anywhere in the world|anywhere|global(ly)?|all countries|most countries|international|remote\s*\(\s*world/i;
const AFRICA = /\bafrica\b|\bnigeria\b|\bemea\b|\bsub[\s-]?saharan\b|\bafrican\b/i;
const BLOCKED_REGION = /\b(us|u\.s\.|usa|united states|any state|canada|uk|united kingdom|europe|eu|eu only|eea|australia|india|latam|latin america|americas|apac|asia|brazil|mexico|germany|france|spain|netherlands|poland|portugal|ireland|chile|argentina|colombia|peru|china|japan|singapore|pakistan|philippines|indonesia|vietnam|israel|turkey|egypt|morocco|ukraine|california|new york|nyc|texas|washington|seattle|san francisco|bay area|boston|chicago|austin|denver|atlanta|toronto|vancouver)\b/i;

/** A US state abbreviation after a comma is a location lock: "Austin, TX", "Gainesville, FL". */
const US_STATE = /,\s*(AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY)\b/;
const BLOCKED_TEXT = /(must|need to|required to|only)\s+(be\s+)?(authorized|eligible|legally)\s+to\s+work\s+in\s+the\s+(us|u\.s\.|usa|united states|canada|uk)|us\s+(citizens?|residents?)\s+only|must\s+(reside|live)\s+in\s+the\s+(us|u\.s\.|usa|united states|canada)|remote\s*[-–(]\s*(us|u\.s\.|usa|united states|canada)\s*\)?/i;

/**
 * "worldwide"  -> open to Nigeria
 * "africa"    -> open to Nigeria (or explicitly EMEA/Africa)
 * "check"     -> region unclear; worth reading before applying
 * "no"        -> explicitly locked to another region
 */
function regionOf(location, text, title = "") {
  const loc = location || "";
  const hay = `${loc} ${text.slice(0, 600)}`;
  if (AFRICA.test(loc) || /\bnigeria\b/i.test(hay)) return "africa";
  if (WORLDWIDE.test(loc)) return "worldwide";
  if (BLOCKED_REGION.test(loc)) return "no";
  // No location field at all (Hacker News posts): trust a country/city in the title.
  if (!loc && BLOCKED_REGION.test(title)) return "no";
  if (US_STATE.test(`${loc} ${title}`)) return "no";
  if (BLOCKED_TEXT.test(hay)) return "no";
  if (WORLDWIDE.test(hay)) return "worldwide";
  return "check";
}

const REGION_LABEL = {
  africa: "Africa / Nigeria eligible",
  worldwide: "Worldwide",
  check: "Check region",
  no: "Region locked",
};

/* ------------------------------------------------------------------ */
/* Sources                                                            */
/* ------------------------------------------------------------------ */

async function getJson(url, timeout = 25000) {
  const res = await fetch(url, { headers: { "User-Agent": UA, Accept: "application/json" }, signal: AbortSignal.timeout(timeout) });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

async function getText(url, timeout = 25000) {
  const res = await fetch(url, { headers: { "User-Agent": UA }, signal: AbortSignal.timeout(timeout) });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.text();
}

const strip = (html = "") =>
  html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&#\d+;/g, " ")
    .replace(/\s+/g, " ")
    .trim();

const toIso = (value) => {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
};

async function remotive() {
  const data = await getJson("https://remotive.com/api/remote-jobs?category=software-dev");
  return (data.jobs ?? []).map((j) => ({
    source: "Remotive",
    title: j.title,
    company: j.company_name,
    location: j.candidate_required_location,
    url: j.url,
    posted: toIso(j.publication_date),
    tags: (j.tags ?? []).join(", "),
    text: strip(j.description),
    salary: j.salary || "",
  }));
}

async function remoteok() {
  const data = await getJson("https://remoteok.com/api");
  return (Array.isArray(data) ? data : [])
    .filter((j) => j && j.position)
    .map((j) => ({
      source: "RemoteOK",
      title: j.position,
      company: j.company,
      location: j.location,
      url: j.url || j.apply_url,
      posted: toIso(j.date),
      tags: (j.tags ?? []).join(", "),
      text: strip(j.description),
      salary: j.salary_min ? `${j.salary_min}-${j.salary_max ?? ""}` : "",
    }));
}

async function jobicy() {
  const out = [];
  /** Two queries: everything open anywhere, plus the WordPress tag (thin but real). */
  const queries = ["?count=50&geo=anywhere", "?count=50&tag=wordpress"];
  for (const query of queries) {
    let cursor = "";
    for (let page = 0; page < 4; page++) {
      const url = `https://jobicy.com/api/v2/remote-jobs${query}${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ""}`;
      const data = await getJson(url);
      const jobs = data.jobs ?? [];
      for (const j of jobs) {
        out.push({
          source: "Jobicy",
          title: j.jobTitle,
          company: j.companyName,
          location: j.jobGeo,
          url: j.url,
          posted: toIso(j.pubDate),
          tags: `${j.jobIndustry ?? ""} ${j.jobLevel ?? ""}`.trim(),
          text: strip(j.jobDescription || j.jobExcerpt || ""),
          salary: "",
        });
      }
      if (!data.nextCursor || !jobs.length) break;
      cursor = data.nextCursor;
    }
  }
  return out;
}

async function workingNomads() {
  const data = await getJson("https://www.workingnomads.com/api/exposed_jobs/");
  return (Array.isArray(data) ? data : data.jobs ?? []).map((j) => ({
    source: "Working Nomads",
    title: j.title,
    company: j.company_name,
    location: j.location,
    url: j.url,
    posted: toIso(j.pub_date),
    tags: `${j.category_name ?? ""} ${Array.isArray(j.tags) ? j.tags.join(", ") : (j.tags ?? "")}`.trim(),
    text: strip(j.description),
    salary: "",
  }));
}

/** Himalayas paginates 20 at a time and states each job's allowed countries. */
async function himalayas() {
  const out = [];
  for (let offset = 0; offset < 120; offset += 20) {
    const data = await getJson(`https://himalayas.app/jobs/api?limit=20&offset=${offset}`);
    const jobs = data.jobs ?? [];
    for (const j of jobs) {
      const secs = Number(j.pubDate);
      const posted = Number.isFinite(secs) && secs > 0 ? new Date(secs * 1000) : null;
      out.push({
        source: "Himalayas",
        title: j.title,
        company: j.companyName,
        location: (j.locationRestrictions ?? []).join(", "),
        url: j.applicationLink || j.guid,
        posted: posted && !Number.isNaN(posted.getTime()) ? posted.toISOString() : null,
        tags: [...(j.parentCategories ?? []), ...(j.categories ?? []), j.seniority ?? ""].join(", "),
        text: strip(j.description || j.excerpt || ""),
        salary: j.minSalary ? `${j.minSalary}-${j.maxSalary ?? ""} ${j.salaryPeriod ?? ""}`.trim() : "",
      });
    }
    if (jobs.length < 20) break;
  }
  return out;
}

/**
 * Hacker News "Ask HN: Who is hiring?" — the startup-heavy thread posted on
 * the 1st of every month. Free, no key, via Algolia's public search API.
 * Each comment is usually "Company | Role | Location | REMOTE | ...".
 */
async function hackerNewsHiring() {
  const stories = await getJson(
    "https://hn.algolia.com/api/v1/search_by_date?query=%22Ask%20HN%3A%20Who%20is%20hiring%3F%22&tags=story&hitsPerPage=10",
  );
  const story = (stories.hits ?? []).find((h) => /^Ask HN: Who is hiring\?/i.test(h.title ?? ""));
  if (!story) return [];

  const res = await getJson(`https://hn.algolia.com/api/v1/search?tags=comment,story_${story.objectID}&hitsPerPage=1000`);
  const comments = (res.hits ?? []).filter(
    (h) =>
      h.comment_text &&
      // Only top-level comments: those are the job posts. Replies are candidates
      // asking questions or saying "I just applied", which are not jobs.
      String(h.parent_id ?? "") === String(story.objectID),
  );

  return comments
    .map((h) => {
      const firstBlock = strip((h.comment_text ?? "").split(/<p>/i)[0] || "") || strip(h.comment_text ?? "");
      // Job posts look like "Company | Role | Location | REMOTE | ..." and have at least three fields.
      const parts = firstBlock.split("|").map((p) => p.trim()).filter(Boolean);
      if (parts.length < 3) return null;
      if (/[?]$/.test(firstBlock)) return null;
      // Remote only: skip clearly on-site or hybrid posts that never mention remote.
      if (/\b(on-?site|in[-\s]?person|hybrid)\b/i.test(firstBlock) && !/remote/i.test(firstBlock)) return null;

      const region = firstBlock.match(/\b(remote|hybrid|on-?site)\b[^|,;.]{0,60}/i);
      return {
        source: "HN Who is hiring",
        title: parts.slice(0, 3).join(" | ").slice(0, 160),
        company: parts[0].slice(0, 80),
        location: region ? region[0] : "",
        url: `https://news.ycombinator.com/item?id=${h.objectID}`,
        posted: toIso(h.created_at),
        tags: "",
        text: strip(h.comment_text ?? ""),
        salary: firstBlock.match(/\$[\d,.]+k?[^|,;.]{0,30}/i)?.[0] ?? "",
      };
    })
    .filter(Boolean);
}

/** The official WordPress job board, an RSS feed of WordPress/PHP gigs. */
async function wordpressJobs() {
  const xml = await getText("https://jobs.wordpress.net/feed/?post_type=job_listing");
  const items = [...xml.matchAll(/<item>([\s\S]*?)<\/item>/gi)].map((m) => m[1]);
  return items.map((raw) => {
    const pick = (tag) => {
      const m = raw.match(new RegExp(`<${tag}[^>]*>([\\s\\S]*?)</${tag}>`, "i"));
      return m ? m[1].replace(/<!\[CDATA\[|\]\]>/g, "").trim() : "";
    };
    const text = strip(pick("description") + " " + pick("content:encoded"));
    const where = text.match(/\b(remote|anywhere(?: in the world)?|worldwide)\b[^.]{0,60}/i);
    return {
      source: "WordPress Jobs",
      title: pick("title"),
      company: "",
      location: where ? where[0] : "",
      url: pick("link"),
      posted: toIso(pick("pubDate")),
      tags: "wordpress php",
      text,
      salary: "",
    };
  });
}

const WWR_FEEDS = [
  "remote-programming-jobs",
  "remote-full-stack-programming-jobs",
  "remote-front-end-programming-jobs",
  "remote-back-end-programming-jobs",
];

async function weWorkRemotely() {
  const out = [];
  for (const feed of WWR_FEEDS) {
    try {
      const xml = await getText(`https://weworkremotely.com/categories/${feed}.rss`);
      const items = [...xml.matchAll(/<item>([\s\S]*?)<\/item>/gi)].map((m) => m[1]);
      for (const raw of items) {
        const pick = (tag) => {
          const m = raw.match(new RegExp(`<${tag}[^>]*>([\\s\\S]*?)</${tag}>`, "i"));
          return m ? m[1].replace(/<!\[CDATA\[|\]\]>/g, "").trim() : "";
        };
        const title = pick("title");
        const [company, ...rest] = title.split(":");
        out.push({
          source: "We Work Remotely",
          title: rest.length ? rest.join(":").trim() : title,
          company: rest.length ? company.trim() : "",
          location: pick("region"),
          url: pick("link"),
          posted: toIso(pick("pubDate")),
          tags: pick("category"),
          text: strip(pick("description")),
          salary: "",
        });
      }
    } catch (err) {
      console.warn(`  ! We Work Remotely ${feed}: ${err.message}`);
    }
  }
  return out;
}

/* ------------------------------------------------------------------ */
/* Filtering and scoring                                              */
/* ------------------------------------------------------------------ */

const normalizeKey = (job) =>
  `${job.title}|${job.company}`.toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();

const daysAgo = (iso) => (iso ? (Date.now() - new Date(iso).getTime()) / 86_400_000 : 999);

function scoreJob(job, family, level, region) {
  let score = 0;
  score += { africa: 55, worldwide: 45, check: 15, no: 0 }[region];
  score += { intern: 25, junior: 20, mid: 15, senior: 0 }[level];
  const age = daysAgo(job.posted);
  score += age <= 3 ? 15 : age <= 7 ? 12 : age <= 14 ? 8 : age <= 30 ? 4 : 0;
  if (family === "wordpress") score += 3;
  if (/nigeria/i.test(`${job.location} ${job.text.slice(0, 800)}`)) score += 10;
  return score;
}

function parseArgs() {
  return { maxAge: MAX_AGE_DAYS, minScore: MIN_SCORE };
}

const FAMILY_ORDER = ["ai", "fullstack", "frontend", "backend", "wordpress"];
const FAMILY_LABEL = {
  ai: "AI / ML engineering",
  fullstack: "Full-stack",
  frontend: "Frontend",
  backend: "Backend",
  wordpress: "WordPress",
};

function csvCell(value) {
  const s = String(value ?? "").replace(/\r?\n/g, " ").replace(/"/g, '""');
  return `"${s}"`;
}

/* ------------------------------------------------------------------ */
/* Main                                                               */
/* ------------------------------------------------------------------ */

const { maxAge, minScore } = parseArgs();

console.log("Fetching free remote job sources…");
const sourceFns = [
  ["Remotive", remotive],
  ["RemoteOK", remoteok],
  ["Jobicy", jobicy],
  ["Working Nomads", workingNomads],
  ["Himalayas", himalayas],
  ["We Work Remotely", weWorkRemotely],
  ["HN Who is hiring", hackerNewsHiring],
  ["WordPress Jobs", wordpressJobs],
];

const all = [];
for (const [name, fn] of sourceFns) {
  try {
    const jobs = await fn();
    for (const job of jobs) {
      if (!job.title || !job.url) continue;
      all.push({ ...job, source: job.source || name });
    }
    console.log(`  ok ${name}: ${jobs.length}`);
  } catch (err) {
    console.warn(`  ! ${name}: ${err.message}`);
  }
}

console.log(`Fetched ${all.length} raw jobs. Filtering…`);

const seen = new Set();
const kept = [];
for (const job of all) {
  const key = normalizeKey(job);
  if (seen.has(key)) continue;
  seen.add(key);

  const age = daysAgo(job.posted);
  if (age > maxAge) continue;

  if (!TECH_TITLE.test(job.title) || EXCLUDE_TITLE.test(job.title)) continue;

  const family = roleFamily(job.title, job.tags, job.text.slice(0, 1500));
  if (!family) continue;

  // A country named in the title beats a vague "Anywhere in the World" region field.
  if (TITLE_REGION_LOCK.test(job.title)) continue;

  const level = levelOf(job.title, job.text);
  if (level === "senior") continue;

  const region = regionOf(job.location, job.text, job.title);
  if (region === "no") continue;

  const score = scoreJob(job, family, level, region);
  if (score < minScore) continue;

  kept.push({ ...job, family, level, region, score, ageDays: Math.round(age) });
}

kept.sort((a, b) => b.score - a.score || a.ageDays - b.ageDays);

await mkdir(OUT, { recursive: true });
await writeFile(path.join(OUT, "all.json"), JSON.stringify(kept, null, 2), "utf8");

const csvHeader = ["score", "family", "level", "region", "title", "company", "location", "posted", "age_days", "source", "salary", "url"];
const csv = [csvHeader.join(",")];
for (const j of kept) {
  csv.push(
    [j.score, j.family, j.level, j.region, j.title, j.company, j.location, j.posted ?? "", j.ageDays, j.source, j.salary, j.url]
      .map(csvCell)
      .join(","),
  );
}
await writeFile(path.join(OUT, "shortlist.csv"), csv.join("\n"), "utf8");

const lines = [];
lines.push("# Worldwide remote roles — frontend, backend, full-stack, WordPress, AI");
lines.push("");
lines.push(`Generated ${new Date().toISOString().slice(0, 10)} from ${sourceFns.map(([name]) => name).join(", ")}.`);
lines.push(`${kept.length} roles kept: intern/junior/mid, worldwide/Africa/check-region, posted in the last ${maxAge} days.`);
lines.push("");
lines.push("Region key: **Africa** = explicitly open to Africa/Nigeria, **Worldwide** = anywhere, **Check** = region unclear — read before applying.");
lines.push("");

const counts = Object.fromEntries(FAMILY_ORDER.map((f) => [f, kept.filter((j) => j.family === f).length]));
lines.push(`| Family | Roles |`);
lines.push(`|---|---|`);
for (const f of FAMILY_ORDER) lines.push(`| ${FAMILY_LABEL[f]} | ${counts[f]} |`);
lines.push("");

for (const family of FAMILY_ORDER) {
  const jobs = kept.filter((j) => j.family === family);
  if (!jobs.length) continue;
  lines.push(`## ${FAMILY_LABEL[family]} (${jobs.length})`);
  lines.push("");
  for (const j of jobs.slice(0, 40)) {
    const posted = j.posted ? j.posted.slice(0, 10) : "date unknown";
    lines.push(`- **[${j.title}](${j.url})** — ${j.company || "unknown company"} · ${j.location || "location unknown"} · ${REGION_LABEL[j.region]} · ${j.level} · ${posted} · score ${j.score}`);
  }
  lines.push("");
}

lines.push("---");
lines.push("");
lines.push("Every row is also in `shortlist.csv` and `all.json`.");
await writeFile(path.join(OUT, "shortlist.md"), lines.join("\n"), "utf8");

console.log("");
console.log(`Kept ${kept.length} roles.`);
for (const f of FAMILY_ORDER) console.log(`  ${FAMILY_LABEL[f]}: ${counts[f]}`);
console.log("");
console.log(`Wrote ${path.join(OUT, "shortlist.md")}`);
console.log(`Wrote ${path.join(OUT, "shortlist.csv")}`);
console.log(`Wrote ${path.join(OUT, "all.json")}`);
