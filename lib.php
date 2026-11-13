<?php

declare(strict_types=1);

function ir_root(): string
{
    return __DIR__;
}

function ir_path(string $rel): string
{
    return ir_root() . '/' . ltrim(str_replace('\\', '/', $rel), '/');
}

function ir_ensure_dirs(): void
{
    foreach (['data', 'feeds'] as $dir) {
        $path = ir_path($dir);
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

function ir_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $path = ir_path('config.php');
    $local = ir_path('config.local.php');
    $base = is_file($path) ? (require $path) : [];
    $over = is_file($local) ? (require $local) : [];
    if (!is_array($base)) {
        $base = [];
    }
    if (!is_array($over)) {
        $over = [];
    }
    $cfg = array_merge($base, $over);
    $tz = (string) ($cfg['timezone'] ?? 'Europe/Berlin');
    if ($tz !== '') {
        date_default_timezone_set($tz);
    }
    return $cfg;
}

function ir_cfg(string $key, mixed $default = null): mixed
{
    $cfg = ir_config();
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function ir_base_url(): string
{
    return rtrim((string) ir_cfg('base_url', ''), '/');
}

function ir_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * @return list<array{id:string,name:string,ical_url:string,site_url:string}>
 */
function ir_sources(): array
{
    $path = ir_path('sources.json');
    if (!is_file($path)) {
        return [];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($row['id'] ?? '')) ?? '';
        $id = trim($id, '-');
        $ical = trim((string) ($row['ical_url'] ?? ''));
        if ($id === '' || $ical === '') {
            continue;
        }
        $out[] = [
            'id' => strtolower($id),
            'name' => trim((string) ($row['name'] ?? $id)),
            'ical_url' => $ical,
            'site_url' => trim((string) ($row['site_url'] ?? '')),
        ];
    }
    return $out;
}

function ir_normalize_ical_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (stripos($url, 'webcal://') === 0) {
        $url = 'https://' . substr($url, strlen('webcal://'));
    }
    return $url;
}

function ir_feed_url(string $id): string
{
    $base = ir_base_url();
    $file = 'feeds/' . rawurlencode($id) . '.xml';
    return $base !== '' ? $base . '/' . $file : $file;
}

function ir_feed_path(string $id): string
{
    return ir_path('feeds/' . $id . '.xml');
}

function ir_status_path(): string
{
    return ir_path('data/status.json');
}

function ir_read_status(): array
{
    $path = ir_status_path();
    if (!is_file($path)) {
        return ['sources' => [], 'last_run' => null];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : ['sources' => [], 'last_run' => null];
}

function ir_write_status(array $status): void
{
    ir_ensure_dirs();
    file_put_contents(
        ir_status_path(),
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

/**
 * @return array<string, string|false>
 */
function ir_without_proxy_env(): array
{
    $keys = [
        'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
        'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
        'SOCKS_PROXY', 'SOCKS5_PROXY', 'socks_proxy', 'socks5_proxy',
    ];
    $restore = [];
    foreach ($keys as $key) {
        $restore[$key] = getenv($key);
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    putenv('NO_PROXY=*');
    putenv('no_proxy=*');
    return $restore;
}

/**
 * @param array<string, string|false> $restore
 */
function ir_restore_env(array $restore): void
{
    foreach ($restore as $key => $value) {
        if ($value === false || $value === null) {
            putenv($key);
            continue;
        }
        putenv($key . '=' . $value);
    }
}

/**
 * @return array{ok:bool,status:int,final_url:string,body:string,error:string}
 */
function ir_http_get(string $url): array
{
    $url = ir_normalize_ical_url($url);
    $timeout = max(5, (int) ir_cfg('request_timeout', 30));
    $ua = (string) ir_cfg('user_agent', 'iCalRssBridge/1.0');
    $headers = [
        'Accept: text/calendar, text/plain, application/ics, */*',
        'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
        'User-Agent: ' . $ua,
    ];
    $restore = ir_without_proxy_env();

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 8,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_PROXY => '',
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $err = curl_error($ch);
            if ($body === false) {
                return ['ok' => false, 'status' => $status, 'final_url' => $final ?: $url, 'body' => '', 'error' => $err ?: 'curl failed'];
            }
            return [
                'ok' => $status >= 200 && $status < 400 && is_string($body),
                'status' => $status,
                'final_url' => $final ?: $url,
                'body' => is_string($body) ? $body : '',
                'error' => ($status >= 200 && $status < 400) ? '' : ('HTTP ' . $status),
            ];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers),
                'follow_location' => 1,
                'max_redirects' => 8,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        $final = $url;
        $respHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : [];
        if (!is_array($respHeaders)) {
            $respHeaders = [];
        }
        foreach ($respHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
            }
            if (stripos($line, 'Location:') === 0) {
                $loc = trim(substr($line, 9));
                if ($loc !== '') {
                    $final = $loc;
                }
            }
        }
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'final_url' => $final, 'body' => '', 'error' => 'request failed'];
        }
        return [
            'ok' => $status === 0 || ($status >= 200 && $status < 400),
            'status' => $status ?: 200,
            'final_url' => $final,
            'body' => $body,
            'error' => '',
        ];
    } finally {
        ir_restore_env($restore);
    }
}

/**
 * Unfold ICS lines and return list of "NAME;params:value" content lines.
 *
 * @return list<string>
 */
function ir_ics_unfold(string $ics): array
{
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $raw = explode("\n", $ics);
    $lines = [];
    foreach ($raw as $line) {
        if ($line === '') {
            continue;
        }
        if ($lines && ($line[0] === ' ' || $line[0] === "\t")) {
            $lines[count($lines) - 1] .= substr($line, 1);
            continue;
        }
        $lines[] = $line;
    }
    return $lines;
}

/**
 * @return array{name:string,params:array<string,string>,value:string}
 */
function ir_ics_parse_property(string $line): array
{
    $parts = explode(':', $line, 2);
    $meta = $parts[0];
    $value = $parts[1] ?? '';
    $chunks = explode(';', $meta);
    $name = strtoupper((string) array_shift($chunks));
    $params = [];
    foreach ($chunks as $chunk) {
        if (strpos($chunk, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $chunk, 2);
        $params[strtoupper($k)] = $v;
    }
    $value = str_replace(['\\\\', '\\n', '\\N', '\\,', '\\;'], ['\\', "\n", "\n", ',', ';'], $value);
    return ['name' => $name, 'params' => $params, 'value' => $value];
}

function ir_parse_ics_datetime(string $value, array $params, DateTimeZone $tz): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = preg_replace('/^"|"$/', '', $value) ?? $value;

    // DATE only: YYYYMMDD
    if (preg_match('/^\d{8}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, $tz);
        return $dt ? $dt->getTimestamp() : null;
    }

    // UTC: YYYYMMDDThhmmssZ
    if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }

    // Local / floating / TZID
    if (preg_match('/^\d{8}T\d{6}$/', $value)) {
        $zone = $tz;
        if (!empty($params['TZID'])) {
            $tzid = trim($params['TZID'], '"');
            try {
                $zone = new DateTimeZone($tzid);
            } catch (Throwable) {
                $zone = $tz;
            }
        }
        $dt = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $zone);
        return $dt ? $dt->getTimestamp() : null;
    }

    try {
        return (new DateTimeImmutable($value, $tz))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return list<array{
 *   uid:string,title:string,start:int,end:?int,all_day:bool,
 *   location:string,description:string,url:string
 * }>
 */
function ir_parse_vevents(string $ics): array
{
    $tzName = (string) ir_cfg('timezone', 'Europe/Berlin');
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable) {
        $tz = new DateTimeZone('UTC');
    }

    $events = [];
    $cur = null;
    foreach (ir_ics_unfold($ics) as $line) {
        $prop = ir_ics_parse_property($line);
        $name = $prop['name'];
        if ($name === 'BEGIN' && strtoupper($prop['value']) === 'VEVENT') {
            $cur = [
                'uid' => '',
                'title' => '',
                'start' => 0,
                'end' => null,
                'all_day' => false,
                'location' => '',
                'description' => '',
                'url' => '',
            ];
            continue;
        }
        if ($cur === null) {
            continue;
        }
        if ($name === 'END' && strtoupper($prop['value']) === 'VEVENT') {
            if ($cur['title'] === '') {
                $cur['title'] = '(ohne Titel)';
            }
            if ($cur['start'] > 0) {
                if ($cur['uid'] === '') {
                    $cur['uid'] = 'hash:' . sha1($cur['title'] . '|' . $cur['start']);
                }
                $events[] = $cur;
            }
            $cur = null;
            continue;
        }

        switch ($name) {
            case 'UID':
                $cur['uid'] = trim($prop['value']);
                break;
            case 'SUMMARY':
                $cur['title'] = trim($prop['value']);
                break;
            case 'LOCATION':
                $cur['location'] = trim($prop['value']);
                break;
            case 'DESCRIPTION':
                $cur['description'] = trim($prop['value']);
                break;
            case 'URL':
                $cur['url'] = trim($prop['value']);
                break;
            case 'DTSTART':
                $ts = ir_parse_ics_datetime($prop['value'], $prop['params'], $tz);
                if ($ts !== null) {
                    $cur['start'] = $ts;
                    $cur['all_day'] = isset($prop['params']['VALUE']) && strtoupper($prop['params']['VALUE']) === 'DATE'
                        || preg_match('/^\d{8}$/', trim($prop['value']));
                }
                break;
            case 'DTEND':
                $ts = ir_parse_ics_datetime($prop['value'], $prop['params'], $tz);
                if ($ts !== null) {
                    $cur['end'] = $ts;
                }
                break;
        }
    }
    return $events;
}

/**
 * @param list<array{uid:string,title:string,start:int,end:?int,all_day:bool,location:string,description:string,url:string}> $events
 * @return list<array{uid:string,title:string,start:int,end:?int,all_day:bool,location:string,description:string,url:string}>
 */
function ir_filter_events(array $events): array
{
    $past = max(0, (int) ir_cfg('past_days', 7));
    $future = max(1, (int) ir_cfg('future_days', 180));
    $now = time();
    $from = $now - ($past * 86400);
    $to = $now + ($future * 86400);
    $out = [];
    foreach ($events as $ev) {
        $start = (int) $ev['start'];
        if ($start < $from || $start > $to) {
            continue;
        }
        $out[] = $ev;
    }
    usort($out, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
    $limit = max(1, (int) ir_cfg('feed_item_limit', 100));
    if (count($out) > $limit) {
        $out = array_slice($out, 0, $limit);
    }
    return $out;
}

function ir_format_when(array $ev): string
{
    $tzName = (string) ir_cfg('timezone', 'Europe/Berlin');
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable) {
        $tz = new DateTimeZone('UTC');
    }
    $start = (new DateTimeImmutable('@' . (int) $ev['start']))->setTimezone($tz);
    if (!empty($ev['all_day'])) {
        $label = $start->format('d.m.Y');
        if (!empty($ev['end'])) {
            // DTEND for all-day is exclusive
            $end = (new DateTimeImmutable('@' . ((int) $ev['end'] - 1)))->setTimezone($tz);
            if ($end->format('Y-m-d') !== $start->format('Y-m-d')) {
                $label .= ' – ' . $end->format('d.m.Y');
            }
        }
        return $label . ' (ganztägig)';
    }
    $label = $start->format('d.m.Y H:i');
    if (!empty($ev['end'])) {
        $end = (new DateTimeImmutable('@' . (int) $ev['end']))->setTimezone($tz);
        if ($end->format('Y-m-d') === $start->format('Y-m-d')) {
            $label .= '–' . $end->format('H:i');
        } else {
            $label .= ' – ' . $end->format('d.m.Y H:i');
        }
    }
    return $label . ' Uhr';
}

function ir_event_html(array $ev): string
{
    $parts = [];
    $parts[] = '<p><strong>' . ir_h(ir_format_when($ev)) . '</strong></p>';
    if ($ev['location'] !== '') {
        $parts[] = '<p>Ort: ' . ir_h($ev['location']) . '</p>';
    }
    if ($ev['description'] !== '') {
        $desc = preg_replace('/\n{3,}/', "\n\n", $ev['description']) ?? $ev['description'];
        $parts[] = '<p>' . nl2br(ir_h($desc), false) . '</p>';
    }
    if ($ev['url'] !== '') {
        $parts[] = '<p><a href="' . ir_h($ev['url']) . '">Zum Termin</a></p>';
    }
    return implode("\n", $parts);
}

/**
 * @param list<array{uid:string,title:string,start:int,end:?int,all_day:bool,location:string,description:string,url:string}> $events
 */
function ir_write_rss(array $source, array $events): string
{
    ir_ensure_dirs();
    $id = $source['id'];
    $feedUrl = ir_feed_url($id);
    $site = $source['site_url'] !== '' ? $source['site_url'] : ir_normalize_ical_url($source['ical_url']);
    $now = date(DATE_RSS);

    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    $rss = $xml->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
    $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
    $xml->appendChild($rss);
    $channel = $xml->createElement('channel');
    $rss->appendChild($channel);

    ir_xml_text($xml, $channel, 'title', $source['name']);
    ir_xml_text($xml, $channel, 'link', $site);
    ir_xml_text($xml, $channel, 'description', 'iCal → RSS: ' . $source['name']);
    ir_xml_text($xml, $channel, 'language', 'de');
    ir_xml_text($xml, $channel, 'lastBuildDate', $now);
    if ($feedUrl !== '' && str_starts_with($feedUrl, 'http')) {
        $atom = $xml->createElement('atom:link');
        $atom->setAttribute('href', $feedUrl);
        $atom->setAttribute('rel', 'self');
        $atom->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atom);
    }

    foreach ($events as $ev) {
        $item = $xml->createElement('item');
        ir_xml_text($xml, $item, 'title', $ev['title']);
        $link = $ev['url'] !== '' ? $ev['url'] : $site;
        ir_xml_text($xml, $item, 'link', $link);
        $guid = $xml->createElement('guid');
        $guid->setAttribute('isPermaLink', 'false');
        $guid->appendChild($xml->createTextNode($ev['uid']));
        $item->appendChild($guid);
        ir_xml_text($xml, $item, 'pubDate', date(DATE_RSS, (int) $ev['start']));
        ir_xml_text($xml, $item, 'category', 'calendar');

        $html = ir_event_html($ev);
        $desc = $xml->createElement('description');
        $desc->appendChild($xml->createCDATASection($html));
        $item->appendChild($desc);
        $content = $xml->createElement('content:encoded');
        $content->appendChild($xml->createCDATASection($html));
        $item->appendChild($content);
        $channel->appendChild($item);
    }

    $path = ir_feed_path($id);
    $xml->save($path);
    return $path;
}

function ir_xml_text(DOMDocument $xml, DOMElement $parent, string $name, string $value): void
{
    $el = $xml->createElement($name);
    $el->appendChild($xml->createTextNode($value));
    $parent->appendChild($el);
}

/**
 * @return array{ok:bool,id:string,name:string,events:int,bytes:int,error:string,feed:string}
 */
function ir_process_source(array $source): array
{
    $result = [
        'ok' => false,
        'id' => $source['id'],
        'name' => $source['name'],
        'events' => 0,
        'bytes' => 0,
        'error' => '',
        'feed' => ir_feed_url($source['id']),
    ];

    $resp = ir_http_get($source['ical_url']);
    $result['bytes'] = strlen($resp['body']);
    if (!$resp['ok'] || $resp['body'] === '') {
        $result['error'] = $resp['error'] !== '' ? $resp['error'] : 'empty response';
        return $result;
    }
    if (stripos($resp['body'], 'BEGIN:VCALENDAR') === false) {
        $result['error'] = 'response is not an iCalendar file';
        return $result;
    }

    $events = ir_filter_events(ir_parse_vevents($resp['body']));
    ir_write_rss($source, $events);
    $result['ok'] = true;
    $result['events'] = count($events);
    return $result;
}

/**
 * @return array{ok:bool,ran_at:string,results:list<array>}
 */
function ir_run_all(): array
{
    ir_ensure_dirs();
    $results = [];
    foreach (ir_sources() as $source) {
        $results[] = ir_process_source($source);
    }
    $status = [
        'last_run' => date('c'),
        'sources' => $results,
    ];
    ir_write_status($status);
    $ok = true;
    foreach ($results as $row) {
        if (empty($row['ok'])) {
            $ok = false;
            break;
        }
    }
    return ['ok' => $ok, 'ran_at' => $status['last_run'], 'results' => $results];
}

function ir_cron_authorized(): bool
{
    $key = (string) ir_cfg('cron_key', '');
    if ($key === '') {
        return true;
    }
    $got = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    return hash_equals($key, $got);
}
