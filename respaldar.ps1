# Respaldo de MecaniGest. Puede ejecutarse a mano o desde el Programador de tareas de Windows.
$ErrorActionPreference = 'Stop'
$phpMecaniGest = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $phpMecaniGest)) { throw 'No se encontró PHP. Instala XAMPP o ajusta la ruta del script.' }
Set-Location -LiteralPath $PSScriptRoot
& $phpMecaniGest 'scripts\respaldar.php'
if ($LASTEXITCODE -ne 0) { throw 'El respaldo no se completó. Verifica que MySQL esté activo.' }
