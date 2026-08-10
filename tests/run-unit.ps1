$phpunit = Join-Path $PSScriptRoot "..\vendor\bin\phpunit.bat"

& $phpunit "--configuration" "phpunit.xml.dist" "--no-coverage"
