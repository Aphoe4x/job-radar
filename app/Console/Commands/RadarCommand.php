<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Free worldwide-remote job radar.
 *
 * Pulls from public, no-key job APIs, keeps only roles a Nigeria-based
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
 *   php artisan radar
 *   php artisan radar --days 30 --score 40
 */
class RadarCommand extends Command
{
    private const UA = "Mozilla/5.0 (compatible; job-radar/1.0)";

    /**
     * Ordered: the first match wins, so put the most specific family first.
     * A generic title (e.g. "Software Engineer") falls back to scanning the body.
     */
    private const ROLE_PATTERNS = [
        ['wordpress', '/\bwordpress\b|\bwoocommerce\b|\bwp\s?(developer|engineer)\b/i'],
        ['fullstack', '/\bfull[\s-]?stack\b|\bfullstack\b/i'],
        ['ai', '/\b(ai|ml)\b|\bmachine learning\b|\bml\s?engineer\b|\bllm\b|\bgenai\b|\bgenerative ai\b|\bnlp\b|\bdeep learning\b|\bdata scientist\b|\bmlops\b/i'],
        ['frontend', '/\bfront[\s-]?end\b|\bfrontend\b|\breact(\.js)?\b|\bvue(\.js)?\b|\bangular\b|\bsvelte\b|\bnext\.js\b|\bui\s?(engineer|developer)\b/i'],
        ['backend', '/\bback[\s-]?end\b|\bbackend\b|\bnode(\.js)?\b|\bdjango\b|\blaravel\b|\brails\b|\bspring\b|\bgolang\b|\bjava\b|\bpython\b|\bphp\b|\bruby\b|\bc#\b|\.net\b|\bdata engineer\b|\bplatform engineer\b|\bdevops\b|\bsre\b|\bsite reliability\b|\binfrastructure\b|\bcloud engineer\b/i'],
    ];

    /** A real engineering/development title; non-technical titles are dropped even if the body mentions "AI". */
    private const TECH_TITLE = '/\b(engineer|developer|programmer|software|full[\s-]?stack|front[\s-]?end|back[\s-]?end|wordpress|react|node|python|django|laravel|php|rails|golang|rust|ml|machine learning|ai|llm|data engineer|platform|devops|sre|site reliability|cloud|mobile|android|ios)\b/i';

    /** Titles that mention tech but are not hands-on development work (sales, analysts, designers, …). */
    private const EXCLUDE_TITLE = '/\b(support|analyst|instructor|accountant|coordinator|rater|raters|reviewer|reviewers|tester|testers|estimator|controller|recruiter|sales|marketing|operations|manager|animator|designer|writer|transcri\w*|attorney|nurse|teacher|administrator|audit|trainer|interviewer|interviewers|specialist|analytics engineer|marketo|hubspot|solutions engineer|implementation engineer|forward deployed sales|editor|video|virtual assistant|assistant|customer|success|advocate|evangelist|sourcer|scout|researcher|distribution)\b/i';

    /** A country named in the title beats a vague "Anywhere in the World" region field. */
    private const TITLE_REGION_LOCK = '/\b(u\.s\.|usa|us based|us-based|based in the us|united states|canada|colombia|brazil|mexico|argentina|india|pakistan|philippines|nigeria-based|uk|united kingdom|europe|emea only|latam|asia|poland|germany|france|spain|portugal|ukraine|israel|australia)\b/i';

    private const SENIOR = '/\bsenior\b|\bsr\.?\b|\blead\b|\bstaff\b|\bprincipal\b|\bhead of\b|\bdirector\b|\bmanager\b|\bvp\b|\bchief\b|\barchitect\b/i';
    private const INTERN = '/\bintern(ship)?\b|\btrainee\b|\bgraduate\s?(program|scheme)?\b|\bworking student\b/i';
    private const JUNIOR = '/\bjunior\b|\bjr\.?\b|\bentry[\s-]?level\b|\bassociate\b|\bnew grad\b|\bfresher\b/i';

    private const WORLDWIDE = '/world[\s-]?wide|anywhere in the world|anywhere|global(ly)?|all countries|most countries|international|remote\s*\(\s*world/i';
    private const AFRICA = '/\bafrica\b|\bnigeria\b|\bemea\b|\bsub[\s-]?saharan\b|\bafrican\b/i';
    private const BLOCKED_REGION = '/\b(us|u\.s\.|usa|united states|any state|canada|uk|united kingdom|europe|eu|eu only|eea|australia|india|latam|latin america|americas|apac|asia|brazil|mexico|germany|france|spain|netherlands|poland|portugal|ireland|chile|argentina|colombia|peru|china|japan|singapore|pakistan|philippines|indonesia|vietnam|israel|turkey|egypt|morocco|ukraine|california|new york|nyc|texas|washington|seattle|san francisco|bay area|boston|chicago|austin|denver|atlanta|toronto|vancouver)\b/i';

    /** "must be authorized to work in the US", "remote (US)", … — bodies claiming US-only roles. */
    private const BLOCKED_TEXT = '/(must|need to|required to|only)\s+(be\s+)?(authorized|eligible|legally)\s+to\s+work\s+in\s+the\s+(us|u\.s\.|usa|united states|canada|uk)|us\s+(citizens?|residents?)\s+only|must\s+(reside|live)\s+in\s+the\s+(us|u\.s\.|usa|united states|canada)|remote\s*[-–(]\s*(us|u\.s\.|usa|united states|canada)\s*\)?/i';

    /** A US state abbreviation after a comma is a location lock: "Austin, TX". */
    private const US_STATE = '/,\s*(AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY)\b/';

    private const FAMILY_ORDER = ['ai', 'fullstack', 'frontend', 'backend', 'wordpress'];
    private const FAMILY_LABEL = [
        'ai' => 'AI / ML engineering',
        'fullstack' => 'Full-stack',
        'frontend' => 'Frontend',
        'backend' => 'Backend',
        'wordpress' => 'WordPress',
    ];
    private const REGION_LABEL = [
        'africa' => 'Africa / Nigeria eligible',
        'worldwide' => 'Worldwide',
        'check' => 'Check region',
        'no' => 'Region locked',
    ];

    protected $signature = 'radar {--days=30} {--score=35}';

    protected $description = 'Free worldwide-remote job radar for Nigeria-based applicants (Remotive, RemoteOK, Jobicy, Working Nomads, Himalayas, We Work Remotely, HN Who is hiring, WordPress Jobs)';

    public function handle(): int
    {
        $maxAge = (int)$this->option('days');
        $minScore = (int)$this->option('score');

        $this->line('Fetching free remote job sources…');
        $sourceFns = [
            ['Remotive', fn () => $this->remotive()],
            ['RemoteOK', fn () => $this->remoteok()],
            ['Jobicy', fn () => $this->jobicy()],
            ['Working Nomads', fn () => $this->workingNomads()],
            ['Himalayas', fn () => $this->himalayas()],
            ['We Work Remotely', fn () => $this->weWorkRemotely()],
            ['HN Who is hiring', fn () => $this->hackerNewsHiring()],
            ['WordPress Jobs', fn () => $this->wordpressJobs()],
        ];

        $all = [];
        foreach ($sourceFns as [$name, $fn]) {
            try {
                $jobs = $fn();
                foreach ($jobs as $job) {
                    if (!is_array($job) || empty($job['title']) || empty($job['url'])) continue;
                    if (empty($job['source'])) $job['source'] = $name;
                    $all[] = $job;
                }
                $this->line("  ok {$name}: " . count($jobs));
            } catch (\Throwable $err) {
                $this->warn("  ! {$name}: " . $err->getMessage() . ' @ ' . basename($err->getFile()) . ':' . $err->getLine());
            }
        }

        $this->line('Fetched ' . count($all) . ' raw jobs. Filtering…');

        $seen = [];
        $kept = [];
        foreach ($all as $job) {
            $key = $this->normalizeKey($job);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $age = $this->daysAgo($job['posted'] ?? null);
            if ($age > $maxAge) continue;

            $title = (string)($job['title'] ?? '');
            if (!preg_match(self::TECH_TITLE, $title) || preg_match(self::EXCLUDE_TITLE, $title)) continue;

            $family = $this->roleFamily($title, (string)($job['tags'] ?? ''), substr((string)($job['text'] ?? ''), 0, 1500));
            if (!$family) continue;
            if (preg_match(self::TITLE_REGION_LOCK, $title)) continue;

            $level = $this->levelOf($title, (string)($job['text'] ?? ''));
            if ($level === 'senior') continue;

            $region = $this->regionOf((string)($job['location'] ?? ''), (string)($job['text'] ?? ''), $title);
            if ($region === 'no') continue;

            $score = $this->scoreJob($job, $family, $level, $region);
            if ($score < $minScore) continue;

            $job['family'] = $family;
            $job['level'] = $level;
            $job['region'] = $region;
            $job['score'] = $score;
            $job['ageDays'] = (int)round($age);
            $kept[] = $job;
        }

        usort($kept, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['ageDays'] <=> $b['ageDays']);

        $counts = [];
        foreach (self::FAMILY_ORDER as $f) {
            $counts[$f] = count(array_filter($kept, fn ($j) => $j['family'] === $f));
        }

        $this->writeOutputs($kept, array_column($sourceFns, 0), $maxAge, $counts);

        $this->line('');
        $this->line('Kept ' . count($kept) . ' roles.');
        foreach (self::FAMILY_ORDER as $f) $this->line('  ' . self::FAMILY_LABEL[$f] . ': ' . $counts[$f]);
        $this->line('');
        $this->line('Wrote ' . base_path('out/shortlist.md'));
        $this->line('Wrote ' . base_path('out/shortlist.csv'));
        $this->line('Wrote ' . base_path('out/all.json'));
        return 0;
    }

    /* ------------------------------------------------------------------ */
    /* Sources                                                             */
    /* ------------------------------------------------------------------ */

    private function getJson(string $url): array
    {
        $res = Http::timeout(25)->withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/json'])->get($url);
        if (!$res->ok()) throw new \RuntimeException('HTTP ' . $res->status());
        $data = $res->json();
        return is_array($data) ? $data : [];
    }

    private function getText(string $url): string
    {
        $res = Http::timeout(25)->withHeaders(['User-Agent' => self::UA])->get($url);
        if (!$res->ok()) throw new \RuntimeException('HTTP ' . $res->status());
        $body = $res->body();
        return is_string($body) ? $body : '';
    }

    /** The official WordPress job board, an RSS feed of WordPress/PHP gigs. */
    private function wordpressJobs(): array
    {
        $xml = $this->getText('https://jobs.wordpress.net/feed/?post_type=job_listing');
        preg_match_all('/<item>[\s\S]*?<\/item>/i', $xml, $m);
        $items = [];
        foreach ($m[0] as $raw) {
            $pick = fn ($tag) => $this->pickTag($raw, $tag);
            $text = $this->strip($pick('description') . ' ' . $pick('content:encoded'));
            $where = [];
            preg_match('/\b(remote|anywhere(?: in the world)?|worldwide)\b[^.]{0,60}/i', $text, $where);
            $items[] = [
                'source' => 'WordPress Jobs',
                'title' => $pick('title'),
                'company' => '',
                'location' => $where ? $where[0] : '',
                'url' => $pick('link'),
                'posted' => $this->toIso($pick('pubDate')),
                'tags' => 'wordpress php',
                'text' => $text,
                'salary' => '',
            ];
        }
        return $items;
    }

    /** RSS item field extractor (shared by WordPress Jobs and We Work Remotely). */
    private function pickTag(string $raw, string $tag): string
    {
        if (!preg_match('/<' . $tag . '[^>]*>([\s\S]*?)<\/' . $tag . '>/i', $raw, $m)) return '';
        return trim(preg_replace('/<!\[CDATA\[|\]\]>/', '', $m[1] ?? ''));
    }

    /** We Work Remotely RSS feeds, one per role family. */
    private function weWorkRemotely(): array
    {
        $out = [];
        foreach (['remote-programming-jobs', 'remote-full-stack-programming-jobs', 'remote-front-end-programming-jobs', 'remote-back-end-programming-jobs'] as $feed) {
            try {
                $xml = $this->getText('https://weworkremotely.com/categories/' . $feed . '.rss');
                preg_match_all('/<item>[\s\S]*?<\/item>/i', $xml, $m);
                foreach ($m[0] as $raw) {
                    $pick = fn ($tag) => $this->pickTag($raw, $tag);
                    $title = $pick('title');
                    $parts = explode(':', $title);
                    $company = array_shift($parts);
                    $hasRest = count($parts) > 0;
                    $out[] = [
                        'source' => 'We Work Remotely',
                        'title' => $hasRest ? trim(implode(':', $parts)) : $title,
                        'company' => $hasRest ? trim((string)$company) : '',
                        'location' => $pick('region'),
                        'url' => $pick('link'),
                        'posted' => $this->toIso($pick('pubDate')),
                        'tags' => $pick('category'),
                        'text' => $this->strip($pick('description')),
                        'salary' => '',
                    ];
                }
            } catch (\Throwable $err) {
                $this->warn('  ! We Work Remotely ' . $feed . ': ' . $err->getMessage());
            }
        }
        return $out;
    }

    private function remotive(): array
    {
        $data = $this->getJson('https://remotive.com/api/remote-jobs?category=software-dev');
        return array_map(fn ($j) => [
            'source' => 'Remotive',
            'title' => $j['title'],
            'company' => $j['company_name'],
            'location' => $j['candidate_required_location'],
            'url' => $j['url'],
            'posted' => $this->toIso($j['publication_date'] ?? null),
            'tags' => implode(', ', $j['tags'] ?? []),
            'text' => $this->strip($j['description'] ?? ''),
            'salary' => $j['salary'] ?? '',
        ], array_is_list($data) ? $data : ($data['jobs'] ?? []));
    }

    private function remoteok(): array
    {
        $data = $this->getJson('https://remoteok.com/api');
        $out = [];
        foreach (array_is_list($data) ? $data : [] as $j) {
            if (!is_array($j) || empty($j['position'])) continue;
            $url = $j['url'] ?: ($j['apply_url'] ?? '');
            $salary = ($j['salary_min'] ?? null) ? trim((string)($j['salary_min'] . '-' . ($j['salary_max'] ?? ''))) : '';
            $out[] = [
                'source' => 'RemoteOK',
                'title' => $j['position'],
                'company' => $j['company'],
                'location' => $j['location'],
                'url' => $url,
                'posted' => $this->toIso($j['date'] ?? null),
                'tags' => implode(', ', $j['tags'] ?? []),
                'text' => $this->strip($j['description'] ?? ''),
                'salary' => $salary,
            ];
        }
        return $out;
    }

    /** Two queries: everything open anywhere, plus the WordPress tag (thin but real). */
    private function jobicy(): array
    {
        $out = [];
        foreach (['?count=50&geo=anywhere', '?count=50&tag=wordpress'] as $query) {
            $cursor = '';
            for ($page = 0; $page < 4; $page++) {
                $url = 'https://jobicy.com/api/v2/remote-jobs' . $query . ($cursor ? '&cursor=' . urlencode($cursor) : '');
                $data = $this->getJson($url);
                $jobs = $data['jobs'] ?? [];
                foreach ($jobs as $j) {
                    $text = $this->toStr($j['jobDescription'] ?? '');
                    if ($text === '') $text = $this->toStr($j['jobExcerpt'] ?? '');
                    $out[] = [
                        'source' => 'Jobicy',
                        'title' => $this->toStr($j['jobTitle'] ?? ''),
                        'company' => $this->toStr($j['companyName'] ?? ''),
                        'location' => $this->toStr($j['jobGeo'] ?? ''),
                        'url' => $this->toStr($j['url'] ?? ''),
                        'posted' => $this->toIso($j['pubDate'] ?? null),
                        'tags' => trim($this->toStr($j['jobIndustry'] ?? '') . ' ' . $this->toStr($j['jobLevel'] ?? '')),
                        'text' => $this->strip($text),
                        'salary' => '',
                    ];
                }
                if (empty($data['nextCursor']) || empty($jobs)) break;
                $cursor = $data['nextCursor'];
            }
        }
        return $out;
    }

    private function workingNomads(): array
    {
        $data = $this->getJson('https://www.workingnomads.com/api/exposed_jobs/');
        $jobs = array_is_list($data) ? $data : ($data['jobs'] ?? []);
        return array_map(fn ($j) => [
            'source' => 'Working Nomads',
            'title' => $this->toStr($j['title']),
            'company' => $this->toStr($j['company_name']),
            'location' => $this->toStr($j['location']),
            'url' => $this->toStr($j['url']),
            'posted' => $this->toIso($j['pub_date'] ?? null),
            'tags' => trim($this->toStr($j['category_name'] ?? '') . ' ' . (is_array($j['tags'] ?? null) ? implode(', ', $j['tags']) : $this->toStr($j['tags'] ?? ''))),
            'text' => $this->strip($this->toStr($j['description'] ?? '')),
            'salary' => '',
        ], $jobs);
    }

    /** Himalayas paginates 20 at a time and states each job's allowed countries. */
    private function himalayas(): array
    {
        $out = [];
        for ($offset = 0; $offset < 120; $offset += 20) {
            $data = $this->getJson('https://himalayas.app/jobs/api?limit=20&offset=' . $offset);
            $jobs = $data['jobs'] ?? [];
            foreach ($jobs as $j) {
                $secs = $j['pubDate'] ?? 0;
                $posted = (is_numeric($secs) && $secs > 0) ? gmdate('Y-m-d\TH:i:s.000\Z', (int)$secs) : null;
                $tags = array_map(fn ($t) => $this->toStr($t), array_merge((array)($j['parentCategories'] ?? []), (array)($j['categories'] ?? []), [$j['seniority'] ?? '']));
                $salary = ($j['minSalary'] ?? null)
                    ? trim($this->toStr($j['minSalary']) . '-' . $this->toStr($j['maxSalary'] ?? '') . ' ' . $this->toStr($j['salaryPeriod'] ?? ''))
                    : '';
                $out[] = [
                    'source' => 'Himalayas',
                    'title' => $this->toStr($j['title']),
                    'company' => $this->toStr($j['companyName']),
                    'location' => implode(', ', (array)($j['locationRestrictions'] ?? [])),
                    'url' => $this->toStr($j['applicationLink'] ?? '') ?: $this->toStr($j['guid'] ?? ''),
                    'posted' => $posted,
                    'tags' => implode(', ', $tags),
                    'text' => $this->strip(($j['description'] ?? '') ?: ($j['excerpt'] ?? '')),
                    'salary' => $salary,
                ];
            }
            if (count($jobs) < 20) break;
        }
        return $out;
    }

    /**
     * Hacker News "Ask HN: Who is hiring?" — the startup-heavy thread posted on
     * the 1st of every month. Free, no key, via Algolia's public search API.
     * Each comment is usually "Company | Role | Location | REMOTE | ...".
     */
    private function hackerNewsHiring(): array
    {
        $stories = $this->getJson('https://hn.algolia.com/api/v1/search_by_date?query=%22Ask%20HN%3A%20Who%20is%20hiring%3F%22&tags=story&hitsPerPage=10');
        $story = null;
        foreach ($stories['hits'] ?? [] as $h) {
            if (preg_match('/^Ask HN: Who is hiring\?/i', (string)($h['title'] ?? ''))) {
                $story = $h;
                break;
            }
        }
        if (!$story) return [];

        $res = $this->getJson('https://hn.algolia.com/api/v1/search?tags=comment,story_' . $story['objectID'] . '&hitsPerPage=1000');
        $out = [];
        foreach ($res['hits'] ?? [] as $h) {
            if (empty($h['comment_text']) || (string)($h['parent_id'] ?? '') !== (string)$story['objectID']) continue;
            $commentText = (string)$h['comment_text'];
            $firstBlock = $this->strip((preg_split('/<p>/i', $commentText, 2)[0] ?? ''));
            if (!$firstBlock) $firstBlock = $this->strip($commentText);
            $parts = [];
            foreach (explode('|', $firstBlock) as $p) {
                if (trim($p) !== '') $parts[] = trim($p);
            }
            if (count($parts) < 3) continue;
            if (preg_match('/[?]$/', $firstBlock)) continue;
            // Remote only: skip clearly on-site or hybrid posts that never mention remote.
            if (preg_match('/\b(on-?site|in[-\s]?person|hybrid)\b/i', $firstBlock) && !preg_match('/remote/i', $firstBlock)) continue;

            $regionM = [];
            preg_match('/\b(remote|hybrid|on-?site)\b[^|,;.]{0,60}/i', $firstBlock, $regionM);
            $salaryM = [];
            preg_match('/\$[\d,.]+k?[^|,;.]{0,30}/i', $firstBlock, $salaryM);
            $out[] = [
                'source' => 'HN Who is hiring',
                'title' => substr(implode(' | ', array_slice($parts, 0, 3)), 0, 160),
                'company' => substr((string)($parts[0] ?? ''), 0, 80),
                'location' => $regionM ? $regionM[0] : '',
                'url' => 'https://news.ycombinator.com/item?id=' . $h['objectID'],
                'posted' => $this->toIso($h['created_at'] ?? null),
                'tags' => '',
                'text' => $this->strip($commentText),
                'salary' => $salaryM ? $salaryM[0] : '',
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Filtering and scoring                                               */
    /* ------------------------------------------------------------------ */

    /** First matching family wins. */
    private function roleFamily(string $title, string $tags, string $text): ?string
    {
        foreach (self::ROLE_PATTERNS as [$family, $re]) if (preg_match($re, $title)) return $family;
        foreach (self::ROLE_PATTERNS as [$family, $re]) if (preg_match($re, $tags)) return $family;
        if (preg_match('/\b(software engineer|software developer|web developer|platform engineer|product engineer)\b/i', $title)) {
            $body = substr($text, 0, 1200);
            foreach (self::ROLE_PATTERNS as [$family, $re]) if (preg_match($re, $body)) return $family;
        }
        return null;
    }

    private function levelOf(string $title, string $text): string
    {
        $hay = $title . ' ' . substr($text, 0, 400);
        if (preg_match(self::INTERN, $title)) return 'intern';
        if (preg_match(self::JUNIOR, $title)) return 'junior';
        if (preg_match(self::SENIOR, $title)) return 'senior';
        if (preg_match(self::INTERN, $hay)) return 'intern';
        if (preg_match(self::JUNIOR, $hay)) return 'junior';
        return 'mid'; // unlabelled roles are most often mid-level
    }

    /**
     * "worldwide"  -> open to Nigeria
     * "africa"     -> open to Nigeria (or explicitly EMEA/Africa)
     * "check"      -> region unclear; worth reading before applying
     * "no"         -> explicitly locked to another region
     */
    private function regionOf(string $location, string $text, string $title = ''): string
    {
        $hay = $location . ' ' . substr($text, 0, 600);
        if (preg_match(self::AFRICA, $location) || preg_match('/\bnigeria\b/i', $hay)) return 'africa';
        if (preg_match(self::WORLDWIDE, $location)) return 'worldwide';
        if (preg_match(self::BLOCKED_REGION, $location)) return 'no';
        if ($location === '' && preg_match(self::BLOCKED_REGION, $title)) return 'no';
        if (preg_match(self::US_STATE, $location . ' ' . $title)) return 'no';
        if (preg_match(self::BLOCKED_TEXT, $hay)) return 'no';
        if (preg_match(self::WORLDWIDE, $hay)) return 'worldwide';
        return 'check';
    }

    private function scoreJob(array $job, string $family, string $level, string $region): int
    {
        $score = 0;
        $score += ['africa' => 55, 'worldwide' => 45, 'check' => 15, 'no' => 0][$region] ?? 0;
        $score += ['intern' => 25, 'junior' => 20, 'mid' => 15, 'senior' => 0][$level] ?? 0;
        $age = $this->daysAgo($job['posted'] ?? null);
        $score += $age <= 3 ? 15 : ($age <= 7 ? 12 : ($age <= 14 ? 8 : ($age <= 30 ? 4 : 0)));
        if ($family === 'wordpress') $score += 3;
        if (preg_match('/nigeria/i', (string)($job['location'] ?? '') . ' ' . substr((string)($job['text'] ?? ''), 0, 800))) $score += 10;
        return $score;
    }

    private function normalizeKey(array $job): string
    {
        $key = strtolower((string)($job['title'] ?? '') . '|' . (string)($job['company'] ?? ''));
        return trim((string)(preg_replace('/[^a-z0-9]+/', ' ', $key) ?? $key));
    }

    private function daysAgo($iso): float
    {
        if (!$iso) return 999.0;
        try {
            $d = new \DateTimeImmutable($iso);
        } catch (\Exception $e) {
            return 999.0;
        }
        return (microtime(true) - $d->getTimestamp()) / 86400.0;
    }

    /**
     * Normalize HTML to readable plain text for the `text` field used by the
     * family/level/region classifiers.
     */
    private function strip(string $html): string
    {
        $html = preg_replace('/<script[\s\S]*?<\/script>/i', ' ', $html);
        $html = preg_replace('/<style[\s\S]*?<\/style>/i', ' ', $html);
        $html = preg_replace('/<[^>]+>/', ' ', $html);
        $html = str_ireplace('&nbsp;', ' ', $html);
        $html = str_ireplace('&amp;', '&', $html);
        $html = str_ireplace('&lt;', '<', $html);
        $html = str_ireplace('&gt;', '>', $html);
        $html = preg_replace('/&#\d+;/', ' ', $html);
        $html = preg_replace('/\s+/', ' ', $html);
        return trim((string)$html);
    }

    /** ISO-8601 UTC timestamp (matches JS `toISOString()`), or null when unparseable. */
    private function toIso($value): ?string
    {
        if (!$value) return null;
        if (is_numeric($value)) {
            $secs = (int)$value;
            return $secs > 0 ? gmdate('Y-m-d\TH:i:s.000\Z', $secs) : null;
        }
        try {
            $d = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }

    /** JS-style string coercion: null/undefined -> '', arrays -> comma-joined. */
    private function toStr($v): string
    {
        if ($v === null) return '';
        if (is_array($v)) return implode(',', array_map(fn ($x) => $this->toStr($x), $v));
        return (string)$v;
    }

    /* ------------------------------------------------------------------ */
    /* Output writers                                                      */
    /* ------------------------------------------------------------------ */

    private function writeOutputs(array $kept, array $sourceNames, int $maxAge, array $counts): void
    {
        $out = base_path('out');
        if (!is_dir($out)) @mkdir($out, 0777, true);

        // out/all.json — every normalized job.
        file_put_contents("$out/all.json", (string)(json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        // out/shortlist.csv — one row per job.
        $csv = [implode(',', ['score', 'family', 'level', 'region', 'title', 'company', 'location', 'posted', 'age_days', 'source', 'salary', 'url'])];
        foreach ($kept as $j) {
            $csv[] = implode(',', array_map(fn ($v) => self::csvCell($v), [
                $j['score'], $j['family'], $j['level'], $j['region'], $j['title'], $j['company'],
                $j['location'], $j['posted'] ?? '', $j['ageDays'], $j['source'], $j['salary'] ?? '', $j['url'],
            ]));
        }
        file_put_contents("$out/shortlist.csv", implode("\n", $csv));

        // out/shortlist.md — human-readable, ranked, grouped by role family.
        $lines = [];
        $lines[] = "# Worldwide remote roles — frontend, backend, full-stack, WordPress, AI";
        $lines[] = '';
        $lines[] = 'Generated ' . gmdate('Y-m-d') . ' from ' . implode(', ', $sourceNames) . '.';
        $lines[] = count($kept) . ' roles kept: intern/junior/mid, worldwide/Africa/check-region, posted in the last ' . $maxAge . ' days.';
        $lines[] = '';
        $lines[] = 'Region key: **Africa** = explicitly open to Africa/Nigeria, **Worldwide** = anywhere, **Check** = region unclear — read before applying.';
        $lines[] = '';
        $lines[] = '| Family | Roles |';
        $lines[] = '|---|---|';
        foreach (self::FAMILY_ORDER as $f) $lines[] = '| ' . self::FAMILY_LABEL[$f] . ' | ' . $counts[$f] . ' |';
        $lines[] = '';
        foreach (self::FAMILY_ORDER as $family) {
            $jobs = array_values(array_filter($kept, fn ($j) => $j['family'] === $family));
            if (!$jobs) continue;
            $lines[] = '## ' . self::FAMILY_LABEL[$family] . ' (' . count($jobs) . ')';
            $lines[] = '';
            $i = 0;
            foreach ($jobs as $j) {
                if (++$i > 40) break;
                $posted = $j['posted'] ? substr((string)$j['posted'], 0, 10) : 'date unknown';
                $lines[] = '- **[' . $j['title'] . '](' . $j['url'] . ')** — ' . ($j['company'] ?: 'unknown company') . ' · ' . ($j['location'] ?: 'location unknown') . ' · ' . self::REGION_LABEL[$j['region']] . ' · ' . $j['level'] . ' · ' . $posted . ' · score ' . $j['score'];
            }
            $lines[] = '';
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'Every row is also in `shortlist.csv` and `all.json`.';
        file_put_contents("$out/shortlist.md", implode("\n", $lines));
    }

    /** Escape a CSV cell: fields are quoted, embedded quotes doubled, newlines replaced. */
    private static function csvCell($value): string
    {
        $s = (string)($value ?? '');
        $s = preg_replace('/\r?\n/', ' ', $s) ?? $s;
        $s = str_replace('"', '""', $s);
        return '"' . $s . '"';
    }
}
