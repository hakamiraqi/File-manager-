# 🎥 Video Conference with Mobile Camera Switching

A real-time video conferencing application built with RTCMultiConnection that supports mobile camera switching between front and back cameras.

## ✨ Features

- **Real-time Video Conferencing**: Multi-party video calls using WebRTC
- **Mobile Camera Switching**: Switch between front and back cameras on mobile devices
- **Modern UI**: Beautiful, responsive design with gradient backgrounds
- **Room-based System**: Create or join conference rooms with unique IDs
- **Cross-platform**: Works on desktop and mobile devices
- **Automatic Mobile Detection**: Camera switching button only appears on mobile devices

## 🚀 Quick Start

### Option 1: Direct HTML File
1. Open `video-conference.html` in a modern web browser
2. Allow camera and microphone permissions when prompted
3. Click "Start Conference" to create a room or "Join Room" to join an existing one

### Option 2: PHP Server
1. Start a PHP server in the project directory:
   ```bash
   php -S localhost:8000
   ```
2. Open `http://localhost:8000` in your browser

### Option 3: Any Web Server
- Place the files on any web server (Apache, Nginx, etc.)
- Access via HTTPS (required for camera access in most browsers)

## 📱 Mobile Camera Switching

### How it Works
- The app automatically detects mobile devices
- A "Switch Camera" button appears only on mobile devices
- Click the button to toggle between front and back cameras
- The switch happens seamlessly during active calls

### Supported Devices
- iOS Safari (iPhone/iPad)
- Android Chrome
- Android Firefox
- Other mobile browsers with camera support

## 🎯 Usage Instructions

### Starting a Conference
1. Click "🚀 Start Conference"
2. Allow camera and microphone permissions
3. Share the generated Room ID with others
4. Wait for participants to join

### Joining a Conference
1. Click "🔗 Join Room"
2. Enter the Room ID provided by the host
3. Allow camera and microphone permissions
4. You'll be connected to the conference

### Switching Cameras (Mobile Only)
1. Ensure you're on a mobile device
2. Click "📷 Switch Camera" button
3. The camera will switch between front and back
4. The switch is applied to all participants in real-time

### Stopping the Conference
1. Click "⏹️ Stop" to end the conference
2. All streams will be closed
3. You can start a new conference or join another room

## 🔧 Technical Details

### Technologies Used
- **RTCMultiConnection**: WebRTC library for peer-to-peer connections
- **WebRTC**: Real-time communication protocol
- **HTML5**: Video and audio elements
- **CSS3**: Modern styling with gradients and animations
- **JavaScript ES6+**: Modern JavaScript with async/await

### Browser Requirements
- Modern browser with WebRTC support
- HTTPS connection (required for camera access)
- Camera and microphone permissions

### Network Requirements
- Stable internet connection
- No firewall blocking WebRTC traffic
- STUN/TURN servers for NAT traversal

## 🛠️ Customization

### Changing Signaling Server
Edit the `socketURL` in the `setupConnection()` method:
```javascript
this.connection.socketURL = 'your-signaling-server-url';
```

### Modifying Video Quality
Adjust the video constraints in `startConference()` and `joinConference()`:
```javascript
video: {
    width: { ideal: 1280 },  // Change resolution
    height: { ideal: 720 },
    facingMode: this.currentCamera
}
```

### Styling Changes
Modify the CSS in the `<style>` section to customize the appearance.

## 🔒 Security Considerations

- Always use HTTPS in production
- Implement proper user authentication if needed
- Consider rate limiting for room creation
- Validate room IDs to prevent unauthorized access

## 🐛 Troubleshooting

### Camera Not Working
- Ensure HTTPS is enabled
- Check browser permissions
- Try refreshing the page
- Verify camera is not in use by another application

### Connection Issues
- Check internet connection
- Verify firewall settings
- Try a different browser
- Check if signaling server is accessible

### Mobile Camera Switching Not Working
- Ensure you're on a mobile device
- Check if device has multiple cameras
- Try refreshing the page
- Verify browser supports `facingMode` constraint

## 📄 License

This project is open source and available under the MIT License.

## 🤝 Contributing

Feel free to submit issues and enhancement requests!

## 📞 Support

For technical support or questions, please open an issue in the project repository.

---

**Note**: This application uses the RTCMultiConnection library which requires a signaling server. The demo uses a public server, but for production use, consider setting up your own signaling server.