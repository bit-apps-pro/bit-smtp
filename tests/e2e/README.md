# Bit SMTP — End-to-End tests (Playwright)

These specs drive the plugin's React admin against a **real WordPress install** and assert the
four core flows end-to-end.

| Spec                          | Flow                                                                                                                                      |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `connection-setup.spec.ts`    | Add connection → pick provider → fill Identity + SMTP settings → Save → appears in the list                                               |
| `send-test.spec.ts`           | An SMTP→Mailpit connection's **Test Connection** actually delivers (confirmed via the Mailpit API)                                        |
| `notifications.spec.ts`       | Save email, Slack, and Telegram failure alerts → reload with masked secrets → test each channel through an intercepted WordPress endpoint |
| `delivery-webhook-ui.spec.ts` | A SendGrid connection's webhook panel: pasteable URL, verification badge, metadata-driven "Create webhook" button                         |

## Prerequisites

- A running WordPress site with **Bit SMTP active** and the frontend built (`pnpm build`).
- **Mailpit** reachable (SMTP `:1025`, REST `:8025`) — the send test delivers to it.
- A WordPress **administrator** account for login.

## Run

```bash
pnpm e2e            # headless, all specs
pnpm e2e:report     # open the last HTML report
pnpm exec playwright test --ui   # interactive
```

## Configuration (env overrides)

| Var                           | Default                            | Purpose                                                                                    |
| ----------------------------- | ---------------------------------- | ------------------------------------------------------------------------------------------ |
| `E2E_BASE_URL`                | `http://wp-dev.io`                 | WordPress base URL                                                                         |
| `E2E_WP_USER` / `E2E_WP_PASS` | `codex-e2e` / a local dev password | admin login used by the auth fixture (a throwaway local account — never a real credential) |
| `E2E_MAILPIT_URL`             | `http://localhost:8025`            | Mailpit REST base                                                                          |
| `E2E_WP_PATH`                 | `/mnt/src/work/wp/sites/wp-dev`    | WordPress root, used by the global-teardown wp-cli cleanup                                 |

## Notes

- Login happens once (`auth.setup.ts`) and the session is reused via `storageState` (git-ignored).
- Specs create connections named `E2E SMTP …`; `global-teardown.ts` removes them via the plugin (best-effort, requires `wp-cli`).
- The notifications spec snapshots and restores its global and channel settings in `afterAll`. Its Slack and Telegram test-send requests are intercepted at the WordPress REST endpoint, so the E2E run never contacts either provider.
- Artifacts (`.auth/`, `.results/`, `.report/`) are git-ignored.
