$ErrorActionPreference = "Stop"

$currentDir = $PSScriptRoot
if (-not $currentDir) { $currentDir = (Get-Location).Path }

$dateStr = Get-Date -Format "yyyyMMdd"
$distDir = Join-Path $currentDir "dist"
$tempStaging = Join-Path $distDir "staging"
$zipPath = Join-Path $distDir "line_system_clean_package_$dateStr.zip"

Write-Host "=== Generating Clean Distribution Package ===" -ForegroundColor Cyan

# 1. Reset staging directory
if (Test-Path $tempStaging) {
    Remove-Item -Recurse -Force $tempStaging
}
if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}
New-Item -ItemType Directory -Path $tempStaging | Out-Null

# 2. Copy directories and files
Write-Host "--> Copying source files..." -ForegroundColor Green
Copy-Item -Path (Join-Path $currentDir "public_html") -Destination $tempStaging -Recurse
Copy-Item -Path (Join-Path $currentDir "batch") -Destination $tempStaging -Recurse

$setupGuide = Join-Path $currentDir "SETUP_GUIDE.md"
if (Test-Path $setupGuide) {
    Copy-Item -Path $setupGuide -Destination $tempStaging
}
$readme = Join-Path $currentDir "README.md"
if (Test-Path $readme) {
    Copy-Item -Path $readme -Destination $tempStaging
}

# 3. Clean databases and personal data
Write-Host "--> Cleaning database and customer images..." -ForegroundColor Yellow
Get-ChildItem -Path $tempStaging -Include *.db, *.sqlite, *.log -Recurse -File | Remove-Item -Force

# Replace account credentials with sample
$dataDir = Join-Path $tempStaging "public_html\data"
$accountsFile = Join-Path $dataDir "line_accounts.json"
$sampleAccounts = Join-Path $dataDir "line_accounts.sample.json"
if (Test-Path $sampleAccounts) {
    Copy-Item -Path $sampleAccounts -Destination $accountsFile -Force
}

# Clean uploaded custom images
$uploadsRichmenu = Join-Path $tempStaging "public_html\uploads\richmenu"
if (Test-Path $uploadsRichmenu) {
    Get-ChildItem -Path $uploadsRichmenu -Include custom_* -File | Remove-Item -Force
}

# Clean development scripts
Get-ChildItem -Path $tempStaging -Include *.ps1, *.py, .DS_Store, Thumbs.db -Recurse -File | Remove-Item -Force

# 4. Create ZIP
Write-Host "--> Creating ZIP package: $zipPath" -ForegroundColor Green
if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}
Compress-Archive -Path "$tempStaging\*" -DestinationPath $zipPath -CompressionLevel Optimal

# 5. Remove staging
Remove-Item -Recurse -Force $tempStaging

Write-Host "=== Package Created Successfully: $zipPath ===" -ForegroundColor Green
