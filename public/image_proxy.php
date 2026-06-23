<?php
error_reporting(0);

$url = $_GET['url'] ?? null;

// Handle Protocol-Relative URLs
if ($url && strpos($url, '//') === 0) {
    $url = 'https:' . $url;
}

function servePlaceholder() {
    header("Content-Type: image/svg+xml");
    header("Cache-Control: public, max-age=86400"); // Cache failures for 1 day
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="420" viewBox="0 0 300 420"><rect width="300" height="420" fill="#1a1f29"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="16" fill="#4b5563">No Cover</text></svg>';
    exit;
}

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    servePlaceholder();
}

$parsedUrl = parse_url($url);
$host = strtolower($parsedUrl['host'] ?? '');

// Bypass proxy entirely for MangaDex and Placeholders to avoid Cloudflare VPS bans
if (strpos($host, 'mangadex.org') !== false || strpos($host, 'placeholder.com') !== false) {
    header("Location: $url", true, 302);
    exit;
}

// --- 1. SERVER-SIDE CACHE SETUP ---
$cacheDir = __DIR__ . '/../cache/images/';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Garbage Collector: 10% chance to run on load
if (rand(1, 10) === 1) {
    $files = glob($cacheDir . '*.cache');
    if (is_array($files)) {
        $now = time();
        foreach ($files as $file) {
            if ($now - filemtime($file) > 86400) { // 1 day
                @unlink($file); 
                @unlink(str_replace('.cache', '.mime', $file));
            }
        }
    }
}

$hash = md5($url);
$cacheFile = $cacheDir . $hash . '.cache';
$mimeFile = $cacheDir . $hash . '.mime';

// --- 2. SERVE FROM LOCAL CACHE ---
if (file_exists($cacheFile) && file_exists($mimeFile) && filesize($cacheFile) > 100) {
    if (time() - filemtime($cacheFile) < 604800) { 
        $contentType = file_get_contents($mimeFile);
        header("Content-Type: $contentType");
        header("Cache-Control: public, max-age=604800");
        header("X-Cache: HIT"); 
        readfile($cacheFile);
        exit;
    }
}

// --- 3. FETCH IMAGE (Native cURL) ---
$parsedUrl = parse_url($url);
$host = strtolower($parsedUrl['host'] ?? '');

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

// Standard headers to impersonate a regular browser
$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'
];

// Set appropriate referers to bypass basic hotlink protection
if (strpos($host, 'weebcentral') !== false) {
    $headers[] = 'Referer: https://weebcentral.com/';
} elseif (strpos($host, 'comick') !== false) {
    $headers[] = 'Referer: https://comick.io/';
} 
// CRITICAL FIX: MangaPill uses external CDNs like readdetectiveconan.com
elseif (strpos($host, 'mangapill') !== false || strpos($host, 'readdetectiveconan') !== false) {
    $headers[] = 'Referer: https://mangapill.com/';
} 
// CRITICAL FIX: Force the main domain for Katana, even if the CDN is a subdomain (i1, i2, etc.)
elseif (strpos($host, 'mangakatana') !== false) {
    $headers[] = 'Referer: https://mangakatana.com/';
} 
elseif (strpos($host, 'mangadex') !== false) {
    $headers[] = 'Referer: https://mangadex.org/'; 
} else {
    $headers[] = 'Referer: https://' . $host . '/';
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// Ensure the request was successful and returned an actual image
if ($httpCode >= 400 || empty($data)) {
    $data = false;
} else {
    // CRITICAL FIX: If Katana maliciously mislabels the image as 'octet-stream', 
    // we use PHP finfo to sniff the raw bytes and find the REAL content type.
    if (strpos($contentType, 'image') === false) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->buffer($data);
        
        if (strpos($realMime, 'image') !== false) {
            $contentType = $realMime; // Fix the label (e.g., changes octet-stream to image/jpeg)
        } else {
            $data = false; // It's truly not an image
        }
    }
}

// --- 4. CACHE AND OUTPUT ---
if ($data && strlen($data) > 100) {
    file_put_contents($cacheFile, $data);
    file_put_contents($mimeFile, $contentType);

    header("Content-Type: $contentType");
    header("Cache-Control: public, max-age=604800"); 
    header("X-Cache: MISS");
    echo $data;
} else {
    servePlaceholder();
}
?>
