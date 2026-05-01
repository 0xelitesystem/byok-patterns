FROM node:20-alpine

WORKDIR /app

# Copy only what's needed. No npm install required: zero dependencies.
COPY server.js ./
COPY package.json ./

# Run as non-root.
USER node

EXPOSE 3000

CMD ["node", "server.js"]
