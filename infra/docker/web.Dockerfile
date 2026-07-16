FROM node:24-bookworm AS base
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

FROM node:24-bookworm-slim AS production
ENV NODE_ENV=production PORT=3000
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/bin/npm /usr/local/bin/npx
WORKDIR /app
COPY --from=build --chown=node:node /prod/web/ ./
USER node
EXPOSE 3000
CMD ["./node_modules/.bin/react-router-serve", "./build/server/index.js"]
