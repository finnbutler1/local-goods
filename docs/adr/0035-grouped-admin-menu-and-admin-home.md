# 0035. A grouped admin menu and a Home page for each admin

**Status:** Accepted, implemented. Front end and routes only: no migration,
money, fraud prompt, AI input, eval data or lock change.

## Context

The BID admin and platform admin menus had grown to seven items, a "More"
menu and a four-slot phone bar, and the platform admin's landing page carried a
hand-written row of twelve shortcut links. Several pages (Corrections, Fee
Settings, Where the Money Is, Demo Activity) highlighted nothing, and the
platform admin's "Dashboard" was really the accounts table.

## Decision

- **One grouped menu** (`Role::navigationGroups()`): Home; Reviews & Decisions;
  Money; Program; AI & Evaluation; People & Access. The BID admin gets the
  subset it can open; a judge viewing as the BID admin gets the same structure,
  read-only, plus Judge Home. `Role::navigation()` is the flattened list.
- **`<x-nav-groups>`** renders it in the sidebar and in the phone Menu sheet.
  Group headings are real buttons (`aria-expanded`, `aria-controls`); the group
  with the current page starts open; with no JavaScript every group shows.
  The sheet is an `<x-menu>`, so Escape, arrow keys and focus return are
  unchanged. Open state is not remembered between pages.
- **Phone bar:** Home, two pinned pages (platform admin: Fraud Review, Where the
  Money Is; BID admin: Fraud Review, Impact) and Menu.
- **Home pages:** `/admin` (platform admin) and `/bid-admin/home` (BID admin;
  where a BID admin lands after sign-in and where a judge lands viewing as the
  BID admin). Cards: waiting for a person, solvency check (pass or fail and
  when), reconciliation (platform admin only, from the demo activity's stored
  check; never calls Stripe), demo activity, feedback (platform admin), recent
  activity (`App\Support\RecentActivity`: no names, emails or amounts).
  `/bid-admin` stays Fraud Review so bookmarks and tour links keep working.
- **Accounts** moved to `/admin/accounts`.
- **Titles:** every menu page's `<title>` and `<h1>` are its menu label; the old
  heading is a line under it. Menu labels are Title Case (a test checks).
- The judge tour and other "go to X" text use the exact menu labels.

## Consequences

- `AdminMenuCoverageTest` fails if an admin page a role can open is missing
  from that role's menu, if a menu link doesn't open, or if a title or heading
  drifts from its label.
- The platform admin's menu now says "Fee Settings" on every page, so
  `BuyCreditsFlowTest::mentionsFees` reads the page body, not the menu. The
  rule that employer and reconciliation pages don't talk about fees while none
  is earned still applies to the page itself.
- Merchant Eval and District Map are public pages without the admin sidebar;
  the menu links to them as before.
- Not yet tried in a real browser or with a screen reader.
