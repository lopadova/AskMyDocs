param(
    [Parameter(Mandatory = $false)]
    [string]$Repo = "lopadova/AskMyDocs",

    [Parameter(Mandatory = $false)]
    [string]$BaseBranch = "feature/v8.0",

    [Parameter(Mandatory = $false)]
    [string]$Goal = "100% roadmap completion",

    [Parameter(Mandatory = $false)]
    [string]$PromptTemplatePath = "docs/v4-platform/AUTOMODE-PROMPT-TEMPLATE.md",

    [Parameter(Mandatory = $false)]
    [string]$CheckpointPath = "docs/v4-platform/STATUS-2026-05-20-v80-automode.md",

    [Parameter(Mandatory = $false)]
    [string]$DispatchCommand = "",

    [Parameter(Mandatory = $false)]
    [int]$PollSeconds = 90,

    [Parameter(Mandatory = $false)]
    [int]$MaxRuntimeMinutes = 45,

    [Parameter(Mandatory = $false)]
    [int]$MaxCheckpointStaleMinutes = 20
)

$ErrorActionPreference = "Stop"

function Get-IsoNow {
    return (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
}

function Get-WorkspaceRoot {
    return (Resolve-Path ".").Path
}

function Ensure-Dir {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Get-RepoStatePaths {
    $root = Get-WorkspaceRoot
    $hash = (New-Object System.Security.Cryptography.SHA256Managed).ComputeHash([Text.Encoding]::UTF8.GetBytes($root))
    $hex = -join ($hash | ForEach-Object { $_.ToString("x2") })
    $dir = Join-Path $root ".automode"
    Ensure-Dir $dir
    return @{
        Dir = $dir
        State = Join-Path $dir "state-$hex.json"
        Lock = Join-Path $dir "lock-$hex.lck"
    }
}

function Read-JsonFile {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    $raw = Get-Content -Path $Path -Raw
    if ([string]::IsNullOrWhiteSpace($raw)) { return $null }
    return ($raw | ConvertFrom-Json)
}

function Write-JsonFile {
    param([string]$Path, [object]$Obj)
    $Obj | ConvertTo-Json -Depth 10 | Set-Content -Path $Path
}

function Acquire-Lock {
    param([string]$LockPath)
    $launcherPid = $PID
    if (Test-Path $LockPath) {
        $existing = Read-JsonFile -Path $LockPath
        if ($null -ne $existing -and $existing.launcher_pid) {
            $p = Get-Process -Id ([int]$existing.launcher_pid) -ErrorAction SilentlyContinue
            if ($null -ne $p) {
                throw "Another automode instance is active for this workspace (launcher_pid=$($existing.launcher_pid))."
            }
        }
        Remove-Item -Path $LockPath -Force -ErrorAction SilentlyContinue
    }
    Write-JsonFile -Path $LockPath -Obj @{
        launcher_pid = $launcherPid
        created_at_utc = Get-IsoNow
        workspace = Get-WorkspaceRoot
    }
}

function Release-Lock {
    param([string]$LockPath)
    if (Test-Path $LockPath) {
        Remove-Item -Path $LockPath -Force -ErrorAction SilentlyContinue
    }
}

function Get-OpenPrs {
    $json = gh pr list --repo $Repo --base $BaseBranch --state open --json number,title,headRefName,headRefOid,url
    return ($json | ConvertFrom-Json)
}

function Get-PrStatus {
    param([int]$Pr)
    $json = gh pr view $Pr --repo $Repo --json number,state,mergeStateStatus,reviewDecision,headRefOid,statusCheckRollup,url
    return ($json | ConvertFrom-Json)
}

function Get-PrInlineCountOnHead {
    param([int]$Pr, [string]$HeadSha)
    $comments = gh api "repos/$Repo/pulls/$Pr/comments" | ConvertFrom-Json
    return @($comments | Where-Object { $_.commit_id -eq $HeadSha }).Count
}

function Build-Snapshot {
    $prs = Get-OpenPrs
    $rows = @()
    foreach ($pr in $prs) {
        $s = Get-PrStatus -Pr $pr.number
        $head = [string]$s.headRefOid
        $inline = Get-PrInlineCountOnHead -Pr $pr.number -HeadSha $head
        $checks = @($s.statusCheckRollup | ForEach-Object {
            "$($_.name):$($_.status):$($_.conclusion)"
        }) -join "; "

        $rows += [pscustomobject]@{
            number = $s.number
            url = $s.url
            head = $head.Substring(0, 7)
            state = $s.state
            merge = $s.mergeStateStatus
            review = $s.reviewDecision
            inline_on_head = $inline
            checks = $checks
        }
    }
    return $rows
}

function Parse-CheckpointContract {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        return @{ valid = $false; reason = "checkpoint file missing" }
    }
    $raw = Get-Content -Path $Path -Raw
    $required = @(
        "agent_state:",
        "last_action:",
        "next_action:",
        "updated_at_utc:"
    )
    foreach ($r in $required) {
        if ($raw -notmatch [regex]::Escape($r)) {
            return @{ valid = $false; reason = "missing contract field: $r" }
        }
    }

    $match = [regex]::Match($raw, "updated_at_utc:\s*([0-9T:\-]+Z)")
    if (-not $match.Success) {
        return @{ valid = $false; reason = "updated_at_utc invalid/missing" }
    }
    $stamp = $match.Groups[1].Value
    try {
        $dt = [DateTime]::Parse($stamp).ToUniversalTime()
    } catch {
        return @{ valid = $false; reason = "updated_at_utc parse failed" }
    }
    return @{
        valid = $true
        updated_at_utc = $dt
    }
}

function Update-Checkpoint {
    param(
        [string]$Path,
        [string]$GoalText,
        [array]$Rows
    )

    $blockLines = @()
    $blockLines += "## AUTO-MODE CHECKPOINT"
    $blockLines += ""
    $blockLines += "- updated_at_utc: $(Get-IsoNow)"
    $blockLines += "- goal: $GoalText"
    $blockLines += "- base_branch: $BaseBranch"
    $blockLines += "- open_pr_count: $($Rows.Count)"
    $blockLines += "- agent_state: working"
    $blockLines += "- last_action: automode poll snapshot + prompt render"
    $blockLines += "- next_action: if child process exited -> dispatch immediately; else keep monitoring"
    $blockLines += ""
    if ($Rows.Count -eq 0) {
        $blockLines += "- prs: none"
    } else {
        $blockLines += "- prs:"
        foreach ($r in $Rows) {
            $blockLines += "  - #$($r.number) head=$($r.head) state=$($r.state) merge=$($r.merge) review=$($r.review) inline_on_head=$($r.inline_on_head)"
            $blockLines += "    - url: $($r.url)"
            $blockLines += "    - checks: $($r.checks)"
        }
    }
    $block = ($blockLines -join "`r`n")

    if (-not (Test-Path $Path)) {
        Set-Content -Path $Path -Value ($block + "`r`n")
        return
    }

    $content = Get-Content -Path $Path -Raw
    $pattern = "(?s)## AUTO-MODE CHECKPOINT.*?(?=(\r?\n## |\z))"
    if ($content -match $pattern) {
        $newContent = [regex]::Replace($content, $pattern, $block)
        Set-Content -Path $Path -Value $newContent
    } else {
        Add-Content -Path $Path -Value ("`r`n`r`n" + $block + "`r`n")
    }
}

function Render-Prompt {
    param(
        [string]$TemplatePath,
        [string]$GoalText,
        [string]$Checkpoint
    )
    $tpl = Get-Content -Path $TemplatePath -Raw
    $tpl = $tpl.Replace("{{GOAL}}", $GoalText)
    $tpl = $tpl.Replace("{{CHECKPOINT_PATH}}", $Checkpoint)
    return $tpl
}

function Is-ProcessAlive {
    param([int]$PidToCheck)
    $p = Get-Process -Id $PidToCheck -ErrorAction SilentlyContinue
    return ($null -ne $p)
}

function Start-DispatchChild {
    param([string]$Dispatch, [string]$PromptFile, [string]$LogDir)
    if ([string]::IsNullOrWhiteSpace($Dispatch)) {
        return $null
    }
    $cmd = $Dispatch.Replace("{PROMPT_FILE}", $PromptFile)
    $ts = (Get-Date).ToUniversalTime().ToString("yyyyMMdd-HHmmss")
    $stdout = Join-Path $LogDir "dispatch-$ts-out.log"
    $stderr = Join-Path $LogDir "dispatch-$ts-err.log"

    # IMPORTANT: execute the command string, don't just emit it.
    $wrapped = @'
$ErrorActionPreference = 'Stop'
Invoke-Expression @'
__AUTOMODE_DISPATCH_COMMAND__
'@
'@
    $wrapped = $wrapped.Replace("__AUTOMODE_DISPATCH_COMMAND__", $cmd)

    $tmp = Join-Path $env:TEMP ("automode-dispatch-" + [guid]::NewGuid().ToString("N") + ".ps1")
    Set-Content -Path $tmp -Value $wrapped
    $p = Start-Process -FilePath "powershell" `
        -ArgumentList @("-NoLogo","-NoProfile","-ExecutionPolicy","Bypass","-File",$tmp) `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        -PassThru
    return @{
        Process = $p
        Stdout = $stdout
        Stderr = $stderr
    }
}

$paths = Get-RepoStatePaths
Acquire-Lock -LockPath $paths.Lock

try {
    $workspace = Get-WorkspaceRoot
    Write-Host "[automode] repo=$Repo base=$BaseBranch goal=$Goal"
    Write-Host "[automode] workspace=$workspace"
    Write-Host "[automode] checkpoint=$CheckpointPath"
    Write-Host "[automode] poll=${PollSeconds}s max_runtime=${MaxRuntimeMinutes}m max_checkpoint_stale=${MaxCheckpointStaleMinutes}m"

    while ($true) {
        $rows = @()
        try {
            $rows = Build-Snapshot
            Update-Checkpoint -Path $CheckpointPath -GoalText $Goal -Rows $rows
        } catch {
            Write-Host "[automode] snapshot/checkpoint warning: $($_.Exception.Message)"
        }

        $prompt = Render-Prompt -TemplatePath $PromptTemplatePath -GoalText $Goal -Checkpoint $CheckpointPath
        $promptFile = Join-Path $env:TEMP "askmydocs-automode-prompt.txt"
        Set-Content -Path $promptFile -Value $prompt

        $state = Read-JsonFile -Path $paths.State
        if ($null -eq $state) {
            $state = [pscustomobject]@{
                launcher_pid = $PID
                workspace = $workspace
                child_pid = $null
                child_started_at_utc = $null
                last_dispatch_at_utc = $null
                last_cycle_at_utc = $null
            }
        }

        $state.last_cycle_at_utc = Get-IsoNow

        $childAlive = $false
        if ($state.child_pid) {
            $childAlive = Is-ProcessAlive -PidToCheck ([int]$state.child_pid)
        }

        if ($childAlive) {
            $started = $null
            try { $started = [DateTime]::Parse([string]$state.child_started_at_utc).ToUniversalTime() } catch {}
            $runtimeExceeded = $false
            if ($null -ne $started) {
                $mins = (New-TimeSpan -Start $started -End (Get-Date).ToUniversalTime()).TotalMinutes
                $runtimeExceeded = ($mins -gt $MaxRuntimeMinutes)
            }

            $contract = Parse-CheckpointContract -Path $CheckpointPath
            $checkpointStale = $false
            if ($contract.valid) {
                $minsStale = (New-TimeSpan -Start $contract.updated_at_utc -End (Get-Date).ToUniversalTime()).TotalMinutes
                $checkpointStale = ($minsStale -gt $MaxCheckpointStaleMinutes)
            }

            if ($runtimeExceeded -and $checkpointStale) {
                Write-Host "[automode] watchdog: child_pid=$($state.child_pid) runtime+stale exceeded -> restart"
                Stop-Process -Id ([int]$state.child_pid) -Force -ErrorAction SilentlyContinue
                $childAlive = $false
                $state.child_pid = $null
                $state.child_started_at_utc = $null
            } else {
                Write-Host "[$(Get-IsoNow)] child_pid=$($state.child_pid) running -> monitor only (no overlap dispatch)"
            }
        }

        if (-not $childAlive) {
            if ([string]::IsNullOrWhiteSpace($DispatchCommand)) {
                Write-Host "[$(Get-IsoNow)] no active child and no DispatchCommand (dry mode)."
            } else {
                Write-Host "[$(Get-IsoNow)] child not running -> dispatch now"
                $child = Start-DispatchChild -Dispatch $DispatchCommand -PromptFile $promptFile -LogDir $paths.Dir
                if ($null -ne $child) {
                    $state.child_pid = $child.Process.Id
                    $state.child_started_at_utc = Get-IsoNow
                    $state.last_dispatch_at_utc = Get-IsoNow
                    $state.child_stdout_log = $child.Stdout
                    $state.child_stderr_log = $child.Stderr
                    Write-Host "[automode] dispatched child_pid=$($child.Process.Id)"
                    Write-Host "[automode] stdout=$($child.Stdout)"
                    Write-Host "[automode] stderr=$($child.Stderr)"
                }
            }
        }

        Write-JsonFile -Path $paths.State -Obj $state
        Start-Sleep -Seconds $PollSeconds
    }
}
finally {
    Release-Lock -LockPath $paths.Lock
}
