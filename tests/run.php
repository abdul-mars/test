<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use QRoute\Core\Csrf;
use QRoute\Core\Database;
use QRoute\Core\RateLimiter;
use QRoute\Core\Security;
use QRoute\Http\Request;
use QRoute\Http\Router;
use QRoute\Http\Response;
use QRoute\Models\ApiKey;
use QRoute\Models\Link;
use QRoute\Models\Rule;
use QRoute\Models\User;
use QRoute\Core\SchemaDumper;
use QRoute\Models\Setting;
use QRoute\Services\Analytics;
use QRoute\Services\Installer;
use QRoute\Services\DeviceDetector;
use QRoute\Services\Plan;
use QRoute\Services\QrCode;
use QRoute\Services\QrRenderer;
use QRoute\Services\RuleEngine;
use QRoute\Services\UrlValidator;

$t = new TestRunner();
test_database();
fwrite(STDOUT, "Running against: " . test_driver() . "\n");

// =====================================================================
$t->group('QR encoder — known-good vectors');

$vectors = json_decode((string) file_get_contents(__DIR__ . '/fixtures/qr_vectors.json'), true);
$t->ok(is_array($vectors) && $vectors !== [], 'fixture file loads');

foreach ($vectors as $v) {
    $qr = QrCode::encode($v['text'], QrCode::eccFromName($v['ecc']), 1, 40, false);
    $rows = [];
    foreach ($qr->matrix() as $r) {
        $rows[] = implode('', array_map(static fn($b) => $b ? '1' : '0', $r));
    }
    $label = 'v' . $v['version'] . '-' . $v['ecc'] . ' ' . substr($v['text'], 0, 18);
    $t->same($v['version'], $qr->version, "{$label}: version");
    $t->same($v['sha256'], hash('sha256', implode("\n", $rows)), "{$label}: matrix unchanged");
}

// Structural invariants that must hold for every symbol.
foreach ([['https://qrt.test/Ab3Xk9', 'Q'], ['x', 'H'], [str_repeat('n', 400), 'M']] as [$text, $ecl]) {
    $qr = QrCode::encode($text, QrCode::eccFromName($ecl));
    $size = $qr->size;
    $t->same($qr->version * 4 + 17, $size, "size matches version for " . substr($text, 0, 12));

    // Three finder patterns: dark 7x7 ring with a 3x3 core.
    $finders = 0;
    foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$ox, $oy]) {
        $good = true;
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 7; $x++) {
                $ring = $x === 0 || $x === 6 || $y === 0 || $y === 6;
                $core = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                if ($qr->isDark($ox + $x, $oy + $y) !== ($ring || $core)) {
                    $good = false;
                }
            }
        }
        $finders += $good ? 1 : 0;
    }
    $t->same(3, $finders, 'three well-formed finder patterns');

    // Timing patterns alternate starting dark.
    $timingOk = true;
    for ($i = 8; $i < $size - 8; $i++) {
        if ($qr->isDark($i, 6) !== ($i % 2 === 0) || $qr->isDark(6, $i) !== ($i % 2 === 0)) {
            $timingOk = false;
        }
    }
    $t->ok($timingOk, 'timing patterns alternate correctly');

    // The dark module is mandatory and always at (8, size-8).
    $t->ok($qr->isDark(8, $size - 8), 'mandatory dark module present');
}

$t->throws(static fn() => QrCode::encode(''), 'empty input is rejected');
$t->throws(static fn() => QrCode::encode(str_repeat('x', 5000)), 'oversized input is rejected');

// Higher EC levels must not produce a smaller symbol for the same data.
$prev = 0;
foreach (['L', 'M', 'Q', 'H'] as $ecl) {
    $v = QrCode::encode(str_repeat('a', 200), QrCode::eccFromName($ecl), 1, 40, false)->version;
    $t->ok($v >= $prev, "EC {$ecl} version {$v} >= previous {$prev}");
    $prev = $v;
}

// =====================================================================
$t->group('QR renderer');

$qr = QrCode::encode('https://qrt.test/abc', QrCode::ECC_QUARTILE);
$svg = QrRenderer::svg($qr);
$t->ok(str_starts_with($svg, '<svg'), 'SVG starts with an svg element');
$t->ok(str_contains($svg, 'viewBox="0 0 ' . ($qr->size + 8)), 'SVG includes the 4-module quiet zone');
$t->ok(!str_contains(strtolower($svg), '<script'), 'SVG contains no script element');

$png = QrRenderer::png($qr, ['scale' => 4]);
$t->same("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'PNG has a valid signature');

$t->same('#ff0000', QrRenderer::colour('#FF0000', '#000000'), 'valid hex colour is accepted');
$t->same('#000000', QrRenderer::colour('red; }</style><script>', '#000000'), 'colour injection falls back to default');
$t->same('#000000', QrRenderer::colour('', '#000000'), 'empty colour falls back');
$t->ok(QrRenderer::contrastRatio('#000000', '#ffffff') > 20, 'black on white is high contrast');
$t->ok(QrRenderer::contrastRatio('#777777', '#888888') < 2, 'grey on grey is low contrast');

// =====================================================================
$t->group('URL validation');

foreach ([
    ['https://example.com/x', true,  'plain https'],
    ['example.com',           true,  'bare domain gets a scheme'],
    ['http://example.com',    true,  'plain http'],
    ['mailto:a@b.com',        true,  'mailto'],
    ['tel:+15551234567',      true,  'tel'],
    ['javascript:alert(1)',   false, 'javascript scheme blocked'],
    ["java\tscript:alert(1)", false, 'javascript with a tab blocked'],
    ['JavaScript:alert(1)',   false, 'javascript in mixed case blocked'],
    ['data:text/html,<script>', false, 'data scheme blocked'],
    ['vbscript:msgbox',       false, 'vbscript blocked'],
    ['file:///etc/passwd',    false, 'file scheme blocked'],
    ['http://127.0.0.1/',     false, 'loopback blocked'],
    ['http://localhost/',     false, 'localhost blocked'],
    ['http://169.254.169.254/latest/meta-data/', false, 'cloud metadata endpoint blocked'],
    ['http://10.0.0.5/',      false, 'private range blocked'],
    ['http://192.168.1.1/',   false, 'private range blocked'],
    ['http://2130706433/',    false, 'decimal-encoded loopback blocked'],
    ['https://evil.com@real.com/', false, 'embedded credentials blocked'],
    ['https://qrt.test/loop', false, 'self-referencing destination blocked'],
    [str_repeat('a', 3000),   false, 'overlong URL blocked'],
    ['',                      false, 'empty URL blocked'],
] as [$url, $expected, $why]) {
    $t->same($expected, UrlValidator::check($url)['ok'], $why);
}

$t->same(false, UrlValidator::check('myapp://open')['ok'], 'custom scheme blocked by default');
$t->same(true, UrlValidator::check('myapp://open', true)['ok'], 'custom scheme allowed when permitted');

// =====================================================================
$t->group('Device detection');

foreach ([
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1', 'mobile', 'ios'],
    ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1', 'tablet', 'ios'],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120.0 Mobile Safari/537.36', 'mobile', 'android'],
    ['Mozilla/5.0 (Linux; Android 14; SM-X200) Chrome/120.0 Safari/537.36', 'tablet', 'android'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36', 'desktop', 'windows'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0 Safari/537.36', 'desktop', 'macos'],
] as [$ua, $device, $os]) {
    $d = DeviceDetector::detect($ua);
    $t->same($device, $d['device'], "device for " . substr($ua, 12, 22));
    $t->same($os, $d['os'], "os for " . substr($ua, 12, 22));
    $t->same(false, $d['is_bot'], 'real browser is not flagged as a bot');
}

foreach ([
    'facebookexternalhit/1.1', 'Slackbot-LinkExpanding 1.0', 'WhatsApp/2.23',
    'Twitterbot/1.0', 'curl/8.0.1', 'Googlebot/2.1', 'TelegramBot (like TwitterBot)',
] as $ua) {
    $t->same(true, DeviceDetector::detect($ua)['is_bot'], 'bot detected: ' . substr($ua, 0, 24));
}

// An iPad requesting the desktop site still reports as iOS.
$ipadDesktop = DeviceDetector::detect('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Version/17.0 Mobile/15E148 Safari/604.1');
$t->same('ios', $ipadDesktop['os'], 'iPad in desktop mode is still iOS');

// =====================================================================
$t->group('Rule engine');

$base = ['device' => 'mobile', 'os' => 'ios', 'browser' => 'safari', 'country' => 'GB',
         'lang' => 'en', 'referer' => '', 'now' => time(), 'scan_count' => 0];

$t->same(true,  RuleEngine::matches(['os' => ['ios']], $base), 'matching os');
$t->same(false, RuleEngine::matches(['os' => ['android']], $base), 'non-matching os');
$t->same(true,  RuleEngine::matches(['os' => ['android', 'ios']], $base), 'values within a condition are OR-ed');
$t->same(true,  RuleEngine::matches(['os' => ['ios'], 'country' => ['GB']], $base), 'conditions are AND-ed');
$t->same(false, RuleEngine::matches(['os' => ['ios'], 'country' => ['US']], $base), 'one failing condition fails the rule');
$t->same(true,  RuleEngine::matches([], $base), 'empty conditions match everything');
$t->same(false, RuleEngine::matches(['nonsense' => ['x']], $base), 'unknown condition never matches');

// Referer matches the domain and its subdomains, but not a lookalike.
$ref = $base;
$ref['referer'] = 'www.instagram.com';
$t->same(true,  RuleEngine::matches(['referer' => ['instagram.com']], $ref), 'subdomain matches parent domain');
$ref['referer'] = 'notinstagram.com';
$t->same(false, RuleEngine::matches(['referer' => ['instagram.com']], $ref), 'lookalike domain does not match');

// Scan caps.
$capped = $base;
$capped['scan_count'] = 99;
$t->same(true,  RuleEngine::matches(['scans' => ['max' => 100]], $capped), 'under the scan cap');
$capped['scan_count'] = 100;
$t->same(false, RuleEngine::matches(['scans' => ['max' => 100]], $capped), 'at the scan cap the rule stops matching');

// Schedules, including one that wraps past midnight.
$noon = ['now' => (int) strtotime('2026-06-15 12:00:00 UTC')] + $base;
$t->same(true,  RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'from' => '09:00', 'to' => '17:00']], $noon), 'inside a daytime window');
$t->same(false, RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'from' => '18:00', 'to' => '23:00']], $noon), 'outside an evening window');
$t->same(false, RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'from' => '22:00', 'to' => '02:00']], $noon), 'noon is outside an overnight window');

$lateNight = ['now' => (int) strtotime('2026-06-15 23:30:00 UTC')] + $base;
$t->same(true, RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'from' => '22:00', 'to' => '02:00']], $lateNight), 'overnight window wraps past midnight');
$earlyHours = ['now' => (int) strtotime('2026-06-16 01:00:00 UTC')] + $base;
$t->same(true, RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'from' => '22:00', 'to' => '02:00']], $earlyHours), 'overnight window covers the early hours');

// 2026-06-15 is a Monday.
$t->same(true,  RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'days' => [1]]], $noon), 'weekday matches');
$t->same(false, RuleEngine::matches(['schedule' => ['tz' => 'UTC', 'days' => [6, 7]]], $noon), 'weekend does not match a Monday');

// The timezone is the customer's, not the server's.
$tokyo = ['now' => (int) strtotime('2026-06-15 23:00:00 UTC')] + $base;
$t->same(true, RuleEngine::matches(['schedule' => ['tz' => 'Asia/Tokyo', 'from' => '07:00', 'to' => '09:00']], $tokyo), 'schedule honours the configured timezone');

// Date windows are inclusive of the end date.
$t->same(true,  RuleEngine::matches(['window' => ['from' => '2026-06-01', 'to' => '2026-06-15']], $noon), 'inside the date window');
$t->same(false, RuleEngine::matches(['window' => ['from' => '2026-06-16']], $noon), 'before the window starts');

// First match wins, in priority order.
$rules = [
    ['id' => 1, 'is_active' => 1, 'conditions' => '{"os":["android"]}', 'target_url' => 'https://play.example'],
    ['id' => 2, 'is_active' => 1, 'conditions' => '{"os":["ios"]}',     'target_url' => 'https://apple.example'],
    ['id' => 3, 'is_active' => 1, 'conditions' => '{"device":["mobile"]}', 'target_url' => 'https://mobile.example'],
];
$resolved = RuleEngine::resolve($rules, $base, 'https://default.example');
$t->same('https://apple.example', $resolved['url'], 'first matching rule wins');
$t->same(2, (int) $resolved['rule']['id'], 'the winning rule is reported');

$rules[1]['is_active'] = 0;
$t->same('https://mobile.example', RuleEngine::resolve($rules, $base, 'https://default.example')['url'], 'paused rules are skipped');

$noMatch = $base;
$noMatch['os'] = 'windows';
$noMatch['device'] = 'desktop';
$fallthrough = RuleEngine::resolve($rules, $noMatch, 'https://default.example');
$t->same('https://default.example', $fallthrough['url'], 'unmatched scans fall through to the default');
$t->same(null, $fallthrough['rule'], 'no rule is reported on fallthrough');

// Sanitisation rejects junk and keeps valid values.
$clean = RuleEngine::sanitize(['os' => ['ios', 'BOGUS'], 'country' => ['gb', 'zzz', '<script>']]);
$t->same(true, $clean['ok'], 'sanitize accepts a valid payload');
$t->same(['ios'], $clean['conditions']['os'], 'unknown enum values are dropped');
$t->same(['GB'], $clean['conditions']['country'], 'country codes are normalised and filtered');
$t->same(false, RuleEngine::sanitize(['schedule' => ['from' => '25:00', 'to' => '09:00']])['ok'], 'invalid time is rejected');
$t->same(false, RuleEngine::sanitize(['window' => ['from' => '2026-12-01', 'to' => '2026-01-01']])['ok'], 'reversed date range is rejected');
$t->same([], RuleEngine::sanitize(['device' => []])['conditions'], 'empty input yields no conditions');

$t->ok(str_contains(RuleEngine::describe(['os' => ['ios']]), 'iOS'), 'describe() renders a readable sentence');

// =====================================================================
$t->group('Users and authentication');

$reg = User::register('Owner@Example.COM', 'a-long-enough-password');
$t->same(true, $reg['ok'], 'registration succeeds');
$user = $reg['user'];
$t->same('owner@example.com', $user->email(), 'email is normalised to lower case');
$t->same('free', $user->plan(), 'new accounts start on the free plan');

$t->same(false, User::register('owner@example.com', 'another-long-password')['ok'], 'duplicate email is rejected');
$t->same(false, User::register('not-an-email', 'a-long-enough-password')['ok'], 'invalid email is rejected');
$t->same(false, User::register('x@y.com', 'short')['ok'], 'short password is rejected');
$t->same(false, User::register('x@y.com', 'password123')['ok'], 'common password is rejected');

$t->ok(User::attemptLogin('owner@example.com', 'a-long-enough-password') !== null, 'correct credentials sign in');
$t->same(null, User::attemptLogin('owner@example.com', 'wrong-password-here'), 'wrong password is refused');
$t->same(null, User::attemptLogin('nobody@example.com', 'a-long-enough-password'), 'unknown account is refused');

$hash = (string) Database::instance()->scalar('SELECT password_hash FROM users WHERE id = :i', ['i' => $user->id()]);
$t->ok(!str_contains($hash, 'a-long-enough-password'), 'password is not stored in plain text');
$t->ok(str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'), 'password uses a strong hash');

// Lockout after repeated failures.
for ($i = 0; $i < 9; $i++) {
    User::attemptLogin('owner@example.com', 'wrong-password-' . $i);
}
$t->same(null, User::attemptLogin('owner@example.com', 'a-long-enough-password'), 'account locks after repeated failures');
Database::instance()->update('users', ['locked_until' => null, 'failed_logins' => 0], ['id' => $user->id()]);
$t->ok(User::attemptLogin('owner@example.com', 'a-long-enough-password') !== null, 'account works again once unlocked');

// =====================================================================
$t->group('CSRF');

$sid = 'session-abc';
$token = Csrf::token($sid);
$t->same(true,  Csrf::validate($sid, $token), 'a fresh token validates');
$t->same(false, Csrf::validate('another-session', $token), 'a token is bound to its session');
$t->same(false, Csrf::validate($sid, 'garbage'), 'garbage is rejected');
$t->same(false, Csrf::validate($sid, '9999999999.' . str_repeat('a', 40)), 'a forged signature is rejected');
$t->same(false, Csrf::validate($sid, (time() - 10) . '.' . substr(Security::hmac($sid . '|' . (time() - 10), 'csrf'), 0, 40)), 'an expired token is rejected');

// =====================================================================
$t->group('Links and slugs');

$link = Link::create($user->id(), Link::generateSlug(), 'https://example.com/menu', ['title' => 'Menu']);
$t->same(7, strlen($link->slug()), 'generated slugs are 7 characters');
$t->same(0, preg_match('/[0O1lI]/', $link->slug()), 'slugs avoid visually ambiguous characters');
$t->ok(Link::findBySlug($link->slug()) !== null, 'a link is retrievable by slug');
$t->same($link->id(), Link::findForUser($link->id(), $user->id())?->id(), 'owner can fetch their link');
$t->same(null, Link::findForUser($link->id(), $user->id() + 999), 'another user cannot fetch it');

foreach (['app', 'api', 'login', 'ADMIN', 'qr'] as $reserved) {
    $t->same(false, Link::validateCustomSlug($reserved)['ok'], "reserved slug rejected: {$reserved}");
}
$t->same(false, Link::validateCustomSlug('ab')['ok'], 'too-short custom slug rejected');
$t->same(false, Link::validateCustomSlug('has space')['ok'], 'slug with a space rejected');
$t->same(false, Link::validateCustomSlug('bad/slash')['ok'], 'slug with a slash rejected');
$t->same(true,  Link::validateCustomSlug('spring-menu')['ok'], 'valid custom slug accepted');
$t->same(false, Link::validateCustomSlug($link->slug())['ok'], 'duplicate slug rejected');

$t->same('https://qrt.test/' . $link->slug(), $link->shortUrl('https://qrt.test'), 'short URL is built correctly');

// =====================================================================
$t->group('Plans and quotas');

$t->same(3, Plan::maxLinks($user), 'free plan allows 3 codes');
$t->same(true, Plan::showsInterstitial($user), 'free plan shows the interstitial');
$t->same(false, Plan::can($user, 'api_access'), 'free plan has no API access');

$user->setPlan(User::PLAN_PRO, time() + 86400);
$t->same(100, Plan::maxLinks($user), 'pro plan raises the code limit');
$t->same(false, Plan::showsInterstitial($user), 'pro plan removes the interstitial');
$t->same(true, Plan::can($user, 'api_access'), 'pro plan unlocks the API');

// An expired paid plan must fall back to free rather than staying paid.
$user->setPlan(User::PLAN_PRO, time() - 86400);
$t->same('free', $user->plan(), 'an expired plan reverts to free');
$user->setPlan(User::PLAN_PRO, time() + 86400);

// =====================================================================
$t->group('API keys');

$created = ApiKey::create($user->id(), 'CI key');
$t->ok(str_starts_with($created['plaintext'], 'qr_live_'), 'key has the expected prefix');
$stored = Database::instance()->first('SELECT key_hash FROM api_keys WHERE id = :i', ['i' => $created['id']]);
$t->ok(!str_contains((string) $stored['key_hash'], $created['plaintext']), 'plaintext key is not stored');
$t->same($user->id(), ApiKey::authenticate($created['plaintext'])?->id(), 'a valid key authenticates');
$t->same(null, ApiKey::authenticate('qr_live_wrong'), 'an invalid key does not authenticate');
$t->same(null, ApiKey::authenticate(''), 'an empty key does not authenticate');

ApiKey::revoke($created['id'], $user->id());
$t->same(null, ApiKey::authenticate($created['plaintext']), 'a revoked key stops working');

// =====================================================================
$t->group('Analytics');

$now = (int) strtotime('2026-06-15 12:00:00 UTC');
for ($i = 0; $i < 5; $i++) {
    Analytics::record($link->id(), $user->id(), null, [
        'now' => $now - $i * 3600, 'device' => 'mobile', 'os' => 'ios',
        'browser' => 'safari', 'country' => 'GB', 'lang' => 'en',
        'referer' => '', 'is_bot' => false, 'ip' => '203.0.113.' . $i, 'ua' => 'ua-' . $i,
    ]);
}
// A repeat visitor on the same day is a scan but not a unique.
Analytics::record($link->id(), $user->id(), null, [
    'now' => $now, 'device' => 'mobile', 'os' => 'ios', 'browser' => 'safari',
    'country' => 'GB', 'lang' => 'en', 'referer' => '', 'is_bot' => false,
    'ip' => '203.0.113.0', 'ua' => 'ua-0',
]);

$day = gmdate('Y-m-d', $now);
$series = Analytics::daily($link->id(), 3, $now);
$t->same(6, $series[$day]['total'], 'all scans for the day are counted');
$t->same(5, $series[$day]['uniques'], 'a repeat visit is not counted as unique');

$devices = Analytics::breakdown($link->id(), 'device', 3, $now);
$t->same('mobile', $devices[0]['value'], 'device breakdown reports the top device');
$t->same(6, $devices[0]['total'], 'device breakdown totals correctly');

// Bots are recorded but never counted.
Analytics::record($link->id(), $user->id(), null, [
    'now' => $now, 'device' => 'bot', 'os' => 'other', 'browser' => 'bot',
    'country' => '', 'lang' => '', 'referer' => '', 'is_bot' => true,
    'ip' => '198.51.100.1', 'ua' => 'Googlebot',
]);
$t->same(6, Analytics::daily($link->id(), 3, $now)[$day]['total'], 'bot traffic is excluded from totals');

// Rolling up must not change the numbers a customer sees.
$before = Analytics::daily($link->id(), 5, $now + 86400);
Analytics::rollup(0, $now + 86400);
$after = Analytics::daily($link->id(), 5, $now + 86400);
$t->same($before[$day]['total'], $after[$day]['total'], 'rollup preserves daily totals');
$t->same($before[$day]['uniques'], $after[$day]['uniques'], 'rollup preserves unique counts');

// After pruning raw rows the rolled-up figures must survive.
Database::instance()->run('DELETE FROM scans WHERE link_id = :l', ['l' => $link->id()]);
$pruned = Analytics::daily($link->id(), 5, $now + 86400);
$t->same($before[$day]['total'], $pruned[$day]['total'], 'totals survive raw scan pruning');

// =====================================================================
$t->group('Rate limiter');

$bucket = 'test:' . bin2hex(random_bytes(4));
$allowed = 0;
for ($i = 0; $i < 7; $i++) {
    if (RateLimiter::hit($bucket, 5, 60)['allowed']) {
        $allowed++;
    }
}
$t->same(5, $allowed, 'the limiter allows exactly the configured number');
$t->same(false, RateLimiter::hit($bucket, 5, 60)['allowed'], 'further requests stay blocked');
$t->ok(RateLimiter::hit('other:' . $bucket, 5, 60)['allowed'], 'a different bucket is unaffected');

// =====================================================================
$t->group('Router');

$r = new Router();
$r->get('/app/links/{id:\d+}', static fn($rq, $a) => Response::text('link:' . $a['id']));
$r->get('/qr/{slug:[A-Za-z0-9_-]{3,32}}.{format:svg|png}', static fn($rq, $a) => Response::text($a['slug'] . '.' . $a['format']));
$r->post('/app/links/{id:\d+}/delete', static fn($rq, $a) => Response::text('deleted'));
$r->get('/{slug:[A-Za-z0-9_-]{3,32}}', static fn($rq, $a) => Response::text('short:' . $a['slug']));
$r->fallback(static fn() => Response::text('404', 404));

$t->same('link:42', $r->dispatch(Request::fake('GET', '/app/links/42'))->body, 'numeric route parameter');
$t->same('404', $r->dispatch(Request::fake('GET', '/app/links/abc'))->body, 'non-numeric id does not match');
$t->same('abc.svg', $r->dispatch(Request::fake('GET', '/qr/abc.svg'))->body, 'extension route matches');
$t->same('404', $r->dispatch(Request::fake('GET', '/qr/abc.gif'))->body, 'unsupported extension does not match');
$t->same('404', $r->dispatch(Request::fake('GET', '/qr/abcXsvg'))->body, 'literal dot is not a wildcard');
$t->same('short:Ab3Xk9p', $r->dispatch(Request::fake('GET', '/Ab3Xk9p'))->body, 'short link catch-all matches');
$t->same(405, $r->dispatch(Request::fake('DELETE', '/app/links/42'))->status, 'wrong method returns 405');
$t->same('link:42', $r->dispatch(Request::fake('HEAD', '/app/links/42'))->body, 'HEAD is served by the GET handler');
$t->same('link:42', $r->dispatch(Request::fake('GET', '/app/links/42/'))->body, 'trailing slash is tolerated');

// =====================================================================
$t->group('Request');

$t->same(true, Request::ipInCidr('10.0.0.5', '10.0.0.0/8'), 'IPv4 inside a CIDR');
$t->same(false, Request::ipInCidr('11.0.0.5', '10.0.0.0/8'), 'IPv4 outside a CIDR');
$t->same(true, Request::ipInCidr('192.168.1.7', '192.168.1.0/24'), 'IPv4 inside a /24');
$t->same(false, Request::ipInCidr('192.168.2.7', '192.168.1.0/24'), 'IPv4 outside a /24');
$t->same(true, Request::ipInCidr('::1', '::1/128'), 'IPv6 loopback');

$req = Request::fake('GET', '/', [], [], ['accept-language' => 'en-GB,en;q=0.9,fr;q=0.8']);
$t->same('en', $req->language(), 'preferred language is parsed');
$t->same('', Request::fake('GET', '/')->language(), 'missing language header yields empty');

// =====================================================================
$t->group('Output escaping');

$xss = '<script>alert("xss")</script>';
$escaped = \QRoute\Core\View::e($xss);
$t->ok(!str_contains($escaped, '<script>'), 'HTML is escaped');
$t->ok(str_contains($escaped, '&lt;script&gt;'), 'escaping produces entities');
$t->ok(!str_contains(\QRoute\Core\View::e("it's \"quoted\""), '"'), 'quotes are escaped');

// A link title containing markup must not survive into rendered output.
$evil = Link::create($user->id(), Link::generateSlug(), 'https://example.com/', ['title' => $xss]);
$t->ok(!str_contains(\QRoute\Core\View::e($evil->title()), '<script>'), 'a hostile link title is escaped');

// =====================================================================
$t->group('Deployment safety');

// Regression: a relative DB_PATH must not resolve inside public/, where
// the database would be downloadable.
$resolve = new ReflectionMethod(Database::class, 'resolveSqlitePath');
$resolve->setAccessible(true);
$root = dirname(__DIR__);
$t->same($root . '/storage/qroute.sqlite', $resolve->invoke(null, 'storage/qroute.sqlite'), 'relative DB_PATH anchors to the project root');
$t->same($root . '/var/db.sqlite', $resolve->invoke(null, 'var/db.sqlite'), 'a differently named relative path also anchors to the root');
$t->same('/var/lib/qroute/db.sqlite', $resolve->invoke(null, '/var/lib/qroute/db.sqlite'), 'absolute DB_PATH is left alone');
$t->same(':memory:', $resolve->invoke(null, ':memory:'), 'in-memory database is left alone');
$t->ok(!str_contains($resolve->invoke(null, 'storage/qroute.sqlite'), '/public/'), 'resolved path is outside the web root');

// The front controller must be the only PHP file reachable by the web.
$phpInPublic = glob(__DIR__ . '/../public/*.php') ?: [];
$t->same(['index.php'], array_map('basename', $phpInPublic), 'public/ contains only the front controller');

// =====================================================================
$t->group('Security headers');

$headers = Security::headers('test-nonce', true);
$csp = $headers['Content-Security-Policy'];
$t->ok(str_contains($csp, "script-src 'self' 'nonce-test-nonce'"), 'script-src is nonce based');
$t->ok(!str_contains($csp, "script-src 'self' 'unsafe-inline'"), "script-src does not allow unsafe-inline");
$t->ok(str_contains($csp, "script-src-attr 'none'"), 'inline event handlers are blocked');
// Regression: a nonce cannot authorise style="" attributes, and the views
// rely on them, so the attribute directive must be present.
$t->ok(str_contains($csp, "style-src-attr 'unsafe-inline'"), 'style attributes are permitted so the UI renders');
$t->ok(str_contains($csp, "object-src 'none'"), 'plugins are blocked');
$t->ok(str_contains($csp, "frame-ancestors 'none'"), 'clickjacking is blocked');
$t->same('nosniff', $headers['X-Content-Type-Options'], 'MIME sniffing is disabled');
$t->ok(isset($headers['Strict-Transport-Security']), 'HSTS is sent over HTTPS');
$t->ok(!isset(Security::headers('n', false)['Strict-Transport-Security']), 'HSTS is not sent over plain HTTP');

// A destination containing CRLF must not be able to inject a second
// header into the redirect response.
$t->same(
    'https://ok.test/X-Injected: 1',
    Response::sanitizeHeaderValue("https://ok.test/\r\nX-Injected: 1"),
    'CRLF is stripped from header values'
);
$t->same('abc', Response::sanitizeHeaderValue("a\0b\nc"), 'null bytes and newlines are stripped');
$t->same('https://ok.test/path', Response::sanitizeHeaderValue('https://ok.test/path'), 'a clean value passes through unchanged');

// =====================================================================
$t->group('Administrator role');

$owner = User::findByEmail('owner@example.com');
$t->same(false, $owner->isAdmin(), 'accounts are not administrators by default');

$owner->setAdmin(true);
$t->same(true, User::find($owner->id())->isAdmin(), 'promotion persists');
$t->same(1, User::countAdmins(), 'administrator count reflects the promotion');

$owner->setAdmin(false);
$t->same(0, User::countAdmins(), 'demotion persists');
$owner->setAdmin(true);

// Suspension must invalidate any session the account already had.
$reg = User::register('suspendme@example.com', 'a-long-enough-password');
$victim = $reg['user'];
Database::instance()->insert('sessions', [
    'id' => 'sess-for-suspension-test', 'user_id' => $victim->id(),
    'ip_hash' => '', 'ua_hash' => '', 'created_at' => time(),
    'last_seen_at' => time(), 'expires_at' => time() + 3600,
]);
$victim->setStatus('suspended');
$t->same('suspended', User::find($victim->id())->status(), 'suspension persists');
$t->same(
    null,
    Database::instance()->scalar('SELECT 1 FROM sessions WHERE user_id = :u', ['u' => $victim->id()]),
    'suspending an account drops its sessions'
);
$t->same(null, User::attemptLogin('suspendme@example.com', 'a-long-enough-password'), 'a suspended account cannot sign in');
$victim->setStatus('active');
$t->ok(User::attemptLogin('suspendme@example.com', 'a-long-enough-password') !== null, 'restoring lets them back in');

// =====================================================================
$t->group('Settings store');

$t->same(null, Setting::get('nothing_here'), 'missing setting returns null');
$t->same('fallback', Setting::get('nothing_here', 'fallback'), 'missing setting honours the default');
Setting::set('site_name', 'Acme Codes');
$t->same('Acme Codes', Setting::get('site_name'), 'a setting round-trips');
Setting::set('site_name', 'Renamed');
$t->same('Renamed', Setting::get('site_name'), 'a setting can be overwritten');
Setting::set('flag_on', '1');
$t->same(true, Setting::bool('flag_on'), 'boolean setting reads true');
$t->same(false, Setting::bool('flag_missing'), 'missing boolean falls back to false');

// =====================================================================
$t->group('Installer');

$checks = Installer::requirements();
$t->ok(count($checks) >= 6, 'requirements are reported');
foreach ($checks as $check) {
    $t->ok(
        isset($check['name'], $check['ok'], $check['required'], $check['detail']),
        'requirement "' . ($check['name'] ?? '?') . '" is fully described'
    );
}

// A failing required check must block; a failing optional one must not.
$t->same(false, Installer::requirementsMet([
    ['name' => 'x', 'ok' => false, 'required' => true, 'detail' => ''],
]), 'a failed required check blocks setup');
$t->same(true, Installer::requirementsMet([
    ['name' => 'x', 'ok' => false, 'required' => false, 'detail' => ''],
    ['name' => 'y', 'ok' => true, 'required' => true, 'detail' => ''],
]), 'a failed optional check does not block setup');

// Database name validation, which is interpolated into CREATE DATABASE.
foreach ([
    ['qroute', true,  'a plain name is accepted'],
    ['qroute_2024', true, 'underscores and digits are accepted'],
    ['qroute; DROP TABLE users', false, 'a name containing SQL is rejected'],
    ['qroute`--', false, 'a name containing a backtick is rejected'],
    ['', false, 'an empty name is rejected'],
    [str_repeat('a', 65), false, 'an over-long name is rejected'],
] as [$name, $shouldPass, $why]) {
    $result = Installer::testDatabase([
        'DB_DRIVER' => 'mysql', 'DB_NAME' => $name,
        'DB_HOST' => '203.0.113.1', 'DB_PORT' => '1', 'DB_USER' => 'x', 'DB_PASS' => '',
    ], false);
    // A valid name gets past validation and fails on connection instead;
    // an invalid one is refused before any connection is attempted.
    $rejectedByValidation = str_contains($result['error'], 'Database name must be');
    $t->same(!$shouldPass, $rejectedByValidation, $why);
}

// Env writing preserves unrelated keys and replaces the targeted ones.
$envFile = sys_get_temp_dir() . '/qroute-env-test-' . bin2hex(random_bytes(4));
file_put_contents($envFile, "# a comment\nAPP_KEY=old-key\nUNRELATED=keep-me\n");
$reflect = new ReflectionMethod(Installer::class, 'quoteEnvValue');
$reflect->setAccessible(true);
$t->same('simple', $reflect->invoke(null, 'simple'), 'a plain env value is not quoted');
$t->ok(str_starts_with($reflect->invoke(null, 'has space'), '"'), 'a value with a space is quoted');
$t->ok(str_contains($reflect->invoke(null, 'say "hi"'), '\\"'), 'embedded quotes are escaped');
@unlink($envFile);

// The lock file is what gates the installer.
$t->same(true, Installer::isInstalled() === is_file(Installer::lockPath()), 'install state follows the lock file');

// =====================================================================
$t->group('Schema dumper');

$dump = SchemaDumper::mysql(__DIR__ . '/../migrations');
$t->ok(str_contains($dump, 'CREATE TABLE IF NOT EXISTS users'), 'dump creates the users table');
$t->ok(str_contains($dump, 'CREATE TABLE IF NOT EXISTS links'), 'dump creates the links table');
$t->ok(str_contains($dump, 'CREATE TABLE IF NOT EXISTS settings'), 'dump includes later migrations');
$t->ok(str_contains($dump, 'ENGINE=InnoDB'), 'dump uses InnoDB');
$t->ok(str_contains($dump, 'utf8mb4'), 'dump uses utf8mb4');
$t->ok(str_contains($dump, 'INSERT IGNORE INTO migrations'), 'dump records the migrations as applied');
$t->ok(!str_contains($dump, '{{'), 'no unexpanded portability tokens remain');
$t->ok(!str_contains($dump, 'IF NOT EXISTS idx_'), 'index creation avoids the MariaDB-only clause');
$t->ok(str_contains($dump, 'AUTO_INCREMENT'), 'dump expands the primary key for MySQL');
$t->ok(!str_contains($dump, 'AUTOINCREMENT'), 'dump does not leak SQLite syntax');

// =====================================================================
$t->group('Serving from a sub-directory');

// Dropping the project into htdocs and browsing to localhost/qroute/public
// is the common XAMPP layout, so routes must match with the prefix removed
// and generated URLs must put it back.
$detect = new ReflectionMethod(Request::class, 'detectBasePath');
$detect->setAccessible(true);
$withScript = static function (string $script) use ($detect): string {
    $previous = $_SERVER['SCRIPT_NAME'] ?? null;
    $_SERVER['SCRIPT_NAME'] = $script;
    $result = $detect->invoke(null);
    if ($previous === null) {
        unset($_SERVER['SCRIPT_NAME']);
    } else {
        $_SERVER['SCRIPT_NAME'] = $previous;
    }
    return $result;
};

$t->same('', $withScript('/index.php'), 'a document root of its own yields no prefix');
$t->same('/qroute/public', $withScript('/qroute/public/index.php'), 'a sub-directory is detected');
$t->same('/test/public', $withScript('/test/public/index.php'), 'nested sub-directory is detected');
$t->same('', $withScript(''), 'a missing SCRIPT_NAME yields no prefix');

// SCRIPT_NAME is server-supplied and ends up printed into every link, so
// anything that is not a plain path must be discarded rather than trusted.
$t->same('', $withScript('/<script>alert(1)</script>/index.php'), 'markup in SCRIPT_NAME is rejected');
$t->same('', $withScript('/a/../../etc/index.php'), 'traversal in SCRIPT_NAME is rejected');
$t->same('', $withScript('/a"onload="x/index.php'), 'quote injection in SCRIPT_NAME is rejected');

// Routing happens on the path with the prefix stripped.
$sub = Request::fake('GET', '/app/links/7', [], [], [], '/qroute/public');
$t->same('/app/links/7', $sub->path, 'the route is matched without the prefix');
$t->same('/qroute/public/app', $sub->url('/app'), 'generated URLs carry the prefix');
$t->same('/qroute/public/assets/app.css', $sub->url('/assets/app.css'), 'asset URLs carry the prefix');
$t->same('/qroute/public', $sub->url(''), 'an empty path becomes the prefix itself');
$t->same('https://example.com/x', $sub->url('https://example.com/x'), 'absolute URLs are left alone');
$t->same('//cdn.example.com/x', $sub->url('//cdn.example.com/x'), 'protocol-relative URLs are left alone');

$root = Request::fake('GET', '/app', [], [], [], '');
$t->same('/app', $root->url('/app'), 'without a prefix URLs are unchanged');
$t->same('/', $root->url(''), 'an empty path at the root becomes /');

// The short-link URL baked into a printed code must include the prefix, or
// every code generated from a sub-directory install would 404 when scanned.
$subLink = Link::create($user->id(), Link::generateSlug(), 'https://example.com/sub');
$t->same(
    'https://qrt.test/qroute/public/' . $subLink->slug(),
    $subLink->shortUrl('https://qrt.test/qroute/public'),
    'a short URL is built from the full base including any prefix'
);
$t->same(
    'https://qrt.test/' . $subLink->slug(),
    $subLink->shortUrl('https://qrt.test'),
    'a short URL at the root has no prefix'
);
$t->same(
    'https://qrt.test/' . $subLink->slug(),
    $subLink->shortUrl('https://qrt.test/'),
    'a trailing slash on the base does not double up'
);

// baseUrl() must include the prefix when APP_URL is not configured, since
// that is what the QR encoder is handed.
$previousAppUrl = \QRoute\Core\Config::get('APP_URL', '');
\QRoute\Core\Config::set('APP_URL', '');
putenv('APP_URL');
unset($_ENV['APP_URL']);
$t->same(
    'https://localhost/qroute/public',
    Request::fake('GET', '/', [], [], [], '/qroute/public')->baseUrl(),
    'baseUrl falls back to host plus prefix'
);
\QRoute\Core\Config::set('APP_URL', (string) $previousAppUrl);

exit($t->finish());
