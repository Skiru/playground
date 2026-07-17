FROM node:24-bookworm@sha256:5711a0d445a1af54af9589066c646df387d1831a608226f4cd694fc59e745059 AS base
ENV PNPM_HOME=/pnpm
ENV PATH=$PNPM_HOME:$PATH
RUN corepack enable
WORKDIR /workspace

FROM base AS dependencies
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml ./
COPY apps/web/package.json apps/web/package.json
COPY packages/api-client/package.json packages/api-client/package.json
RUN pnpm install --frozen-lockfile

FROM dependencies AS development
COPY . .
EXPOSE 3000

FROM dependencies AS build
COPY . .
RUN pnpm --filter @family-places/api-client build \
    && pnpm --filter @family-places/web build \
    && pnpm --filter @family-places/web --prod deploy --legacy /prod/web \
    && cp -R apps/web/build /prod/web/build \
    && test -x /prod/web/node_modules/.bin/react-router-serve

FROM node:24-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d AS production
ENV NODE_ENV=production PORT=3000

LABEL org.opencontainers.image.source="https://github.com/Skiru/playground"
LABEL org.opencontainers.image.revision="2338908d630973138a6d9fd27d2ae8d758ba6d50"
LABEL org.opencontainers.image.created="2026-07-17T09:00:00Z"
LABEL org.opencontainers.image.version="1.0.0"
LABEL org.opencontainers.image.title="family-places-web"
LABEL org.opencontainers.image.description="FamilyPlaces public web SSR catalog service"

RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/bin/npm /usr/local/bin/npx
WORKDIR /app
COPY --from=build --chown=node:node /prod/web/ ./
USER node
EXPOSE 3000
CMD ["./node_modules/.bin/react-router-serve", "./build/server/index.js"]
