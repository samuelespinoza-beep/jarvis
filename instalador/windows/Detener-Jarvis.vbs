' Detiene el Asistente IA: cierra el servidor y la cola (solo los procesos de Jarvis).
Option Explicit

Dim proceso
For Each proceso In GetObject("winmgmts:\\.\root\cimv2").ExecQuery("SELECT * FROM Win32_Process WHERE Name = 'jarvis-php.exe'")
    proceso.Terminate
Next
