$pluginDir  = $PSScriptRoot
$pluginSlug = "smart-image-matcher"
$zipName    = "$pluginSlug.zip"
$zipPath    = Join-Path $pluginDir $zipName

# Folders/files to exclude from the zip
$excludes = @(
    ".git",
    ".legacy",
    ".cursor",
    ".kiro",
    ".wp-ai",
    ".agent-skills",
    "node_modules",
    "tests",
    "docs",
    "build-zip.ps1",
    "phpcs.xml.dist",
    "phpstan.neon.dist",
    "phpunit.xml.dist",
    ".editorconfig",
    ".gitignore",
    ".plugincheckignore",
    ".phpunit.cache",
    ".phpunit.result.cache",
    "activitylog.txt",
    "IMPLEMENTATION_PLAN.md",
    "agents.md",
    "development.md",
    "package.json",
    "*.zip",
    # License check and upgrade-link UI — excluded from the free build.
    "src\Premium\License.php",
    "src\UI\PremiumLock.php"
)

# Remove old zip if it exists
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')

Get-ChildItem -Path $pluginDir -Recurse -File | ForEach-Object {
    $relativePath = $_.FullName.Substring($pluginDir.Length + 1)

    # Check if this file is under any excluded path
    $skip = $false
    foreach ($ex in $excludes) {
        if ($relativePath -like "$ex*" -or $relativePath -like "$ex\*") {
            $skip = $true
            break
        }
    }

    if (-not $skip) {
        $entryName = "$pluginSlug/$($relativePath -replace '\\','/')"
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}

$zip.Dispose()

$size = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host "Built: $zipPath ($size MB)"
Write-Host "Contents preview (top-level):"
[System.IO.Compression.ZipFile]::OpenRead($zipPath).Entries |
    Where-Object { ($_.FullName -split '/').Count -le 2 } |
    Select-Object -ExpandProperty FullName |
    Sort-Object -Unique |
    ForEach-Object { Write-Host "  $_" }
