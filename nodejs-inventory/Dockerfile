# syntax=docker/dockerfile:1

# The inventory service. Built by docker-compose with two contexts:
#   - this folder, nodejs-inventory/ (the service code)
#   - "proto": the shared contract in proto/ at the project root
FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund

COPY src ./src
# The tests need no extra packages, so they ship with the image and can be run
# inside the running stack: docker compose exec inventory npm test
COPY test ./test
COPY --from=proto . ./proto

# Files keep the permissions they have on the host; make sure the "node" user
# can read every file (and enter every folder) whatever those were.
RUN chmod -R a+rX /app

USER node
EXPOSE 50051

CMD ["node", "src/index.js"]
