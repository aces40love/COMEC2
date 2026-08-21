[CmdletBinding()]
param(
    [string]$Root = (Split-Path -Parent $PSScriptRoot),
    [ValidateSet('Repository', 'Public')]
    [string]$Scope = 'Repository',
    [string]$OutputPath = '',
    [string]$CompareTo = ''
)

$ErrorActionPreference = 'Stop'
$resolvedRoot = (Resolve-Path -LiteralPath $Root).Path.TrimEnd('\', '/')

$records = Get-ChildItem -LiteralPath $resolvedRoot -Recurse -File -Force -Name |
    Where-Object {
        $relative = $_.Replace('\', '/')
        if ($relative -match '(^|/)\.git(/|$)') {
            return $false
        }
        if ($Scope -eq 'Repository') {
            return $true
        }
        return $relative -match '^(?:[^/]+\.html|robots\.txt|sitemap\.xml|\.htaccess|\.nojekyll|(?:assets|api|app|admin)/)'
    } |
    ForEach-Object {
        $relative = $_.Replace('\', '/')
        $fullName = Join-Path $resolvedRoot $_
        [ordered]@{
            path = $relative
            bytes = (Get-Item -LiteralPath $fullName -Force).Length
            sha256 = (Get-FileHash -LiteralPath $fullName -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    } |
    Sort-Object path

$manifest = [ordered]@{
    format = 1
    scope = $Scope
    generated_utc = [DateTime]::UtcNow.ToString('o')
    file_count = @($records).Count
    files = @($records)
}
$json = $manifest | ConvertTo-Json -Depth 5

if ($OutputPath -ne '') {
    $outputFull = [IO.Path]::GetFullPath($OutputPath)
    $outputParent = Split-Path -Parent $outputFull
    if (-not (Test-Path -LiteralPath $outputParent -PathType Container)) {
        throw "Output directory does not exist: $outputParent"
    }
    [IO.File]::WriteAllText($outputFull, $json, [Text.UTF8Encoding]::new($false))
}

if ($CompareTo -ne '') {
    $referencePath = (Resolve-Path -LiteralPath $CompareTo).Path
    $reference = Get-Content -LiteralPath $referencePath -Raw | ConvertFrom-Json
    if ($reference.scope -ne $Scope) {
        throw "Manifest scope mismatch: expected $Scope but found $($reference.scope)."
    }

    $expected = @{}
    foreach ($file in $reference.files) {
        $expected[[string]$file.path] = [string]$file.sha256
    }
    $actual = @{}
    foreach ($file in $manifest.files) {
        $actual[[string]$file.path] = [string]$file.sha256
    }

    $differences = [System.Collections.Generic.List[object]]::new()
    foreach ($path in @($expected.Keys + $actual.Keys | Sort-Object -Unique)) {
        if (-not $expected.ContainsKey($path)) {
            $differences.Add([pscustomobject]@{ status = 'extra'; path = $path })
        } elseif (-not $actual.ContainsKey($path)) {
            $differences.Add([pscustomobject]@{ status = 'missing'; path = $path })
        } elseif ($expected[$path] -ne $actual[$path]) {
            $differences.Add([pscustomobject]@{ status = 'changed'; path = $path })
        }
    }

    if ($differences.Count -gt 0) {
        $differences | Format-Table -AutoSize | Out-String | Write-Output
        throw "Site verification failed with $($differences.Count) difference(s)."
    }
    Write-Output "Verified $($manifest.file_count) $Scope file(s): all SHA-256 hashes match."
} elseif ($OutputPath -eq '') {
    $json
}
