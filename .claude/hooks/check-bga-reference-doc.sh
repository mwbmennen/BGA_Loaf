#!/bin/bash

# On stop: if none of bga-studio-reference.md, loaf-remarks.md, or
# bga-template-upstream-notes.md was modified today, ask Claude to review the session for
# any of these three kinds of lesson. If any was already updated this session, exit
# silently so Claude can stop normally.

REFERENCE_DOC="docs/bga-studio-reference.md"
REMARKS_DOC="docs/loaf-remarks.md"
UPSTREAM_DOC="docs/bga-template-upstream-notes.md"
TODAY=$(date +%Y-%m-%d)

REFERENCE_DATE=$(date -r "$REFERENCE_DOC" +%Y-%m-%d 2>/dev/null || echo "")
REMARKS_DATE=$(date -r "$REMARKS_DOC" +%Y-%m-%d 2>/dev/null || echo "")
UPSTREAM_DATE=$(date -r "$UPSTREAM_DOC" +%Y-%m-%d 2>/dev/null || echo "")

if [ "$REFERENCE_DATE" = "$TODAY" ] || [ "$REMARKS_DATE" = "$TODAY" ] || [ "$UPSTREAM_DATE" = "$TODAY" ]; then
  exit 0
fi

echo '{"continue": false, "stopReason": "Before finishing: review this session for any new lessons learned. Generic BGA Studio/framework conventions go in docs/bga-studio-reference.md; L'\''Oaf-specific implementation judgment calls and known gaps go in docs/loaf-remarks.md. Also check docs/bga-template-upstream-notes.md: if anything generic (not L'\''Oaf-specific) came up that belongs in the bga-game-template repo eventually, log or update an entry there too, even if it duplicates something you already wrote into bga-studio-reference.md. If nothing new to add to any of the three, just confirm briefly."}'
