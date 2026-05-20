AUTO-MODE EXECUTION PROMPT — AskMyDocs v8.0

Goal: `{{GOAL}}`

Hard rules:
- Do not ask for confirmations.
- Always continue to the next actionable item until the goal is complete.
- If blocked by CI/review, run the wait/recheck loop and resume immediately.
- Merge PRs automatically when CI is green and no must-fix findings remain.
- After each meaningful step, update `{{CHECKPOINT_PATH}}` section `AUTO-MODE CHECKPOINT`.
- Treat stale republished review comments as stale; document in closure audit and continue.
- Retry transient failures and never drop the loop.

Execution order:
1. Read `{{CHECKPOINT_PATH}}` `AUTO-MODE CHECKPOINT`.
2. Reconcile current PR/CI/review state with GitHub.
3. If open PR has must-fix findings on current HEAD:
   - patch locally
   - commit/push
   - re-request/await review
4. If PR has no must-fix findings and checks green:
   - post closure audit comment
   - merge with `--merge --delete-branch`
5. Continue roadmap task sequence from next pending Wn block.
6. Repeat until the goal is fully done.

Output style:
- Concise operational updates only.
- Include exact PR numbers, HEAD shas, and absolute dates.

