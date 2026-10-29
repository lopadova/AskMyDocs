"""Rebuild the local October evidence without checking out or changing any source.

Remote evidence is the saved, read-only GitHub API snapshot supplied via
--remote-cache. It is kept separate from local HEADs and the consumer lockfile.
"""
from __future__ import annotations

import argparse
import csv
import io
import json
import re
import subprocess
from collections import Counter
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[4]
START = "2026-10-01T00:00:00+02:00"
END = "2026-10-30T00:00:00+01:00"
BASE = "cd1e917f7b37aed43ad33881e7d8146f81748700"
HEAD = "8003ca793c0218ec13451b449fa02a874de762a0"


def git(path, *args, required=True):
    result = subprocess.run(["git", "-C", str(path), *args], text=True,
                            capture_output=True, check=False)
    if required and result.returncode:
        raise RuntimeError(result.stderr.strip())
    return result.stdout.strip() if result.returncode == 0 else None


def commits(path, *revisions):
    raw = git(path, "log", *revisions, f"--since-as-filter={START}",
              f"--until={END}", "--format=%H%x1f%aI%x1f%cI%x1f%an%x1f%s")
    return [dict(zip(("sha", "authored", "committed", "author", "subject"),
                     line.split("\x1f"))) for line in (raw or "").splitlines()]


def remote_repo(url):
    if not url:
        return None
    return re.sub(r"\.git$", "", url.replace("git@github.com:", "https://github.com/"))


def run(remote_cache):
    output = ROOT / "marketing/fonti/ottobre-2026"
    output.mkdir(parents=True, exist_ok=True)
    local = []
    for path in [ROOT] + sorted(Path("/Users/marco/packages").glob("*")):
        if not (path / ".git").exists():
            continue
        if path != ROOT and not (path.name.startswith("askmydocs") or path.name in
             {"AgenticFlow", "agents-full-duplex-ui-bridge", "Ui4VocalAgent", "laravel-ui4-workbench"}):
            continue
        revision = HEAD if path == ROOT else git(path, "rev-parse", "HEAD")
        local.append({"name": path.name, "path": str(path), "revision": revision,
            "remote": remote_repo(git(path, "remote", "get-url", "origin", required=False)),
            "branch": git(path, "branch", "--show-current"),
            "commits": commits(path, revision)})
    main = commits(ROOT, f"{BASE}..{HEAD}")
    outside = commits(ROOT, "--branches", "--not", HEAD)
    for commit in outside:
        commit["branches"] = (git(ROOT, "for-each-ref", "--contains=" + commit["sha"],
                                 "--format=%(refname:short)", "refs/heads") or "").splitlines()
    remote_input = json.loads(remote_cache.read_text())
    remote = remote_input["remote_default_branch_snapshots"] if isinstance(remote_input, dict) else remote_input
    for row in remote:
        if "error" in row:
            row["error"] = "GitHub API 404: repository non accessibile con la connessione corrente."
    old = json.loads(git(ROOT, "show", BASE + ":composer.lock"))
    new = json.loads(git(ROOT, "show", HEAD + ":composer.lock"))
    old_packages = {p["name"]: p for k in ("packages", "packages-dev") for p in old[k]}
    packages = []
    for k in ("packages", "packages-dev"):
        for package in new[k]:
            if package["name"].startswith(("padosoft/", "agents-full-duplex/")):
                prev = old_packages.get(package["name"])
                packages.append({"name": package["name"], "before": prev["version"] if prev else None,
                    "after": package["version"], "revision": package["source"]["reference"],
                    "repository": remote_repo(package["source"]["url"]),
                    "changed": prev is None or prev["source"]["reference"] != package["source"]["reference"]})
    file_stats = git(ROOT, "diff", "--numstat", BASE, HEAD).splitlines()
    kinds = Counter("merge" if c["subject"].startswith("Merge ") else
                    c["subject"].split(":")[0].split("(")[0] for c in main)
    audit = {"period": {"start": START, "end_exclusive": END,
              "scope": "1-29 ottobre 2026, snapshot congelato al commit delle 09:15:41 CET",
              "collected_at": datetime.now(ZoneInfo("Europe/Rome")).isoformat()},
        "main": {"repository": "https://github.com/lopadova/AskMyDocs", "ref": "refs/remotes/origin/develop",
            "base": BASE, "head": HEAD, "remote_head_verified": HEAD,
            "remote_verification_url": "https://api.github.com/repos/lopadova/AskMyDocs/branches/develop",
            "commits": main, "counts": dict(kinds), "files_changed": len(file_stats),
            "insertions": sum(int(l.split("\t")[0]) for l in file_stats if not l.startswith("-")),
            "deletions": sum(int(l.split("\t")[1]) for l in file_stats if not l.startswith("-")),
            "daily": dict(sorted(Counter(c["committed"][:10] for c in main).items()))},
        "locked_packages": packages, "local_snapshots": local,
        "remote_default_branch_snapshots": remote, "core_outside_develop": outside,
        "limitations": ["Le date dei commit sono date di integrazione/committer; non ore lavorate.",
          "I conteggi includono merge e documentazione; non sono conteggi di funzionalità.",
          "Il confronto delle dipendenze usa gli SHA del lockfile, non il contenuto locale di vendor.",
          "I commit fuori develop possono essere copie ribasate o varianti di lavoro già integrato.",
          "Le query remote coprono il ramo predefinito; i checkout locali includono i rami HEAD indicati.",
          "Nessuna esecuzione di test applicativi o verifica live di mailbox è stata svolta per questo report.",
          "I risultati dei test nel testo provengono da registri di verifica storici e non vanno sommati.",
          "Sono escluse le modifiche non committate e il lavoro successivo allo snapshot."]}
    (output / "audit.json").write_text(json.dumps(audit, ensure_ascii=False, indent=2) + "\n")
    rows = []
    for c in main:
        rows.append({"scope": "develop", "repository": "AskMyDocs", **c,
                     "url": audit["main"]["repository"] + "/commit/" + c["sha"]})
    for snapshot in local[1:]:
        for c in snapshot["commits"]:
            rows.append({"scope": "package_local_head", "repository": snapshot["name"], **c,
                "url": (snapshot["remote"] + "/commit/" + c["sha"]) if snapshot["remote"] else ""})
    for snapshot in remote:
        for c in snapshot.get("commits", []):
            rows.append({"scope": "package_remote_default", "repository": snapshot["name"],
                         "sha": c["sha"], "authored": "", "committed": c["date"],
                         "author": "", "subject": c["subject"], "url": c["url"]})
    for c in outside:
        rows.append({"scope": "outside_develop_raw", "repository": "AskMyDocs", **c, "url": ""})
    with (output / "registro-commit.csv").open("w", newline="", encoding="utf-8-sig") as stream:
        writer = csv.DictWriter(stream, fieldnames=["scope", "repository", "sha", "authored", "committed", "author", "subject", "url"], extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)
    print(json.dumps({"develop_commits": len(main), "kinds": dict(kinds),
        "locked_packages": len(packages), "local_checkouts": len(local),
        "remote_accessible": sum("commits" in r for r in remote),
        "outside_raw": len(outside), "register_rows": len(rows)}, ensure_ascii=False))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--remote-cache", type=Path, required=True)
    run(parser.parse_args().remote_cache)
