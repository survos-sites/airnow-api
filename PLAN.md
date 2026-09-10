# Checkpoint — 2026-09-10

The small PHP ^8.5 / Symfony ^8.1 gateway is implemented and deployed to
https://airnow-api.survos.com on Dokku/fsn1. Observation and forecast routes return
live data (three observations and six forecasts in the verification), and repeat
requests preserve fetchedAt. The HTTP test suite passes: five tests, 45 assertions.
The public GitHub repository is survos-sites/airnow-api; its CI is green.

Keep this as a runnable checkpoint. The user is reassessing priorities: improve
local-site monitoring inside the existing showcase app before making another desktop
wrapper, and prioritize a printer/scanner bridge that exposes narrowly scoped hardware
operations through an authenticated tunnel. Do not build another site-inventory app.
Showcase already has src/Service/SymfonyProxy.php reading localhost:7080/index.json.

The Air Quality desktop proof and both architecture/DMG CI checks have passed in the
separate survos-sites/airnow repository. Its hosted-source adapter is not implemented.
The tutorial should not require a paid Apple Developer membership. Existing signed-release
workflow is an optional future path; do not trigger it without credentials. Source/local
builds suffice for now; any downloadable unsigned tutorial build must be labeled clearly.
The Medium article remains planned, not published.

Deployment verification: standard Symfony HttpClient receives HTTP 200 and three
observations from the public HTTPS endpoint. Cloudflare terminates HTTPS; the origin
uses HTTP. A tested origin certificate enabled Dokku's automatic redirect and caused
a loop through the current ingress, so that new origin configuration was reverted.
Both API calls and shared caching are verified; no Cloudflare-wide changes were made.
