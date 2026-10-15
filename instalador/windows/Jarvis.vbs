' Abre el Asistente IA: arranca el servidor y la cola (ocultos, sin ventanas negras)
' si no están corriendo y abre el navegador. Con /fondo no abre el navegador
' (se usa al encender la PC).
Option Explicit

Dim sh, fso, carpeta, php, app, wmi
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
Set wmi = GetObject("winmgmts:\\.\root\cimv2")

carpeta = fso.GetParentFolderName(WScript.ScriptFullName)
php = """" & carpeta & "\php\jarvis-php.exe"""
app = carpeta & "\app"
sh.Environment("PROCESS")("PHPRC") = carpeta & "\php"

If Not Corriendo("-S 127.0.0.1:8073") Then
    sh.CurrentDirectory = app & "\public"
    sh.Run php & " -S 127.0.0.1:8073 """ & app & "\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php""", 0, False
End If

If Not Corriendo("queue:work") Then
    sh.CurrentDirectory = app
    sh.Run php & " artisan queue:work --sleep=1 --timeout=600", 0, False
End If

If WScript.Arguments.Count = 0 Then
    EsperarServidor
    sh.Run "http://127.0.0.1:8073"
End If

' ¿Hay un proceso de Jarvis con este texto en su línea de comandos?
Function Corriendo(texto)
    Dim proceso
    Corriendo = False
    For Each proceso In wmi.ExecQuery("SELECT CommandLine FROM Win32_Process WHERE Name = 'jarvis-php.exe'")
        If InStr(1, proceso.CommandLine & "", texto, vbTextCompare) > 0 Then Corriendo = True
    Next
End Function

' Espera hasta 15 segundos a que el servidor responda.
Sub EsperarServidor()
    Dim intento, http
    For intento = 1 To 30
        On Error Resume Next
        Set http = CreateObject("MSXML2.ServerXMLHTTP")
        http.Open "GET", "http://127.0.0.1:8073/up", False
        http.Send
        If Err.Number = 0 Then
            If http.Status = 200 Then Exit Sub
        End If
        Err.Clear
        On Error GoTo 0
        WScript.Sleep 500
    Next
End Sub
