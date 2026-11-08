# History rewrite runbook: removing redacted names from git history

> **Not done yet.** This is a plan, written 2026-11-06. It needs Finn's
> explicit approval on the day (planned Monday 2026-11-09, before the
> 2026-11-13 code freeze), including changing the CLAUDE.md rule "never
> rewrite history" and running `merchant:lock --force`. Nothing here has
> been run.

## Why

Merchant data is public business information only, with no owner names or
personal details (Prof. Yoest's rule; TRANSPARENCY.md "Merchant data: public
business information only"). The names were removed from the code on
2026-10-21, but the repo is public and older commits still hold them.

## What the audit found (2026-11-06)

The audit ran with scripts that keep the names in memory only and print
counts, paths and commit hashes. **No name appears in this document.**

- **9 names:** 4 real people the AI named in its merchant reasoning
  (redacted in `34fdc84`) and 5 candidate businesses whose names are a
  person's (removed in `3e59269`). Also one personal detail: a café's
  founders described as military veterans (2 snippets).
- **Files:**
  - People's names: `data/merchant_assessments.json`,
    `docs/evals/merchant-eval-latest.json`,
    `docs/evals/merchant-eval-results.md`, and 4 files in
    `docs/evals/merchant-runs/` (the 2026-10-15 runs for prompts 1abdea31 dev,
    458e8c2d dev, 458e8c2d holdout `.json` and `.md`).
  - Candidate names: `data/merchant_candidates.csv` only.
  - Veteran detail: the same merchant output files.
- **Commits:**
  - People's names in 86 commits, `c3adfce` (Oct 15) to `9442899` (Oct 19).
  - Candidate names in 100 commits, `6b68968` (Oct 14) to `b181ab5` (Oct 21).
  - None in HEAD, none in any commit message.
- **Nowhere else:**
  - One branch (`main`) on Nov 6; no tags, stashes or notes. By Nov 7
    `redesign-a` and `design-pass` existed (both merged, 0 commits not in
    `main`) and were deleted locally; see step 1 for GitHub.
  - On GitHub: 0 forks, 0 issues, 0 pull requests, 0 releases, 0 Actions
    artifacts.
  - All 60 CI runs from Oct 14 to 22 were scanned, and none of their logs
    contains a name.
- **What a rewrite changes:** 289 of 408 commits on Nov 6 (everything from
  `6b68968` on); 319 of 438 in the Nov 7 dry run at `77a402d` (names and
  snippet counts unchanged). Every new commit adds one: **recount on the day** with the
  dry run before step 2.
  - The fraud eval lock commit `5f594c1` comes earlier, so it **doesn't
    change**.
  - The merchant label commit `30b610e` **does change**.

## Why it is safe

- HEAD has none of the names, so **HEAD's tree hash is identical after the
  rewrite**. That proves the current code didn't change.
- `data/merchant_labels.csv` has none of the names, so **its blob hash is
  identical** at the new labeling commit. Anyone can check that the labels
  weren't touched.
- `git filter-repo` keeps author and commit dates.

## Every place that cites a commit hash

Map each old hash with filter-repo's `.git/filter-repo/commit-map`
(`old new` full hashes per line).

| Where | Hash | How to update |
|---|---|---|
| `data/merchant_labels_lock.json` | `30b610e…` (full) | `php artisan merchant:lock --commit=<new full hash> --force` (rows unchanged; combined hash recomputed; `MerchantLabelLockTest`) |
| CLAUDE.md, TRANSPARENCY.md | `30b610e` | new short hash |
| `docs/accessibility.md` | `4349e39` `458f89d` `4790ea7` `7d64000` `80e1157` `83353e6` `9064eb0` `c1c27f5` `e19f070` | map each |
| `docs/adr/0028-refunds-and-corrections.md` | `d8aa1ea` | map |
| fraud lock, CLAUDE.md, TRANSPARENCY.md, `docs/evals/fraud-*`, `docs/evals/runs/*` | `5f594c1` | **unchanged**: check it still resolves |
| `.last-deploy-commit`, `.deploy-zip-commit` (git-ignored) | last deployed / last zipped commit | replace **both** with the mapped full hash from the commit-map, so `scripts/deploy-migrations.sh` still works |
| `docs/history-rewrite-runbook.md` (this file) | the hashes it cites | map each |
| `4c6ac43` in `resources/views/admin/feedback.blade.php` | placeholder text, not a citation | none |
| Live database `feedback.fixed_commit_sha` (judges see "Fixed in commit") | each one | Finn edits them in `/admin/feedback`. Never by script, never by Claude |
| `/eval/merchants` and fraud results links | from the locks | follow the lock change; live after the next deploy |
| Old GitHub Actions runs | old hashes | leave them (they show timing); explain in the ADR |
| `4c6ac43` in `FeedbackInboxController` | example text, not a citation | none |

Every cited hash and both deploy markers are mapped through the
commit-map, never by hand. A hash with no line in the commit-map is written
as "old hash (before Nov 9 rewrite)" next to the old short hash.

Rerun the scan before the day, in case new citations were added since:
`git grep -n -o -I -w -E '[0-9a-f]{7,40}' -- ':!composer.lock' ':!package-lock.json' ':!public/build' ':!data/sources'`,
keeping the matches where `git cat-file -t` says `commit`.

## Runbook

1. **Approve.** Finn changes the CLAUDE.md rule and okays `merchant:lock --force`.
   - CI is green on `main`.
   - `main` is the only branch, locally and on GitHub
     (`git ls-remote --heads origin` shows only `refs/heads/main`). Any
     other branch is deleted first, after `git rev-list --count main..<branch>`
     says 0; otherwise it would keep the old history reachable after the
     force-push.
   - Nobody pushes until step 7 is done.
   - `brew install git-filter-repo`.
2. **Back up.**
   - `git clone --mirror https://github.com/finnbutler1/local-goods.git ~/lg-backup-2026-11-09.git`.
     It contains the names: keep it on this computer only, never push it,
     and delete it in step 9.
   - Record `git rev-parse HEAD^{tree}` and `git rev-parse HEAD:data/merchant_labels.csv`.
3. **Build the replacement file without printing it.**
   - Save the script below as `~/lg-redactions.py`, outside the repo.
   - From the repo, run `python3 -I ~/lg-redactions.py ~/lg-redactions.txt`.
     It prints only counts: expect `names: 4, anchored non-name edits: 2,
     candidates: 5` and `wrote 11 lines`.
   - Never open, print or commit `~/lg-redactions.txt`.
4. **Rewrite a fresh clone**, not the working copy:
   - `git clone https://github.com/finnbutler1/local-goods.git ~/lg-rewrite && cd ~/lg-rewrite`
   - `git filter-repo --replace-text ~/lg-redactions.txt`
5. **Verify before pushing.** In `~/lg-rewrite`:
   - `git rev-parse HEAD^{tree}` equals step 2's value.
   - `git rev-parse HEAD:data/merchant_labels.csv` equals step 2's value.
   - Run the audit with the names read from the backup and the search done
     in the rewritten repo:
     `cd ~/lg-backup-2026-11-09.git && python3 -I ~/lg-redactions.py --audit ~/lg-rewrite`.
     It must print `commits x terms with a hit: 0` and exit 0.
   - `git cat-file -t 5f594c1` says `commit`.
   - `composer install && php -d memory_limit=2G vendor/bin/phpunit` passes.
6. **Push.**
   - `git remote add origin https://github.com/finnbutler1/local-goods.git`
   - `git push --force origin main`
   - Then `git clone https://github.com/finnbutler1/local-goods.git ~/lg-check`
     and rerun the audit from the backup against it:
     `cd ~/lg-backup-2026-11-09.git && python3 -I ~/lg-redactions.py --audit ~/lg-check`.
     It must still print 0.
7. **Follow-up commit** in a fresh clone (retire the old working copy;
   don't pull into it):
   - Update every hash in the table above.
   - Re-lock the merchant labels.
   - Add ADR 0039 (0037 and 0038 are taken) with a table mapping old hashes to new ones for every
     cited commit.
   - Rewrite TRANSPARENCY.md's "Earlier versions remain in the public git
     history" paragraph and the history sentence under "What a person checks
     by hand".
   - Add a CHANGELOG entry and update CLAUDE.md.
   - Wait for CI, then deploy (no migrations). Finn updates the feedback
     SHAs in `/admin/feedback`.
8. **GitHub Support** (support.github.com → "Remove sensitive data from a
   repository"):
   - Give the repo URL, and say the history was rewritten and force-pushed
     on <date>.
   - Attach the list of old commit hashes that held the data (the 100 from
     `6b68968` to `b181ab5`; hashes only, no names).
   - Confirm there are no forks, pull requests or artifacts.
   - Ask them to remove cached views and garbage-collect the unreferenced
     commits, so old hashes no longer open on github.com.
9. **Clean up** after Support confirms: delete `~/lg-backup-2026-11-09.git`,
   `~/lg-redactions.txt` and `~/lg-rewrite`.
10. **Check GitHub by direct URL.** A few days after Support's reply, open
    `https://github.com/finnbutler1/local-goods/commit/<old hash>` for a
    sample of the old hashes from step 8 (first, last and a few between)
    while logged out. If any still opens, reply to the Support ticket with
    those hashes and ask again for the cached views to be purged. Done when
    none of them opens.

Consequences to accept:
- All 289 later commit hashes change.
- Anyone who cloned before keeps the old history (no forks are known).
- Old commits may stay reachable on GitHub until Support purges them.
- Old CI runs point at hashes that will stop resolving.

## The script

It reads the two redaction commits and never prints a name, only counts.
It keeps an edit only if its old text is gone from HEAD, so HEAD can't
change. Non-name edits keep 25 characters of context on each side, so a
common word is never replaced on its own. Candidate names are anchored to
their `candidate_id,` field, because some are very short.

The two redaction commits get new hashes in the rewrite, and their diffs
disappear. So the script always reads the names from a repo that still has
the old history (the working copy before the rewrite, or the backup), and
`--audit TARGET` searches another repo.

```python
#!/usr/bin/env python3
"""Build the git filter-repo --replace-text file for the history rewrite.

Reads the two redaction commits (34fdc84: names in the AI's merchant
reasoning; 3e59269: five person-named candidates) and writes one
`literal==>replacement` line per redaction. It NEVER prints a name: only
counts. Usage (from the repo, before the rewrite):

    python3 -I redactions.py OUT_FILE         # writes OUT_FILE (mode 600)
    python3 -I redactions.py --audit [REPO]   # counts hits in all of REPO's history

Run it from a repo that still has the old history (the names are read from
its two redaction commits). REPO defaults to the current one.
"""
import csv, difflib, io, os, re, subprocess, sys

def git(*a, repo=None):
    pre = ["-C", repo] if repo else []
    return subprocess.run(["git", *pre, *a], capture_output=True, text=True, check=False).stdout

def changed_line_pairs(commit, *paths):
    """(old line, new line) pairs for each changed hunk, by file."""
    diff = git("show", "--format=", "--unified=0", commit, "--", *paths)
    pairs, minus, plus = [], [], []
    def flush():
        pairs.extend(zip(minus, plus)); minus.clear(); plus.clear()
    for line in diff.splitlines():
        if line.startswith(("@@", "diff ")):
            flush()
        elif line.startswith("-") and not line.startswith("---"):
            minus.append(line[1:])
        elif line.startswith("+") and not line.startswith("+++"):
            plus.append(line[1:])
    flush()
    return pairs

TOKEN = re.compile(r"\[name removed\]|\w[\w'’.-]*|[^\w\s]")

def ai_redactions():
    """Names (global) and the veteran wording (anchored with context)."""
    names, anchored = set(), set()
    for old, new in changed_line_pairs("34fdc84", "data", "docs"):
        a, b = TOKEN.findall(old), TOKEN.findall(new)
        for op, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes():
            if op not in ("replace", "delete"):
                continue
            if "[name removed]" in b[j1:j2]:
                names.add(" ".join(a[i1:i2]))
        # Character level for the non-name edits: the changed middle plus
        # 25 characters each side, so a common word is never replaced alone.
        p = 0
        while p < min(len(old), len(new)) and old[p] == new[p]:
            p += 1
        s = 0
        while s < min(len(old), len(new)) - p and old[-1 - s] == new[-1 - s]:
            s += 1
        lo, hi = max(0, p - 25), 25
        o, n = old[lo:len(old) - s + min(s, hi)], new[lo:len(new) - s + min(s, hi)]
        if "[name removed]" not in new[p:len(new) - s]:
            anchored.add((o, n))
    # An edit whose old text is still in HEAD wasn't a redaction (e.g. the
    # added "redacted" note); HEAD is already clean and must not change.
    anchored = {(o, n) for o, n in anchored if not git("grep", "-F", "-l", "-e", o, "HEAD").strip()}
    return sorted(names), sorted(anchored)

def candidate_redactions():
    before = git("show", "3e59269^:data/merchant_candidates.csv")
    kept = {r["candidate_id"] for r in csv.DictReader(io.StringIO(git("show", "3e59269:data/merchant_candidates.csv")))}
    out = []
    for raw in before.splitlines():
        row = next(csv.reader([raw]))
        if row and row[0] not in kept and row[0] != "candidate_id":
            field = raw[len(row[0]) + 1:].split(",", 1)[0] if not raw[len(row[0]) + 1:].startswith('"') else '"' + row[1].replace('"', '""') + '"'
            literal = f"{row[0]},{field},"
            assert literal in raw
            out.append((row[1], literal, f"{row[0]},[name removed],"))
    return out

def main():
    names, anchored = ai_redactions()
    cands = candidate_redactions()
    print(f"names: {len(names)}, anchored non-name edits: {len(anchored)}, candidates: {len(cands)}")
    if sys.argv[1:2] == ["--audit"]:
        target = os.path.expanduser(sys.argv[2]) if len(sys.argv) > 2 else None
        terms = names + [c[0] for c in cands]
        hits = 0
        for rev in git("rev-list", "--all", repo=target).split():
            for t in terms:
                if git("grep", "-F", "-i", "-l", "-e", t, rev, repo=target).strip():
                    hits += 1
            for o, _ in anchored:
                if git("grep", "-F", "-l", "-e", o, rev, repo=target).strip():
                    hits += 1
        head = sum(bool(git("grep", "-F", "-i", "-l", "-e", t, "HEAD", repo=target).strip()) for t in terms)
        print(f"commits x terms with a hit: {hits}; terms in HEAD: {head}")
        sys.exit(1 if hits else 0)
    if len(sys.argv) != 2 or sys.argv[1].startswith("-"):
        sys.exit("usage: redactions.py OUT_FILE | --audit [REPO]")
    fd = os.open(sys.argv[1], os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as f:
        for n in names:
            f.write(f"{n}==>[name removed]\n")
        for o, n in anchored:
            f.write(f"{o}==>{n}\n")
        for _, o, n in cands:
            f.write(f"{o}==>{n}\n")
    print(f"wrote {len(names) + len(anchored) + len(cands)} lines")

if __name__ == "__main__":
    main()
```
