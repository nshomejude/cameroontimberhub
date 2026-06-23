<#
.SYNOPSIS
    Regenerate the deterministic parts of the design-system mirror so it can't drift
    from the real app. Run after any UI / token / email change.

.DESCRIPTION
    Default mode rebuilds:
      1. front-end assets (npm run build)
      2. design-system/shared/app.css  (copied from the freshly compiled public/build)
      3. emails/*.html                 (re-rendered from the real mailables)

    -Check mode performs NO writes; it exits 1 if shared/app.css is older than
    resources/css/app.css (i.e. the mirror is stale). Use it in a git pre-commit hook.

.EXAMPLE
    pwsh design-system/_build/regen.ps1
    pwsh design-system/_build/regen.ps1 -Check
#>
param([switch]$Check)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path "$PSScriptRoot\..\..").Path
Set-Location $root

$srcCss   = Join-Path $root 'resources/css/app.css'
$mirrorCss = Join-Path $root 'design-system/shared/app.css'

if ($Check) {
    if (-not (Test-Path $mirrorCss)) { Write-Host "design-system/shared/app.css missing — run regen."; exit 1 }
    $src = (Get-Item $srcCss).LastWriteTimeUtc
    $mir = (Get-Item $mirrorCss).LastWriteTimeUtc
    if ($src -gt $mir) {
        Write-Host "STALE: resources/css/app.css changed after design-system/shared/app.css."
        Write-Host "       Run:  pwsh design-system/_build/regen.ps1"
        exit 1
    }
    Write-Host "design-system mirror CSS is up to date."
    exit 0
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) { $php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' }

Write-Host "[1/3] Building front-end assets (npm run build)..."
npm run build | Out-Null

Write-Host "[2/3] Copying compiled Tailwind -> design-system/shared/app.css"
$built = Get-ChildItem (Join-Path $root 'public/build/assets/app-*.css') | Select-Object -First 1
Copy-Item $built.FullName $mirrorCss -Force
Write-Host "      $($built.Name) -> shared/app.css ($([math]::Round((Get-Item $mirrorCss).Length/1kb)) KB)"

Write-Host "[3/3] Re-rendering transactional emails (branded theme)..."
& $php artisan config:clear | Out-Null
& $php artisan tinker 'design-system/_build/render-emails.php' | Out-Null

Write-Host ""
Write-Host "Done. Deterministic parts regenerated."
Write-Host "NOTE: the component / screen / admin / dark-mode CARDS are authored by Claude"
Write-Host "      workflows (_build/build-cards.mjs, build-admin-cards.mjs, build-dark-mode.mjs)."
Write-Host "      Re-run the relevant workflow via Claude if you changed those components."
