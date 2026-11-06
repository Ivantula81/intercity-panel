# Two-minute notification grace period

2026-11-06. Owner approved a server-enforced two-minute cancellation window
for new manifest notification runs and visible stop controls. Existing runs
and their timestamps must not be rewritten. No database migration.

RULE-01/02/03/06/07/11/13/15/17; RISK-001/004/008/009/013.

New runs atomically persist a server-owned start timestamp and set delivery
availability to database current time plus 120 seconds. Emergency requests do
not bypass this window. Replayed requests reuse the original run and timer.
Cancellation uses the existing transactional run-wide operation. A new
confirmation is required after changes; queued snapshots are not edited.
Existing expiry and Moscow working-hour checks remain authoritative. A run
whose grace ends after the dispatch deadline is skipped, not sent late.

Queue controls are no longer collapsible. Countdown uses the API server time,
ticks locally without extra API requests, and restores from persisted state.
Already accepted/in-flight sends are explicitly not described as recalled.
Free broadcasts are unchanged (only manifest notification launch is delayed).

Checks completed before release:

- Full `scripts/preflight.sh`: passed (PHP/JS syntax and regression suite).
- Nine new isolated SQLite assertions: enforced delay including emergency,
  early claim rejected, authoritative timestamp, replay without duplicates or
  timer reset, cancellation before start and after expiry, one claim after delay.
- Real-browser synthetic fixture at 1360 and 390 px: countdown and button label
  transition, slow provider, failed cancellation/retry, duplicate click,
  isolated run scope, partial result, reload, no overflow or JS runtime errors.
- Mobile screenshot visually inspected. No real messages sent by tests.

Limits: worker cadence may postpone actual start beyond 120 seconds; the UI
calls this waiting in the queue rather than promising immediate dispatch.
Public work-journal synchronization remains pending; operational infrastructure
details are deliberately absent from this document.
