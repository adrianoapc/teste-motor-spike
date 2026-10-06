#!/bin/sh
# Aplica db/migrations/V*.sql em ordem, uma vez cada, no banco indicado.
# Uso: PGHOST=... PGUSER=... PGPASSWORD=... ./migrar.sh <banco>
set -eu
export PGOPTIONS="${PGOPTIONS:-} -c client_min_messages=warning"
BANCO="$1"
DIR="$(cd "$(dirname "$0")/../migrations" && pwd)"
psql -v ON_ERROR_STOP=1 -q -d "$BANCO" -c "CREATE TABLE IF NOT EXISTS public.migracao_aplicada (arquivo text PRIMARY KEY, aplicada_em timestamptz NOT NULL DEFAULT now());"
for f in "$DIR"/V*.sql; do
  nome="$(basename "$f")"
  ja="$(psql -tA -d "$BANCO" -c "SELECT 1 FROM public.migracao_aplicada WHERE arquivo = '$nome'")"
  if [ "$ja" = "1" ]; then
    echo "  = $nome (já aplicada)"
    continue
  fi
  echo "  + $nome"
  psql -v ON_ERROR_STOP=1 -q -d "$BANCO" --single-transaction \
       -f "$f" \
       -c "INSERT INTO public.migracao_aplicada (arquivo) VALUES ('$nome');"
done
echo "migrações ok em $BANCO"
