#!/bin/sh

set -ea

SCRIPT_DIR=$(realpath "$(dirname "$0")")
cd $SCRIPT_DIR

ELASTIC_NODE_REQUESTS_MEMORY=1000Mi
ELASTIC_NODE_LIMITS_MEMORY=1000Mi

if [ -f $SCRIPT_DIR/.env ]; then
  . $SCRIPT_DIR/.env
fi

envsubst < $SCRIPT_DIR/values.yaml > $SCRIPT_DIR/values.out.yaml

helm dep build

if [ $1 = "deploy" ]; then
  # to debug: --dry-run --debug
  helm upgrade --install ecamp3-logging \
      --namespace ecamp3-logging \
      --create-namespace \
      $SCRIPT_DIR \
      --values $SCRIPT_DIR/values.out.yaml
  exit 0
fi

if [ $1 = "diff" ]; then
  helm template \
      --namespace ecamp3-logging --no-hooks --skip-tests ecamp3-logging  \
      $SCRIPT_DIR \
      --values $SCRIPT_DIR/values.out.yaml | kubectl diff --namespace ecamp3-logging -f -
  exit 0
fi
