FROM node:26-bookworm@sha256:219fc9da91e7f29a9f32290ff598cdf8886fd68f421ff515c8f93434da39a271 AS base
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

FROM node:26-bookworm-slim@sha256:2d49d876e96237d76de412761cf05dbfe5aee325cc4406a4d41d5824c5bb8beb AS production
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
