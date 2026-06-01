Set-Location "d:\Kir\nirva\public_html"

# Check for changes
$status = git status --porcelain 2>&1
if (-not $status) { exit 0 }

# Collect changed files
$staged   = git diff --cached --name-only 2>&1
$unstaged = git diff --name-only 2>&1
$untracked = git ls-files --others --exclude-standard 2>&1
$allFiles = ($staged + $unstaged + $untracked) | Where-Object { $_ -and $_.Trim() } | Sort-Object -Unique

# Build commit message from top-level dirs/features
$dirs = $allFiles | ForEach-Object {
    $parts = $_ -split '/'
    if ($parts.Count -ge 2) { "$($parts[0])/$($parts[1])" } else { $parts[0] }
} | Sort-Object -Unique | Select-Object -First 5

$summary = $dirs -join ', '
$count = $allFiles.Count
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm"

$msg = "[auto] Update $summary ($count files) - $timestamp"

git add -A
git commit -m $msg
git push origin main
