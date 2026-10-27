$ErrorActionPreference = "Stop"

# Run from the root of a freshly cloned C2ME-ORGA/fabrication repository.
# This script preserves docs/, scripts/, and the existing Git checkout.
$repoRoot = (Get-Location).Path
if (-not (Test-Path (Join-Path $repoRoot ".git"))) {
    throw "Run this script from the root of the cloned fabrication Git repository."
}
if ((Test-Path (Join-Path $repoRoot "artisan")) -or (Test-Path (Join-Path $repoRoot "composer.json"))) {
    throw "A Laravel/composer project already exists here. Stop to avoid overwriting files."
}

$php = Get-Command php -ErrorAction SilentlyContinue
$composer = Get-Command composer -ErrorAction SilentlyContinue
if (-not $php) { throw "PHP is required. Install/configure PHP 8.3+ (use a Laravel 13-supported version)." }
if (-not $composer) { throw "Composer is required and must be available in PATH." }

$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("fabrication-laravel-" + [guid]::NewGuid().ToString("N"))
$templatePath = Join-Path $tempRoot "app"

try {
    New-Item -ItemType Directory -Path $tempRoot | Out-Null
    Write-Host "Creating a clean Laravel 13 project in a temporary directory..."
    composer create-project laravel/laravel $templatePath "^13.0"
    if ($LASTEXITCODE -ne 0) { throw "composer create-project failed." }

    # Copy the Laravel skeleton, excluding generated dependencies, local secrets,
    # and files already maintained by this repository.
    $excluded = @(".git", "vendor", ".env", "docs", "scripts", "README.md")
    Get-ChildItem -Force $templatePath | Where-Object { $excluded -notcontains $_.Name } | ForEach-Object {
        $destination = Join-Path $repoRoot $_.Name
        if (Test-Path $destination) {
            throw "Destination already exists: $destination. Nothing was overwritten after the conflict was detected."
        }
        Copy-Item -Path $_.FullName -Destination $destination -Recurse
    }

    Write-Host "Installing Livewire 4 and FilamentPHP 5..."
    Push-Location $repoRoot
    try {
        composer require "livewire/livewire:~4.2" "filament/filament:~5.4"
        if ($LASTEXITCODE -ne 0) { throw "composer require failed. Review the dependency error before retrying." }

        php artisan filament:install --panels
        if ($LASTEXITCODE -ne 0) { throw "Filament panel installation failed." }

        if (-not (Test-Path ".env")) {
            Copy-Item ".env.example" ".env"
            php artisan key:generate
            if ($LASTEXITCODE -ne 0) { throw "Laravel key generation failed." }
        }

        Write-Host ""
        Write-Host "Bootstrap completed. Next:"
        Write-Host "1. Configure your database in .env."
        Write-Host "2. Create the first administrator according to the Filament version's setup flow."
        Write-Host "3. Run php artisan migrate."
        Write-Host "4. Run php artisan test."
        Write-Host "5. Review the changes, then commit composer.json, composer.lock and the application skeleton."
    }
    finally {
        Pop-Location
    }
}
finally {
    if (Test-Path $tempRoot) { Remove-Item $tempRoot -Recurse -Force }
}
