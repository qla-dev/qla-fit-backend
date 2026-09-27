<?php

// Apache rewrites /endpoints/* into /endpoints/public/index.php. Give
// Symfony the external script path so it detects the base URL correctly.
return static function (array $server): array {
    $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === '/endpoints' || str_starts_with($path ?: '', '/endpoints/')) {
        if ($path !== '/endpoints/public' && ! str_starts_with($path, '/endpoints/public/')) {
            $server['SCRIPT_NAME'] = '/endpoints/index.php';
            $server['PHP_SELF'] = '/endpoints/index.php';
            if ($path === '/endpoints') {
                $server['REQUEST_URI'] = '/endpoints/'.substr($server['REQUEST_URI'], strlen('/endpoints'));
            }
        }
    }

    return $server;
};
