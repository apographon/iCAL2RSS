<?php

declare(strict_types=1);

/**
 * iCal → RSS Bridge — settings.
 *
 * base_url: public URL of this folder, no trailing slash.
 *           Example: https://example.com/ical-rss
 * cron_key: optional. If set, HTTP cron needs ?key=…
 */
return [
    'base_url' => '',
    'timezone' => 'Europe/Berlin',
    'cron_key' => '',
    'user_agent' => 'Mozilla/5.0 (compatible; iCalRssBridge/1.0; +https://freshrss.org)',
    'request_timeout' => 30,
    'past_days' => 7,
    'future_days' => 180,
    'feed_item_limit' => 100,
];
