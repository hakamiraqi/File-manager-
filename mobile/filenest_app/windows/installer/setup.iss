; Inno Setup script for the FileNest Windows app (built by .github/workflows/mobile-apps.yml).
;   flutter build windows --release --dart-define=API_BASE_URL=https://files.azoclinic.duckdns.org/api/v1
;   ISCC.exe windows\installer\setup.iss /DMyAppVersion=1.0.1
#ifndef MyAppVersion
  #define MyAppVersion "1.0.0"
#endif

#define MyAppName "FileNest"
#define MyAppExeName "filenest_app.exe"
#define MyAppPublisher "FileNest"
; Fixed GUID: keep it the same between releases so a new version upgrades in place.
#define MyAppId "{4E2B7C1D-9A3F-4F6E-8B21-6C0D5A9E3F72}"

[Setup]
AppId={{#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\FileNest
DefaultGroupName={#MyAppName}
UninstallDisplayIcon={app}\{#MyAppExeName}
OutputDir=..\..\build\windows\installer
OutputBaseFilename=FileNestSetup-{#MyAppVersion}
SetupIconFile=..\runner\resources\app_icon.ico
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableWelcomePage=yes
CloseApplications=yes
RestartApplications=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"

[Files]
Source: "..\..\build\windows\x64\runner\Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch {#MyAppName}"; Flags: nowait postinstall
