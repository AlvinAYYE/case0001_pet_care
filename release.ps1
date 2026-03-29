param (
    [Parameter(Mandatory=$true)]
    [ValidateSet("frontend", "backend", "all")]
    [string]$Type
)

$registryBase = "ghcr.io/alvinayye/case0001_pet_care"
$versionPath = Join-Path $PSScriptRoot "VERSION"
$frontendPkg = Join-Path $PSScriptRoot "frontend\package.json"

if (!(Test-Path $versionPath)) {
    Write-Error "VERSION file not found!"
    exit 1
}

# 1. Load and Increment Version
$currentVersion = Get-Content $versionPath
Write-Host "Current Version: $currentVersion"

$v = [version]$currentVersion
$newVersion = "$($v.Major).$($v.Minor).$($v.Build + 1)"
Write-Host "New Version: $newVersion"
Set-Content -Path $versionPath -Value $newVersion

# 2. Sync Frontend package.json if needed
if ($Type -eq "frontend" -or $Type -eq "all") {
    Write-Host "Syncing frontend/package.json..."
    $content = Get-Content $frontendPkg | ConvertFrom-Json
    $content.version = $newVersion
    $content | ConvertTo-Json -Depth 10 | Set-Content $frontendPkg
}

# Functions for Build, Test, Push
function Release-Component ($componentName, $dockerfileDir, $imageName) {
    $fullImageName = "${registryBase}-${componentName}"
    $tag = "${fullImageName}:${newVersion}"
    $latestTag = "${fullImageName}:latest"

    Write-Host "`n--- Releasing $componentName ---"
    
    # Build
    Write-Host "Building image $tag..."
    docker build -t $tag -t $latestTag $dockerfileDir
    if ($LASTEXITCODE -ne 0) { throw "Build failed for $componentName" }

    # Test (In-Container)
    Write-Host "Testing ${componentName} in container..."
    $projectName = "test-release-${componentName}"
    
    # Use alternative ports for testing to avoid conflicts
    $testBackendPort = 18080
    $testFrontendPort = 15180
    $env:BACKEND_PORT = $testBackendPort
    $env:FRONTEND_PORT = $testFrontendPort
    
    docker compose -p $projectName up -d --build
    
    Write-Host "Waiting for services to start..."
    Start-Sleep -Seconds 15
    
    # Basic check
    $testPath = if ($componentName -eq "backend") { "/api/health" } else { "/" }
    $testPort = if ($componentName -eq "backend") { $testBackendPort } else { $testFrontendPort }
    
    try {
        $response = Invoke-WebRequest -Uri "http://localhost:${testPort}${testPath}" -UseBasicParsing -ErrorAction Stop
        Write-Host "Health check passed for ${componentName}"
    } catch {
        docker compose -p $projectName down -v
        Remove-Item Env:BACKEND_PORT
        Remove-Item Env:FRONTEND_PORT
        throw "Health check failed for ${componentName} (at ${testPath}): $_"
    }
    
    docker compose -p $projectName down -v
    Remove-Item Env:BACKEND_PORT
    Remove-Item Env:FRONTEND_PORT

    # Push
    Write-Host "Pushing image $tag..."
    docker push $tag
    docker push $latestTag
    if ($LASTEXITCODE -ne 0) { Write-Warning "Push failed! Ensure you are logged in." }
}

# Execute
try {
    if ($Type -eq "backend" -or $Type -eq "all") {
        Release-Component "backend" "./backend" "backend"
    }
    if ($Type -eq "frontend" -or $Type -eq "all") {
        Release-Component "frontend" "./frontend" "frontend"
    }
    Write-Host "`nSuccessfully released $newVersion ($Type)"
} catch {
    Write-Error "Release failed: $_"
    # Optional: Rollback version file?
}
