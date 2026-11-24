$ErrorActionPreference = 'Stop'
$raizMecaniGest = $PSScriptRoot
$phpMecaniGest = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $phpMecaniGest)) { throw 'No se encontró PHP. Instala XAMPP o ajusta la ruta del script.' }
Set-Location -LiteralPath $raizMecaniGest
Write-Host 'MecaniGest - aplicación web local' -ForegroundColor Cyan
Write-Host 'Activa MySQL desde el panel de XAMPP antes de continuar.'
Write-Host 'Abre http://127.0.0.1:8097 en tu navegador.'
Write-Host 'Las credenciales iniciales están en storage/accesos-locales.txt.'
Write-Host 'Mantén esta ventana abierta. Ctrl+C detiene el servidor.'
& $phpMecaniGest -S 127.0.0.1:8097 -t public
