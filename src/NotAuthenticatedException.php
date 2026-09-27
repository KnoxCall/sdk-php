<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * No usable credential was found by auto-detection (PARITY §1, added 2026-08).
 *
 * Raised when the credential chain gives up with none of: an explicit
 * credential option, the `KNOXCALL_ACCESS_TOKEN` / `KNOXCALL_API_KEY` env
 * token, the `knoxcall login` credentials file, or the
 * `KNOXCALL_CLIENT_ID` + `KNOXCALL_CLIENT_SECRET` env pair.
 *
 * A subclass of {@see KnoxCallException} — the SDK's generic bootstrap error —
 * so existing `catch (KnoxCallException)` blocks keep working, but distinctly
 * typed so callers can branch on "not logged in — offer login()" versus a
 * genuine misconfiguration. See {@see KnoxCall::login()} /
 * {@see KnoxCall::ensureLogin()}.
 */
class NotAuthenticatedException extends KnoxCallException {}
