<?php

return [
    'dashboard_cache_seconds' => (int) env('DASHBOARD_CACHE_SECONDS', 120),
    'reports_cache_seconds' => (int) env('REPORTS_CACHE_SECONDS', 180),
    'ui_catalog_cache_minutes' => (int) env('UI_CATALOG_CACHE_MINUTES', 30),
    'ui_recent_customers_cache_minutes' => (int) env('UI_RECENT_CUSTOMERS_CACHE_MINUTES', 15),
    'ui_coils_cache_seconds' => (int) env('UI_COILS_CACHE_SECONDS', 90),
];
