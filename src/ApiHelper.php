<?php
function fetchMangadexApi($url) {
    // Enforce rate limiting centrally (max 4 requests/sec) to protect your IP
    usleep(250000);

    // --- 1. CACHE CONFIGURATION ---
    $cacheDir = __DIR__ . '/../cache/';
    $cacheTime = 4 * 3600; // 4 hours in seconds (14400)

    // Automatically create the cache folder if it doesn't exist yet
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Create a unique, safe filename based on the requested URL
    $cacheFile = $cacheDir . md5($url) . '.json';

    // --- 2. CHECK EXISTING CACHE ---
    // If the file exists AND it is less than 4 hours old, serve it instantly!
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cachedData = file_get_contents($cacheFile);
        $decodedData = json_decode($cachedData, true);
        
        // Double check the cache isn't accidentally a blank error array
        if (!empty($decodedData)) {
            return $decodedData;
        }
    }

    // --- 3. FETCH FRESH DATA ---
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Quick timeouts so a dead node doesn't hang the whole website
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    curl_setopt($ch, CURLOPT_USERAGENT, 'ComixKini/3.0');
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("cURL Error in ApiHelper: " . curl_error($ch));
        
        // FAILSAFE: If Node is dead/banned, but we have an expired cache file, 
        // serve the expired cache anyway so the user's page doesn't break!
        if (file_exists($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    $data = json_decode($response, true);

    // --- 4. SAVE FRESH DATA TO CACHE ---
    // Only save the file if WeebCentral actually gave us a real result, 
    // ensuring we never accidentally cache a blank array or an error message!
    if (!empty($data) && !isset($data['error'])) {
        file_put_contents($cacheFile, $response);
    }

    return $data;
}
?>
