Set-Location "d:\Kir\nirva\public_html"

# Check for changes
$status = & git status --porcelain 2>$null
if (-not $status) { exit 0 }

# Collect changed file names as plain strings
$allFiles = @()
$allFiles += (& git diff --cached --name-only 2>$null) | Where-Object { $_ -is [string] -and $_.Trim() }
$allFiles += (& git diff --name-only 2>$null) | Where-Object { $_ -is [string] -and $_.Trim() }
$allFiles += (& git ls-files --others --exclude-standard 2>$null) | Where-Object { $_ -is [string] -and $_.Trim() }
$allFiles = $allFiles | Sort-Object -Unique

# Build commit message from top-level dirs/features
$dirs = $allFiles | ForEach-Object {
    $parts = $_ -split '/'
    if ($parts.Count -ge 2) { "$($parts[0])/$($parts[1])" } else { $parts[0] }
} | Sort-Object -Unique | Select-Object -First 5

$summary = if ($dirs) { $dirs -join ', ' } else { 'misc' }
$count = $allFiles.Count
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm"

$msg = "[auto] Update $summary ($count files) - $timestamp"

& git add -A
& git commit -m $msg
& git push origin main
