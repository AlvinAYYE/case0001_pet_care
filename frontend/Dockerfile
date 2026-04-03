FROM node:20-alpine AS build

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .

ARG VITE_NEWS_API_URL=/news
ARG VITE_SENIOR_API_URL=/senior-care
ENV VITE_NEWS_API_URL=${VITE_NEWS_API_URL}
ENV VITE_SENIOR_API_URL=${VITE_SENIOR_API_URL}

RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
