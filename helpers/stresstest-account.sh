#!/usr/bin/env bash
#
# Runs app:account:add-credit / add-debit COUNT times, one after another, for a single account (inside the php
# container), with random amounts and effective dates. Used by helpers/stresstest.sh, can also be run on its own.
#
# Prints a single summary line: <account id> ok=N failed=N credit=SUM debit=SUM time=SECONDSs
# (failed commands are listed on stderr). Interrupting it stops the loop after the current command.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

COUNT=200
MIN_AMOUNT=1
MAX_AMOUNT=100000
DEBIT_RATIO=50
DAYS=30
DRY_RUN=0
ACCOUNT_ID=''

usage() {
    cat <<EOF
Usage: helpers/stresstest-account.sh <account id> [options]

Runs app:account:add-credit / add-debit one after another for a single account, with random amounts
and effective dates (commands are dispatched asynchronously, start the workers: make up-workers).

Options:
  -n, --count N          number of commands (default: ${COUNT})
      --min-amount N     minimal amount in minor units (default: ${MIN_AMOUNT})
      --max-amount N     maximal amount in minor units (default: ${MAX_AMOUNT})
  -d, --debit-ratio N    percentage of debit commands, 0-100 (default: ${DEBIT_RATIO})
      --days N           effective date randomly within the last N days, 0 = now (default: ${DAYS})
      --dry-run          print the commands instead of running them
  -h, --help             show this help
EOF
}

fail() {
    echo "error: $*" >&2
    exit 1
}

require_int() {
    [[ $2 =~ ^[0-9]+$ ]] || fail "$1 must be a non-negative integer, got \"$2\""
}

while (( $# > 0 )); do
    case $1 in
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        -*)
            (( $# >= 2 )) || fail "option \"$1\" requires a value"
            case $1 in
                -n|--count) COUNT=$2 ;;
                --min-amount) MIN_AMOUNT=$2 ;;
                --max-amount) MAX_AMOUNT=$2 ;;
                -d|--debit-ratio) DEBIT_RATIO=$2 ;;
                --days) DAYS=$2 ;;
                *) usage >&2; fail "unknown option \"$1\"" ;;
            esac
            shift 2
            ;;
        *)
            [[ -z $ACCOUNT_ID ]] || fail "unexpected argument \"$1\""
            ACCOUNT_ID=$1
            shift
            ;;
    esac
done

[[ -n $ACCOUNT_ID ]] || { usage >&2; fail 'account id is required'; }
[[ $ACCOUNT_ID =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]] \
    || fail "invalid account id \"$ACCOUNT_ID\""
require_int --count "$COUNT"
require_int --min-amount "$MIN_AMOUNT"
require_int --max-amount "$MAX_AMOUNT"
require_int --debit-ratio "$DEBIT_RATIO"
require_int --days "$DAYS"
(( COUNT > 0 )) || fail '--count must be greater than zero'
(( MIN_AMOUNT > 0 )) || fail '--min-amount must be greater than zero'
(( MAX_AMOUNT >= MIN_AMOUNT )) || fail '--max-amount must not be lower than --min-amount'
(( DEBIT_RATIO <= 100 )) || fail '--debit-ratio must be within 0..100'

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
else
    COMPOSE=(docker-compose)
fi

# 32-bit random number in REPLY (SRANDOM: bash 5.1+, falls back to two 15-bit RANDOMs);
# no $(...), which would fork a subshell for every call
random32() {
    if [[ -n ${SRANDOM-} ]]; then
        REPLY=$SRANDOM
    else
        REPLY=$(( (RANDOM << 15) | RANDOM ))
    fi
}

# one line per command: <credit|debit> <amount> <effective at>
generate() {
    local i side amount timestamp effective_at now
    local amount_range=$(( MAX_AMOUNT - MIN_AMOUNT + 1 )) period=$(( DAYS * 86400 ))
    printf -v now '%(%s)T' -1

    for (( i = 0; i < COUNT; i++ )); do
        random32
        amount=$(( MIN_AMOUNT + REPLY % amount_range ))
        random32
        timestamp=$(( now - (period > 0 ? REPLY % period : 0) ))
        printf -v effective_at '%(%Y-%m-%dT%H:%M:%S%z)T' "$timestamp"
        random32
        if (( REPLY % 100 < DEBIT_RATIO )); then side=debit; else side=credit; fi

        echo "$side $amount $effective_at"
    done
}

if (( DRY_RUN )); then
    generate | while read -r side amount effective_at; do
        echo "php bin/console app:account:add-${side} ${ACCOUNT_ID} ${amount} --effective-at=${effective_at}"
    done
    exit 0
fi

printf -v START '%(%s)T' -1

# The commands are generated on the fly and run one by one in the container; when this script is interrupted, the
# input ends and the loop stops after the current command. Each command boots a new PHP process: without Xdebug and
# with opcache kept on disk between processes (timestamps still validated) a run takes ~130 ms instead of ~280 ms.
SUMMARY=$(
    generate | "${COMPOSE[@]}" exec -T -e XDEBUG_MODE=off php bash -c '
        mkdir -p /tmp/stresstest-opcache
        while read -r side amount effective_at; do
            if php -d opcache.enable_cli=1 -d opcache.file_cache=/tmp/stresstest-opcache \
                -d opcache.file_cache_only=1 bin/console "app:account:add-$side" "$0" "$amount" \
                --effective-at="$effective_at" --no-interaction >/dev/null 2>&1
            then
                echo "ok $side $amount"
            else
                echo "failed $side $amount $effective_at"
            fi
        done
    ' "$ACCOUNT_ID" | awk -v account="$ACCOUNT_ID" '
        $1 == "ok" { ok++; sum[$2] += $3 }
        $1 == "failed" { failed++; print account ": failed " $2 " " $3 " " $4 > "/dev/stderr" }
        END { printf "%s ok=%d failed=%d credit=%d debit=%d", account, ok, failed, sum["credit"], sum["debit"] }
    '
)

printf -v END '%(%s)T' -1
echo "${SUMMARY} time=$(( END - START ))s"
