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
    [int]$PollSeconds = 90
)

$ErrorActionPreference = "Stop"

function Get-IsoNow {
    return (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
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

Write-Host "[automode] repo=$Repo base=$BaseBranch goal=$Goal"
Write-Host "[automode] checkpoint=$CheckpointPath"
Write-Host "[automode] poll=${PollSeconds}s"

while ($true) {
    try {
        $rows = Build-Snapshot
        Update-Checkpoint -Path $CheckpointPath -GoalText $Goal -Rows $rows

        $prompt = Render-Prompt -TemplatePath $PromptTemplatePath -GoalText $Goal -Checkpoint $CheckpointPath
        $promptFile = Join-Path $env:TEMP "askmydocs-automode-prompt.txt"
        Set-Content -Path $promptFile -Value $prompt

        Write-Host ""
        Write-Host "[$(Get-IsoNow)] open_prs=$($rows.Count)"
        foreach ($r in $rows) {
            Write-Host "  #$($r.number) head=$($r.head) merge=$($r.merge) review=$($r.review) inline=$($r.inline_on_head)"
        }

        if ($DispatchCommand -ne "") {
            $cmd = $DispatchCommand.Replace("{PROMPT_FILE}", $promptFile)
            Write-Host "[automode] dispatch: $cmd"
            Invoke-Expression $cmd
        } else {
            Write-Host "[automode] prompt generated at: $promptFile"
            Write-Host "[automode] no DispatchCommand configured (dry mode)."
        }
    } catch {
        Write-Host "[automode] error: $($_.Exception.Message)"
    }

    Start-Sleep -Seconds $PollSeconds
}
