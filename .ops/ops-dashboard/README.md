# ops-dashboard

This is a helm chart to deploy an oauth2-proxy and a homer dashboard.
Then the ecamp3-developers can use their github login
to see our applications like graphana, kibana, kubernetes-dashboard...

## Prerequisites

You need the oauth2-proxy helm chart:

```shell
helm repo add oauth2-proxy https://oauth2-proxy.github.io/manifests
helm repo update
```

You also need the kubernetes-dashboard helm chart:

```shell
helm repo add kubernetes-dashboard https://kubernetes.github.io/dashboard/
helm repo update
```

## Deployment

First, check what is currently applied:

```shell
helm -n ops-dashboard get values ops-dashboard
```

Fill in the values for .env according to .env.example

```shell
cp .env-example .env
```

you may diff the current deployment with the one you want to do now

```shell
./deploy.sh diff
````

Deploy

```shell
./deploy.sh deploy
```
