# Sprint 11 Report — Mobile / Responsive (learner portal)

**Status:** feature-complete · **239 tests** (was 235 at S10 close; +4) · Pint +
PHPStan (L6) clean on PHP 8.3. Verified in-browser at 375 / 1280px against the
mobile-first design. A **re-skin, not a feature sprint** — no new product
capabilities, only responsive layout + navigation.

## Objective
Make the **learner/manager portal** work well on a phone and tablet without
regressing desktop, matching the owner's `CipherLearn Learner App.html`
mobile-first design.

## Scope (owner-confirmed at kickoff)
- **Portal only** — the Filament admin panel is **out of scope** (already
  responsive out of the box; admins are desktop-first).
- **Mobile nav = a bottom tab bar** (confirmed from the design, not a drawer):
  **Learning · Paths · Certificates · Profile**, with **My team** for managers.
  Profile opens the account sheet (switcher + sign out).
- **Existing surfaces only** — the mockup's Chat, Progress and Announcements
  screens are unbuilt features (chat was deferred; notifications live in the bell)
  and stay deferred.

## The core move: one shared responsive shell
The portal shell (sidebar + top bar) had been **copy-pasted into all 7 portal
views**, and only the dashboard had any mobile treatment — the other six had
`hidden lg:flex` sidebars and **no mobile nav at all** (headless on a phone).

Extracted into **one shared, responsive shell**:
- **`<x-portal.shell>`** (`App\View\Components\Portal\Shell`) — desktop left
  sidebar (≥lg); on mobile a sticky top bar + a **fixed bottom tab bar**. It
  **computes its own chrome** from the signed-in user (initials, job title, roles
  → team & admin access), so a page supplies only its `title`, optional top-bar
  `actions`, and content. **Drill-down** pages (course, quiz) pass a `back` target
  → mobile back header, no bottom nav (the design's sub-screen pattern).
- **`<x-portal.account-menu>`** — the switcher/sign-out body, shared by the desktop
  dropdown and the mobile Profile bottom sheet.
- **`NotificationsBell`** — the bell extracted into its own Livewire component so it
  works on **every** page; its mark-as-read previously lived only on the Dashboard.

All 7 views now reduce to `<x-portal.shell>…content…</x-portal.shell>`, the
7-way duplication gone.

## Delivered
- **Shell + bell + account menu** as above.
- **All portal pages responsive:** dashboard (2-up→4-up tiles, stacked→3-column
  cards), catalogue & paths (card grids → one column), team (assign rows reflow;
  modal already fluid), certificates (now a proper top-level destination, not a
  back-header page), course player and quiz (single-column drill-downs with a
  mobile back header). Content restores the multi-column desktop view at `lg`.
- **Login** already collapsed to a single-column mobile sign-in (brand panel
  `hidden lg:flex`); confirmed at 375px.
- **Public certificate pages** — wrapped the long verify URL and tightened mobile
  padding so the certificate fits a phone (a learner shares a cert link).

## Tests (+4 → 239)
- `PortalShellTest` (3): the shell always shows the core destinations; **My team**
  is hidden from a plain learner and shown to a manager (someone with direct
  reports).
- `PortalNotificationsTest` updated: the bell's mark-as-read now targets the new
  `NotificationsBell` component (it renders on every page via the shell).
- The heavy lifting is visual, so **browser verification at 375/1280 is the primary
  proof** (dashboard, paths, course drill-down, team, Profile sheet, login).

## Explicitly NOT in this sprint (deferred, by design)
- Filament admin responsiveness (owner: portal only).
- Cohort chat / a standalone Progress page / a full Announcements page — unbuilt
  features the mockup shows; not responsive work.
- Native apps / PWA / offline · dark mode.

## Notes for the deploy sprint
- New Tailwind utility classes only style once assets are rebuilt; the production
  image must run `npm run build` on release (already on the deploy checklist).

## Next
**Sprint 12 — Deploy + demo** (scheduler for `notifications:overdue` /
`outbox:work` / `hris:sync-employees`, real mail, `migrate --force` +
`permissions:sync` across all tenants, `APP_DEBUG=false`) — closes V1.
