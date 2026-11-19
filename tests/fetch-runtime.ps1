$ErrorActionPreference='Stop'
$cache=Join-Path $PSScriptRoot 'runtime-cache'
$packages=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'runtime-packages.json') -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path $cache -Force | Out-Null
foreach($entry in $packages.PSObject.Properties) {
    $spec=$entry.Value
    $url=[System.Uri]$spec.url
    if($url.Scheme -ne 'https' -or $url.Host -ne 'downloads.wordpress.org' -or [System.IO.Path]::GetFileName($spec.file) -ne $spec.file) { throw 'Unexpected package source or filename.' }
    $target=Join-Path $cache $spec.file
    if(-not(Test-Path -LiteralPath $target)) {
        $partial=$target+'.partial'
        if(Test-Path -LiteralPath $partial) { throw "Partial download already exists: $partial" }
        try {
            Invoke-WebRequest -Uri $spec.url -OutFile $partial -TimeoutSec 60
            if((Get-FileHash -LiteralPath $partial -Algorithm SHA256).Hash -ne $spec.sha256) { throw "Package hash mismatch: $($entry.Name)" }
            Move-Item -LiteralPath $partial -Destination $target
        } finally { if(Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial } }
    }
    if((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $spec.sha256) { throw "Cached package hash mismatch: $($entry.Name)" }
    Write-Output "Verified cached $($entry.Name) $($spec.version)."
}
