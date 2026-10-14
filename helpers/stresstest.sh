#!/usr/bin/env bash
#
# Stress test: purges the rabbit queues, resets the dev database (composer dev:reset: schema + fixtures), then runs
# helpers/stresstest-account.sh (a loop of add-credit / add-debit commands) for every account in the database,
# at most PARALLEL accounts at the same time. The commands are dispatched asynchronously, so the rabbit workers must
# be running (make up-workers) to process them.
#
# Afterwards, once `bin/console messenger:stats` shows rabbit 0, compare the entries with the account totals:
#   SELECT a.id, a.credit, a.debit,
#          COALESCE(SUM(e.amount) FILTER (WHERE e.side = 'credit'), 0) AS entries_credit,
#          COALESCE(SUM(e.amount) FILTER (WHERE e.side = 'debit'), 0)  AS entries_debit
#   FROM account a LEFT JOIN account_entry e ON e.account_id = a.id GROUP BY a.id ORDER BY a.id;

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

COUNT=200
PARALLEL=10
MIN_AMOUNT=1
MAX_AMOUNT=100000
DEBIT_RATIO=50
DAYS=30
ACCOUNTS=''
DB_USER=root
DB_NAME=symfony8
PURGE=1
RESET=1

usage() {
    cat <<EOF
Usage: helpers/stresstest.sh [options]

Purges the rabbit queues, resets the dev database (composer dev:reset), then runs a loop of
add-credit / add-debit commands (helpers/stresstest-account.sh) for every account in the database,
at most --parallel accounts at the same time (start the workers: make up-workers).

Options:
  -n, --count N          commands per account (default: ${COUNT})
  -p, --parallel N       accounts processed at the same time (default: ${PARALLEL})
      --min-amount N     minimal amount in minor units (default: ${MIN_AMOUNT})
      --max-amount N     maximal amount in minor units (default: ${MAX_AMOUNT})
  -d, --debit-ratio N    percentage of debit commands, 0-100 (default: ${DEBIT_RATIO})
      --days N           effective date randomly within the last N days, 0 = now (default: ${DAYS})
  -a, --accounts IDS     comma separated account ids instead of all accounts from the database
      --no-purge         keep the messages waiting in the rabbit queues
      --no-reset         keep the current database (skip composer dev:reset)
  -h, --help             show this help

Examples:
  helpers/stresstest.sh
  helpers/stresstest.sh -n 1000 -p 5 --debit-ratio 30
  helpers/stresstest-account.sh 00000000-0000-4000-8000-000000000001 -n 50   # a single account
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
        --no-purge) PURGE=0; shift ;;
        --no-reset) RESET=0; shift ;;
        -h|--help) usage; exit 0 ;;
        *)
            (( $# >= 2 )) || { usage >&2; fail "unknown option or missing value: \"$1\""; }
            case $1 in
                -n|--count) COUNT=$2 ;;
                -p|--parallel) PARALLEL=$2 ;;
                --min-amount) MIN_AMOUNT=$2 ;;
                --max-amount) MAX_AMOUNT=$2 ;;
                -d|--debit-ratio) DEBIT_RATIO=$2 ;;
                --days) DAYS=$2 ;;
                -a|--accounts) ACCOUNTS=$2 ;;
                *) usage >&2; fail "unknown option \"$1\"" ;;
            esac
            shift 2
            ;;
    esac
done

require_int --parallel "$PARALLEL"
(( PARALLEL > 0 )) || fail '--parallel must be greater than zero'

ACCOUNT_OPTIONS=(-n "$COUNT" --min-amount "$MIN_AMOUNT" --max-amount "$MAX_AMOUNT" --debit-ratio "$DEBIT_RATIO"
    --days "$DAYS")
# the per account options are validated by stresstest-account.sh: check them once, before purging and resetting
helpers/stresstest-account.sh 00000000-0000-4000-8000-000000000000 "${ACCOUNT_OPTIONS[@]}" --dry-run >/dev/null

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
else
    COMPOSE=(docker-compose)
fi

if (( PURGE )); then
    echo 'Purging the rabbit queues (messages and delayed retries)...'
    while read -r queue; do
        [[ $queue == messages || $queue == delay_* ]] || continue
        "${COMPOSE[@]}" exec -T rabbitmq rabbitmqctl purge_queue -q "$queue"
    done < <("${COMPOSE[@]}" exec -T rabbitmq rabbitmqctl list_queues -q --no-table-headers name)
fi

if (( RESET )); then
    echo 'Resetting the dev database (composer dev:reset)...'
    "${COMPOSE[@]}" exec -T php composer dev:reset --no-interaction \
        || fail 'composer dev:reset failed, run it manually to see why'
fi

if [[ -n $ACCOUNTS ]]; then
    IFS=',' read -r -a ACCOUNT_IDS <<< "$ACCOUNTS"
else
    mapfile -t ACCOUNT_IDS < <(
        "${COMPOSE[@]}" exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -tA -c 'SELECT id FROM account ORDER BY id'
    )
fi
(( ${#ACCOUNT_IDS[@]} > 0 )) || fail 'no accounts found (load the fixtures: composer dev:reset, or pass --accounts)'

echo "Accounts: ${#ACCOUNT_IDS[@]}, commands per account: ${COUNT} (total: $(( ${#ACCOUNT_IDS[@]} * COUNT ))),"\
    "accounts at the same time: ${PARALLEL}"

printf -v START '%(%s)T' -1

# Ctrl+C reaches the whole process group: every running account loop stops after its current command.
printf '%s\n' "${ACCOUNT_IDS[@]}" \
    | xargs -P "$PARALLEL" -I{} helpers/stresstest-account.sh {} "${ACCOUNT_OPTIONS[@]}" \
    | awk -v total="${#ACCOUNT_IDS[@]}" '
        {
            printf "[%d/%d] %s\n", ++done, total, $0
            for (i = 2; i <= NF; i++) {
                split($i, field, "=")
                sum[field[1]] += field[2]
            }
        }
        END {
            printf "Ordered: %d commands, %d failed; credit %d, debit %d (minor units)\n",
                sum["ok"], sum["failed"], sum["credit"], sum["debit"]
        }
    '

printf -v END '%(%s)T' -1
echo "Done in $(( END - START ))s."
echo "Queue: ${COMPOSE[*]} exec php bin/console messenger:stats (wait for rabbit to reach 0 before comparing)"
