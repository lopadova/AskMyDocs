"""Nightly retention sweeper (STD-DAT-008).

Deletes files older than the retention period of their category. The root
directory is read from the NW_RETENTION_ROOT environment variable.
"""
from __future__ import annotations

import argparse
import os
import time
from dataclasses import dataclass
from pathlib import Path

SECONDS_PER_DAY = 86_400
ROOT_ENV_NAME = "NW_RETENTION_ROOT"


@dataclass(frozen=True)
class Policy:
    name: str
    subdir: str
    max_age_days: int


POLICIES = (
    Policy("application_logs", "logs/app", 90),
    Policy("security_logs", "logs/security", 365),
    Policy("support_call_recordings", "recordings", 60),
    Policy("candidate_resumes", "recruiting/rejected", 183),
    Policy("customer_orders", "orders/inactive", 730),
    Policy("billing_documents", "billing", 3650),
)


def cutoff_timestamp(now: float, max_age_days: int) -> float:
    """Files last modified before this timestamp are expired."""
    return now - max_age_days * SECONDS_PER_DAY


def sweep_policy(root: Path, policy: Policy, now: float, dry_run: bool = True) -> int:
    """Remove (or just count, when dry_run) expired files of one policy."""
    base = root / policy.subdir
    if not base.is_dir():
        return 0

    limit = cutoff_timestamp(now, policy.max_age_days)
    removed = 0
    for path in base.rglob("*"):
        if not path.is_file() or path.stat().st_mtime >= limit:
            continue
        removed += 1
        if not dry_run:
            path.unlink()
    return removed


def sweep(root: Path, now: float | None = None, dry_run: bool = True) -> dict[str, int]:
    """Run every policy and return the number of expired files per category."""
    moment = time.time() if now is None else now
    return {p.name: sweep_policy(root, p, moment, dry_run) for p in POLICIES}


def main() -> int:
    parser = argparse.ArgumentParser(description="Delete files past their retention period")
    parser.add_argument("--apply", action="store_true", help="really delete (default is dry run)")
    args = parser.parse_args()

    root_value = os.environ.get(ROOT_ENV_NAME)
    if not root_value:
        parser.error(f"environment variable {ROOT_ENV_NAME} is not set")

    report = sweep(Path(root_value), dry_run=not args.apply)
    for name, count in report.items():
        print(f"{name}: {count} expired")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
