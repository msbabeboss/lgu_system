'-----------------------------------------------------------------
' LGU ARIMA API — Silent Auto-Start with Restart Guard
' Runs Python completely hidden (no CMD window)
' Restarts the API automatically if it crashes
'-----------------------------------------------------------------
Dim python, script, wsh, shell

python = "C:\Users\user\AppData\Local\Python\pythoncore-3.14-64\python.exe"
script = "C:\xampp\htdocs\lgu_system\python\arima_api.py"

Set wsh   = CreateObject("WScript.Shell")
Set shell = CreateObject("WScript.Shell")

Do
    ' 0 = hidden window, False = don't wait (non-blocking for loop)
    ' But we use .Run with bWaitOnReturn=True so we can restart on exit
    wsh.Run Chr(34) & python & Chr(34) & " " & Chr(34) & script & Chr(34), 0, True
    ' If we reach here, the process exited — wait 3 seconds then restart
    WScript.Sleep 3000
Loop
