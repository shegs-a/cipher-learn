# Sprint 11 Plan — Mobile / Responsive (learner portal)

**Goal:** make the **learner/manager portal** work well on a phone (375px) and tablet,
without regressing the desktop (≥1024px) experience — matching the owner's
`CipherLearn Learner App.html` mobile-first design. **This is a re-skin, not a
feature sprint:** no new product capabilities, only responsive layout + navigation.

## Scope (owner-confirmed at kickoff)
- **Portal only.** The **Filament admin panel is explicitly OUT of scope** this
  sprint (it is already responsive out of the box; admins are desktop-first).
- **Mobile navigation = a bottom tab bar** (the design uses this, not a drawer):
  **Learning · Paths · Certificates · Profile**, with **My team** appearing as an
  extra tab for managers. The **Profile** tab opens the account sheet (portal
  switcher + notifications + sign out).
- **Match the mockup's existing surfaces only.** The mockup's `Chat` and `Progress`
  tabs, its cohort-chat screen, and its full "Announcements" page correspond to
  features that are **not built** (chat was deferred; there is no standalone
  progress/announcements page — notifications live in the bell). Those stay
  **deferred**; we do not build them here.

## The core move: one shared responsive shell
Today the portal **shell (sidebar + top bar) is duplicated across all 7 portal
Blade views** (`dashboard`, `catalogue`, `paths`, `team`, `certificates`, `course`,
`quiz`); only `dashboard` has a partial mobile top-bar, and the other six have
`hidden lg:flex` sidebars with **no mobile nav at all** (headless on a phone).

Extract the shell into **one shared layout** — a `<x-portal.shell>` Blade component
(desktop sidebar + top bar + **mobile bottom nav**) — and reduce each page to its
content. Responsiveness is then solved **once**. This also removes a real
duplication smell. Every page keeps its current server data/behaviour untouched.

### Breakpoints
- **`< lg` (mobile + tablet portrait, incl. 375px):** no sidebar; sticky top bar
  (brand + notifications + avatar) + **fixed bottom tab bar**; single-column content.
- **`≥ lg` (desktop, 1280px target):** persistent left sidebar as today; bottom nav
  hidden; multi-column content restored.

## Work breakdown
1. **Shared shell component** — `resources/views/components/portal/shell.blade.php`
   (+ a small nav-items helper): desktop sidebar (lifted from the current markup),
   sticky mobile top bar, and the fixed bottom tab bar with the confirmed tabs +
   the manager-only **My team** tab. Active-tab state driven by the current route.
2. **Adopt the shell in all 7 portal views** — replace each page's hand-rolled
   sidebar wrapper with `<x-portal.shell>…content…</x-portal.shell>`. No logic
   changes; Livewire components and their data stay as-is.
3. **Make the content responsive, page by page:**
   - Dashboard: stat tiles 4-up → 2-up (sm) → stacked; course cards two-column →
     stacked; the "why you were assigned this" block stays the hero.
   - Catalogue / Paths: card grids collapse to one column; filter/enrol buttons go
     full-width and thumb-reachable.
   - Course player: side-by-side modules/content → stacked; sticky "Start / Mark
     complete" action within thumb reach.
   - Quiz + Result: single-column; large tap targets for options; certificate card
     fits 375px.
   - Team (manager): the assign UI + reports list reflow to one column.
   - Certificates: card grid → single column.
4. **Login (Livewire `Auth\Login`)** — the desktop split-screen collapses to the
   mockup's single-column mobile sign-in (brand panel hidden `< lg`).
5. **Public certificate pages** (`/certificates/{…}` print + `/verify/{serial}`) —
   confirm they read well on a phone (a learner shares a cert link); light fixes only.
6. **Verification** — drive the real app in the in-app browser at **375 / 768 /
   1280** across the whole journey; screenshot the key screens. Add lightweight
   tests where they add value (e.g. the bottom nav renders the manager-only Team tab
   only for managers; the shell renders for each page) — the heavy lifting here is
   visual, so browser verification is the primary proof.

## Explicitly NOT in this sprint (deferred, by design)
- **Filament admin** responsiveness (owner: portal only).
- **Cohort chat / course chat**, a **standalone Progress page**, a full
  **Announcements page** — unbuilt features the mockup shows; not responsive work.
- Native apps / PWA / offline. Dark mode.

## Definition of done
- Every portal page usable at 375px with the bottom tab bar; no horizontal scroll;
  desktop (1280px) unchanged.
- One shared shell; the 7-way duplication gone.
- Login + public cert/verify pages read well on a phone.
- Full suite + Pint + PHPStan (L6) green on PHP 8.3; browser walkthrough captured in
  `docs/sprint-11-report.md`.

## Next
**Sprint 12 — Deploy + demo** (wire the scheduler for `notifications:overdue` /
`outbox:work` / `hris:sync-employees`, real mail, `migrate --force` + a
`permissions:sync` across all tenants, `APP_DEBUG=false`) — closes V1.
