<?php
$files = glob(__DIR__ . '/public/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    $newContent = preg_replace(
        '/session_save_path\(\$sessionPath\);/s',
        'if (is_writable($sessionPath)) { session_save_path($sessionPath); }',
        $content
    );
    $newContent = preg_replace(
        '/mkdir\(\$sessionPath,\s*0755,\s*true\);/s',
        '@mkdir($sessionPath, 0777, true);',
        $newContent
    );
    if ($content !== $newContent) {
        file_put_contents($file, $newContent);
        echo "Fixed $file\n";
    }
}
echo "Done";
