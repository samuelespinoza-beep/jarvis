; Instalador del Asistente IA para Windows (Inno Setup 6).
; Lo compila instalador/windows/empaquetar.sh dentro de dist/windows.
; Se instala en la carpeta del usuario (no pide permisos de administrador,
; salvo para el runtime de Microsoft si la PC no lo tiene).

#ifndef Version
  #define Version "1.0.0"
#endif

[Setup]
AppId={{6A1F3C52-9B8E-4D7A-A2C4-5E0B7D19F3A8}
AppName=Asistente IA
AppVersion={#Version}
AppPublisher=Desarrollo Web
DefaultDirName={localappdata}\Jarvis
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=salida
OutputBaseFilename=Asistente-IA-{#Version}-windows
SetupIconFile=app\public\favicon.ico
UninstallDisplayIcon={app}\app\public\favicon.ico
UninstallDisplayName=Asistente IA
Compression=lzma2
SolidCompression=yes
WizardStyle=modern

[Languages]
Name: "es"; MessagesFile: "compiler:Languages\Spanish.isl"

[Tasks]
Name: "escritorio"; Description: "Crear un acceso directo en el escritorio"
Name: "alencender"; Description: "Dejar el Asistente IA listo en segundo plano al encender la PC"; Flags: unchecked

[InstallDelete]
; Accesos directos con el nombre anterior (Jarvis).
Type: files; Name: "{autoprograms}\Jarvis.lnk"
Type: files; Name: "{autoprograms}\Detener Jarvis.lnk"
Type: files; Name: "{autodesktop}\Jarvis.lnk"
Type: files; Name: "{userstartup}\Jarvis.lnk"
; Al actualizar se reemplaza el código, pero nunca los datos (.env, base de datos, audios).
Type: filesandordirs; Name: "{app}\app\app"
Type: filesandordirs; Name: "{app}\app\bootstrap\cache\*.php"
Type: filesandordirs; Name: "{app}\app\config"
Type: filesandordirs; Name: "{app}\app\database\migrations"
Type: filesandordirs; Name: "{app}\app\public\build"
Type: filesandordirs; Name: "{app}\app\resources"
Type: filesandordirs; Name: "{app}\app\routes"
Type: filesandordirs; Name: "{app}\app\vendor"
Type: filesandordirs; Name: "{app}\php"

[Files]
Source: "app\*"; DestDir: "{app}\app"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "php\*"; DestDir: "{app}\php"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "Jarvis.vbs"; DestDir: "{app}"; Flags: ignoreversion
Source: "Detener-Jarvis.vbs"; DestDir: "{app}"; Flags: ignoreversion
Source: "vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall; Check: FaltaRuntimeMicrosoft

[INI]
; Rutas absolutas: PHP se ejecuta desde la carpeta de la app, no desde la suya.
Filename: "{app}\php\php.ini"; Section: "PHP"; Key: "extension_dir"; String: """{app}\php\ext"""
Filename: "{app}\php\php.ini"; Section: "curl"; Key: "curl.cainfo"; String: """{app}\php\cacert.pem"""
Filename: "{app}\php\php.ini"; Section: "openssl"; Key: "openssl.cafile"; String: """{app}\php\cacert.pem"""

[Icons]
Name: "{autoprograms}\Asistente IA"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\Jarvis.vbs"""; IconFilename: "{app}\app\public\favicon.ico"
Name: "{autoprograms}\Detener Asistente IA"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\Detener-Jarvis.vbs"""
Name: "{autodesktop}\Asistente IA"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\Jarvis.vbs"""; IconFilename: "{app}\app\public\favicon.ico"; Tasks: escritorio
Name: "{userstartup}\Asistente IA"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\Jarvis.vbs"" /fondo"; Tasks: alencender

[Run]
Filename: "{tmp}\vc_redist.x64.exe"; Parameters: "/install /quiet /norestart"; StatusMsg: "Instalando componentes de Microsoft (Visual C++)…"; Flags: shellexec waituntilterminated; Check: FaltaRuntimeMicrosoft
Filename: "{app}\php\jarvis-php.exe"; Parameters: """{app}\app\artisan"" jarvis:instalar --no-interaction --llave=""{code:LlaveOpenAI}"""; WorkingDir: "{app}\app"; StatusMsg: "Preparando la base de datos…"; Flags: runhidden waituntilterminated
Filename: "{sys}\wscript.exe"; Parameters: """{app}\Jarvis.vbs"""; Description: "Abrir el Asistente IA"; Flags: postinstall nowait skipifsilent

[UninstallRun]
Filename: "{sys}\wscript.exe"; Parameters: """{app}\Detener-Jarvis.vbs"""; Flags: runhidden waituntilterminated; RunOnceId: "DetenerJarvis"

[Code]
var
  PaginaLlave: TInputQueryWizardPage;

function YaConfigurado(): Boolean;
begin
  Result := FileExists(ExpandConstant('{app}\app\.env'));
end;

procedure InitializeWizard();
begin
  PaginaLlave := CreateInputQueryPage(wpSelectTasks,
    'Llave de OpenAI',
    'El Asistente IA usa OpenAI para transcribir las reuniones, resumirlas y responder en el chat.',
    'Pega tu llave de OpenAI (empieza con sk-). Si estás actualizando el Asistente IA, puedes dejarla vacía para conservar la que ya tenías.');
  PaginaLlave.Add('Llave de OpenAI:', True);
end;

function LlaveOpenAI(Param: String): String;
begin
  Result := Trim(PaginaLlave.Values[0]);
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;

  if (CurPageID = PaginaLlave.ID) and (LlaveOpenAI('') = '') and not YaConfigurado() then
  begin
    MsgBox('Necesitas una llave de OpenAI para que el Asistente IA funcione.', mbError, MB_OK);
    Result := False;
  end;
end;

// PHP para Windows necesita el runtime de Visual C++ 2015-2022.
function FaltaRuntimeMicrosoft(): Boolean;
var
  Instalado: Cardinal;
begin
  Result := not (RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Installed', Instalado) and (Instalado = 1));
end;

// Cierra el Asistente IA antes de reemplazar sus archivos.
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Codigo: Integer;
begin
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM jarvis-php.exe', '', SW_HIDE, ewWaitUntilTerminated, Codigo);
  Result := '';
end;
