# Queue Worker Supervisor Script - Multi-Worker Parallel Processing
# Runs multiple queue workers simultaneously for true concurrent job processing

$projectPath = "d:\02-Coding\SEO Validation App\seo-workbook-verifier"
$logFile = "$projectPath\storage\logs\queue-worker.log"
$workerCount = 4  # Run 4 parallel workers for true concurrent processing
$workers = @()
$monitoringActive = $true

Write-Host "Starting Queue Worker Supervisor (Multi-Worker Mode)..." -ForegroundColor Green
Write-Host "Project: $projectPath" -ForegroundColor Cyan
Write-Host "Workers: $workerCount (true concurrent processing)" -ForegroundColor Cyan
Write-Host "Log file: $logFile" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Green

# Ensure log file exists
if (-not (Test-Path $logFile)) {
    New-Item -Path $logFile -Force | Out-Null
}

function Log-Message {
    param([string]$message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Write-Host "[$timestamp] $message" -ForegroundColor Cyan
    Add-Content -Path $logFile -Value "[$timestamp] $message"
}

# Graceful shutdown handler
$null = Register-EngineEvent -SourceIdentifier PowerShell.Exiting -Action {
    Write-Host "`nStopping all queue workers..." -ForegroundColor Yellow
    foreach ($worker in $workers) {
        if ($worker.Process -and -not $worker.Process.HasExited) {
            try {
                Stop-Process -InputObject $worker.Process -Force -ErrorAction SilentlyContinue
                Log-Message "Worker #$($worker.WorkerId) stopped (PID: $($worker.PID))"
            } catch {}
        }
    }
}

Log-Message "Initializing $workerCount parallel queue workers..."

# Start initial worker pool
for ($i = 1; $i -le $workerCount; $i++) {
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Write-Host "[$timestamp] Starting worker #$i..." -ForegroundColor Yellow
    
    try {
        $process = Start-Process -FilePath "php" -ArgumentList "artisan", "queue:work", "--timeout=2700" `
            -WorkingDirectory $projectPath `
            -PassThru `
            -NoNewWindow
        
        $workers += @{
            WorkerId = $i
            Process = $process
            PID = $process.Id
            StartTime = Get-Date
            RestartCount = 0
        }
        
        Log-Message "Worker #$i started (PID: $($process.Id))"
        Start-Sleep -Milliseconds 500  # Stagger startups
        
    } catch {
        Log-Message "ERROR starting worker #$i : $_"
    }
}

Log-Message "All workers initialized. Monitoring for crashes..."
Log-Message "======================================`n"

# Monitor and restart workers on crash
$monitorLoop = 0
while ($monitorLoop -lt 9999) {
    $monitorLoop++
    Start-Sleep -Seconds 3
    
    for ($i = 0; $i -lt $workers.Count; $i++) {
        $worker = $workers[$i]
        
        if ($worker.Process.HasExited) {
            $worker.RestartCount++
            $uptime = (Get-Date) - $worker.StartTime
            Log-Message "Worker #$($worker.WorkerId) exited (PID: $($worker.PID), Uptime: $($uptime.TotalSeconds)s, Restart attempt: $($worker.RestartCount))"
            
            # Restart the crashed worker
            try {
                $newProcess = Start-Process -FilePath "php" -ArgumentList "artisan", "queue:work", "--timeout=2700" `
                    -WorkingDirectory $projectPath `
                    -PassThru `
                    -NoNewWindow
                
                $workers[$i] = @{
                    WorkerId = $worker.WorkerId
                    Process = $newProcess
                    PID = $newProcess.Id
                    StartTime = Get-Date
                    RestartCount = $worker.RestartCount
                }
                
                Log-Message "Worker #$($worker.WorkerId) restarted (New PID: $($newProcess.Id))"
                
            } catch {
                Log-Message "ERROR restarting worker #$($worker.WorkerId) : $_"
            }
            
            Start-Sleep -Seconds 1
        }
    }
}
