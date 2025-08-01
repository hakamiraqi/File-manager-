const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 3000;

const server = http.createServer((req, res) => {
    // Set CORS headers for WebRTC
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    // Handle OPTIONS request for CORS
    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }

    // Serve the video conference HTML file
    if (req.url === '/' || req.url === '/index.html') {
        fs.readFile(path.join(__dirname, 'video-conference.html'), (err, data) => {
            if (err) {
                res.writeHead(500, { 'Content-Type': 'text/html' });
                res.end(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Error</title>
                        <style>
                            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                            .error { color: red; }
                        </style>
                    </head>
                    <body>
                        <h1>Video Conference Application</h1>
                        <p class="error">Error: Could not load video-conference.html</p>
                        <p>Please ensure the file exists in the same directory as server.js</p>
                    </body>
                    </html>
                `);
            } else {
                res.writeHead(200, { 'Content-Type': 'text/html' });
                res.end(data);
            }
        });
    } else {
        // Handle 404 for other routes
        res.writeHead(404, { 'Content-Type': 'text/html' });
        res.end(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>404 - Not Found</title>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                </style>
            </head>
            <body>
                <h1>404 - Page Not Found</h1>
                <p>The requested page could not be found.</p>
                <a href="/">Go to Video Conference</a>
            </body>
            </html>
        `);
    }
});

server.listen(PORT, () => {
    console.log(`🎥 Video Conference Server running on http://localhost:${PORT}`);
    console.log(`📱 Mobile camera switching enabled`);
    console.log(`🚀 Open your browser and navigate to the URL above`);
    console.log(`💡 For mobile testing, use your device's IP address instead of localhost`);
});