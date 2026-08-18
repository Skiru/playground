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

ARG OCI_SOURCE=https://github.com/Skiru/playground
ARG OCI_REVISION
ARG OCI_CREATED
ARG OCI_VERSION
LABEL org.opencontainers.image.source=$OCI_SOURCE \
    org.opencontainers.image.revision=$OCI_REVISION \
    org.opencontainers.image.created=$OCI_CREATED \
    org.opencontainers.image.version=$OCI_VERSION \
    org.opencontainers.image.title="family-places-web" \
    org.opencontainers.image.description="FamilyPlaces public web SSR catalog service"

RUN apt-get clean && apt-get update -o Acquire::Check-Valid-Until=false -o Acquire::AllowInsecureRepositories=true -o Acquire::AllowDowngradeToInsecureRepositories=true && apt-get upgrade -y --no-install-recommends && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/bin/npm /usr/local/bin/npx
WORKDIR /app
COPY --from=build --chown=node:node /prod/web/ ./
USER node
EXPOSE 3000
CMD ["./node_modules/.bin/react-router-serve", "./build/server/index.js"]
