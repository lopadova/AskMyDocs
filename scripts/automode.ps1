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
    ,
    [Parameter(Mandatory = $false)]
    [int]$MaxVerifiedWaitingMinutes = 20
    ,
    [Parameter(Mandatory = $false)]
    [int]$NoOpenPrCooldownMinutes = 10

    ,
    [Parameter(Mandatory = $false)]
    [switch]$FollowChildLogs

    ,
    [Parameter(Mandatory = $false)]
    [int]$GraceMinutes = 5

    ,
    [Parameter(Mandatory = $false)]
    [switch]$DryRun

    ,
    [Parameter(Mandatory = $false)]
    [switch]$VerboseWatchdog

    ,
    [Parameter(Mandatory = $false)]
    [switch]$EnableReattach = $true
)

$ErrorActionPreference = "Stop"
$AutomodeVersion = "2026-05-20.6"

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

function Cleanup-OldAutomodeLogs {
    param(
        [string]$LogDir,
        [int]$RetentionDays = 15
    )
    Write-Host "[automode] check pulizia log..."
    if (-not (Test-Path $LogDir)) {
        Write-Host "[automode] 0 log trovati, nessuno cancellato."
        return
    }

    $cutoff = (Get-Date).ToUniversalTime().AddDays(-1 * $RetentionDays)
    $logs = @(Get-ChildItem -Path $LogDir -File -Filter "dispatch-*.log" -ErrorAction SilentlyContinue)
    $toDelete = @($logs | Where-Object { $_.LastWriteTimeUtc -lt $cutoff })

    if ($toDelete.Count -eq 0) {
        Write-Host "[automode] $($logs.Count) log trovati, nessuno cancellato."
        return
    }

    $deleted = 0
    foreach ($f in $toDelete) {
        try {
            Write-Host "[automode] cancello log... $($f.Name)"
            Remove-Item -LiteralPath $f.FullName -Force -ErrorAction Stop
            $deleted++
        } catch {
            Write-Host "[automode] warning: impossibile cancellare $($f.Name): $($_.Exception.Message)"
        }
    }
    Write-Host "[automode] $deleted log cancellati."
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

function Get-CheckpointAgentState {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    try {
        $raw = Get-Content -Path $Path -Raw
        $m = [regex]::Match($raw, "(?im)^\s*-\s*agent_state:\s*([a-zA-Z0-9_\-]+)\s*$")
        if ($m.Success) { return $m.Groups[1].Value.Trim().ToLowerInvariant() }
        return $null
    } catch {
        return $null
    }
}

function Get-CheckpointOpenPrNumber {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    try {
        $raw = Get-Content -Path $Path -Raw
        # Prefer explicit "open" rows from the prs list.
        $openMatches = [regex]::Matches($raw, "(?im)^\s*-\s*#(\d+):.*status:\s*open\b")
        if ($openMatches.Count -gt 0) {
            return [int]$openMatches[$openMatches.Count - 1].Groups[1].Value
        }
        # Fallback: parse PR number from next_action line.
        $nextActionMatch = [regex]::Match($raw, "(?im)^\s*-\s*next_action:\s*.*?\bPR\s*#(\d+)\b")
        if ($nextActionMatch.Success) {
            return [int]$nextActionMatch.Groups[1].Value
        }
        # Last fallback: any PR row, newest occurrence.
        $allMatches = [regex]::Matches($raw, "(?im)^\s*-\s*#(\d+):\s*https://github\.com/")
        if ($allMatches.Count -gt 0) {
            return [int]$allMatches[$allMatches.Count - 1].Groups[1].Value
        }
        return $null
    } catch {
        return $null
    }
}

function Test-PrStillWaiting {
    param([int]$PrNumber)
    try {
        $json = gh pr view $PrNumber --repo $Repo --json state,reviewDecision,statusCheckRollup
        $pr = $json | ConvertFrom-Json
    } catch {
        return @{
            ok = $false
            waiting = $true
            reason = "gh_query_failed"
        }
    }

    if ([string]$pr.state -ne "OPEN") {
        return @{ ok = $true; waiting = $false; reason = "pr_not_open" }
    }

    $reviewDecision = [string]$pr.reviewDecision
    $checks = @($pr.statusCheckRollup)

    $checksPending = $false
    foreach ($c in $checks) {
        $status = [string]$c.status
        $conclusion = [string]$c.conclusion
        if ($status -match "^(IN_PROGRESS|PENDING|QUEUED|EXPECTED)$") {
            $checksPending = $true
            break
        }
        if ([string]::IsNullOrWhiteSpace($conclusion) -and $status -ne "COMPLETED") {
            $checksPending = $true
            break
        }
    }

    $reviewWaiting = ($reviewDecision -eq "REVIEW_REQUIRED")
    $waiting = ($checksPending -or $reviewWaiting)
    $reason = if ($checksPending) { "checks_pending" } elseif ($reviewWaiting) { "review_required" } else { "checks_done_and_review_not_required" }
    return @{ ok = $true; waiting = $waiting; reason = $reason }
}

function Get-ProcessStartTimeUtc {
    param([int]$PidToCheck)
    try {
        $p = Get-Process -Id $PidToCheck -ErrorAction Stop
        return $p.StartTime.ToUniversalTime()
    } catch {
        return $null
    }
}

function Get-ProcessStartTimeUtcString {
    param([int]$PidToCheck)
    $dt = Get-ProcessStartTimeUtc -PidToCheck $PidToCheck
    if ($null -eq $dt) { return $null }
    return $dt.ToString("yyyy-MM-ddTHH:mm:ssZ")
}

function Normalize-UtcInstantString {
    param([object]$Value)
    if ($null -eq $Value) { return $null }
    $raw = [string]$Value
    if ([string]::IsNullOrWhiteSpace($raw)) { return $null }
    $styles = [System.Globalization.DateTimeStyles]::AssumeUniversal -bor [System.Globalization.DateTimeStyles]::AdjustToUniversal
    $cultures = @(
        [System.Globalization.CultureInfo]::InvariantCulture,
        [System.Globalization.CultureInfo]::GetCultureInfo("en-US"),
        [System.Globalization.CultureInfo]::CurrentCulture
    )
    $formats = @(
        "yyyy-MM-ddTHH:mm:ssZ",
        "yyyy-MM-ddTHH:mm:ss.fffZ",
        "MM/dd/yyyy HH:mm:ss",
        "M/d/yyyy H:mm:ss",
        "dd/MM/yyyy HH:mm:ss",
        "d/M/yyyy H:mm:ss"
    )
    foreach ($c in $cultures) {
        foreach ($f in $formats) {
            try {
                $dt = [DateTime]::ParseExact($raw, $f, $c, $styles)
                return $dt.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
            } catch {}
        }
    }
    try {
        return ([DateTime]::Parse($raw).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ"))
    } catch {
        return $null
    }
}

function Get-ManagedDispatchShells {
    param([int]$LauncherPid)
    $procs = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue
    if (-not $procs) { return @() }
    return @(
        $procs | Where-Object {
            $_.ParentProcessId -eq $LauncherPid -and
            ($_.Name -eq "powershell.exe" -or $_.Name -eq "pwsh.exe") -and
            ($_.CommandLine -match "automode-dispatch-")
        }
    )
}

function Get-WorkspaceCodexCandidates {
    param([string]$WorkspacePath)
    $procs = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue
    if (-not $procs) { return @() }
    return @(
        $procs | Where-Object {
            $_.Name -eq "codex.exe" -and
            $_.CommandLine -like "* -C *$WorkspacePath*" -and
            $_.CommandLine -match "AUTO-MODE EXECUTION PROMPT"
        } | Sort-Object CreationDate -Descending
    )
}

function Get-DescendantPids {
    param([int]$RootPid)
    $all = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue
    if (-not $all) { return @() }
    $result = New-Object System.Collections.Generic.List[int]
    $queue = New-Object System.Collections.Generic.Queue[int]
    $queue.Enqueue($RootPid)
    while ($queue.Count -gt 0) {
        $current = $queue.Dequeue()
        $children = @($all | Where-Object { $_.ParentProcessId -eq $current } | Select-Object -ExpandProperty ProcessId)
        foreach ($c in $children) {
            if (-not $result.Contains([int]$c)) {
                $result.Add([int]$c)
                $queue.Enqueue([int]$c)
            }
        }
    }
    return @($result)
}

function Get-AncestorPids {
    param([int]$FromPid)
    $all = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue
    if (-not $all) { return @() }
    $map = @{}
    foreach ($proc in $all) {
        $map[[int]$proc.ProcessId] = [int]$proc.ParentProcessId
    }
    $result = New-Object System.Collections.Generic.List[int]
    $current = $FromPid
    while ($map.ContainsKey($current)) {
        $parent = [int]$map[$current]
        if ($parent -le 0) { break }
        if ($result.Contains($parent)) { break }
        $result.Add($parent)
        $current = $parent
    }
    return @($result)
}

function Stop-ProcessTreeSafe {
    param([int]$RootPid)
    $protected = New-Object System.Collections.Generic.HashSet[int]
    [void]$protected.Add([int]$PID)
    foreach ($ancestorPid in (Get-AncestorPids -FromPid ([int]$PID))) {
        [void]$protected.Add([int]$ancestorPid)
    }

    if ($protected.Contains([int]$RootPid)) {
        Write-Host "[automode] safety: refusing to kill protected pid=$RootPid"
        return
    }

    foreach ($childProcId in (Get-DescendantPids -RootPid $RootPid | Sort-Object -Descending)) {
        if ($protected.Contains([int]$childProcId)) {
            Write-Host "[automode] safety: skipping protected child pid=$childProcId"
            continue
        }
        Stop-Process -Id $childProcId -Force -ErrorAction SilentlyContinue
    }
    Stop-Process -Id $RootPid -Force -ErrorAction SilentlyContinue
}

function Get-CheckpointFreshnessMinutes {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        return @{ ok = $false; reason = "checkpoint missing"; minutes = [double]::PositiveInfinity; source = "none" }
    }

    $contract = Parse-CheckpointContract -Path $Path
    if ($contract.valid) {
        $mins = (New-TimeSpan -Start $contract.updated_at_utc -End (Get-Date).ToUniversalTime()).TotalMinutes
        return @{ ok = $true; reason = "contract"; minutes = $mins; source = "updated_at_utc" }
    }

    $mtime = (Get-Item $Path).LastWriteTimeUtc
    $minsFile = (New-TimeSpan -Start $mtime -End (Get-Date).ToUniversalTime()).TotalMinutes
    return @{ ok = $true; reason = "fallback_file_mtime"; minutes = $minsFile; source = "file_mtime_utc" }
}

function Get-ProtectedPids {
    $protected = New-Object System.Collections.Generic.HashSet[int]
    [void]$protected.Add([int]$PID)
    foreach ($ancestorPid in (Get-AncestorPids -FromPid ([int]$PID))) {
        [void]$protected.Add([int]$ancestorPid)
    }
    return $protected
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
    $wrapped = @(
        "`$ErrorActionPreference = 'Stop'"
        "Invoke-Expression @'"
        "__AUTOMODE_DISPATCH_COMMAND__"
        "'@"
    ) -join "`n"
    $wrapped = $wrapped.Replace("__AUTOMODE_DISPATCH_COMMAND__", $cmd)

    $tmp = Join-Path $env:TEMP ("automode-dispatch-" + [guid]::NewGuid().ToString("N") + ".ps1")
    Set-Content -Path $tmp -Value $wrapped
    $isCodexDispatch = ($cmd -match '(^|\s)codex(\.exe)?(\s|$)')

    if ($isCodexDispatch) {
        Write-Host "[automode] dispatch detected codex command: launching visible terminal (TTY required)"
        $p = Start-Process -FilePath "powershell" `
            -ArgumentList @("-NoLogo","-NoProfile","-ExecutionPolicy","Bypass","-NoExit","-File",$tmp) `
            -WindowStyle Normal `
            -PassThru
        Start-Sleep -Milliseconds 800
        $spawnedCodex = Get-CimInstance Win32_Process -Filter "Name = 'codex.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.ParentProcessId -eq $p.Id } |
            Sort-Object CreationDate -Descending |
            Select-Object -First 1
        $trackedPid = if ($spawnedCodex) { [int]$spawnedCodex.ProcessId } else { [int]$p.Id }
        $trackedStart = Get-ProcessStartTimeUtcString -PidToCheck $trackedPid
        return @{
            Process = $p
            TrackedPid = $trackedPid
            TrackedStartUtc = $trackedStart
            Stdout = $null
            Stderr = $null
        }
    }

    $p = Start-Process -FilePath "powershell" `
        -ArgumentList @("-NoLogo","-NoProfile","-ExecutionPolicy","Bypass","-File",$tmp) `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        -PassThru
    return @{
        Process = $p
        TrackedPid = [int]$p.Id
        TrackedStartUtc = Get-ProcessStartTimeUtcString -PidToCheck ([int]$p.Id)
        Stdout = $stdout
        Stderr = $stderr
    }
}

function Read-NewLogChunk {
    param(
        [string]$Path,
        [long]$Offset
    )
    if (-not (Test-Path $Path)) {
        return @{ Text = ""; Offset = $Offset }
    }
    $fs = [System.IO.File]::Open($Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
    try {
        if ($Offset -gt $fs.Length) {
            $Offset = 0
        }
        $fs.Seek($Offset, [System.IO.SeekOrigin]::Begin) | Out-Null
        $sr = New-Object System.IO.StreamReader($fs)
        $text = $sr.ReadToEnd()
        $newOffset = $fs.Position
        $sr.Dispose()
        return @{ Text = $text; Offset = $newOffset }
    } finally {
        $fs.Dispose()
    }
}

$paths = Get-RepoStatePaths
Acquire-Lock -LockPath $paths.Lock

try {
    $workspace = Get-WorkspaceRoot
    Cleanup-OldAutomodeLogs -LogDir $paths.Dir -RetentionDays 15
    Write-Host "[automode] repo=$Repo base=$BaseBranch goal=$Goal"
    Write-Host "[automode] version=$AutomodeVersion"
    Write-Host "[automode] workspace=$workspace"
    Write-Host "[automode] checkpoint=$CheckpointPath"
    Write-Host "[automode] poll=${PollSeconds}s max_runtime=${MaxRuntimeMinutes}m max_checkpoint_stale=${MaxCheckpointStaleMinutes}m grace=${GraceMinutes}m"
    Write-Host "[automode] max_verified_waiting=${MaxVerifiedWaitingMinutes}m"
    Write-Host "[automode] no_open_pr_cooldown=${NoOpenPrCooldownMinutes}m"
    Write-Host "[automode] follow_child_logs=$FollowChildLogs"
    Write-Host "[automode] dry_run=$DryRun"
    Write-Host "[automode] verbose_watchdog=$VerboseWatchdog"
    Write-Host "[automode] enable_reattach=$EnableReattach"

    $stdoutOffset = 0L
    $stderrOffset = 0L
    $currentFollowPid = $null

    while ($true) {
        try {
            # Monitor must not refresh checkpoint timestamps; checkpoint freshness is owned by codex worker.
            $null = Build-Snapshot
        } catch {
            Write-Host "[automode] snapshot warning: $($_.Exception.Message)"
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
                child_managed = $false
                child_started_at_utc = $null
                last_dispatch_at_utc = $null
                last_cycle_at_utc = $null
            }
        }
        if ($null -eq $state.PSObject.Properties["child_managed"]) {
            $state | Add-Member -NotePropertyName "child_managed" -NotePropertyValue $false
        }
        if ($null -eq $state.PSObject.Properties["child_process_start_utc"]) {
            $state | Add-Member -NotePropertyName "child_process_start_utc" -NotePropertyValue $null
        }
        if ($null -eq $state.PSObject.Properties["no_open_pr_since_utc"]) {
            $state | Add-Member -NotePropertyName "no_open_pr_since_utc" -NotePropertyValue $null
        }

        $state.last_cycle_at_utc = Get-IsoNow

        # New automode launcher session: keep same managed child, but restart
        # grace timer so watchdog does not kill immediately on inherited stale age.
        if ([int]$state.launcher_pid -ne [int]$PID) {
            $state.launcher_pid = [int]$PID
            if ($state.child_pid -and [bool]$state.child_managed) {
                $state.child_started_at_utc = Get-IsoNow
            }
        }

        # Safety invariant: there must be at most one managed dispatch child
        # per automode launcher. If duplicates exist, keep the newest and kill
        # older managed children to prevent parallel codex runs.
        $managedShells = @(Get-ManagedDispatchShells -LauncherPid ([int]$PID) | Sort-Object CreationDate -Descending)
        if ($managedShells.Count -gt 1) {
            $keeper = $managedShells[0]
            $older = @($managedShells | Select-Object -Skip 1)
            Write-Host "[automode] duplicate managed children detected: count=$($managedShells.Count) keep_pid=$($keeper.ProcessId) kill_older=$(@($older | ForEach-Object { $_.ProcessId }) -join ',')"
            foreach ($old in $older) {
                if ($DryRun) {
                    Write-Host "[automode] DRYRUN dedupe: would kill old managed child pid=$($old.ProcessId)"
                } else {
                    Stop-ProcessTreeSafe -RootPid ([int]$old.ProcessId)
                }
            }
            $state.child_pid = [int]$keeper.ProcessId
            $state.child_managed = $true
            $state.child_process_start_utc = Get-ProcessStartTimeUtcString -PidToCheck ([int]$keeper.ProcessId)
            if ([string]::IsNullOrWhiteSpace([string]$state.child_started_at_utc)) {
                $state.child_started_at_utc = Get-IsoNow
            }
        }

        $childAlive = $false
        if ($state.child_pid) {
            $actualStartUtc = Get-ProcessStartTimeUtcString -PidToCheck ([int]$state.child_pid)
            if ($null -eq $actualStartUtc) {
                $state.child_managed = $false
                $state.child_pid = $null
                $state.child_started_at_utc = $null
                $state.child_process_start_utc = $null
            }
            elseif (-not [bool]$state.child_managed) {
                Write-Host "[automode] ignoring unmanaged child_pid=$($state.child_pid) (not launched by automode)"
                $state.child_pid = $null
                $state.child_started_at_utc = $null
                $state.child_process_start_utc = $null
            }
            else {
                $recordedStartUtc = Normalize-UtcInstantString -Value $state.child_process_start_utc
                if ($null -eq $recordedStartUtc) {
                    Write-Host "[automode] refusing reattach for child_pid=$($state.child_pid): invalid child_process_start_utc value=$($state.child_process_start_utc)"
                    $state.child_managed = $false
                    $state.child_pid = $null
                    $state.child_started_at_utc = $null
                    $state.child_process_start_utc = $null
                }
                elseif ([string]$recordedStartUtc -ne [string]$actualStartUtc) {
                    Write-Host "[automode] refusing reattach for child_pid=$($state.child_pid): start mismatch recorded=$recordedStartUtc actual=$actualStartUtc"
                    $state.child_managed = $false
                    $state.child_pid = $null
                    $state.child_started_at_utc = $null
                    $state.child_process_start_utc = $null
                }
                else {
                    # Canonicalize persisted format once so future cycles stay quiet.
                    $state.child_process_start_utc = $recordedStartUtc
                    $childAlive = $true
                }
            }
        }

        if (-not $childAlive -and [bool]$EnableReattach) {
            $candidates = @(Get-WorkspaceCodexCandidates -WorkspacePath $workspace)
            if ($candidates.Count -gt 0) {
                $pick = $candidates[0]
                if ($candidates.Count -gt 1) {
                    Write-Host "[automode] reattach: found $($candidates.Count) codex candidates for workspace, selecting newest pid=$($pick.ProcessId)"
                } else {
                    Write-Host "[automode] reattach: found existing codex pid=$($pick.ProcessId)"
                }
                $state.child_pid = [int]$pick.ProcessId
                $state.child_managed = $true
                $state.child_process_start_utc = Get-ProcessStartTimeUtcString -PidToCheck ([int]$pick.ProcessId)
                # Reattach grace window starts now, regardless of prior persisted shape.
                $state.child_started_at_utc = Get-IsoNow
                $childAlive = $true
            }
        }

        if ($childAlive) {
            if ($FollowChildLogs -and $state.child_pid -ne $null) {
                if ($currentFollowPid -ne [int]$state.child_pid) {
                    $currentFollowPid = [int]$state.child_pid
                    $stdoutOffset = 0L
                    $stderrOffset = 0L
                }
                if ($state.child_stdout_log) {
                    $o = Read-NewLogChunk -Path ([string]$state.child_stdout_log) -Offset $stdoutOffset
                    $stdoutOffset = [long]$o.Offset
                    if (-not [string]::IsNullOrWhiteSpace([string]$o.Text)) {
                        ([string]$o.Text -split "`r?`n" | Where-Object { $_ -ne "" }) | ForEach-Object {
                            Write-Host "[child:out] $_"
                        }
                    }
                }
                if ($state.child_stderr_log) {
                    $e = Read-NewLogChunk -Path ([string]$state.child_stderr_log) -Offset $stderrOffset
                    $stderrOffset = [long]$e.Offset
                    if (-not [string]::IsNullOrWhiteSpace([string]$e.Text)) {
                        ([string]$e.Text -split "`r?`n" | Where-Object { $_ -ne "" }) | ForEach-Object {
                            Write-Host "[child:err] $_"
                        }
                    }
                }
            }

            $started = $null
            $normalizedStarted = Normalize-UtcInstantString -Value $state.child_started_at_utc
            if ($null -eq $normalizedStarted) {
                # Try to self-heal from real process start if persisted value drifted.
                $normalizedStarted = Get-ProcessStartTimeUtcString -PidToCheck ([int]$state.child_pid)
            }
            if ($null -ne $normalizedStarted) {
                $state.child_started_at_utc = $normalizedStarted
                try { $started = [DateTime]::Parse($normalizedStarted).ToUniversalTime() } catch {}
            }
            $runtimeExceeded = $false
            $mins = -1
            if ($null -ne $started) {
                $mins = (New-TimeSpan -Start $started -End (Get-Date).ToUniversalTime()).TotalMinutes
                $runtimeExceeded = ($mins -gt $MaxRuntimeMinutes)
            }

            $freshness = Get-CheckpointFreshnessMinutes -Path $CheckpointPath
            $minsStale = $freshness.minutes
            $checkpointStale = ($minsStale -gt $MaxCheckpointStaleMinutes)

            $pastGrace = $false
            if ($null -ne $started) {
                $minsSinceStart = (New-TimeSpan -Start $started -End (Get-Date).ToUniversalTime()).TotalMinutes
                $pastGrace = ($minsSinceStart -gt $GraceMinutes)
            } else {
                # Unknown start time: fail-safe to monitor-only (never kill on unknown age).
                $pastGrace = $false
                Write-Host "[automode] watchdog note: child_started_at_utc unresolved -> monitor-only (skip stale kill this cycle)"
            }

            $staleTriggered = ($pastGrace -and $checkpointStale)
            if ($staleTriggered) {
                try {
                    $openPrsNow = @(Get-OpenPrs)
                } catch {
                    $openPrsNow = @()
                }
                if ($openPrsNow.Count -eq 0) {
                    $nowUtc = (Get-Date).ToUniversalTime()
                    $sinceUtc = Normalize-UtcInstantString -Value $state.no_open_pr_since_utc
                    if ($null -eq $sinceUtc) {
                        $state.no_open_pr_since_utc = Get-IsoNow
                        Write-Host "[automode] watchdog: no open PRs -> start cooldown (${NoOpenPrCooldownMinutes}m), skip stale kill"
                        $staleTriggered = $false
                    } else {
                        $since = [DateTime]::Parse($sinceUtc).ToUniversalTime()
                        $minsNoOpen = (New-TimeSpan -Start $since -End $nowUtc).TotalMinutes
                        if ($minsNoOpen -lt $NoOpenPrCooldownMinutes) {
                            Write-Host "[automode] watchdog: no open PRs cooldown active (${([math]::Round($minsNoOpen,2))}/${NoOpenPrCooldownMinutes}m) -> skip stale kill"
                            $staleTriggered = $false
                        } else {
                            Write-Host "[automode] watchdog: no open PRs cooldown elapsed (${([math]::Round($minsNoOpen,2))}m) -> stale kill allowed"
                        }
                    }
                } else {
                    $state.no_open_pr_since_utc = $null
                }
            }
            $agentState = Get-CheckpointAgentState -Path $CheckpointPath
            $isWaitingState = ($null -ne $agentState -and $agentState -match "^waiting(_|$)")
            if ($staleTriggered -and $isWaitingState) {
                if ($minsStale -lt $MaxVerifiedWaitingMinutes) {
                    Write-Host "[automode] watchdog: stale checkpoint but agent_state=$agentState (<${MaxVerifiedWaitingMinutes}m) -> skip stale kill"
                    $staleTriggered = $false
                } else {
                    $prNum = Get-CheckpointOpenPrNumber -Path $CheckpointPath
                    if ($null -eq $prNum) {
                        Write-Host "[automode] watchdog: waiting-state stale >=${MaxVerifiedWaitingMinutes}m but no PR found in checkpoint -> keep stale kill path"
                    } else {
                        $waitProbe = Test-PrStillWaiting -PrNumber $prNum
                        if ($waitProbe.waiting) {
                            Write-Host "[automode] watchdog: waiting-state verified on PR #$prNum ($($waitProbe.reason)) -> skip stale kill"
                            $staleTriggered = $false
                        } else {
                            Write-Host "[automode] watchdog: waiting-state NOT verified on PR #$prNum ($($waitProbe.reason)) -> stale kill allowed"
                        }
                    }
                }
            }
            $hardRuntimeTriggered = $runtimeExceeded

            if ($VerboseWatchdog) {
                $startedRaw = [string]$state.child_started_at_utc
                $startedParsed = if ($null -ne $started) { $started.ToString("o") } else { "null" }
                $uptimeFmt = if ($mins -ge 0) { [math]::Round($mins,3) } else { "n/a" }
                $staleFmt = if ([double]::IsInfinity([double]$minsStale)) { "INF" } else { [math]::Round([double]$minsStale,3) }
                Write-Host "[automode][wd] pid=$($state.child_pid) started_raw='$startedRaw' started_parsed=$startedParsed uptime_m=$uptimeFmt grace_m=$GraceMinutes past_grace=$pastGrace stale_m=$staleFmt stale_src=$($freshness.source) stale_threshold_m=$MaxCheckpointStaleMinutes runtime_threshold_m=$MaxRuntimeMinutes runtime_exceeded=$runtimeExceeded stale_triggered=$staleTriggered hard_runtime_triggered=$hardRuntimeTriggered"
            }

            if ($staleTriggered -or $hardRuntimeTriggered) {
                $reason = if ($staleTriggered) { "stale checkpoint after grace" } else { "max runtime exceeded" }
                if (-not [bool]$state.child_managed) {
                    Write-Host "[automode] watchdog: child_pid=$($state.child_pid) reason=$reason but child_managed=false -> skip kill/restart"
                } elseif ($DryRun) {
                    Write-Host "[automode] DRYRUN watchdog: would kill child_pid=$($state.child_pid) reason=$reason stale=${minsStale}m src=$($freshness.source)"
                } else {
                    Write-Host "[automode] watchdog: child_pid=$($state.child_pid) $reason (stale=${minsStale}m src=$($freshness.source)) -> restart"
                    Stop-ProcessTreeSafe -RootPid ([int]$state.child_pid)
                    Start-Sleep -Milliseconds 300
                    if (Is-ProcessAlive -PidToCheck ([int]$state.child_pid)) {
                        Write-Host "[automode] watchdog warning: child_pid=$($state.child_pid) still alive after kill attempt"
                    }
                    $childAlive = $false
                    $state.child_pid = $null
                    $state.child_started_at_utc = $null
                    $state.child_process_start_utc = $null
                    $currentFollowPid = $null
                }
            } else {
                $minsSince = if ($null -ne $started) { [math]::Round((New-TimeSpan -Start $started -End (Get-Date).ToUniversalTime()).TotalMinutes,2) } else { -1 }
                $minsStaleRound = if ([double]::IsInfinity([double]$minsStale)) { "INF" } else { [math]::Round([double]$minsStale,2) }
                Write-Host "[$(Get-IsoNow)] child_pid=$($state.child_pid) running -> monitor only (uptime=${minsSince}m stale=${minsStaleRound}m src=$($freshness.source) grace=$GraceMinutes)"
            }
        }

        if (-not $childAlive) {
            if ([string]::IsNullOrWhiteSpace($DispatchCommand)) {
                Write-Host "[$(Get-IsoNow)] no active child and no DispatchCommand (dry mode)."
            } else {
                if ($DryRun) {
                    Write-Host "[$(Get-IsoNow)] DRYRUN dispatch: would start command:"
                    Write-Host $DispatchCommand
                    break
                }
                Write-Host "[$(Get-IsoNow)] child not running -> dispatch now"
                $child = Start-DispatchChild -Dispatch $DispatchCommand -PromptFile $promptFile -LogDir $paths.Dir
                if ($null -ne $child) {
                    $state.child_pid = if ($child.TrackedPid) { [int]$child.TrackedPid } else { [int]$child.Process.Id }
                    $state.child_managed = $true
                    $state.child_started_at_utc = Get-IsoNow
                    $state.child_process_start_utc = if ($child.TrackedStartUtc) { [string]$child.TrackedStartUtc } else { Get-ProcessStartTimeUtcString -PidToCheck ([int]$state.child_pid) }
                    $state.last_dispatch_at_utc = Get-IsoNow
                    $state.child_stdout_log = $child.Stdout
                    $state.child_stderr_log = $child.Stderr
                    $currentFollowPid = [int]$state.child_pid
                    $stdoutOffset = 0L
                    $stderrOffset = 0L
                    Write-Host "[automode] dispatched child_pid=$($state.child_pid)"
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
