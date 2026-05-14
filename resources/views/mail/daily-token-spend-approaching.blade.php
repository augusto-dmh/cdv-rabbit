cdv-rabbit — Daily Token Spend Alert
=====================================

Workspace: {{ $workspace->name }}

Your workspace has consumed {{ number_format($consumed) }} tokens today
out of a daily cap of {{ number_format($cap) }} tokens
({{ round($consumed / max($cap, 1) * 100, 1) }}% used).

If you expect this to continue, consider raising your daily_token_cap
or enabling the kill switch to pause reviews until tomorrow.

This alert was sent because consumption exceeded your configured
threshold of {{ $workspace->daily_token_cap_alert_threshold }}%.

--
cdv-rabbit · automated code review
