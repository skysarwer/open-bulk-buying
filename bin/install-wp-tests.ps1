<#
.SYNOPSIS
	Prepares a disposable WordPress test environment for PHPUnit on Windows.

.DESCRIPTION
	- Uses the WordPress installation this plugin lives in (or -CoreDir).
	- Creates the test database with the mysql CLI when available; otherwise prints the SQL.
	- Writes tests\wp-tests-config.php.

	The test library itself comes from Composer (vendor\wp-phpunit). Run `composer install` first.
	THE TEST DATABASE IS WIPED ON EVERY TEST RUN.

.EXAMPLE
	.\bin\install-wp-tests.ps1
	.\bin\install-wp-tests.ps1 -DbName openbb_tests -DbUser root -DbPass '' -DbHost localhost
#>
param(
	[string] $DbName = 'openbb_tests',
	[string] $DbUser = 'root',
	[string] $DbPass = '',
	[string] $DbHost = 'localhost',
	[string] $CoreDir = '',
	[switch] $SkipDbCreate
)

$ErrorActionPreference = 'Stop'
$pluginDir = Split-Path -Parent $PSScriptRoot

if (-not $CoreDir) {
	$CoreDir = (Resolve-Path (Join-Path $pluginDir '..\..\..')).Path
}
if (-not (Test-Path (Join-Path $CoreDir 'wp-settings.php'))) {
	throw "No WordPress installation found at $CoreDir. Pass -CoreDir."
}
Write-Host "Using WordPress at $CoreDir"

if (-not $SkipDbCreate) {
	$sql = "CREATE DATABASE IF NOT EXISTS ``$DbName``"
	$mysql = Get-Command mysql -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source
	if (-not $mysql) {
		# WAMP keeps MySQL off PATH.
		$mysql = Get-ChildItem 'C:\wamp64\bin\mysql\*\bin\mysql.exe','C:\wamp64\bin\mariadb\*\bin\mysql.exe' -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
	}
	if ($mysql) {
		$mysqlArgs = @("--user=$DbUser", "--host=$DbHost", '-e', $sql)
		if ($DbPass) { $mysqlArgs = @("--password=$DbPass") + $mysqlArgs }
		Write-Host "Creating database $DbName"
		& $mysql @mysqlArgs
		if ($LASTEXITCODE -ne 0) { throw 'mysql failed to create the database.' }
	} else {
		Write-Warning "mysql CLI not found. Create the database yourself (e.g. in phpMyAdmin):`n  $sql"
	}
}

$coreForPhp = ($CoreDir -replace '\\', '/').TrimEnd('/')
$config = Get-Content (Join-Path $pluginDir 'tests\wp-tests-config-sample.php') -Raw
$config = $config -replace [regex]::Escape('dirname( __DIR__, 4 )'), "'$coreForPhp'"
$config = $config -replace "\?: 'openbb_tests'", "?: '$DbName'"
$config = $config -replace "\?: 'root'", "?: '$DbUser'"
$config = $config -replace "\?: '' \);", "?: '$DbPass' );"
$config = $config -replace "\?: 'localhost'", "?: '$DbHost'"
$target = Join-Path $pluginDir 'tests\wp-tests-config.php'
Set-Content -Path $target -Value $config -NoNewline
Write-Host "Wrote $target"

Write-Host "`nDone. Run the tests with: composer test"
