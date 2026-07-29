# Compose topology

`compose.prod.arm64.yaml` is additive. It uses immutable digest variables, no host ports, an internal database network, a Caddy gateway on the edge network, and outbound-only cloudflared. Gateway maps `/api/*` to API and UI traffic to web. Database has 3 GB/0.75 CPU, API 900 MB/0.55 CPU, worker 512 MB/0.35 CPU, web 512 MB/0.35 CPU; this reserves host headroom on 12 GB RAM. Render validation passed.
