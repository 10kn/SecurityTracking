#!/usr/bin/env bash
# Blocks Bash commands that rewrite files with Prettier or ESLint --fix.
# The repo has no formatter config, so defaults would reformat hand-formatted TSX.

command=$(jq -r '.tool_input.command // ""')

if grep -Eq '(^|[^[:alnum:]_-])prettier([^[:alnum:]_-]|$)' <<<"$command" && grep -Eq '(--write|(^|[[:space:]])-w([[:space:]]|$))' <<<"$command"; then
    echo "Blocked: this repo has no Prettier config; prettier --write would reformat hand-formatted files. Format by hand to match sibling files and check TSX with npx tsc --noEmit." >&2
    exit 2
fi

if grep -Eq '(^|[^[:alnum:]_-])eslint([^[:alnum:]_-]|$)' <<<"$command" && grep -Eq -- '--fix' <<<"$command"; then
    echo "Blocked: this repo has no ESLint config; eslint --fix would rewrite files. Check TSX with npx tsc --noEmit instead." >&2
    exit 2
fi

exit 0
