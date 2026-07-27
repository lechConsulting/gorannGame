# --- Frontend React/Vite : build statique servi par nginx -------------------
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# En prod, tout est servi sur le même domaine → chemins relatifs (zéro CORS).
ENV VITE_API_BASE=""
ENV VITE_MERCURE_URL="/.well-known/mercure"
RUN npm run build

# --- Image finale : nginx qui sert la SPA + proxy API + Mercure -------------
FROM nginx:alpine
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx.conf /etc/nginx/conf.d/app.conf
COPY --from=build /app/dist /usr/share/nginx/html
