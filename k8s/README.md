# Radix on Kubernetes

The second route to production. The first is `compose.yaml` in the repository root, deployed as a Portainer stack;
both run the same image and read the same environment variables, so what differs is only how those variables are
supplied and how migrations are ordered.

## What is here

| File | What it holds |
| --- | --- |
| `namespace.yaml` | The `radix` namespace. |
| `configmap.yaml` | Everything that is not a secret: hostnames, endpoints, addresses, the Valkey DSN. |
| `secret.example.yaml` | The plaintext template. **Never applied.** It is the input to `kubeseal`. |
| `sealedsecret.yaml` | A placeholder. Replace it with the real sealed output before deploying. |
| `valkey.yaml`, `rabbitmq.yaml` | The two stateful pieces the stack carries itself. |
| `job-migrate.yaml` | Migrations, once per release, before the rollout. |
| `deployment-app.yaml` | The pods that serve HTTP, plus the shared `radix-data` volume. |
| `deployment-workers.yaml` | One Deployment per messenger transport and per schedule. |
| `service.yaml` | The app Service, and Mailpit relaying to the real SMTP server. |

Routing is **not** here either. The cluster's own Traefik decides which names reach this Service, `join.gewis.nl`
among them, so nothing in this directory declares an Ingress.

PostgreSQL and MariaDB are **not** here. Both are reached over the network, exactly as they are under compose, and
their credentials travel inside `DATABASE_DSN` and `WEB_DATABASE_DSN` in the sealed Secret.

## Sealing the secrets

The `sealedsecret.yaml` in this directory is a placeholder with `PLACEHOLDER_SEAL_ME` where ciphertext belongs; the
controller will reject it. Produce the real one:

```sh
cp secret.example.yaml /tmp/radix-secrets.yaml
$EDITOR /tmp/radix-secrets.yaml            # fill in every REPLACE_ME
kubeseal --format yaml --controller-namespace kube-system \
  < /tmp/radix-secrets.yaml > sealedsecret.yaml
shred -u /tmp/radix-secrets.yaml
```

What comes out is safe to commit: only the sealed-secrets controller in that cluster holds the key that opens it. A
SealedSecret is encrypted against one cluster, so moving to another means sealing again.

## Deploying

```sh
kubectl apply -k .
kubectl -n radix wait --for=condition=complete job/radix-migrate --timeout=30m
kubectl -n radix rollout status deployment/radix-app
```

`kustomization.yaml`'s `images.newTag` is the one place a release moves; the migration Job runs the same tag, because
it has to migrate towards the code that is about to serve.

## How this differs from the compose route

- **Migrations.** Under compose the single `app` container migrates on start. Here several replicas start at once, so
  they set `SKIP_MIGRATIONS` — they still wait for both databases — and `job-migrate.yaml` owns the migration. The Job
  carries an Argo CD `PreSync` hook; with plain `kubectl` the `wait` above is what enforces the order.
- **Where values come from.** Portainer supplies the stack's environment and `compose.yaml` guards each one with
  `${VAR:?...}`. Here they are split by sensitivity: the ConfigMap for what may be read, the SealedSecret for what may
  not.
- **Defaults are shared.** Neither route carries the values that are the same everywhere. Those are `env(NAME):
  default` parameters in `config/services.yaml` — the mail display names, the pinned Stripe and Mailman API versions,
  the watermark tag, the TU/e subnets — and changing one is a code change.
