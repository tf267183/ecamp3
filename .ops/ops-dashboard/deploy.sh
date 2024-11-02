#!/bin/sh

set -ea

SCRIPT_DIR=$(realpath "$(dirname "$0")")
cd $SCRIPT_DIR

. $SCRIPT_DIR/.env

envsubst < $SCRIPT_DIR/values.yaml > $SCRIPT_DIR/values.out.yaml

helm dep build

if [ $1 = "deploy" ]; then
  # to debug: --dry-run --debug
  helm upgrade --install ops-dashboard --namespace=ops-dashboard --create-namespace $SCRIPT_DIR --values $SCRIPT_DIR/values.out.yaml
  exit 0
fi

if [ $1 = "diff" ]; then
  helm template \
      --namespace ops-dashboard --no-hooks --skip-tests ops-dashboard  \
      $SCRIPT_DIR \
      --values $SCRIPT_DIR/values.out.yaml | kubectl diff --namespace ops-dashboard -f -
  exit 0
fi
