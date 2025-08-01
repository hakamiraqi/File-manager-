<?php
// Simple PHP server for video conference
header('Content-Type: text/html; charset=utf-8');

// Check if video-conference.html exists
if (file_exists('video-conference.html')) {
    readfile('video-conference.html');
} else {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Video Conference</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error { color: red; }
        </style>
    </head>
    <body>
        <h1>Video Conference Application</h1>
        <p class="error">Error: video-conference.html file not found!</p>
        <p>Please ensure the video-conference.html file exists in the same directory.</p>
    </body>
    </html>';
}
?>
