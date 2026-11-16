<?php

declare(strict_types=1);

namespace KnoxCall;

/**
 * 402 — a plan/billing limit was hit. Two error types share this status:
 * `plan_limit` (a counted quota was reached) and `plan_feature` (the capability
 * itself is not on the tier). The mapping is on STATUS, so both land here. Distinct
 * from PermissionDeniedException (403) so a caller can show an "upgrade" prompt
 * rather than an "access denied" one. Carries code/message/requestId like the
 * other typed exceptions.
 */
class PaymentRequiredException extends ApiException {}
