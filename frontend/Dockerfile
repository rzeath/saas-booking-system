FROM node:24-alpine AS development

ARG HOST_UID=1000
ARG HOST_GID=1000

ENV NPM_CONFIG_CACHE=/tmp/npm-cache

RUN set -eux; \
    if [ "${HOST_UID}" != "1000" ] || [ "${HOST_GID}" != "1000" ]; then \
        deluser node; \
        delgroup node; \
        addgroup -g "${HOST_GID}" node; \
        adduser -D -u "${HOST_UID}" -G node node; \
    fi; \
    mkdir -p /var/www/frontend /tmp/npm-cache; \
    chown -R node:node /var/www/frontend /tmp/npm-cache

WORKDIR /var/www/frontend

COPY --chown=node:node package.json package-lock.json ./

USER node

RUN npm ci --no-audit --no-fund

USER root

COPY --chown=node:node . .

USER node

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
