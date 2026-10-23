param(
  [string]$Root = (Get-Location).Path,
  [int]$Port = 8000
)

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
  throw 'PHP is required to run the OTP email endpoints. Install PHP and add it to PATH.'
}

if (-not (Test-Path -LiteralPath (Join-Path $Root '.env'))) {
  Write-Warning 'No .env file was found. Copy .env.example to .env and enter the server-only Firebase and Gmail OAuth values before testing email delivery.'
}

Write-Host "Serving $Root at http://localhost:$Port/ with PHP"
& $php.Source -S "127.0.0.1:$Port" -t $Root
