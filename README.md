# Argano Dispatch — Take-Home

A small internal document-sharing app. Your job is to extend it with three
features that "customers" have been asking for.

> **How to use this repo.** Fork or clone it into a repo of your own (private
> is fine), do the work there, and send your interviewer a link to your fork
> or a zip of your branch. The exercise prompt itself is MIT-licensed — see
> `LICENSE`.

## What you'll need

Just Docker (with Compose). PHP, SQLite, and the rest live inside the
container — there's nothing to install on the host.

```
docker compose up
```

Then open <http://localhost:8000>. The first run builds the image (~30s);
subsequent runs are instant.

Every `docker compose up` re-seeds `db.sqlite` from scratch, so you always
start from a known state. `Ctrl+C` stops everything.

To run the test suite:

```
docker compose exec app php tests/test.php
```

You edit files on your host with whatever editor you like — the project
directory is mounted into the container, so changes are picked up on the next
browser refresh.

## The app

Argano Dispatch is a small internal tool that lets staff prepare documents
and hand them to recipients via single-use share links. The repo gives you a
staff admin page, a creation form, share-link generation, and a recipient
view. The schema (`schema.sql`) and the helpers in `lib/bootstrap.php` are
meant to feel like a real (if scrappy) line-of-business app, not a tutorial.

Spend a few minutes reading the code before you start writing any.

## Working with AI

How you set this repo up for AI-assisted development is **part of what we're
evaluating**. Context files, permissions, hooks, custom commands, house
conventions, subagent or parallel orchestration, custom skills — whatever
matches the way you actually work.

We're deliberately not prescribing a tool or a setup. Commit what you would
commit on a real Argano engagement. If you decide that bespoke setup isn't
worth the cost on a three-hour exercise, that's a defensible answer — say so
in your video and tell us why.

## What to build

Three customer requests. Pick whatever order makes sense to you, scope each
one yourself, and ship as much as you can in the time you have.

### 1. Schedule when a document goes live

Staff need to be able to author a document now and have it become visible to
recipients at a specific date and time. Anyone hitting the share link before
that moment should see a "not yet available" message rather than the
document body.

### 2. Short, human-friendly document IDs

Today documents are referred to by raw auto-increment integers (`#1`, `#2`)
and share links are opaque hex tokens. Customers want each document to carry
a **short identifier a human can actually use** — read out loud on a call,
type into the address bar, paste into an email. Examples of the rough shape
(not a spec): `welcome-2026`, `onboarding-packet-3k`, `DSP-7QX4`.

The format, length, and URL design are yours to choose. Think about
collisions, guessability, and how this lives alongside (or replaces) the
existing share-token mechanism.

### 3. Find a document by title

Staff need to find a document to share by searching for it by title rather
than scrolling. You decide what "search" means — exact, prefix, substring,
fuzzy, ranked — and explain why your choice fits.

## What we're deliberately leaving open

- Whether the readable IDs **replace** the existing share-token mechanism or
  **sit alongside** it. There are real tradeoffs (privacy, guessability,
  link permanence) — make a call.
- The URL structure for viewing a document.
- How you organise and run schema migrations (see Requirements below — this
  repo has no migration tooling yet).
- How the three features interact with each other.

We want to see your judgment, not just your code. Use the video to walk us
through the calls you made.

## Requirements

- **Schema changes go through migration files you add to the repo**, not by
  editing `schema.sql` directly. There is no migration runner today — the
  design of one (or the decision to keep it dead simple) is yours. Tell us
  about it in the video.
- Each feature you ship has at least one test. The existing pattern is in
  `tests/test.php` — copy it or improve it, your call.
- Document creation, scheduling changes, and share actions are written to
  `audit_log`. The pattern is in `lib/bootstrap.php`.
- `docker compose up` from a fresh clone of your branch must still work for
  whoever reviews it.

## What to send back

1. A branch (or a zip) with your changes, plus a commit log that tells the
   story of how you got there.
2. A short walkthrough video (~5 min). It should cover:
   - What you built and what you deliberately scoped out.
   - The design decisions you made and the alternatives you rejected.
   - Anything in the existing code you'd flag in a real PR review.
   - What you'd do with another half day.
   - **Your AI workflow.** What you used AI for, what you did yourself, a
     moment you pushed back on a suggestion, and where you noticed AI
     helping versus hurting.
3. *(Optional)* Chat transcripts or links if they're easy to share. A
   thoughtful minute on video beats an unedited log dump.

## Time

Plan on roughly **three hours of focused work**. You probably won't finish
all three features cleanly — that's expected and fine. Prioritise, ship the
parts you finish well, and tell us what you skipped and why. Partial and
thoughtful beats rushed and complete every time.

## What we're looking for

- How you handle ambiguity — the spec is fuzzy on purpose.
- How you gather context before you start writing.
- How you set up and work with AI tools, including when you push back.
- How you verify your own work.
- How you communicate tradeoffs and surprising things you ran into.

Finished-but-sloppy loses to unfinished-but-thoughtful.
