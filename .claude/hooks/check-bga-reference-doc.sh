#!/bin/bash

# On stop: if neither bga-studio-reference.md nor gelati-remarks.md was modified today,
# ask Claude to review the session for either kind of lesson. If either was already
# updated this session, exit silently so Claude can stop normally.

REFERENCE_DOC="docs/bga-studio-reference.md"
REMARKS_DOC="docs/gelati-remarks.md"
TODAY=$(date +%Y-%m-%d)

REFERENCE_DATE=$(date -r "$REFERENCE_DOC" +%Y-%m-%d 2>/dev/null || echo "")
REMARKS_DATE=$(date -r "$REMARKS_DOC" +%Y-%m-%d 2>/dev/null || echo "")

if [ "$REFERENCE_DATE" = "$TODAY" ] || [ "$REMARKS_DATE" = "$TODAY" ]; then
  exit 0
fi

echo '{"continue": false, "stopReason": "Before finishing: review this session for any new lessons learned. Generic BGA Studio/framework conventions go in docs/bga-studio-reference.md; Gelati-specific implementation judgment calls and known gaps go in docs/gelati-remarks.md. If you found anything worth documenting in either, update it. If nothing new to add, just confirm briefly."}'
