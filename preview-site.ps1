$ErrorActionPreference = "Stop"

$siteRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$port = 8765
$previewUrl = "http://127.0.0.1:$port/"
$serverProcess = $null
$startedServer = $false

try {
    $existingListener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue

    if (-not $existingListener) {
        $pythonCommand = Get-Command python -ErrorAction Stop
        $serverProcess = Start-Process `
            -FilePath $pythonCommand.Source `
            -ArgumentList @("-m", "http.server", "$port", "--bind", "127.0.0.1") `
            -WorkingDirectory $siteRoot `
            -WindowStyle Hidden `
            -PassThru
        $startedServer = $true

        Start-Sleep -Milliseconds 900
        if ($serverProcess.HasExited) {
            throw "The local preview server could not start."
        }
    }

    Start-Process $previewUrl
    Write-Host ""
    Write-Host "COMEC preview is open at $previewUrl" -ForegroundColor Cyan
    Write-Host "The video will play inside the page when you click Play." -ForegroundColor Green
    Write-Host "Leave this window open while reviewing the website."
    Write-Host ""
    Read-Host "Press Enter to close this preview"
}
catch {
    Write-Host ""
    Write-Host "Could not open the COMEC preview: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Install Python 3, then run this preview again."
    Read-Host "Press Enter to close"
}
finally {
    if ($startedServer -and $serverProcess -and -not $serverProcess.HasExited) {
        Stop-Process -Id $serverProcess.Id
    }
}
