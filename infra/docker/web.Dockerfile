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
RUN pnpm --filter @family-places/api-client build && pnpm --filter @family-places/web build

FROM node:24-bookworm-slim AS production
ENV NODE_ENV=production
WORKDIR /app
COPY --from=build /workspace/apps/web/build ./build
COPY --from=build /workspace/apps/web/package.json ./package.json
COPY --from=dependencies /workspace/node_modules ./node_modules
USER node
EXPOSE 3000
CMD ["node", "./node_modules/@react-router/serve/bin.js", "./build/server/index.js"]
